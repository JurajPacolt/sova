<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;

/**
 * Orchestrates the workflow authoring lifecycle from WORKFLOW-A-TYPY-ULOH.md
 * §8: create and edit the single draft, report its publishing impact and
 * publish it atomically — switching the active version, migrating every issue
 * off the retired version, bumping the configuration revision and recording
 * history, an audit trail and a domain event in one transaction.
 *
 * The stored transition rules are validated structurally but not yet executed
 * at transition time: the issue schema carries no resolution, resolved-at or
 * custom-field columns in this slice, so validators and actions are persisted
 * for a later runtime engine (documented boundary).
 */
final readonly class WorkflowConfigurationService
{
    public function __construct(
        private Connection $connection,
        private WorkflowConfigurationRepository $workflows,
        private WorkflowValidator $validator,
        private IssueMigrator $issueMigrator,
        private ConfigurationEventPublisher $events,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @return list<WorkflowSummary>
     */
    public function listWorkflows(string $tenantId, string $projectId): array
    {
        return $this->workflows->listWorkflows($tenantId, $projectId);
    }

    public function getWorkflow(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): WorkflowSummary {
        $summary = $this->workflows->findWorkflowSummary($tenantId, $projectId, $workflowId);

        if ($summary === null) {
            throw $this->workflowNotFound();
        }

        return $summary;
    }

    public function createDraft(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): WorkflowVersionView {
        if ($this->workflows->findWorkflowSummary($tenantId, $projectId, $workflowId) === null) {
            throw $this->workflowNotFound();
        }

        try {
            return $this->connection->transactional(
                function () use ($tenantId, $projectId, $workflowId): WorkflowVersionView {
                    $draftId = $this->workflows->createDraftFromPublished(
                        $tenantId,
                        $projectId,
                        $workflowId,
                    );

                    return $this->requireVersion($tenantId, $projectId, $draftId);
                },
            );
        } catch (UniqueConstraintViolationException) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'WORKFLOW_DRAFT_EXISTS',
                'A draft already exists for this workflow. Edit or publish it instead.',
            );
        }
    }

    public function updateDraft(
        string $tenantId,
        string $projectId,
        string $workflowId,
        DraftContentInput $content,
    ): WorkflowVersionView {
        $draft = $this->workflows->findDraftVersion($tenantId, $projectId, $workflowId);

        if ($draft === null) {
            throw $this->draftMissing();
        }

        return $this->connection->transactional(
            function () use ($tenantId, $projectId, $draft, $content): WorkflowVersionView {
                if (!$this->workflows->replaceDraftContent(
                    $tenantId,
                    $projectId,
                    $draft->id,
                    $content,
                )) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'WORKFLOW_DRAFT_CONFLICT',
                        'The draft changed in the meantime. Reload and try again.',
                    );
                }

                return $this->requireVersion($tenantId, $projectId, $draft->id);
            },
        );
    }

    /**
     * @return list<\Sova\ProjectConfiguration\Domain\WorkflowValidationError>
     */
    public function validateDraft(string $tenantId, string $projectId, string $workflowId): array
    {
        $draft = $this->workflows->findDraftVersion($tenantId, $projectId, $workflowId);

        if ($draft === null) {
            throw $this->draftMissing();
        }

        return $this->validator->validate($draft);
    }

    public function impact(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ImpactReport {
        $draft = $this->workflows->findDraftVersion($tenantId, $projectId, $workflowId);

        if ($draft === null) {
            throw $this->draftMissing();
        }

        $active = $this->activeVersion($tenantId, $projectId, $workflowId);
        $counts = $active === null
            ? []
            : $this->issueMigrator->countIssuesByStatus($tenantId, $projectId, $active->id);

        return $this->buildImpact($tenantId, $projectId, $workflowId, $draft, $active, $counts);
    }

    public function publish(
        string $tenantId,
        string $projectId,
        string $workflowId,
        PublishInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): WorkflowVersionView {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $workflowId,
                $input,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): WorkflowVersionView {
                $revision = $this->workflows->lockConfigurationRevision($tenantId, $projectId);

                if ($input->expectedConfigVersion !== $revision) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'PROJECT_CONFIG_VERSION_CONFLICT',
                        'The project configuration changed in the meantime. Reload and try again.',
                    );
                }

                $draft = $this->workflows->findDraftVersion($tenantId, $projectId, $workflowId);

                if ($draft === null) {
                    throw $this->draftMissing();
                }

                $errors = $this->validator->validate($draft);

                if ($errors !== []) {
                    throw new DomainProblemException(
                        ProblemType::ValidationFailed,
                        'WORKFLOW_INVALID',
                        $errors[0]->detail,
                    );
                }

                $active = $this->activeVersion($tenantId, $projectId, $workflowId);
                $statusIdMapping = $this->resolveStatusMapping($tenantId, $projectId, $draft, $active, $input);

                $this->workflows->publishDraftVersion($tenantId, $projectId, $draft->id);
                $this->workflows->setActiveVersion($tenantId, $projectId, $workflowId, $draft->id);

                $migrated = 0;

                if ($active !== null && $active->id !== $draft->id) {
                    $migrated = $this->issueMigrator->migrateWorkflowVersion(
                        $tenantId,
                        $projectId,
                        $active->id,
                        $draft->id,
                        $statusIdMapping,
                        $actorUserId,
                    );
                    $this->workflows->retireVersion($tenantId, $projectId, $active->id);
                }

                $newRevision = $this->workflows->bumpConfigurationRevision($tenantId, $projectId);
                $this->recordPublication(
                    $tenantId,
                    $projectId,
                    $workflowId,
                    $draft,
                    $active,
                    $newRevision,
                    $migrated,
                    $actorUserId,
                    $requestId,
                    $ipAddress,
                );

                return $this->requireVersion($tenantId, $projectId, $draft->id);
            },
        );
    }

    /**
     * @return list<ConfigurationHistoryEntry>
     */
    public function history(
        string $tenantId,
        string $projectId,
        int $limit,
    ): array {
        return $this->workflows->listHistory($tenantId, $projectId, max(1, min($limit, 100)));
    }

    private function activeVersion(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?WorkflowVersionView {
        $activeVersionId = $this->workflows->findActiveVersionId($tenantId, $projectId, $workflowId);

        return $activeVersionId === null
            ? null
            : $this->workflows->loadVersion($tenantId, $projectId, $activeVersionId);
    }

    /**
     * @param array<string, int> $counts issue count keyed by current status id
     */
    private function buildImpact(
        string $tenantId,
        string $projectId,
        string $workflowId,
        WorkflowVersionView $draft,
        ?WorkflowVersionView $active,
        array $counts,
    ): ImpactReport {
        $draftStatusCodes = $this->statusCodes($draft);
        $activeStatusCodes = $active === null ? [] : $this->statusCodes($active);
        $removedStatusCodes = array_values(array_diff($activeStatusCodes, $draftStatusCodes));
        $draftCodeLookup = array_fill_keys($draftStatusCodes, true);

        $affected = [];
        $requiredMapping = [];

        if ($active !== null) {
            foreach ($active->statuses as $status) {
                $count = $counts[$status->statusId] ?? 0;

                if ($count > 0) {
                    $affected[] = new StatusIssueCount(
                        statusId: $status->statusId,
                        statusCode: $status->code,
                        statusName: $status->name,
                        count: $count,
                    );

                    if (!isset($draftCodeLookup[$status->code])) {
                        $requiredMapping[] = $status->code;
                    }
                }
            }
        }

        return new ImpactReport(
            workflowId: $workflowId,
            expectedConfigVersion: $this->workflows->configurationRevision($tenantId, $projectId),
            validationErrors: $this->validator->validate($draft),
            typeCodesUsingWorkflow: $this->workflows->typeCodesUsingWorkflow(
                $tenantId,
                $projectId,
                $workflowId,
            ),
            addedStatusCodes: array_values(array_diff($draftStatusCodes, $activeStatusCodes)),
            removedStatusCodes: $removedStatusCodes,
            addedTransitionCodes: array_values(array_diff(
                $this->transitionCodes($draft),
                $active === null ? [] : $this->transitionCodes($active),
            )),
            removedTransitionCodes: array_values(array_diff(
                $active === null ? [] : $this->transitionCodes($active),
                $this->transitionCodes($draft),
            )),
            affectedIssueCounts: $affected,
            requiredStatusMappingCodes: $requiredMapping,
        );
    }

    /**
     * Builds the total map from every current status id to the status id the
     * issues on it land on, and rejects a publish that leaves a used status
     * without a target or points one at a status the new version does not have.
     *
     * @return array<string, string> current status id => target status id
     */
    private function resolveStatusMapping(
        string $tenantId,
        string $projectId,
        WorkflowVersionView $draft,
        ?WorkflowVersionView $active,
        PublishInput $input,
    ): array {
        if ($active === null) {
            return [];
        }

        $draftIdByCode = [];

        foreach ($draft->statuses as $status) {
            $draftIdByCode[$status->code] = $status->statusId;
        }

        $counts = $this->issueMigrator->countIssuesByStatus($tenantId, $projectId, $active->id);
        $mapping = [];
        $missing = [];

        foreach ($active->statuses as $status) {
            if (isset($draftIdByCode[$status->code])) {
                $mapping[$status->statusId] = $draftIdByCode[$status->code];

                continue;
            }

            $targetCode = $input->statusMapping[$status->code] ?? null;

            if ($targetCode === null) {
                if (($counts[$status->statusId] ?? 0) > 0) {
                    $missing[] = $status->code;
                }

                $mapping[$status->statusId] = $status->statusId;

                continue;
            }

            if (!isset($draftIdByCode[$targetCode])) {
                throw new DomainProblemException(
                    ProblemType::ValidationFailed,
                    'WORKFLOW_MIGRATION_TARGET_INVALID',
                    'A migration target must be a status of the new workflow.',
                    ['status_mapping' => [sprintf(
                        'Status "%s" is not part of the new workflow.',
                        $targetCode,
                    )]],
                );
            }

            $mapping[$status->statusId] = $draftIdByCode[$targetCode];
        }

        if ($missing !== []) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'WORKFLOW_MIGRATION_REQUIRED',
                sprintf(
                    'Provide a migration target for statuses still carrying issues: %s.',
                    implode(', ', $missing),
                ),
            );
        }

        return $mapping;
    }

    private function recordPublication(
        string $tenantId,
        string $projectId,
        string $workflowId,
        WorkflowVersionView $draft,
        ?WorkflowVersionView $active,
        int $revision,
        int $migrated,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): void {
        $draftStatusCodes = $this->statusCodes($draft);
        $activeStatusCodes = $active === null ? [] : $this->statusCodes($active);

        $this->workflows->recordHistory(
            $tenantId,
            $projectId,
            $revision,
            'WORKFLOW_PUBLISHED',
            $workflowId,
            $draft->id,
            $actorUserId,
            [
                'version_number' => $draft->versionNumber,
                'migrated_issue_count' => $migrated,
                'added_status_codes' => array_values(
                    array_diff($draftStatusCodes, $activeStatusCodes),
                ),
                'removed_status_codes' => array_values(
                    array_diff($activeStatusCodes, $draftStatusCodes),
                ),
            ],
        );
        $this->events->publish(
            $tenantId,
            $projectId,
            $revision,
            'PROJECT_WORKFLOW_PUBLISHED',
            [
                'workflow_id' => $workflowId,
                'workflow_version_id' => $draft->id,
                'version_number' => $draft->versionNumber,
                'migrated_issue_count' => $migrated,
            ],
        );
        $this->audit->record(
            eventType: 'PROJECT_WORKFLOW_PUBLISHED',
            outcome: 'SUCCESS',
            reasonCode: 'WORKFLOW_PUBLISHED',
            requestId: $requestId,
            actorUserId: $actorUserId,
            tenantId: $tenantId,
            ipAddress: $ipAddress,
            metadata: [
                'project_id' => $projectId,
                'workflow_id' => $workflowId,
                'workflow_version_id' => $draft->id,
                'revision' => $revision,
                'migrated_issue_count' => $migrated,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function statusCodes(WorkflowVersionView $version): array
    {
        return array_map(static fn(VersionStatusView $status): string => $status->code, $version->statuses);
    }

    /**
     * @return list<string>
     */
    private function transitionCodes(WorkflowVersionView $version): array
    {
        return array_map(static fn(TransitionView $transition): string => $transition->code, $version->transitions);
    }

    private function requireVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): WorkflowVersionView {
        $version = $this->workflows->loadVersion($tenantId, $projectId, $versionId);

        if ($version === null) {
            throw new RuntimeException('The stored workflow version could not be loaded.');
        }

        return $version;
    }

    private function workflowNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'PROJECT_RESOURCE_NOT_FOUND',
            'The workflow was not found in this project.',
        );
    }

    private function draftMissing(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'WORKFLOW_DRAFT_MISSING',
            'Create a draft before continuing.',
        );
    }
}
