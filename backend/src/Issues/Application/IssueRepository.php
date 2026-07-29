<?php

declare(strict_types=1);

namespace Sova\Issues\Application;

interface IssueRepository
{
    /**
     * @return list<IssueDetails>
     */
    public function listForProject(
        string $tenantId,
        string $projectId,
        int $limit,
    ): array;

    public function find(string $tenantId, string $issueId): ?IssueDetails;

    /**
     * Locks the issue row so a transition reads and writes a consistent state.
     */
    public function findForUpdate(string $tenantId, string $issueId): ?IssueDetails;

    /**
     * Atomically reserves the next number of the project. Safe under
     * concurrency: the counter row is upserted and incremented in one
     * statement.
     */
    public function reserveNumber(string $tenantId, string $projectId): int;

    public function create(IssueRecord $record): void;

    /**
     * Moves the issue to a new status when its version still matches, bumping
     * the version and applying the transition's resolution effect. Returns
     * false when another writer got there first.
     */
    public function applyTransition(
        string $tenantId,
        string $issueId,
        string $statusId,
        int $expectedVersion,
        TransitionEffect $effect,
    ): bool;

    /**
     * Moves the issue to a new issue type, its published workflow version and a
     * status that version contains, when the issue version still matches.
     * Returns false when another writer got there first.
     */
    public function applyTypeChange(
        string $tenantId,
        string $issueId,
        string $issueTypeId,
        string $workflowVersionId,
        string $statusId,
        int $expectedVersion,
    ): bool;

    /**
     * Appends one entry to the user-facing issue history.
     *
     * `$changesIssue` says whether the entry accompanies a change to the issue
     * itself. Such entries stay unique per issue version, which is what stops a
     * transition from being recorded twice. An entry that only annotates the
     * issue — a comment, for instance — passes false: it must not bump
     * `issues.version`, so several of them legitimately share one version.
     *
     * @param array<string, mixed> $metadata
     */
    public function recordHistory(
        string $tenantId,
        string $projectId,
        string $issueId,
        int $issueVersion,
        string $eventType,
        ?string $actorUserId,
        ?string $transitionId,
        ?string $fromStatusId,
        ?string $toStatusId,
        array $metadata = [],
        bool $changesIssue = true,
    ): void;

    /**
     * The hierarchy level of the parent's issue type, or null when the parent
     * does not exist in this tenant and project.
     */
    public function parentHierarchyLevel(
        string $tenantId,
        string $projectId,
        string $parentIssueId,
    ): ?int;

    /**
     * Distinct hierarchy levels of the issue's direct children, so a type
     * change can reject a level that could no longer parent them.
     *
     * @return list<int>
     */
    public function childHierarchyLevels(
        string $tenantId,
        string $projectId,
        string $parentIssueId,
    ): array;

    public function membershipIsActive(
        string $tenantId,
        string $membershipId,
    ): bool;

    public function workgroupIsActive(
        string $tenantId,
        string $workgroupId,
    ): bool;
}
