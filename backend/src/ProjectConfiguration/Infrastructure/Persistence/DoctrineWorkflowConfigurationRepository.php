<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;
use Sova\ProjectConfiguration\Application\ConfigurationHistoryEntry;
use Sova\ProjectConfiguration\Application\DraftContentInput;
use Sova\ProjectConfiguration\Application\DraftStatusInput;
use Sova\ProjectConfiguration\Application\DraftTransitionInput;
use Sova\ProjectConfiguration\Application\RuleView;
use Sova\ProjectConfiguration\Application\TransitionView;
use Sova\ProjectConfiguration\Application\VersionStatusView;
use Sova\ProjectConfiguration\Application\WorkflowConfigurationRepository;
use Sova\ProjectConfiguration\Application\WorkflowSummary;
use Sova\ProjectConfiguration\Application\WorkflowVersionView;
use Sova\ProjectConfiguration\Domain\ConfigurationStatus;
use Sova\ProjectConfiguration\Domain\StatusCategory;
use Sova\ProjectConfiguration\Domain\TransitionRuleType;
use Sova\ProjectConfiguration\Domain\WorkflowVersionState;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineWorkflowConfigurationRepository implements
    WorkflowConfigurationRepository
{
    public function __construct(private Connection $connection) {}

    public function listWorkflows(string $tenantId, string $projectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, name, description, active_version_id, status
                FROM project_workflows
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                ORDER BY name, id
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return array_map(
            fn(array $row): WorkflowSummary => $this->hydrateSummary($tenantId, $projectId, $row),
            $rows,
        );
    }

    public function findWorkflowSummary(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?WorkflowSummary {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, name, description, active_version_id, status
                FROM project_workflows
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :workflow_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );

        return $row === false ? null : $this->hydrateSummary($tenantId, $projectId, $row);
    }

    public function loadVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): ?WorkflowVersionView {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, workflow_id, version_number, state, version, initial_status_id
                FROM project_workflow_versions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :version_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );

        if ($row === false) {
            return null;
        }

        return new WorkflowVersionView(
            id: $this->stringValue($row, 'id'),
            workflowId: $this->stringValue($row, 'workflow_id'),
            versionNumber: $this->integerValue($row, 'version_number'),
            state: $this->versionState($row),
            optimisticVersion: $this->integerValue($row, 'version'),
            initialStatusId: $this->nullableStringValue($row, 'initial_status_id'),
            statuses: $this->loadVersionStatuses($tenantId, $projectId, $versionId),
            transitions: $this->loadVersionTransitions($tenantId, $projectId, $versionId),
        );
    }

    public function findActiveVersionId(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?string {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT active_version_id
                FROM project_workflows
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :workflow_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );

        return is_string($value) ? $value : null;
    }

    public function findDraftVersion(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?WorkflowVersionView {
        $versionId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM project_workflow_versions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_id = :workflow_id
                    AND state = :draft
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
                'draft' => WorkflowVersionState::Draft->value,
            ],
        );

        if (!is_string($versionId)) {
            return null;
        }

        return $this->loadVersion($tenantId, $projectId, $versionId);
    }

    public function typeCodesUsingWorkflow(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): array {
        $rows = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT issue_type.code
                FROM project_issue_type_workflows mapping
                INNER JOIN project_issue_types issue_type
                    ON issue_type.tenant_id = mapping.tenant_id
                    AND issue_type.project_id = mapping.project_id
                    AND issue_type.id = mapping.issue_type_id
                WHERE mapping.tenant_id = :tenant_id
                    AND mapping.project_id = :project_id
                    AND mapping.workflow_id = :workflow_id
                ORDER BY issue_type.code
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );

        $codes = [];

        foreach ($rows as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function configurationRevision(string $tenantId, string $projectId): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT revision
                FROM project_configuration_revisions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return $this->toRevision($value);
    }

    public function listHistory(string $tenantId, string $projectId, int $limit): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    id,
                    revision,
                    event_type,
                    workflow_id,
                    workflow_version_id,
                    actor_user_id,
                    metadata,
                    created_at
                FROM project_configuration_history
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                ORDER BY created_at DESC, revision DESC
                LIMIT :limit
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'limit' => $limit,
            ],
        );

        return array_map($this->hydrateHistoryEntry(...), $rows);
    }

    public function createDraftFromPublished(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): string {
        $activeVersionId = $this->findActiveVersionId($tenantId, $projectId, $workflowId);
        $source = $activeVersionId === null
            ? null
            : $this->loadVersion($tenantId, $projectId, $activeVersionId);

        $draftVersionId = (string) UuidV7::generate();
        $this->connection->insert('project_workflow_versions', [
            'id' => $draftVersionId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'workflow_id' => $workflowId,
            'version_number' => $this->nextVersionNumber($tenantId, $projectId, $workflowId),
            'state' => WorkflowVersionState::Draft->value,
            'initial_status_id' => $source?->initialStatusId,
        ]);

        if ($source !== null) {
            $this->copyVersionContent($tenantId, $projectId, $draftVersionId, $source);
        }

        return $draftVersionId;
    }

    public function replaceDraftContent(
        string $tenantId,
        string $projectId,
        string $draftVersionId,
        DraftContentInput $content,
    ): bool {
        $bumped = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_workflow_versions
                SET version = version + 1
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :version_id
                    AND state = :draft
                    AND version = :expected_version
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $draftVersionId,
                'draft' => WorkflowVersionState::Draft->value,
                'expected_version' => $content->expectedVersion,
            ],
        );

        if ($bumped !== 1) {
            return false;
        }

        $statusIdByCode = $this->upsertStatuses($tenantId, $projectId, $content->statuses);
        $this->replaceMembership(
            $tenantId,
            $projectId,
            $draftVersionId,
            $content->statuses,
            $statusIdByCode,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_workflow_versions
                SET initial_status_id = :initial_status_id
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :version_id
                SQL,
            [
                'initial_status_id' => $this->statusId(
                    $statusIdByCode,
                    $content->initialStatusCode,
                ),
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $draftVersionId,
            ],
        );
        $this->replaceTransitions(
            $tenantId,
            $projectId,
            $draftVersionId,
            $content->transitions,
            $statusIdByCode,
        );

        return true;
    }

    public function lockConfigurationRevision(string $tenantId, string $projectId): int
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_configuration_revisions (tenant_id, project_id)
                VALUES (:tenant_id, :project_id)
                ON CONFLICT (project_id) DO NOTHING
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT revision
                FROM project_configuration_revisions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                FOR UPDATE
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return $this->toRevision($value);
    }

    public function bumpConfigurationRevision(string $tenantId, string $projectId): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE project_configuration_revisions
                SET revision = revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                RETURNING revision
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return $this->toRevision($value);
    }

    public function publishDraftVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_workflow_versions
                SET state = :published,
                    published_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :version_id
                    AND state = :draft
                SQL,
            [
                'published' => WorkflowVersionState::Published->value,
                'draft' => WorkflowVersionState::Draft->value,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );
    }

    public function retireVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_workflow_versions
                SET state = :retired
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :version_id
                SQL,
            [
                'retired' => WorkflowVersionState::Retired->value,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );
    }

    public function setActiveVersion(
        string $tenantId,
        string $projectId,
        string $workflowId,
        string $versionId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_workflows
                SET active_version_id = :version_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :workflow_id
                SQL,
            [
                'version_id' => $versionId,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @throws JsonException
     */
    public function recordHistory(
        string $tenantId,
        string $projectId,
        int $revision,
        string $eventType,
        ?string $workflowId,
        ?string $workflowVersionId,
        ?string $actorUserId,
        array $metadata,
    ): void {
        $this->connection->insert('project_configuration_history', [
            'id' => (string) UuidV7::generate(),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'revision' => $revision,
            'event_type' => $eventType,
            'workflow_id' => $workflowId,
            'workflow_version_id' => $workflowVersionId,
            'actor_user_id' => $actorUserId,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSummary(string $tenantId, string $projectId, array $row): WorkflowSummary
    {
        $activeVersionId = $this->nullableStringValue($row, 'active_version_id');
        $workflowId = $this->stringValue($row, 'id');

        return new WorkflowSummary(
            id: $workflowId,
            name: $this->stringValue($row, 'name'),
            description: $this->stringValue($row, 'description'),
            activeVersionId: $activeVersionId,
            status: $this->configurationStatus($row),
            publishedVersion: $activeVersionId === null
                ? null
                : $this->loadVersion($tenantId, $projectId, $activeVersionId),
            draftVersion: $this->findDraftVersion($tenantId, $projectId, $workflowId),
        );
    }

    /**
     * @return list<VersionStatusView>
     */
    private function loadVersionStatuses(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    status.id,
                    status.code,
                    status.name,
                    status.category,
                    status.color_token,
                    member.position
                FROM workflow_version_statuses member
                INNER JOIN project_statuses status
                    ON status.tenant_id = member.tenant_id
                    AND status.project_id = member.project_id
                    AND status.id = member.status_id
                WHERE member.tenant_id = :tenant_id
                    AND member.project_id = :project_id
                    AND member.workflow_version_id = :version_id
                ORDER BY member.position, status.code
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );

        return array_map(
            fn(array $row): VersionStatusView => new VersionStatusView(
                statusId: $this->stringValue($row, 'id'),
                code: $this->stringValue($row, 'code'),
                name: $this->stringValue($row, 'name'),
                category: $this->statusCategory($row),
                colorToken: $this->stringValue($row, 'color_token'),
                position: $this->integerValue($row, 'position'),
            ),
            $rows,
        );
    }

    /**
     * @return list<TransitionView>
     */
    private function loadVersionTransitions(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    id,
                    code,
                    name,
                    from_status_id,
                    to_status_id,
                    permission_code,
                    is_primary,
                    position
                FROM project_workflow_transitions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_version_id = :version_id
                ORDER BY position, code
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );

        return array_map(
            fn(array $row): TransitionView => new TransitionView(
                id: $this->stringValue($row, 'id'),
                code: $this->stringValue($row, 'code'),
                name: $this->stringValue($row, 'name'),
                fromStatusId: $this->stringValue($row, 'from_status_id'),
                toStatusId: $this->stringValue($row, 'to_status_id'),
                permissionCode: $this->nullableStringValue($row, 'permission_code'),
                isPrimary: $this->boolValue($row, 'is_primary'),
                position: $this->integerValue($row, 'position'),
                rules: $this->loadTransitionRules($this->stringValue($row, 'id')),
            ),
            $rows,
        );
    }

    /**
     * @return list<RuleView>
     */
    private function loadTransitionRules(string $transitionId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, rule_type, rule_key, configuration, position
                FROM workflow_transition_rules
                WHERE transition_id = :transition_id
                ORDER BY position, rule_key
                SQL,
            ['transition_id' => $transitionId],
        );

        return array_map(
            fn(array $row): RuleView => new RuleView(
                id: $this->stringValue($row, 'id'),
                ruleType: $this->ruleType($row),
                ruleKey: $this->stringValue($row, 'rule_key'),
                configuration: $this->jsonObject($row, 'configuration'),
                position: $this->integerValue($row, 'position'),
            ),
            $rows,
        );
    }

    private function copyVersionContent(
        string $tenantId,
        string $projectId,
        string $targetVersionId,
        WorkflowVersionView $source,
    ): void {
        foreach ($source->statuses as $status) {
            $this->connection->insert('workflow_version_statuses', [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $targetVersionId,
                'status_id' => $status->statusId,
                'position' => $status->position,
            ]);
        }

        foreach ($source->transitions as $transition) {
            $transitionId = (string) UuidV7::generate();
            $this->connection->insert('project_workflow_transitions', [
                'id' => $transitionId,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $targetVersionId,
                'code' => $transition->code,
                'name' => $transition->name,
                'from_status_id' => $transition->fromStatusId,
                'to_status_id' => $transition->toStatusId,
                'permission_code' => $transition->permissionCode,
                'is_primary' => $transition->isPrimary,
                'position' => $transition->position,
            ], ['is_primary' => 'boolean']);
            $this->insertRules($tenantId, $projectId, $transitionId, $transition->rules);
        }
    }

    /**
     * @param list<RuleView> $rules
     */
    private function insertRules(
        string $tenantId,
        string $projectId,
        string $transitionId,
        array $rules,
    ): void {
        foreach ($rules as $rule) {
            $this->connection->insert('workflow_transition_rules', [
                'id' => (string) UuidV7::generate(),
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'transition_id' => $transitionId,
                'rule_type' => $rule->ruleType->value,
                'rule_key' => $rule->ruleKey,
                'configuration' => json_encode($rule->configuration, JSON_THROW_ON_ERROR),
                'position' => $rule->position,
            ]);
        }
    }

    /**
     * @param list<DraftStatusInput> $statuses
     *
     * @return array<string, string> status code to identifier
     */
    private function upsertStatuses(string $tenantId, string $projectId, array $statuses): array
    {
        $idByCode = [];

        foreach ($statuses as $status) {
            $existing = $this->connection->fetchOne(
                <<<'SQL'
                    SELECT id
                    FROM project_statuses
                    WHERE tenant_id = :tenant_id
                        AND project_id = :project_id
                        AND code = :code
                    SQL,
                [
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'code' => $status->code,
                ],
            );

            if (is_string($existing)) {
                // A reused status keeps its identity and any issues on it, so
                // its project-level attributes are never rewritten from a draft.
                $idByCode[$status->code] = $existing;

                continue;
            }

            $id = (string) UuidV7::generate();
            $this->connection->insert('project_statuses', [
                'id' => $id,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'code' => $status->code,
                'name' => $status->name,
                'description' => $status->description,
                'category' => $status->category->value,
                'color_token' => $status->colorToken,
                'position' => $status->position,
            ]);
            $idByCode[$status->code] = $id;
        }

        return $idByCode;
    }

    /**
     * @param list<DraftStatusInput>  $statuses
     * @param array<string, string>   $statusIdByCode
     */
    private function replaceMembership(
        string $tenantId,
        string $projectId,
        string $versionId,
        array $statuses,
        array $statusIdByCode,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM workflow_version_statuses
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_version_id = :version_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );

        foreach ($statuses as $status) {
            $this->connection->insert('workflow_version_statuses', [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $versionId,
                'status_id' => $this->statusId($statusIdByCode, $status->code),
                'position' => $status->position,
            ]);
        }
    }

    /**
     * @param list<DraftTransitionInput> $transitions
     * @param array<string, string>      $statusIdByCode
     */
    private function replaceTransitions(
        string $tenantId,
        string $projectId,
        string $versionId,
        array $transitions,
        array $statusIdByCode,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM project_workflow_transitions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_version_id = :version_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'version_id' => $versionId,
            ],
        );

        foreach ($transitions as $transition) {
            $transitionId = (string) UuidV7::generate();
            $this->connection->insert('project_workflow_transitions', [
                'id' => $transitionId,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $versionId,
                'code' => $transition->code,
                'name' => $transition->name,
                'from_status_id' => $this->statusId($statusIdByCode, $transition->fromCode),
                'to_status_id' => $this->statusId($statusIdByCode, $transition->toCode),
                'permission_code' => $transition->permissionCode,
                'is_primary' => $transition->isPrimary,
                'position' => $transition->position,
            ], ['is_primary' => 'boolean']);

            foreach ($transition->rules as $rule) {
                $this->connection->insert('workflow_transition_rules', [
                    'id' => (string) UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'project_id' => $projectId,
                    'transition_id' => $transitionId,
                    'rule_type' => $rule->ruleType->value,
                    'rule_key' => $rule->ruleKey,
                    'configuration' => json_encode($rule->configuration, JSON_THROW_ON_ERROR),
                    'position' => $rule->position,
                ]);
            }
        }
    }

    private function nextVersionNumber(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): int {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(MAX(version_number), 0) + 1
                FROM project_workflow_versions
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND workflow_id = :workflow_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );

        return $this->toRevision($value);
    }

    /**
     * @param array<string, string> $statusIdByCode
     */
    private function statusId(array $statusIdByCode, string $code): string
    {
        $id = $statusIdByCode[$code] ?? null;

        if ($id === null) {
            throw new RuntimeException(sprintf(
                'The draft references a status code "%s" that is not part of its statuses.',
                $code,
            ));
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateHistoryEntry(array $row): ConfigurationHistoryEntry
    {
        return new ConfigurationHistoryEntry(
            id: $this->stringValue($row, 'id'),
            revision: $this->integerValue($row, 'revision'),
            eventType: $this->stringValue($row, 'event_type'),
            workflowId: $this->nullableStringValue($row, 'workflow_id'),
            workflowVersionId: $this->nullableStringValue($row, 'workflow_version_id'),
            actorUserId: $this->nullableStringValue($row, 'actor_user_id'),
            metadata: $this->jsonObject($row, 'metadata'),
            createdAt: $this->stringValue($row, 'created_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function versionState(array $row): WorkflowVersionState
    {
        $state = WorkflowVersionState::tryFrom($this->stringValue($row, 'state'));

        if ($state === null) {
            throw new RuntimeException('Unknown workflow version state.');
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function statusCategory(array $row): StatusCategory
    {
        $category = StatusCategory::tryFrom($this->stringValue($row, 'category'));

        if ($category === null) {
            throw new RuntimeException('Unknown status category.');
        }

        return $category;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function ruleType(array $row): TransitionRuleType
    {
        $type = TransitionRuleType::tryFrom($this->stringValue($row, 'rule_type'));

        if ($type === null) {
            throw new RuntimeException('Unknown transition rule type.');
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function configurationStatus(array $row): ConfigurationStatus
    {
        $status = ConfigurationStatus::tryFrom($this->stringValue($row, 'status'));

        if ($status === null) {
            throw new RuntimeException('Unknown project configuration status.');
        }

        return $status;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function jsonObject(array $row, string $key): array
    {
        $value = $row[$key] ?? null;

        if (!is_string($value) || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf(
                'Column "%s" does not contain valid JSON.',
                $key,
            ), previous: $exception);
        }

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function toRevision(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('Expected an integer revision value.');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a nullable string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function integerValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer.',
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function boolValue(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;

        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
