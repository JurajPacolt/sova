<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Issues\Application\Watcher\WatcherRepository;
use Sova\Issues\Application\Watcher\WatchSource;
use Sova\ProjectConfiguration\Application\ProjectConfigurationRepository;
use Sova\ProjectConfiguration\Domain\HierarchyLevel;
use Sova\Shared\Domain\Error\DomainProblemException;
use Sova\Shared\Domain\Error\ProblemType;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class IssueService
{
    public function __construct(
        private Connection $connection,
        private IssueRepository $issues,
        private ProjectConfigurationRepository $configuration,
        private IssueEventPublisher $events,
        private TransitionRuleEvaluator $rules,
        private WatcherRepository $watchers,
    ) {}

    /**
     * Creates an issue from the project's own metadata. The client never picks
     * the workflow version or the initial status.
     */
    public function create(
        string $tenantId,
        string $projectId,
        string $projectCode,
        CreateIssueInput $input,
        string $actorUserId,
        string $reporterMembershipId,
    ): IssueDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $projectId,
                $projectCode,
                $input,
                $actorUserId,
                $reporterMembershipId,
            ): IssueDetails {
                $target = $this->configuration->findCreationTarget(
                    $tenantId,
                    $projectId,
                    $input->issueTypeId,
                );

                if ($target === null) {
                    throw new DomainProblemException(
                        ProblemType::ValidationFailed,
                        'ISSUE_TYPE_NOT_AVAILABLE',
                        'The issue type is archived, unknown or has no published workflow.',
                        ['issue_type_id' => ['Choose an active issue type.']],
                    );
                }

                $this->assertHierarchy($tenantId, $projectId, $input, $target->hierarchyLevel);
                $this->assertAssignees($tenantId, $input);

                $number = $this->issues->reserveNumber($tenantId, $projectId);
                $issueId = (string) UuidV7::generate();
                $this->issues->create(new IssueRecord(
                    id: $issueId,
                    tenantId: $tenantId,
                    projectId: $projectId,
                    number: $number,
                    key: sprintf('%s-%d', $projectCode, $number),
                    title: $input->title,
                    description: $input->description,
                    issueTypeId: $target->issueTypeId,
                    workflowVersionId: $target->workflowVersionId,
                    statusId: $target->initialStatusId,
                    parentIssueId: $input->parentIssueId,
                    reporterMembershipId: $reporterMembershipId,
                    assigneeMembershipId: $input->assigneeMembershipId,
                    assigneeWorkgroupId: $input->assigneeWorkgroupId,
                    priority: $input->priority,
                    createdByUserId: $actorUserId,
                ));
                $this->issues->recordHistory(
                    tenantId: $tenantId,
                    projectId: $projectId,
                    issueId: $issueId,
                    issueVersion: 1,
                    eventType: 'ISSUE_CREATED',
                    actorUserId: $actorUserId,
                    transitionId: null,
                    fromStatusId: null,
                    toStatusId: $target->initialStatusId,
                );
                // The reporter follows what they filed unless they later say
                // otherwise; the automatic rule never overrides a stored
                // decision (webflow §6).
                $this->watchers->watchAutomatically(
                    $tenantId,
                    $projectId,
                    $issueId,
                    $reporterMembershipId,
                    WatchSource::Author,
                );

                if (
                    $input->assigneeMembershipId !== null
                    && $input->assigneeMembershipId !== $reporterMembershipId
                ) {
                    $this->watchers->watchAutomatically(
                        $tenantId,
                        $projectId,
                        $issueId,
                        $input->assigneeMembershipId,
                        WatchSource::Assignee,
                    );
                }

                $this->events->publish(
                    tenantId: $tenantId,
                    issueId: $issueId,
                    sequenceNumber: 1,
                    eventName: 'ISSUE_CREATED',
                    payload: [
                        // Consumers need the actor to avoid telling people
                        // about their own action.
                        'actor_user_id' => $actorUserId,
                        'reporter_membership_id' => $reporterMembershipId,
                        'assignee_membership_id' => $input->assigneeMembershipId,
                        'project_id' => $projectId,
                        'issue_type_id' => $target->issueTypeId,
                        'status_id' => $target->initialStatusId,
                    ],
                );

                return $this->reload($tenantId, $issueId);
            },
        );
    }

    /**
     * Transitions available to the actor for this issue right now. The caller
     * supplies the permission check so authorization stays in one service.
     *
     * @param callable(?string): bool $permissionCheck receives the transition's
     *                                                 extra permission code
     *
     * @return list<AvailableTransition>
     */
    public function availableTransitions(
        IssueDetails $issue,
        callable $permissionCheck,
        TransitionActor $actor,
    ): array {
        $available = [];

        foreach ($this->configuration->listTransitions(
            $issue->tenantId,
            $issue->projectId,
            $issue->workflowVersionId,
        ) as $transition) {
            if (!$transition->startsFrom($issue->statusId)) {
                continue;
            }

            if (!$permissionCheck($transition->permissionCode)) {
                continue;
            }

            if (!$this->rules->conditionsSatisfied(
                $transition->rules,
                $permissionCheck,
                $actor,
                $issue->assigneeMembershipId,
            )) {
                continue;
            }

            $available[] = new AvailableTransition(
                $transition,
                $issue->version,
                $this->rules->requiredFields($transition->rules),
            );
        }

        return $available;
    }

    /**
     * Executes a transition by identifier. Rejects a stale expected version, a
     * transition from another status and a target outside the workflow version.
     *
     * @param callable(?string): bool $permissionCheck
     */
    public function transition(
        string $tenantId,
        string $issueId,
        string $transitionId,
        int $expectedVersion,
        string $actorUserId,
        callable $permissionCheck,
        TransitionActor $actor,
        ?string $suppliedResolution,
    ): IssueDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $issueId,
                $transitionId,
                $expectedVersion,
                $actorUserId,
                $permissionCheck,
                $actor,
                $suppliedResolution,
            ): IssueDetails {
                $issue = $this->issues->findForUpdate($tenantId, $issueId);

                if ($issue === null) {
                    throw $this->issueNotFound();
                }

                if ($issue->version !== $expectedVersion) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'ISSUE_VERSION_CONFLICT',
                        'The issue changed in the meantime. Reload and try again.',
                    );
                }

                $transition = $this->configuration->findTransition(
                    $tenantId,
                    $issue->projectId,
                    $issue->workflowVersionId,
                    $transitionId,
                );

                if (
                    $transition === null
                    || !$transition->startsFrom($issue->statusId)
                ) {
                    throw new DomainProblemException(
                        ProblemType::ValidationFailed,
                        'TRANSITION_NOT_AVAILABLE',
                        'The transition does not belong to the current status and workflow version.',
                    );
                }

                if (
                    !$permissionCheck($transition->permissionCode)
                    || !$this->rules->conditionsSatisfied(
                        $transition->rules,
                        $permissionCheck,
                        $actor,
                        $issue->assigneeMembershipId,
                    )
                ) {
                    throw new DomainProblemException(
                        ProblemType::PermissionDenied,
                        'PERMISSION_DENIED',
                        'You do not have permission to perform this operation.',
                    );
                }

                if (!$this->configuration->versionContainsStatus(
                    $tenantId,
                    $issue->projectId,
                    $issue->workflowVersionId,
                    $transition->toStatusId,
                )) {
                    throw new DomainProblemException(
                        ProblemType::ValidationFailed,
                        'WORKFLOW_INVALID',
                        'The target status does not belong to the workflow version.',
                    );
                }

                $effect = $this->rules->apply(
                    $transition->rules,
                    $suppliedResolution,
                    $issue->resolution,
                );

                if (!$this->issues->applyTransition(
                    $tenantId,
                    $issueId,
                    $transition->toStatusId,
                    $expectedVersion,
                    $effect,
                )) {
                    throw new DomainProblemException(
                        ProblemType::Conflict,
                        'ISSUE_VERSION_CONFLICT',
                        'The issue changed in the meantime. Reload and try again.',
                    );
                }

                $newVersion = $expectedVersion + 1;
                $this->issues->recordHistory(
                    tenantId: $tenantId,
                    projectId: $issue->projectId,
                    issueId: $issueId,
                    issueVersion: $newVersion,
                    eventType: 'ISSUE_TRANSITIONED',
                    actorUserId: $actorUserId,
                    transitionId: $transition->id,
                    fromStatusId: $issue->statusId,
                    toStatusId: $transition->toStatusId,
                );
                $this->events->publish(
                    tenantId: $tenantId,
                    issueId: $issueId,
                    sequenceNumber: $newVersion,
                    eventName: 'ISSUE_TRANSITIONED',
                    payload: [
                        'actor_user_id' => $actorUserId,
                        'project_id' => $issue->projectId,
                        'transition_id' => $transition->id,
                        'from_status_id' => $issue->statusId,
                        'to_status_id' => $transition->toStatusId,
                    ] + ($effect->touchesResolution
                        ? ['resolution' => $effect->resolution]
                        : []),
                );

                return $this->reload($tenantId, $issueId);
            },
        );
    }

    /**
     * Changes an issue's type per WORKFLOW-A-TYPY-ULOH.md §5.4: the target type
     * must be an active type of the same project with a published workflow, the
     * parent and any existing children must still form a valid hierarchy, and
     * the current status must map into the target workflow version — otherwise
     * the caller supplies an explicit target status.
     */
    public function changeType(
        string $tenantId,
        string $issueId,
        string $targetIssueTypeId,
        int $expectedVersion,
        ?string $targetStatusId,
        string $actorUserId,
    ): IssueDetails {
        return $this->connection->transactional(
            function () use (
                $tenantId,
                $issueId,
                $targetIssueTypeId,
                $expectedVersion,
                $targetStatusId,
                $actorUserId,
            ): IssueDetails {
                $issue = $this->issues->findForUpdate($tenantId, $issueId);

                if ($issue === null) {
                    throw $this->issueNotFound();
                }

                if ($issue->version !== $expectedVersion) {
                    throw $this->versionConflict();
                }

                if ($targetIssueTypeId === $issue->issueTypeId) {
                    throw new DomainProblemException(
                        ProblemType::ValidationFailed,
                        'ISSUE_TYPE_UNCHANGED',
                        'The issue already has this type.',
                        ['target_issue_type_id' => ['Choose a different issue type.']],
                    );
                }

                $target = $this->configuration->findCreationTarget(
                    $tenantId,
                    $issue->projectId,
                    $targetIssueTypeId,
                );

                // findCreationTarget only returns an active type with a
                // published workflow, so a missing, archived or unpublished
                // target reads the same and never leaks which one it was.
                if ($target === null) {
                    throw new DomainProblemException(
                        ProblemType::ValidationFailed,
                        'ISSUE_TYPE_INVALID',
                        'The target issue type is not an active type with a published workflow.',
                        ['target_issue_type_id' => ['Choose an active issue type.']],
                    );
                }

                $this->assertTypeHierarchy($tenantId, $issue, $target->hierarchyLevel);

                $newStatusId = $this->resolveTargetStatus(
                    $tenantId,
                    $issue,
                    $target->workflowVersionId,
                    $targetStatusId,
                );

                if (!$this->issues->applyTypeChange(
                    $tenantId,
                    $issueId,
                    $target->issueTypeId,
                    $target->workflowVersionId,
                    $newStatusId,
                    $expectedVersion,
                )) {
                    throw $this->versionConflict();
                }

                $newVersion = $expectedVersion + 1;
                $this->issues->recordHistory(
                    tenantId: $tenantId,
                    projectId: $issue->projectId,
                    issueId: $issueId,
                    issueVersion: $newVersion,
                    eventType: 'ISSUE_TYPE_CHANGED',
                    actorUserId: $actorUserId,
                    transitionId: null,
                    fromStatusId: $issue->statusId,
                    toStatusId: $newStatusId,
                    metadata: [
                        'from_issue_type_id' => $issue->issueTypeId,
                        'to_issue_type_id' => $target->issueTypeId,
                        'workflow_version_id' => $target->workflowVersionId,
                    ],
                );
                $this->events->publish(
                    tenantId: $tenantId,
                    issueId: $issueId,
                    sequenceNumber: $newVersion,
                    eventName: 'ISSUE_TYPE_CHANGED',
                    payload: [
                        'project_id' => $issue->projectId,
                        'from_issue_type_id' => $issue->issueTypeId,
                        'to_issue_type_id' => $target->issueTypeId,
                        'workflow_version_id' => $target->workflowVersionId,
                        'from_status_id' => $issue->statusId,
                        'to_status_id' => $newStatusId,
                    ],
                );

                return $this->reload($tenantId, $issueId);
            },
        );
    }

    private function assertHierarchy(
        string $tenantId,
        string $projectId,
        CreateIssueInput $input,
        HierarchyLevel $level,
    ): void {
        $parentLevel = null;

        if ($input->parentIssueId !== null) {
            $rawLevel = $this->issues->parentHierarchyLevel(
                $tenantId,
                $projectId,
                $input->parentIssueId,
            );

            // A parent outside this tenant and project reads as missing, so the
            // response never confirms that it exists elsewhere.
            if ($rawLevel === null) {
                throw new DomainProblemException(
                    ProblemType::ResourceNotFound,
                    'PROJECT_RESOURCE_NOT_FOUND',
                    'The parent issue was not found in this project.',
                );
            }

            $parentLevel = HierarchyLevel::tryFrom($rawLevel);
        }

        if ($level->requiresParent() && $parentLevel === null) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'HIERARCHY_INVALID',
                'A sub-task requires a standard parent issue.',
                ['parent_issue_id' => ['Choose a standard parent issue.']],
            );
        }

        if (!$level->acceptsParent($parentLevel)) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'HIERARCHY_INVALID',
                'The parent issue does not satisfy the hierarchy of this issue type.',
                ['parent_issue_id' => ['Choose a parent allowed for this issue type.']],
            );
        }
    }

    private function assertAssignees(string $tenantId, CreateIssueInput $input): void
    {
        if (
            $input->assigneeMembershipId !== null
            && !$this->issues->membershipIsActive($tenantId, $input->assigneeMembershipId)
        ) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ISSUE_ASSIGNEE_INACTIVE',
                'The assignee must be an active tenant member.',
            );
        }

        if (
            $input->assigneeWorkgroupId !== null
            && !$this->issues->workgroupIsActive($tenantId, $input->assigneeWorkgroupId)
        ) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ISSUE_ASSIGNEE_WORKGROUP_INACTIVE',
                'The assigned workgroup must be active.',
            );
        }
    }

    private function assertTypeHierarchy(
        string $tenantId,
        IssueDetails $issue,
        HierarchyLevel $level,
    ): void {
        $parentLevel = null;

        if ($issue->parentIssueId !== null) {
            $rawLevel = $this->issues->parentHierarchyLevel(
                $tenantId,
                $issue->projectId,
                $issue->parentIssueId,
            );

            $parentLevel = $rawLevel === null ? null : HierarchyLevel::tryFrom($rawLevel);
        }

        if ($level->requiresParent() && $parentLevel === null) {
            throw $this->hierarchyInvalid(
                'A sub-task requires a standard parent issue.',
            );
        }

        if (!$level->acceptsParent($parentLevel)) {
            throw $this->hierarchyInvalid(
                'The parent issue does not satisfy the hierarchy of the target type.',
            );
        }

        foreach ($this->issues->childHierarchyLevels(
            $tenantId,
            $issue->projectId,
            $issue->id,
        ) as $rawChildLevel) {
            $childLevel = HierarchyLevel::tryFrom($rawChildLevel);

            if ($childLevel === null || !$childLevel->acceptsParent($level)) {
                throw $this->hierarchyInvalid(
                    'An existing child issue would no longer fit under the target type.',
                );
            }
        }
    }

    private function resolveTargetStatus(
        string $tenantId,
        IssueDetails $issue,
        string $workflowVersionId,
        ?string $targetStatusId,
    ): string {
        if ($this->configuration->versionContainsStatus(
            $tenantId,
            $issue->projectId,
            $workflowVersionId,
            $issue->statusId,
        )) {
            return $issue->statusId;
        }

        if ($targetStatusId === null) {
            throw new DomainProblemException(
                ProblemType::Conflict,
                'ISSUE_TYPE_STATUS_MAPPING_REQUIRED',
                'The current status does not exist in the target workflow. Provide a target status.',
            );
        }

        if (!$this->configuration->versionContainsStatus(
            $tenantId,
            $issue->projectId,
            $workflowVersionId,
            $targetStatusId,
        )) {
            throw new DomainProblemException(
                ProblemType::ValidationFailed,
                'ISSUE_TYPE_STATUS_INVALID',
                'The target status does not belong to the target workflow version.',
                ['target_status_id' => ['Choose a status of the target workflow.']],
            );
        }

        return $targetStatusId;
    }

    private function hierarchyInvalid(string $detail): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ValidationFailed,
            'HIERARCHY_INVALID',
            $detail,
        );
    }

    private function versionConflict(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::Conflict,
            'ISSUE_VERSION_CONFLICT',
            'The issue changed in the meantime. Reload and try again.',
        );
    }

    private function reload(string $tenantId, string $issueId): IssueDetails
    {
        $issue = $this->issues->find($tenantId, $issueId);

        if ($issue === null) {
            throw new RuntimeException('The stored issue could not be loaded.');
        }

        return $issue;
    }

    private function issueNotFound(): DomainProblemException
    {
        return new DomainProblemException(
            ProblemType::ResourceNotFound,
            'ISSUE_NOT_FOUND',
            'The issue was not found.',
        );
    }
}
