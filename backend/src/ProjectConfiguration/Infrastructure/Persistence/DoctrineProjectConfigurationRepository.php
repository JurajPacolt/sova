<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use JsonException;
use RuntimeException;
use Sova\ProjectConfiguration\Application\CreateIssueTypeInput;
use Sova\ProjectConfiguration\Application\IssueCreationTarget;
use Sova\ProjectConfiguration\Application\IssueTypeAdministrationRepository;
use Sova\ProjectConfiguration\Application\IssueTypeDetails;
use Sova\ProjectConfiguration\Application\ProjectConfigurationRepository;
use Sova\ProjectConfiguration\Application\RuleView;
use Sova\ProjectConfiguration\Application\StatusDetails;
use Sova\ProjectConfiguration\Application\TransitionDetails;
use Sova\ProjectConfiguration\Application\UpdateIssueTypeInput;
use Sova\ProjectConfiguration\Domain\ConfigurationStatus;
use Sova\ProjectConfiguration\Domain\HierarchyLevel;
use Sova\ProjectConfiguration\Domain\StatusCategory;
use Sova\ProjectConfiguration\Domain\TransitionRuleType;
use Sova\ProjectConfiguration\Domain\WorkflowVersionState;
use ValueError;

final readonly class DoctrineProjectConfigurationRepository implements
    ProjectConfigurationRepository,
    IssueTypeAdministrationRepository
{
    public function __construct(private Connection $connection) {}

    public function listIssueTypes(string $tenantId, string $projectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->issueTypeSql() . "\nORDER BY issue_type.position, issue_type.code",
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return array_map($this->hydrateIssueType(...), $rows);
    }

    public function findIssueType(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): ?IssueTypeDetails {
        $row = $this->connection->fetchAssociative(
            $this->issueTypeSql() . "\n    AND issue_type.id = :issue_type_id",
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
            ],
        );

        return $row === false ? null : $this->hydrateIssueType($row);
    }

    public function findForUpdate(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): ?IssueTypeDetails {
        $row = $this->connection->fetchAssociative(
            $this->issueTypeSql() . "\n    AND issue_type.id = :issue_type_id"
                . "\nFOR UPDATE OF issue_type",
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
            ],
        );

        return $row === false ? null : $this->hydrateIssueType($row);
    }

    public function workflowCanServeActiveType(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM project_workflows workflow
                    INNER JOIN project_workflow_versions version
                        ON version.tenant_id = workflow.tenant_id
                        AND version.project_id = workflow.project_id
                        AND version.id = workflow.active_version_id
                        AND version.state = 'PUBLISHED'
                    WHERE workflow.tenant_id = :tenant_id
                        AND workflow.project_id = :project_id
                        AND workflow.id = :workflow_id
                        AND workflow.status = 'ACTIVE'
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
            ],
        );

        return $this->booleanValue($value);
    }

    public function create(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        CreateIssueTypeInput $input,
    ): void {
        $this->connection->insert('project_issue_types', [
            'id' => $issueTypeId,
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'code' => $input->code,
            'name' => $input->name,
            'description' => $input->description,
            'hierarchy_level' => $input->hierarchyLevel->value,
            'position' => $input->position,
            'icon' => $input->icon,
            'color_token' => $input->colorToken,
            'status' => ConfigurationStatus::Active->value,
        ]);
        $this->connection->insert('project_issue_type_workflows', [
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'issue_type_id' => $issueTypeId,
            'workflow_id' => $input->workflowId,
        ]);
    }

    public function update(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        UpdateIssueTypeInput $input,
    ): bool {
        $updated = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_issue_types
                SET name = :name,
                    description = :description,
                    hierarchy_level = :hierarchy_level,
                    position = :position,
                    icon = :icon,
                    color_token = :color_token,
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :issue_type_id
                    AND version = :expected_version
                SQL,
            [
                'name' => $input->name,
                'description' => $input->description,
                'hierarchy_level' => $input->hierarchyLevel->value,
                'position' => $input->position,
                'icon' => $input->icon,
                'color_token' => $input->colorToken,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
                'expected_version' => $input->expectedTypeVersion,
            ],
        );

        if ($updated !== 1) {
            return false;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_issue_type_workflows (
                    tenant_id,
                    project_id,
                    issue_type_id,
                    workflow_id
                )
                VALUES (
                    :tenant_id,
                    :project_id,
                    :issue_type_id,
                    :workflow_id
                )
                ON CONFLICT (project_id, issue_type_id) DO UPDATE
                SET workflow_id = EXCLUDED.workflow_id,
                    updated_at = CURRENT_TIMESTAMP
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
                'workflow_id' => $input->workflowId,
            ],
        );

        return true;
    }

    public function archive(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        int $expectedTypeVersion,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_issue_types
                SET status = 'ARCHIVED',
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND project_id = :project_id
                    AND id = :issue_type_id
                    AND version = :expected_version
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
                'expected_version' => $expectedTypeVersion,
            ],
        ) === 1;
    }

    public function hierarchyChangeIsValid(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        HierarchyLevel $targetLevel,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT NOT EXISTS (
                    SELECT 1
                    FROM issues issue
                    LEFT JOIN issues parent
                        ON parent.tenant_id = issue.tenant_id
                        AND parent.project_id = issue.project_id
                        AND parent.id = issue.parent_issue_id
                    LEFT JOIN project_issue_types parent_type
                        ON parent_type.tenant_id = parent.tenant_id
                        AND parent_type.project_id = parent.project_id
                        AND parent_type.id = parent.issue_type_id
                    WHERE issue.tenant_id = :tenant_id
                        AND issue.project_id = :project_id
                        AND issue.issue_type_id = :issue_type_id
                        AND NOT COALESCE((
                            (:target_level = 1 AND issue.parent_issue_id IS NULL)
                            OR (
                                :target_level = 0
                                AND (
                                    issue.parent_issue_id IS NULL
                                    OR parent_type.hierarchy_level = 1
                                )
                            )
                            OR (
                                :target_level = -1
                                AND parent_type.hierarchy_level = 0
                            )
                        ), FALSE)
                    UNION ALL
                    SELECT 1
                    FROM issues child
                    INNER JOIN issues parent
                        ON parent.tenant_id = child.tenant_id
                        AND parent.project_id = child.project_id
                        AND parent.id = child.parent_issue_id
                    INNER JOIN project_issue_types child_type
                        ON child_type.tenant_id = child.tenant_id
                        AND child_type.project_id = child.project_id
                        AND child_type.id = child.issue_type_id
                    WHERE parent.tenant_id = :tenant_id
                        AND parent.project_id = :project_id
                        AND parent.issue_type_id = :issue_type_id
                        AND NOT (
                            (child_type.hierarchy_level = 0 AND :target_level = 1)
                            OR (
                                child_type.hierarchy_level = -1
                                AND :target_level = 0
                            )
                        )
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
                'target_level' => $targetLevel->value,
            ],
        );

        return $this->booleanValue($value);
    }

    public function listStatuses(string $tenantId, string $projectId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    status.id,
                    status.project_id,
                    status.code,
                    status.name,
                    status.description,
                    status.category,
                    status.position,
                    status.status
                FROM project_statuses status
                WHERE status.tenant_id = :tenant_id
                    AND status.project_id = :project_id
                ORDER BY status.position, status.code
                SQL,
            ['tenant_id' => $tenantId, 'project_id' => $projectId],
        );

        return array_map($this->hydrateStatus(...), $rows);
    }

    public function findCreationTarget(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): ?IssueCreationTarget {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT
                    issue_type.id AS issue_type_id,
                    issue_type.code AS issue_type_code,
                    issue_type.hierarchy_level,
                    version.id AS workflow_version_id,
                    version.initial_status_id
                FROM project_issue_types issue_type
                INNER JOIN project_issue_type_workflows mapping
                    ON mapping.tenant_id = issue_type.tenant_id
                    AND mapping.project_id = issue_type.project_id
                    AND mapping.issue_type_id = issue_type.id
                INNER JOIN project_workflows workflow
                    ON workflow.tenant_id = mapping.tenant_id
                    AND workflow.project_id = mapping.project_id
                    AND workflow.id = mapping.workflow_id
                INNER JOIN project_workflow_versions version
                    ON version.tenant_id = workflow.tenant_id
                    AND version.project_id = workflow.project_id
                    AND version.id = workflow.active_version_id
                WHERE issue_type.tenant_id = :tenant_id
                    AND issue_type.project_id = :project_id
                    AND issue_type.id = :issue_type_id
                    AND issue_type.status = 'ACTIVE'
                    AND workflow.status = 'ACTIVE'
                    AND version.state = :published
                    AND version.initial_status_id IS NOT NULL
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
                'published' => WorkflowVersionState::Published->value,
            ],
        );

        if ($row === false) {
            return null;
        }

        return new IssueCreationTarget(
            issueTypeId: $this->stringValue($row, 'issue_type_id'),
            issueTypeCode: $this->stringValue($row, 'issue_type_code'),
            hierarchyLevel: $this->hierarchyLevel($row),
            workflowVersionId: $this->stringValue($row, 'workflow_version_id'),
            initialStatusId: $this->stringValue($row, 'initial_status_id'),
        );
    }

    public function listTransitions(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            $this->transitionSql() . "\nORDER BY transition.position, transition.code",
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $workflowVersionId,
            ],
        );

        return array_map($this->hydrateTransition(...), $rows);
    }

    public function findTransition(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
        string $transitionId,
    ): ?TransitionDetails {
        $row = $this->connection->fetchAssociative(
            $this->transitionSql() . "\n    AND transition.id = :transition_id",
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $workflowVersionId,
                'transition_id' => $transitionId,
            ],
        );

        return $row === false ? null : $this->hydrateTransition($row);
    }

    public function versionContainsStatus(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
        string $statusId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM workflow_version_statuses version_status
                    WHERE version_status.tenant_id = :tenant_id
                        AND version_status.project_id = :project_id
                        AND version_status.workflow_version_id = :workflow_version_id
                        AND version_status.status_id = :status_id
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'workflow_version_id' => $workflowVersionId,
                'status_id' => $statusId,
            ],
        );

        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }

    private function issueTypeSql(): string
    {
        return <<<'SQL'
            SELECT
                issue_type.id,
                issue_type.project_id,
                issue_type.code,
                issue_type.name,
                issue_type.description,
                issue_type.hierarchy_level,
                issue_type.position,
                issue_type.icon,
                issue_type.color_token,
                issue_type.status,
                issue_type.version,
                mapping.workflow_id
            FROM project_issue_types issue_type
            LEFT JOIN project_issue_type_workflows mapping
                ON mapping.tenant_id = issue_type.tenant_id
                AND mapping.project_id = issue_type.project_id
                AND mapping.issue_type_id = issue_type.id
            WHERE issue_type.tenant_id = :tenant_id
                AND issue_type.project_id = :project_id
            SQL;
    }

    private function transitionSql(): string
    {
        return <<<'SQL'
            SELECT
                transition.id,
                transition.workflow_version_id,
                transition.code,
                transition.name,
                transition.from_status_id,
                transition.to_status_id,
                target_status.code AS to_status_code,
                target_status.name AS to_status_name,
                transition.permission_code,
                transition.is_primary,
                transition.position,
                COALESCE((
                    SELECT jsonb_agg(
                        jsonb_build_object(
                            'id', rule.id,
                            'rule_type', rule.rule_type,
                            'rule_key', rule.rule_key,
                            'configuration', rule.configuration,
                            'position', rule.position
                        )
                        ORDER BY rule.position, rule.rule_key
                    )
                    FROM workflow_transition_rules rule
                    WHERE rule.transition_id = transition.id
                ), '[]'::jsonb) AS rules
            FROM project_workflow_transitions transition
            INNER JOIN project_statuses target_status
                ON target_status.tenant_id = transition.tenant_id
                AND target_status.project_id = transition.project_id
                AND target_status.id = transition.to_status_id
            WHERE transition.tenant_id = :tenant_id
                AND transition.project_id = :project_id
                AND transition.workflow_version_id = :workflow_version_id
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateIssueType(array $row): IssueTypeDetails
    {
        return new IssueTypeDetails(
            id: $this->stringValue($row, 'id'),
            projectId: $this->stringValue($row, 'project_id'),
            code: $this->stringValue($row, 'code'),
            name: $this->stringValue($row, 'name'),
            description: $this->stringValue($row, 'description'),
            hierarchyLevel: $this->hierarchyLevel($row),
            position: $this->integerValue($row, 'position'),
            icon: $this->stringValue($row, 'icon'),
            colorToken: $this->stringValue($row, 'color_token'),
            status: $this->configurationStatus($row),
            version: $this->integerValue($row, 'version'),
            workflowId: $this->nullableStringValue($row, 'workflow_id'),
        );
    }

    private function booleanValue(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't';
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateStatus(array $row): StatusDetails
    {
        $category = $this->stringValue($row, 'category');

        try {
            $statusCategory = StatusCategory::from($category);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf('Unknown status category "%s".', $category),
                previous: $exception,
            );
        }

        return new StatusDetails(
            id: $this->stringValue($row, 'id'),
            projectId: $this->stringValue($row, 'project_id'),
            code: $this->stringValue($row, 'code'),
            name: $this->stringValue($row, 'name'),
            description: $this->stringValue($row, 'description'),
            category: $statusCategory,
            position: $this->integerValue($row, 'position'),
            status: $this->configurationStatus($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateTransition(array $row): TransitionDetails
    {
        $isPrimary = $row['is_primary'] ?? null;

        return new TransitionDetails(
            id: $this->stringValue($row, 'id'),
            workflowVersionId: $this->stringValue($row, 'workflow_version_id'),
            code: $this->stringValue($row, 'code'),
            name: $this->stringValue($row, 'name'),
            fromStatusId: $this->stringValue($row, 'from_status_id'),
            toStatusId: $this->stringValue($row, 'to_status_id'),
            toStatusCode: $this->stringValue($row, 'to_status_code'),
            toStatusName: $this->stringValue($row, 'to_status_name'),
            permissionCode: $this->nullableStringValue($row, 'permission_code'),
            isPrimary: $isPrimary === true || $isPrimary === 1 || $isPrimary === 't',
            position: $this->integerValue($row, 'position'),
            rules: $this->hydrateRules($row),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<RuleView>
     */
    private function hydrateRules(array $row): array
    {
        $raw = $row['rules'] ?? null;

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(
                'The transition rules column does not contain valid JSON.',
                previous: $exception,
            );
        }

        if (!is_array($decoded)) {
            return [];
        }

        $rules = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            /** @var array<string, mixed> $entry */
            $type = TransitionRuleType::tryFrom($this->stringValue($entry, 'rule_type'));

            if ($type === null) {
                throw new RuntimeException('Unknown transition rule type.');
            }

            $configuration = $entry['configuration'] ?? [];

            if (!is_array($configuration)) {
                $configuration = [];
            }

            /** @var array<string, mixed> $configuration */
            $rules[] = new RuleView(
                id: $this->stringValue($entry, 'id'),
                ruleType: $type,
                ruleKey: $this->stringValue($entry, 'rule_key'),
                configuration: $configuration,
                position: $this->integerValue($entry, 'position'),
            );
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hierarchyLevel(array $row): HierarchyLevel
    {
        $level = HierarchyLevel::tryFrom($this->integerValue($row, 'hierarchy_level'));

        if ($level === null) {
            throw new RuntimeException('Unknown issue type hierarchy level.');
        }

        return $level;
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
}
