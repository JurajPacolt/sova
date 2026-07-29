<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

/**
 * The little the notification module needs to know about tenant members: who is
 * behind a membership, and where to reach them.
 */
interface MemberDirectory
{
    /**
     * Active memberships only, so a disabled member is silently dropped from
     * every audience rather than needing a check at each call site.
     *
     * @param list<string> $membershipIds
     *
     * @return array<string, string> membership identifier to user identifier
     */
    public function usersFor(string $tenantId, array $membershipIds): array;

    public function contactFor(string $tenantId, string $membershipId): ?MemberContact;
}
