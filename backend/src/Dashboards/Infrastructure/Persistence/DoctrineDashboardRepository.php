<?php

declare(strict_types=1);

namespace Sova\Dashboards\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use Sova\Dashboards\Application\Dashboard;
use Sova\Dashboards\Application\DashboardRepository;
use Sova\Dashboards\Domain\DashboardName;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Ownership is part of the `WHERE` clause of every statement, never a check
 * performed afterwards in PHP. A dashboard belonging to somebody else simply
 * does not come back, so a forgotten filter cannot leak one.
 */
final readonly class DoctrineDashboardRepository implements DashboardRepository
{
    public function __construct(private Connection $connection) {}

    public function listOwned(string $tenantId, string $membershipId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->selectSql() . "\nORDER BY dashboard.position, dashboard.id",
            ['tenant_id' => $tenantId, 'membership_id' => $membershipId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): ?Dashboard {
        $row = $this->connection->fetchAssociative(
            $this->selectSql() . "\n    AND dashboard.id = :dashboard_id",
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'dashboard_id' => $dashboardId,
            ],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function nameIsFree(
        string $tenantId,
        string $membershipId,
        string $normalizedName,
        ?string $exceptDashboardId,
    ): bool {
        $taken = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM dashboards
                    WHERE tenant_id = :tenant_id
                        AND owner_membership_id = :membership_id
                        AND normalized_name = :normalized_name
                        AND (:except_id::uuid IS NULL OR id <> :except_id::uuid)
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'normalized_name' => $normalizedName,
                'except_id' => $exceptDashboardId,
            ],
        );

        return !in_array($taken, [true, 1, '1', 't'], true);
    }

    public function countOwned(string $tenantId, string $membershipId): int
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM dashboards
                WHERE tenant_id = :tenant_id
                    AND owner_membership_id = :membership_id
                SQL,
            ['tenant_id' => $tenantId, 'membership_id' => $membershipId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function create(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        string $name,
        int $position,
        bool $isDefault,
    ): void {
        $this->connection->insert(
            'dashboards',
            [
                'id' => $dashboardId,
                'tenant_id' => $tenantId,
                'owner_membership_id' => $membershipId,
                'name' => $name,
                'normalized_name' => DashboardName::normalize($name),
                'position' => $position,
                'is_default' => $isDefault,
            ],
            ['is_default' => ParameterType::BOOLEAN],
        );
    }

    public function rename(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
        int $expectedVersion,
        string $name,
        ?int $position,
    ): ?int {
        $version = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE dashboards
                SET name = :name,
                    normalized_name = :normalized_name,
                    position = COALESCE(:position, position),
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :dashboard_id
                    AND owner_membership_id = :membership_id
                    AND version = :expected_version
                RETURNING version
                SQL,
            [
                'tenant_id' => $tenantId,
                'dashboard_id' => $dashboardId,
                'membership_id' => $membershipId,
                'expected_version' => $expectedVersion,
                'name' => $name,
                'normalized_name' => DashboardName::normalize($name),
                'position' => $position,
            ],
        );

        return is_numeric($version) ? (int) $version : null;
    }

    /**
     * Both statements run in one transaction: the partial unique index would
     * otherwise reject the second default that briefly exists between them.
     */
    public function makeDefault(
        string $tenantId,
        string $dashboardId,
        string $membershipId,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $tenantId,
            $dashboardId,
            $membershipId,
        ): void {
            $connection->executeStatement(
                <<<'SQL'
                    UPDATE dashboards
                    SET is_default = FALSE,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE tenant_id = :tenant_id
                        AND owner_membership_id = :membership_id
                        AND is_default
                        AND id <> :dashboard_id
                    SQL,
                [
                    'tenant_id' => $tenantId,
                    'membership_id' => $membershipId,
                    'dashboard_id' => $dashboardId,
                ],
            );

            $connection->executeStatement(
                <<<'SQL'
                    UPDATE dashboards
                    SET is_default = TRUE,
                        version = version + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE tenant_id = :tenant_id
                        AND id = :dashboard_id
                        AND owner_membership_id = :membership_id
                        AND NOT is_default
                    SQL,
                [
                    'tenant_id' => $tenantId,
                    'dashboard_id' => $dashboardId,
                    'membership_id' => $membershipId,
                ],
            );
        });
    }

    public function delete(string $tenantId, string $dashboardId, string $membershipId): bool
    {
        return $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM dashboards
                WHERE tenant_id = :tenant_id
                    AND id = :dashboard_id
                    AND owner_membership_id = :membership_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'dashboard_id' => $dashboardId,
                'membership_id' => $membershipId,
            ],
        ) > 0;
    }

    public function copy(
        string $tenantId,
        string $sourceDashboardId,
        string $targetDashboardId,
        string $membershipId,
        string $name,
        int $position,
    ): void {
        $this->connection->transactional(function (Connection $connection) use (
            $tenantId,
            $sourceDashboardId,
            $targetDashboardId,
            $membershipId,
            $name,
            $position,
        ): void {
            $connection->insert(
                'dashboards',
                [
                    'id' => $targetDashboardId,
                    'tenant_id' => $tenantId,
                    'owner_membership_id' => $membershipId,
                    'name' => $name,
                    'normalized_name' => DashboardName::normalize($name),
                    'position' => $position,
                    // A copy never steals the default flag from the original.
                    'is_default' => false,
                ],
                ['is_default' => ParameterType::BOOLEAN],
            );

            // The widgets are copied; the saved queries they point at are not.
            // Duplicating those would double the member's query list every time
            // they copied a dashboard.
            //
            // Identifiers are minted in PHP rather than by `gen_random_uuid()`:
            // every identifier in SOVA is a UUIDv7, and the stable widget order
            // falls back to the id, so a random v4 would sort arbitrarily.
            $widgets = $connection->fetchAllAssociative(
                <<<'SQL'
                    SELECT saved_query_id, type_key, schema_version, title,
                           configuration, x, y, width, height
                    FROM dashboard_widgets
                    WHERE tenant_id = :tenant_id
                        AND dashboard_id = :source_id
                    ORDER BY y, x, id
                    SQL,
                ['tenant_id' => $tenantId, 'source_id' => $sourceDashboardId],
            );

            foreach ($widgets as $widget) {
                $connection->insert('dashboard_widgets', [
                    'id' => (string) UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'dashboard_id' => $targetDashboardId,
                    'saved_query_id' => $this->string($widget, 'saved_query_id'),
                    'type_key' => $this->string($widget, 'type_key'),
                    'schema_version' => (int) $this->string($widget, 'schema_version'),
                    'title' => $this->string($widget, 'title'),
                    'configuration' => $this->string($widget, 'configuration'),
                    'x' => (int) $this->string($widget, 'x'),
                    'y' => (int) $this->string($widget, 'y'),
                    'width' => (int) $this->string($widget, 'width'),
                    'height' => (int) $this->string($widget, 'height'),
                ]);
            }
        });
    }

    public function activeDashboardId(string $tenantId, string $membershipId): ?string
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT active_dashboard_id
                FROM membership_dashboard_preferences
                WHERE tenant_id = :tenant_id
                    AND membership_id = :membership_id
                SQL,
            ['tenant_id' => $tenantId, 'membership_id' => $membershipId],
        );

        return is_string($value) ? $value : null;
    }

    public function setActiveDashboard(
        string $tenantId,
        string $membershipId,
        string $dashboardId,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO membership_dashboard_preferences (
                    tenant_id, membership_id, active_dashboard_id
                )
                VALUES (:tenant_id, :membership_id, :dashboard_id)
                ON CONFLICT (membership_id) DO UPDATE
                SET active_dashboard_id = EXCLUDED.active_dashboard_id,
                    updated_at = CURRENT_TIMESTAMP
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'dashboard_id' => $dashboardId,
            ],
        );
    }

    /**
     * The widget count comes from the same statement as the row: the management
     * screen shows it for every dashboard, and a second query per row would be
     * an N+1 for a number PostgreSQL can count while it is already there.
     */
    private function selectSql(): string
    {
        return <<<'SQL'
            SELECT dashboard.id,
                   dashboard.tenant_id,
                   dashboard.owner_membership_id,
                   dashboard.name,
                   dashboard.position,
                   dashboard.is_default,
                   dashboard.version,
                   dashboard.created_at,
                   dashboard.updated_at,
                   (
                       SELECT COUNT(*)
                       FROM dashboard_widgets widget
                       WHERE widget.dashboard_id = dashboard.id
                   ) AS widget_count
            FROM dashboards dashboard
            WHERE dashboard.tenant_id = :tenant_id
                AND dashboard.owner_membership_id = :membership_id
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Dashboard
    {
        return new Dashboard(
            $this->string($row, 'id'),
            $this->string($row, 'tenant_id'),
            $this->string($row, 'owner_membership_id'),
            $this->string($row, 'name'),
            (int) $this->string($row, 'position'),
            in_array($row['is_default'] ?? null, [true, 1, '1', 't'], true),
            (int) $this->string($row, 'widget_count'),
            (int) $this->string($row, 'version'),
            $this->moment($this->string($row, 'created_at')),
            $this->moment($this->string($row, 'updated_at')),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function moment(string $value): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception) {
            return new DateTimeImmutable();
        }
    }
}
