<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * The authoring side of a project's workflow configuration: reading workflows
 * and their versions, editing the single draft and the atomic publish plumbing
 * (revision lock, version state changes, history). The read-only runtime side
 * lives in {@see ProjectConfigurationRepository}.
 */
interface WorkflowConfigurationRepository
{
    /**
     * @return list<WorkflowSummary>
     */
    public function listWorkflows(string $tenantId, string $projectId): array;

    public function findWorkflowSummary(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?WorkflowSummary;

    public function loadVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): ?WorkflowVersionView;

    public function findActiveVersionId(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?string;

    public function findDraftVersion(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): ?WorkflowVersionView;

    /**
     * Issue type codes whose active mapping points at this workflow.
     *
     * @return list<string>
     */
    public function typeCodesUsingWorkflow(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): array;

    public function configurationRevision(string $tenantId, string $projectId): int;

    /**
     * @return list<ConfigurationHistoryEntry>
     */
    public function listHistory(string $tenantId, string $projectId, int $limit): array;

    /**
     * Copies the active published version into a new draft. Throws a unique
     * constraint violation when a draft already exists (one draft per workflow).
     *
     * @return string the new draft version identifier
     */
    public function createDraftFromPublished(
        string $tenantId,
        string $projectId,
        string $workflowId,
    ): string;

    /**
     * Replaces the entire content of the draft: status membership (creating
     * genuinely new project statuses, reusing existing codes untouched),
     * transitions, rules and the initial status. Bumps the draft's optimistic
     * version only when it still matches; returns false on a stale editor.
     */
    public function replaceDraftContent(
        string $tenantId,
        string $projectId,
        string $draftVersionId,
        DraftContentInput $content,
    ): bool;

    /**
     * Locks and returns the current project configuration revision so a publish
     * serializes against concurrent editors.
     */
    public function lockConfigurationRevision(string $tenantId, string $projectId): int;

    public function bumpConfigurationRevision(string $tenantId, string $projectId): int;

    public function publishDraftVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): void;

    public function retireVersion(
        string $tenantId,
        string $projectId,
        string $versionId,
    ): void;

    public function setActiveVersion(
        string $tenantId,
        string $projectId,
        string $workflowId,
        string $versionId,
    ): void;

    /**
     * @param array<string, mixed> $metadata
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
    ): void;
}
