<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Application;

use Sova\SavedQueries\Domain\SavedQueryAccess;
use Sova\SavedQueries\Domain\SavedQueryVisibility;

interface SavedQueryRepository
{
    /**
     * Queries the member may see: their own, plus shared ones they hold a grant
     * for either directly or through an active workgroup. A tenant
     * administrator additionally sees every shared query, which is what makes
     * abandoned ones recoverable.
     *
     * @return list<SavedQuery>
     */
    public function listVisible(
        string $tenantId,
        string $membershipId,
        bool $administrator,
    ): array;

    public function find(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        bool $administrator,
    ): ?SavedQuery;

    /**
     * @return list<SavedQueryGrant>
     */
    public function listGrants(string $tenantId, string $savedQueryId): array;

    /**
     * @param list<string> $defaultColumns
     */
    public function create(
        string $tenantId,
        string $savedQueryId,
        string $ownerMembershipId,
        string $name,
        string $description,
        string $rawQuery,
        string $canonicalQuery,
        array $defaultColumns,
        SavedQueryVisibility $visibility,
    ): void;

    /**
     * Applies an edit under an optimistic lock.
     *
     * @param list<string> $defaultColumns
     *
     * @return int|null the new version, or null when the expected one no longer matches
     */
    public function update(
        string $tenantId,
        string $savedQueryId,
        int $expectedVersion,
        string $name,
        string $description,
        string $rawQuery,
        string $canonicalQuery,
        array $defaultColumns,
        SavedQueryVisibility $visibility,
    ): ?int;

    public function archive(string $tenantId, string $savedQueryId, int $expectedVersion): ?int;

    /**
     * Replaces every grant of the query in one go, so a removed principal
     * cannot survive a partially applied update.
     *
     * @param list<array{membership_id: ?string, workgroup_id: ?string, access: SavedQueryAccess}> $grants
     */
    public function replaceGrants(
        string $tenantId,
        string $savedQueryId,
        array $grants,
        string $grantedByUserId,
    ): void;

    public function setFavourite(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        bool $favourite,
    ): void;

    /** True when the name is free for this owner among live queries. */
    public function nameIsFree(
        string $tenantId,
        string $ownerMembershipId,
        string $normalizedName,
        ?string $exceptSavedQueryId,
    ): bool;

    /**
     * Principals that exist, are active and belong to this tenant. Anything
     * missing from the result must not receive a grant.
     *
     * @param list<string> $membershipIds
     * @param list<string> $workgroupIds
     *
     * @return array{memberships: list<string>, workgroups: list<string>}
     */
    public function activePrincipals(
        string $tenantId,
        array $membershipIds,
        array $workgroupIds,
    ): array;
}
