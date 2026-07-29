<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * The read side of a project's configuration. This is the stable interface the
 * issue tracking module depends on; it never touches configuration tables.
 */
interface ProjectConfigurationRepository
{
    /**
     * @return list<IssueTypeDetails>
     */
    public function listIssueTypes(string $tenantId, string $projectId): array;

    /**
     * @return list<StatusDetails>
     */
    public function listStatuses(string $tenantId, string $projectId): array;

    /**
     * Resolves an active issue type to its published workflow version and that
     * version's initial status. Null when the type is missing, archived or has
     * no published workflow.
     */
    public function findCreationTarget(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): ?IssueCreationTarget;

    public function findIssueType(
        string $tenantId,
        string $projectId,
        string $issueTypeId,
    ): ?IssueTypeDetails;

    /**
     * Transitions defined by a workflow version, ordered for presentation.
     *
     * @return list<TransitionDetails>
     */
    public function listTransitions(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
    ): array;

    public function findTransition(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
        string $transitionId,
    ): ?TransitionDetails;

    /**
     * Whether the status belongs to the workflow version, so a transition can
     * never move an issue onto a status the version does not contain.
     */
    public function versionContainsStatus(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
        string $statusId,
    ): bool;
}
