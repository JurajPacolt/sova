<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use RuntimeException;
use Sova\ProjectConfiguration\Domain\ConfigurationStatus;
use Sova\Shared\Application\Audit\SecurityAuditRecorder;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class IssueTypeAdministrationService
{
    public function __construct(
        private Connection $connection,
        private ProjectConfigurationRepository $configuration,
        private IssueTypeAdministrationRepository $issueTypes,
        private WorkflowConfigurationRepository $workflows,
        private ConfigurationEventPublisher $events,
        private SecurityAuditRecorder $audit,
    ) {}

    /**
     * @return list<IssueTypeDetails>
     */
    public function list(string $tenantId, string $projectId): array
    {
        return $this->configuration->listIssueTypes($tenantId, $projectId);
    }

    public function create(
        string $tenantId,
        string $projectId,
        CreateIssueTypeInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): IssueTypeDetails {
        try {
            return $this->connection->transactional(
                function () use (
                    $tenantId,
                    $projectId,
                    $input,
                    $actorUserId,
                    $requestId,
                    $ipAddress,
                ): IssueTypeDetails {
                    $this->requireExpectedRevision(
                        $tenantId,
                        $projectId,
                        $input->expectedConfigVersion,
                    );
                    $this->requireWorkflow($tenantId, $projectId, $input->workflowId);
                    $issueTypeId = (string) UuidV7::generate();
                    $this->issueTypes->create(
                        $tenantId,
                        $projectId,
                        $issueTypeId,
                        $input,
                    );
                    $revision = $this->workflows->bumpConfigurationRevision(
                        $tenantId,
                        $projectId,
                    );
                    $this->record(
                        $tenantId,
                        $projectId,
                        $issueTypeId,
                        $input->workflowId,
                        $revision,
                        'ISSUE_TYPE_CREATED',
                        $actorUserId,
                        $requestId,
                        $ipAddress,
                        ['issue_type_id' => $issueTypeId],
                    );

                    return $this->requireType($tenantId, $projectId, $issueTypeId);
                },
            );
        } catch (UniqueConstraintViolationException) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ISSUE_TYPE_CODE_TAKEN',
                'An issue type with this code already exists in the project.',
            );
        }
    }

    public function update(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        UpdateIssueTypeInput $input,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): IssueTypeDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $issueTypeId,
                $input,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): IssueTypeDetails {
                $this->requireExpectedRevision(
                    $tenantId,
                    $projectId,
                    $input->expectedConfigVersion,
                );
                $current = $this->issueTypes->findForUpdate(
                    $tenantId,
                    $projectId,
                    $issueTypeId,
                );

                if ($current === null) {
                    throw $this->notFound();
                }

                if ($current->version !== $input->expectedTypeVersion) {
                    throw $this->typeVersionConflict();
                }

                if ($current->status === ConfigurationStatus::Archived) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'ISSUE_TYPE_ARCHIVED',
                        'An archived issue type cannot be edited.',
                    );
                }

                $this->requireWorkflow($tenantId, $projectId, $input->workflowId);

                if (
                    $current->hierarchyLevel !== $input->hierarchyLevel
                    && !$this->issueTypes->hierarchyChangeIsValid(
                        $tenantId,
                        $projectId,
                        $issueTypeId,
                        $input->hierarchyLevel,
                    )
                ) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'ISSUE_TYPE_HIERARCHY_IN_USE',
                        'Existing parent or child relationships do not allow this hierarchy level.',
                    );
                }

                if ($this->matches($current, $input)) {
                    return $current;
                }

                if (!$this->issueTypes->update(
                    $tenantId,
                    $projectId,
                    $issueTypeId,
                    $input,
                )) {
                    throw $this->typeVersionConflict();
                }

                $revision = $this->workflows->bumpConfigurationRevision(
                    $tenantId,
                    $projectId,
                );
                $this->record(
                    $tenantId,
                    $projectId,
                    $issueTypeId,
                    $input->workflowId,
                    $revision,
                    'ISSUE_TYPE_UPDATED',
                    $actorUserId,
                    $requestId,
                    $ipAddress,
                    [
                        'issue_type_id' => $issueTypeId,
                        'previous_workflow_id' => $current->workflowId,
                        'workflow_id' => $input->workflowId,
                        'previous_hierarchy_level' => $current->hierarchyLevel->value,
                        'hierarchy_level' => $input->hierarchyLevel->value,
                    ],
                );

                return $this->requireType($tenantId, $projectId, $issueTypeId);
            },
        );
    }

    public function archive(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        int $expectedConfigVersion,
        int $expectedTypeVersion,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
    ): IssueTypeDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $issueTypeId,
                $expectedConfigVersion,
                $expectedTypeVersion,
                $actorUserId,
                $requestId,
                $ipAddress,
            ): IssueTypeDetails {
                $this->requireExpectedRevision(
                    $tenantId,
                    $projectId,
                    $expectedConfigVersion,
                );
                $current = $this->issueTypes->findForUpdate(
                    $tenantId,
                    $projectId,
                    $issueTypeId,
                );

                if ($current === null) {
                    throw $this->notFound();
                }

                if ($current->version !== $expectedTypeVersion) {
                    throw $this->typeVersionConflict();
                }

                if ($current->status === ConfigurationStatus::Archived) {
                    return $current;
                }

                if (!$this->issueTypes->archive(
                    $tenantId,
                    $projectId,
                    $issueTypeId,
                    $expectedTypeVersion,
                )) {
                    throw $this->typeVersionConflict();
                }

                $revision = $this->workflows->bumpConfigurationRevision(
                    $tenantId,
                    $projectId,
                );
                $this->record(
                    $tenantId,
                    $projectId,
                    $issueTypeId,
                    $current->workflowId,
                    $revision,
                    'ISSUE_TYPE_ARCHIVED',
                    $actorUserId,
                    $requestId,
                    $ipAddress,
                    ['issue_type_id' => $issueTypeId],
                );

                return $this->requireType($tenantId, $projectId, $issueTypeId);
            },
        );
    }

    private function requireExpectedRevision(
        string $tenantId,
        string $projectId,
        int $expectedRevision,
    ): void {
        $revision = $this->workflows->lockConfigurationRevision($tenantId, $projectId);

        if ($revision !== $expectedRevision) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'PROJECT_CONFIG_VERSION_CONFLICT',
                'The project configuration changed in the meantime. Reload and try again.',
            );
        }
    }

    private function requireWorkflow(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): void {
        if (!$this->issueTypes->workflowCanServeActiveType(
            $tenantId,
            $projectId,
            $workflowId,
        )) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ISSUE_TYPE_WORKFLOW_INVALID',
                'Choose an active workflow with a published version in this project.',
                ['workflow_id' => ['Choose an active published workflow.']],
            );
        }
    }

    private function requireType(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): IssueTypeDetails {
        $issueType = $this->configuration->findIssueType(
            $tenantId,
            $projectId,
            $issueTypeId,
        );

        if ($issueType === null) {
            throw new RuntimeException(
                'The issue type could not be loaded after the change.',
            );
        }

        return $issueType;
    }

    private function matches(
        IssueTypeDetails $current,
        UpdateIssueTypeInput $input,
    ): bool {
        return $current->name === $input->name
            && $current->description === $input->description
            && $current->hierarchyLevel === $input->hierarchyLevel
            && $current->position === $input->position
            && $current->icon === $input->icon
            && $current->colorToken === $input->colorToken
            && $current->workflowId === $input->workflowId;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
        ?string $workflowId,
        int $revision,
        string $eventType,
        string $actorUserId,
        string $requestId,
        ?string $ipAddress,
        array $metadata,
    ): void {
        $this->workflows->recordHistory(
            $tenantId,
            $projectId,
            $revision,
            $eventType,
            $workflowId,
            null,
            $actorUserId,
            $metadata,
        );
        $this->events->publish(
            $tenantId,
            $projectId,
            $revision,
            $eventType,
            ['issue_type_id' => $issueTypeId, 'revision' => $revision],
        );
        $this->audit->record(
            eventType: $eventType,
            outcome: 'SUCCESS',
            reasonCode: $eventType,
            requestId: $requestId,
            actorUserId: $actorUserId,
            tenantId: $tenantId,
            ipAddress: $ipAddress,
            metadata: [
                'project_id' => $projectId,
                'issue_type_id' => $issueTypeId,
                'revision' => $revision,
            ],
        );
    }

    private function notFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'ISSUE_TYPE_NOT_FOUND',
            'The issue type was not found in this project.',
        );
    }

    private function typeVersionConflict(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'ISSUE_TYPE_VERSION_CONFLICT',
            'The issue type changed in the meantime. Reload and try again.',
        );
    }
}
