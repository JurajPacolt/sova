<?php

declare(strict_types=1);

namespace Sova\Issues\Application\Watcher;

interface WatcherRepository
{
    /**
     * Members currently watching the issue. The caller passes the memberships
     * it may reveal, because the list must not expose people outside the
     * reader's project context (webflow §6).
     *
     * @return list<Watcher>
     */
    public function listForIssue(string $tenantId, string $issueId): array;

    /**
     * Memberships currently watching the issue, optionally without the member
     * behind `$excludeUserId`. Notifications use it to avoid telling someone
     * about their own action.
     *
     * @return list<string>
     */
    public function watchingMembershipIds(
        string $tenantId,
        string $issueId,
        ?string $excludeUserId = null,
    ): array;

    public function isWatching(
        string $tenantId,
        string $issueId,
        string $membershipId,
    ): bool;

    /**
     * Records an explicit decision. It always wins over an automatic rule.
     */
    public function setWatching(
        string $tenantId,
        string $projectId,
        string $issueId,
        string $membershipId,
        bool $watching,
    ): void;

    /**
     * Subscribes a member because of something they did — authoring the issue,
     * being assigned, commenting. It must never overwrite a stored decision,
     * so a member who explicitly unwatched stays unwatched.
     */
    public function watchAutomatically(
        string $tenantId,
        string $projectId,
        string $issueId,
        string $membershipId,
        WatchSource $source,
    ): void;
}
