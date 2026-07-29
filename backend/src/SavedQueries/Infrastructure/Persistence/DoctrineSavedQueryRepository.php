<?php

declare(strict_types=1);

namespace Sova\SavedQueries\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use JsonException;
use Sova\SavedQueries\Application\SavedQuery;
use Sova\SavedQueries\Application\SavedQueryGrant;
use Sova\SavedQueries\Application\SavedQueryRepository;
use Sova\SavedQueries\Domain\SavedQueryAccess;
use Sova\SavedQueries\Domain\SavedQueryName;
use Sova\SavedQueries\Domain\SavedQueryVisibility;
use Sova\Shared\Domain\ValueObject\UuidV7;

/**
 * Visibility and the caller's access level are decided **in SQL**, in the same
 * statement that reads the row. A query the caller may not reach never leaves
 * the database, so there is no filtering step in PHP that a future caller could
 * forget to apply.
 */
final readonly class DoctrineSavedQueryRepository implements SavedQueryRepository
{
    public function __construct(private Connection $connection) {}

    public function listVisible(
        string $tenantId,
        string $membershipId,
        bool $administrator,
    ): array {
        $rows = $this->connection->fetchAllAssociative(
            $this->selectSql() . "\nORDER BY LOWER(saved_query.name), saved_query.id",
            $this->accessParameters($tenantId, $membershipId, $administrator),
            ['administrator' => ParameterType::BOOLEAN],
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function find(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        bool $administrator,
    ): ?SavedQuery {
        $row = $this->connection->fetchAssociative(
            $this->selectSql() . "\n    AND saved_query.id = :saved_query_id",
            $this->accessParameters($tenantId, $membershipId, $administrator)
                + ['saved_query_id' => $savedQueryId],
            ['administrator' => ParameterType::BOOLEAN],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function listGrants(string $tenantId, string $savedQueryId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT grant_row.id,
                       grant_row.membership_id,
                       grant_row.workgroup_id,
                       grant_row.access,
                       COALESCE(granted_user.display_name, workgroup.name) AS display_name
                FROM saved_query_grants grant_row
                LEFT JOIN tenant_memberships membership
                    ON membership.tenant_id = grant_row.tenant_id
                    AND membership.id = grant_row.membership_id
                LEFT JOIN users granted_user
                    ON granted_user.id = membership.user_id
                LEFT JOIN workgroups workgroup
                    ON workgroup.tenant_id = grant_row.tenant_id
                    AND workgroup.id = grant_row.workgroup_id
                WHERE grant_row.tenant_id = :tenant_id
                    AND grant_row.saved_query_id = :saved_query_id
                ORDER BY display_name, grant_row.id
                SQL,
            ['tenant_id' => $tenantId, 'saved_query_id' => $savedQueryId],
        );

        $grants = [];

        foreach ($rows as $row) {
            $grants[] = new SavedQueryGrant(
                $this->string($row, 'id'),
                $this->nullableString($row, 'membership_id'),
                $this->nullableString($row, 'workgroup_id'),
                $this->nullableString($row, 'display_name'),
                SavedQueryAccess::tryFrom($this->string($row, 'access'))
                    ?? SavedQueryAccess::View,
            );
        }

        return $grants;
    }

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
    ): void {
        $this->connection->insert('saved_queries', [
            'id' => $savedQueryId,
            'tenant_id' => $tenantId,
            'owner_membership_id' => $ownerMembershipId,
            'name' => $name,
            'normalized_name' => SavedQueryName::normalize($name),
            'description' => $description,
            'raw_query' => $rawQuery,
            'canonical_query' => $canonicalQuery,
            'default_columns' => $this->encodeColumns($defaultColumns),
            'visibility' => $visibility->value,
        ]);
    }

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
    ): ?int {
        $version = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE saved_queries
                SET name = :name,
                    normalized_name = :normalized_name,
                    description = :description,
                    raw_query = :raw_query,
                    canonical_query = :canonical_query,
                    default_columns = :default_columns::jsonb,
                    visibility = :visibility,
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :saved_query_id
                    AND version = :expected_version
                    AND archived_at IS NULL
                RETURNING version
                SQL,
            [
                'tenant_id' => $tenantId,
                'saved_query_id' => $savedQueryId,
                'expected_version' => $expectedVersion,
                'name' => $name,
                'normalized_name' => SavedQueryName::normalize($name),
                'description' => $description,
                'raw_query' => $rawQuery,
                'canonical_query' => $canonicalQuery,
                'default_columns' => $this->encodeColumns($defaultColumns),
                'visibility' => $visibility->value,
            ],
        );

        return $this->versionValue($version);
    }

    public function archive(string $tenantId, string $savedQueryId, int $expectedVersion): ?int
    {
        $version = $this->connection->fetchOne(
            <<<'SQL'
                UPDATE saved_queries
                SET archived_at = CURRENT_TIMESTAMP,
                    version = version + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :saved_query_id
                    AND version = :expected_version
                    AND archived_at IS NULL
                RETURNING version
                SQL,
            [
                'tenant_id' => $tenantId,
                'saved_query_id' => $savedQueryId,
                'expected_version' => $expectedVersion,
            ],
        );

        return $this->versionValue($version);
    }

    public function replaceGrants(
        string $tenantId,
        string $savedQueryId,
        array $grants,
        string $grantedByUserId,
    ): void {
        $this->connection->transactional(function () use (
            $tenantId,
            $savedQueryId,
            $grants,
            $grantedByUserId,
        ): void {
            $this->connection->delete('saved_query_grants', [
                'tenant_id' => $tenantId,
                'saved_query_id' => $savedQueryId,
            ]);

            foreach ($grants as $grant) {
                $this->connection->insert('saved_query_grants', [
                    'id' => (string) UuidV7::generate(),
                    'tenant_id' => $tenantId,
                    'saved_query_id' => $savedQueryId,
                    'membership_id' => $grant['membership_id'],
                    'workgroup_id' => $grant['workgroup_id'],
                    'access' => $grant['access']->value,
                    'granted_by_user_id' => $grantedByUserId,
                ]);
            }

            // Sharing is exactly "has at least one grant", so the flag is
            // derived here instead of being a second thing to keep in step.
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE saved_queries
                    SET visibility = CASE
                            WHEN EXISTS (
                                SELECT 1 FROM saved_query_grants
                                WHERE tenant_id = :tenant_id
                                    AND saved_query_id = :saved_query_id
                            ) THEN 'SHARED'
                            ELSE 'PRIVATE'
                        END,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE tenant_id = :tenant_id
                        AND id = :saved_query_id
                    SQL,
                ['tenant_id' => $tenantId, 'saved_query_id' => $savedQueryId],
            );
        });
    }

    public function setFavourite(
        string $tenantId,
        string $savedQueryId,
        string $membershipId,
        bool $favourite,
    ): void {
        if (!$favourite) {
            $this->connection->delete('saved_query_favourites', [
                'membership_id' => $membershipId,
                'saved_query_id' => $savedQueryId,
            ]);

            return;
        }

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO saved_query_favourites (
                    tenant_id, membership_id, saved_query_id
                )
                VALUES (:tenant_id, :membership_id, :saved_query_id)
                ON CONFLICT (membership_id, saved_query_id) DO NOTHING
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'saved_query_id' => $savedQueryId,
            ],
        );
    }

    public function nameIsFree(
        string $tenantId,
        string $ownerMembershipId,
        string $normalizedName,
        ?string $exceptSavedQueryId,
    ): bool {
        $taken = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM saved_queries
                    WHERE tenant_id = :tenant_id
                        AND owner_membership_id = :owner_membership_id
                        AND normalized_name = :normalized_name
                        AND archived_at IS NULL
                        AND (:except_id::uuid IS NULL OR id <> :except_id::uuid)
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'owner_membership_id' => $ownerMembershipId,
                'normalized_name' => $normalizedName,
                'except_id' => $exceptSavedQueryId,
            ],
        );

        return !in_array($taken, [true, 1, '1', 't'], true);
    }

    public function activePrincipals(
        string $tenantId,
        array $membershipIds,
        array $workgroupIds,
    ): array {
        return [
            'memberships' => $membershipIds === [] ? [] : $this->identifiers(
                <<<'SQL'
                    SELECT membership.id
                    FROM tenant_memberships membership
                    INNER JOIN users user_account ON user_account.id = membership.user_id
                    WHERE membership.tenant_id = :tenant_id
                        AND membership.id IN (:ids)
                        AND membership.status = 'ACTIVE'
                        AND user_account.status = 'ACTIVE'
                    SQL,
                $tenantId,
                $membershipIds,
            ),
            'workgroups' => $workgroupIds === [] ? [] : $this->identifiers(
                <<<'SQL'
                    SELECT workgroup.id
                    FROM workgroups workgroup
                    WHERE workgroup.tenant_id = :tenant_id
                        AND workgroup.id IN (:ids)
                        AND workgroup.status = 'ACTIVE'
                    SQL,
                $tenantId,
                $workgroupIds,
            ),
        ];
    }

    /**
     * The caller's reach and their access level, computed alongside the row.
     * `EDIT` comes from ownership, from administration, or from the strongest
     * grant that applies — directly or through a workgroup they are in.
     */
    private function selectSql(): string
    {
        return <<<'SQL'
            SELECT saved_query.id,
                   saved_query.tenant_id,
                   saved_query.owner_membership_id,
                   owner_user.display_name AS owner_display_name,
                   saved_query.name,
                   saved_query.description,
                   saved_query.raw_query,
                   saved_query.canonical_query,
                   saved_query.language_version,
                   saved_query.default_columns,
                   saved_query.visibility,
                   saved_query.version,
                   saved_query.archived_at,
                   saved_query.created_at,
                   saved_query.updated_at,
                   (saved_query.owner_membership_id = :membership_id) AS viewer_is_owner,
                   CASE
                       WHEN saved_query.owner_membership_id = :membership_id THEN 'EDIT'
                       WHEN :administrator THEN 'EDIT'
                       WHEN EXISTS (
                           SELECT 1 FROM saved_query_grants reach
                           LEFT JOIN workgroup_members member
                               ON member.tenant_id = reach.tenant_id
                               AND member.workgroup_id = reach.workgroup_id
                               AND member.membership_id = :membership_id
                           WHERE reach.tenant_id = saved_query.tenant_id
                               AND reach.saved_query_id = saved_query.id
                               AND reach.access = 'EDIT'
                               AND (
                                   reach.membership_id = :membership_id
                                   OR member.membership_id IS NOT NULL
                               )
                       ) THEN 'EDIT'
                       ELSE 'VIEW'
                   END AS viewer_access,
                   EXISTS (
                       SELECT 1 FROM saved_query_favourites favourite
                       WHERE favourite.saved_query_id = saved_query.id
                           AND favourite.membership_id = :membership_id
                   ) AS favourite
            FROM saved_queries saved_query
            INNER JOIN tenant_memberships owner_membership
                ON owner_membership.tenant_id = saved_query.tenant_id
                AND owner_membership.id = saved_query.owner_membership_id
            INNER JOIN users owner_user
                ON owner_user.id = owner_membership.user_id
            WHERE saved_query.tenant_id = :tenant_id
                AND (
                    saved_query.owner_membership_id = :membership_id
                    OR (
                        saved_query.visibility = 'SHARED'
                        AND (
                            :administrator
                            OR EXISTS (
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
            SQL;
    }

    /**
     * @return array<string, mixed>
     */
    private function accessParameters(
        string $tenantId,
        string $membershipId,
        bool $administrator,
    ): array {
        return [
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'administrator' => $administrator,
        ];
    }

    /**
     * @param list<string> $identifiers
     *
     * @return list<string>
     */
    private function identifiers(string $sql, string $tenantId, array $identifiers): array
    {
        $found = [];

        foreach ($this->connection->fetchFirstColumn(
            $sql,
            ['tenant_id' => $tenantId, 'ids' => array_values($identifiers)],
            ['ids' => ArrayParameterType::STRING],
        ) as $value) {
            if (is_string($value)) {
                $found[] = $value;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $columns
     */
    private function encodeColumns(array $columns): string
    {
        try {
            return json_encode(array_values($columns), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[]';
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SavedQuery
    {
        return new SavedQuery(
            $this->string($row, 'id'),
            $this->string($row, 'tenant_id'),
            $this->string($row, 'owner_membership_id'),
            $this->nullableString($row, 'owner_display_name'),
            $this->string($row, 'name'),
            $this->string($row, 'description'),
            $this->string($row, 'raw_query'),
            $this->string($row, 'canonical_query'),
            (int) $this->string($row, 'language_version'),
            $this->decodeColumns($this->nullableString($row, 'default_columns')),
            SavedQueryVisibility::tryFrom($this->string($row, 'visibility'))
                ?? SavedQueryVisibility::Private_,
            (int) $this->string($row, 'version'),
            $this->nullableString($row, 'archived_at') !== null,
            SavedQueryAccess::tryFrom($this->string($row, 'viewer_access'))
                ?? SavedQueryAccess::View,
            $this->flag($row['viewer_is_owner'] ?? null),
            $this->flag($row['favourite'] ?? null),
            $this->moment($this->string($row, 'created_at')),
            $this->moment($this->string($row, 'updated_at')),
        );
    }

    /**
     * @return list<string>
     */
    private function decodeColumns(?string $value): array
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

        $columns = [];

        foreach ($decoded as $column) {
            if (is_string($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    private function versionValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    private function flag(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 't'], true);
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
