<?php

declare(strict_types=1);

namespace Sova\Notifications\Application;

interface PreferenceRepository
{
    /**
     * The effective preference for every kind: the member's stored choices,
     * with the documented default filling in anything they never touched.
     *
     * @return array<string, ChannelPreference> keyed by kind value
     */
    public function forMember(string $tenantId, string $membershipId): array;

    /**
     * The effective preferences of several members at once, so delivering one
     * event does not turn into one query per recipient.
     *
     * @param list<string> $membershipIds
     *
     * @return array<string, array<string, ChannelPreference>> membership to kind map
     */
    public function forMembers(string $tenantId, array $membershipIds): array;

    /**
     * Replaces the member's stored choices. Kinds absent from the list fall
     * back to their default again.
     *
     * @param list<ChannelPreference> $preferences
     */
    public function replace(
        string $tenantId,
        string $membershipId,
        array $preferences,
    ): void;
}
