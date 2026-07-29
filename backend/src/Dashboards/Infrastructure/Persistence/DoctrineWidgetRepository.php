<?php

declare(strict_types=1);

namespace Sova\Dashboards\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Exception;
use JsonException;
use Sova\Dashboards\Application\DashboardWidget;
use Sova\Dashboards\Application\WidgetRepository;
use Sova\Dashboards\Domain\WidgetRegistry\WidgetType;

/**
 * Widget rows always carry their dashboard and tenant in the `WHERE` clause,
 * and the layout is applied in one transaction against the dashboard's version.
 */
final readonly class DoctrineWidgetRepository implements WidgetRepository
{
    public function __construct(private Connection $connection) {}

    public function listForDashboard(string $tenantId, string $dashboardId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->selectSql() . "\nORDER BY widget.y, widget.x, widget.id",
            ['tenant_id' => $tenantId, 'dashboard_id' => $dashboardId],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(
        string $tenantId,
        string $dashboardId,
        string $widgetId,
    ): ?DashboardWidget {
        $row = $this->connection->fetchAssociative(
            $this->selectSql() . "\n    AND widget.id = :widget_id",
            [
                'tenant_id' => $tenantId,
                'dashboard_id' => $dashboardId,
                'widget_id' => $widgetId,
            ],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function countForDashboard(string $tenantId, string $dashboardId): int
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM dashboard_widgets
                WHERE tenant_id = :tenant_id
                    AND dashboard_id = :dashboard_id
                SQL,
            ['tenant_id' => $tenantId, 'dashboard_id' => $dashboardId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The same reachability rule the saved-query list uses: the member's own
     * live queries, plus shared ones they hold a grant for directly or through
     * a workgroup. Kept as one statement so a widget can never point at a query
     * its owner could not open.
     */
    public function savedQueryIsUsable(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
    ): bool {
        $usable = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM saved_queries saved_query
                    WHERE saved_query.tenant_id = :tenant_id
                        AND saved_query.id = :saved_query_id
                        AND saved_query.archived_at IS NULL
                        AND (
                            saved_query.owner_membership_id = :membership_id
                            OR (
                                saved_query.visibility = 'SHARED'
                                AND EXISTS (
                                    SELECT 1 FROM saved_query_grants reach
                                    LEFT JOIN workgroup_members member
                                        ON member.tenant_id = reach.tenant_id
                                        AND member.workgroup_id = reach.workgroup_id
                                        AND member.membership_id = :membership_id
                                    WHERE reach.tenant_id = saved_query.tenant_id
                                        AND reach.saved_query_id = saved_query.id
                                        AND (
                                            reach.membership_id = :membership_id
                                            OR member.membership_id IS NOT NULL
                                        )
                                )
                            )
                        )
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'saved_query_id' => $savedQueryId,
                'membership_id' => $membershipId,
            ],
        );

        return in_array($usable, [true, 1, '1', 't'], true);
    }

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
    ): void {
        $this->connection->insert('dashboard_widgets', [
            'id' => $widgetId,
            'tenant_id' => $tenantId,
            'dashboard_id' => $dashboardId,
            'saved_query_id' => $savedQueryId,
            'type_key' => $typeKey,
            'schema_version' => $schemaVersion,
            'title' => $title,
            'configuration' => $this->encode($configuration),
            'x' => $x,
            'y' => $y,
            'width' => $width,
            'height' => $height,
        ]);
    }

    public function update(
        string $tenantId,
        string $dashboardId,
        string $widgetId,
        int $expectedVersion,
        string $savedQueryId,
        string $title,
        array $configuration,
    ): ?int {
        $version = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE dashboard_widgets
                SET saved_query_id = :saved_query_id,
                    title = :title,
                    configuration = CAST(:configuration AS jsonb),
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND dashboard_id = :dashboard_id
                    AND id = :widget_id
                    AND version = :expected_version
                RETURNING version
                SQL,
            [
                'tenant_id' => $tenantId,
                'dashboard_id' => $dashboardId,
                'widget_id' => $widgetId,
                'expected_version' => $expectedVersion,
                'saved_query_id' => $savedQueryId,
                'title' => $title,
                'configuration' => $this->encode($configuration),
            ],
        );

        return is_numeric($version) ? (int) $version : null;
    }

    public function delete(string $tenantId, string $dashboardId, string $widgetId): bool
    {
        return $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM dashboard_widgets
                WHERE tenant_id = :tenant_id
                    AND dashboard_id = :dashboard_id
                    AND id = :widget_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'dashboard_id' => $dashboardId,
                'widget_id' => $widgetId,
            ],
        ) > 0;
    }

    public function applyLayout(
        string $tenantId,
        string $dashboardId,
        int $expectedDashboardVersion,
        array $placements,
    ): ?int {
        return $this->connection->transactional(
            function (Connection $connection) use (
                $tenantId,
                $dashboardId,
                $expectedDashboardVersion,
                $placements,
            ): ?int {
                // The dashboard's version is bumped first and under the caller's
                // expectation, so a second tab applying its own arrangement at
                // the same time loses here rather than half-way through.
                $version = $connection->fetchOne(
                    <<<'SQL'
                        UPDATE dashboards
                        SET version = version + 1,
                            updated_at = CURRENT_TIMESTAMP
                        WHERE tenant_id = :tenant_id
                            AND id = :dashboard_id
                            AND version = :expected_version
                        RETURNING version
                        SQL,
                    [
                        'tenant_id' => $tenantId,
                        'dashboard_id' => $dashboardId,
                        'expected_version' => $expectedDashboardVersion,
                    ],
                );

                if (!is_numeric($version)) {
                    return null;
                }

                foreach ($placements as $placement) {
                    $connection->executeStatement(
                        <<<'SQL'
                            UPDATE dashboard_widgets
                            SET x = :x,
                                y = :y,
                                width = :width,
                                height = :height,
                                version = version + 1,
                                updated_at = CURRENT_TIMESTAMP
                            WHERE tenant_id = :tenant_id
                                AND dashboard_id = :dashboard_id
                                AND id = :widget_id
                            SQL,
                        [
                            'tenant_id' => $tenantId,
                            'dashboard_id' => $dashboardId,
                            'widget_id' => $placement->widgetId,
                            'x' => $placement->x,
                            'y' => $placement->y,
                            'width' => $placement->width,
                            'height' => $placement->height,
                        ],
                    );
                }

                return (int) $version;
            },
        );
    }

    public function countUsingSavedQuery(string $tenantId, string $savedQueryId): int
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM dashboard_widgets
                WHERE tenant_id = :tenant_id
                    AND saved_query_id = :saved_query_id
                SQL,
            ['tenant_id' => $tenantId, 'saved_query_id' => $savedQueryId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * The source's name and whether it is still live come from the same
     * statement: a dashboard renders every widget's source, and one query per
     * widget would be an N+1 for two columns.
     */
    private function selectSql(): string
    {
        return <<<'SQL'
            SELECT widget.id,
                   widget.dashboard_id,
                   widget.saved_query_id,
                   widget.type_key,
                   widget.schema_version,
                   widget.title,
                   widget.configuration,
                   widget.x,
                   widget.y,
                   widget.width,
                   widget.height,
                   widget.version,
                   widget.created_at,
                   widget.updated_at,
                   saved_query.name AS source_name,
                   (saved_query.archived_at IS NULL) AS source_reachable
            FROM dashboard_widgets widget
            LEFT JOIN saved_queries saved_query
                ON saved_query.tenant_id = widget.tenant_id
                AND saved_query.id = widget.saved_query_id
            WHERE widget.tenant_id = :tenant_id
                AND widget.dashboard_id = :dashboard_id
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): DashboardWidget
    {
        $typeKey = $this->string($row, 'type_key');

        return new DashboardWidget(
            $this->string($row, 'id'),
            $this->string($row, 'dashboard_id'),
            $this->string($row, 'saved_query_id'),
            $this->nullableString($row, 'source_name'),
            in_array($row['source_reachable'] ?? null, [true, 1, '1', 't'], true),
            $typeKey,
            WidgetType::tryFrom($typeKey) !== null,
            (int) $this->string($row, 'schema_version'),
            $this->string($row, 'title'),
            $this->decode($this->nullableString($row, 'configuration')),
            (int) $this->string($row, 'x'),
            (int) $this->string($row, 'y'),
            (int) $this->string($row, 'width'),
            (int) $this->string($row, 'height'),
            (int) $this->string($row, 'version'),
            $this->moment($this->string($row, 'created_at')),
            $this->moment($this->string($row, 'updated_at')),
        );
    }

    /**
     * @param array<string, mixed> $configuration
     */
    private function encode(array $configuration): string
    {
        try {
            return json_encode($configuration, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
        } catch (JsonException) {
            return '{}';
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(?string $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        try {
            $decoded = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $configuration = [];

        foreach ($decoded as $key => $entry) {
            $configuration[(string) $key] = $entry;
        }

        return $configuration;
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

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
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
