<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

/**
 * Every method takes the owning membership, not just the tenant.
 *
 * That is deliberate: there is no call that could accidentally read or write
 * somebody else's dashboard, because there is no signature that would let it.
 */
interface DashboardRepository
{
    /**
     * @return list<Dashboard>
     */
    public function listOwned(string $tenantId, string $membershipId): array;

    public function find(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): ?Dashboard;

    public function nameIsFree(
        string $tenantId,
        string $membershipId,
        string $normalizedName,
        ?string $exceptDashboardId,
    ): bool;

    public function countOwned(string $tenantId, string $membershipId): int;

    /**
     * Stores a new dashboard. The first one a membership creates becomes its
     * default, since a member must always have exactly one.
     */
    public function create(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        string $name,
        int $position,
        bool $isDefault,
    ): void;

    /**
     * @return int|null the new version, or null when the expected one no longer matches
     */
    public function rename(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        int $expectedVersion,
        string $name,
        ?int $position,
    ): ?int;

    /**
     * Moves the default flag in one transaction, so there is never a moment
     * with two defaults or none.
     */
    public function makeDefault(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): void;

    public function delete(string $tenantId, string $dashboardId, string $membershipId): bool;

    /**
     * Copies the dashboard with its widgets. The copy points at the same saved
     * queries — duplicating those would silently double everybody's query list.
     */
    public function copy(
        string $tenantId,
        string $sourceDashboardId,
        string $targetDashboardId,
        string $membershipId,
        string $name,
        int $position,
    ): void;

    public function activeDashboardId(string $tenantId, string $membershipId): ?string;

    public function setActiveDashboard(
        string $tenantId,
        string $membershipId,
        string $dashboardId,
    ): void;
}
