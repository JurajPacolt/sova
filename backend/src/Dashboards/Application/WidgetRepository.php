<?php

declare(strict_types=1);

namespace Sova\Dashboards\Application;

use Sova\Dashboards\Domain\WidgetPlacement;

/**
 * Widgets are always addressed through their dashboard, and the dashboard is
 * always addressed through its owner. There is no signature here that could
 * reach a widget on somebody else's dashboard.
 */
interface WidgetRepository
{
    /**
     * @return list<DashboardWidget>
     */
    public function listForDashboard(string $tenantId, string $dashboardId): array;

    public function find(
        string $tenantId,
        string $dashboardId,
        string $widgetId,
    ): ?DashboardWidget;

    public function countForDashboard(string $tenantId, string $dashboardId): int;

    /**
     * Whether the saved query is one the caller may use as a source: their own,
     * or one shared with them. A query they cannot reach must not become a
     * widget, or the dashboard would become a way to run somebody else's query
     * without holding a grant.
     */
    public function savedQueryIsUsable(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
    ): bool;

    /**
     * @param array<string, mixed> $configuration
     */
    public function create(
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        string $savedQueryId,
        string $typeKey,
        int $schemaVersion,
        string $title,
        array $configuration,
        int $x,
        int $y,
        int $width,
        int $height,
    ): void;

    /**
     * @param array<string, mixed> $configuration
     *
     * @return int|null the new version, or null when the expected one no longer matches
     */
    public function update(
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        int $expectedVersion,
        string $savedQueryId,
        string $title,
        array $configuration,
    ): ?int;

    public function delete(string $tenantId, string $dashboardId, string $widgetId): bool;

    /**
     * Applies the whole arrangement in one transaction against the dashboard's
     * version, so a second tab cannot half-apply its own layout over this one.
     *
     * @param list<WidgetPlacement> $placements
     *
     * @return int|null the dashboard's new version, or null on a version conflict
     */
    public function applyLayout(
        string $tenantId,
        string $dashboardId,
        int $expectedDashboardVersion,
        array $placements,
    ): ?int;

    /**
     * How many widgets across the tenant use this saved query. Archiving one
     * that a widget still renders would leave the dashboard pointing at
     * something retired.
     */
    public function countUsingSavedQuery(string $tenantId, string $savedQueryId): int;
}
