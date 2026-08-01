<?php

declare(strict_types=1);

namespace Sova\ProjectConfiguration\Application;

/**
 * Migrates issues off a retired workflow version, owned by the issue tracking
 * module so the configuration module never writes issue tables directly
 * (WORKFLOW-A-TYPY-ULOH.md §15). The configuration module depends on this port;
 * the issues module supplies the adapter.
 */
interface IssueMigrator
{
    /**
     * How many issues currently sit on each status of a workflow version.
     *
     * @return array<string, int> status identifier => issue count
     */
    public function countIssuesByStatus(
        string $tenantId,
        string $projectId,
        string $workflowVersionId,
    ): array;

    /**
     * Moves every issue on the retired version onto the newly published one,
     * remapping its status, bumping its optimistic version and recording issue
     * history plus an outbox event. Runs inside the caller's transaction.
     *
     * @param array<string, string> $statusIdMapping current status id => target status id
     *
     * @return int the number of migrated issues
     */
    public function migrateWorkflowVersion(
        string $tenantId,
        string $projectId,
        string $fromVersionId,
        string $toVersionId,
        array $statusIdMapping,
        string $actorUserId,
    ): int;
}
