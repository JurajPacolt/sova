<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Tenancy\Application\System\SystemTenantCreationRecord;
use Sova\Tenancy\Application\System\SystemTenantDetails;
use Sova\Tenancy\Application\System\SystemTenantRepository;
use Sova\Tenancy\Domain\Tenant\TenantStatus;
use ValueError;

final readonly class DoctrineSystemTenantRepository implements SystemTenantRepository
{
    public function __construct(private Connection $connection) {}

    public function listAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            $this->detailsSql()
            . "\nWHERE tenant.status <> 'DELETED'"
            . "\nORDER BY LOWER(tenant.name), tenant.id",
        );

        return array_map($this->hydrate(...), $rows);
    }

    public function findById(
        string $tenantId,
        bool $forUpdate = false,
    ): ?SystemTenantDetails {
        $row = $this->connection->fetchAssociative(
            $this->detailsSql()
            . "\nWHERE tenant.id = :tenant_id"
            . ($forUpdate ? "\nFOR UPDATE OF tenant" : ''),
            ['tenant_id' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findCreationRecord(
        string $actorUserId,
        string $idempotencyKey,
    ): ?SystemTenantCreationRecord {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT request_fingerprint, tenant_id
                FROM system_tenant_creation_requests
                WHERE actor_user_id = :actor_user_id
                    AND idempotency_key = :idempotency_key
                SQL,
            [
                'actor_user_id' => $actorUserId,
                'idempotency_key' => $idempotencyKey,
            ],
        );

        return $row === false
            ? null
            : new SystemTenantCreationRecord(
                $this->stringValue($row, 'request_fingerprint'),
                $this->stringValue($row, 'tenant_id'),
            );
    }

    public function create(
        string $tenantId,
        string $name,
        string $slug,
    ): void {
        $this->connection->insert('tenants', [
            'id' => $tenantId,
            'name' => $name,
            'slug' => $slug,
            'status' => TenantStatus::Pending->value,
        ]);
    }

    public function rememberCreation(
        string $actorUserId,
        string $idempotencyKey,
        string $requestFingerprint,
        string $tenantId,
    ): void {
        $this->connection->insert('system_tenant_creation_requests', [
            'actor_user_id' => $actorUserId,
            'idempotency_key' => $idempotencyKey,
            'request_fingerprint' => $requestFingerprint,
            'tenant_id' => $tenantId,
        ]);
    }

    public function activeOwnerCount(string $tenantId): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM tenant_memberships membership
                INNER JOIN tenant_membership_role_assignments assignment
                    ON assignment.tenant_id = membership.tenant_id
                    AND assignment.membership_id = membership.id
                INNER JOIN tenant_roles role
                    ON role.tenant_id = assignment.tenant_id
                    AND role.id = assignment.role_id
                WHERE membership.tenant_id = :tenant_id
                    AND membership.status = 'ACTIVE'
                    AND role.status = 'ACTIVE'
                    AND role.code = 'TENANT_OWNER'
                SQL,
            ['tenant_id' => $tenantId],
        );

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(
            'The database returned an invalid active owner count.',
        );
    }

    public function changeStatus(
        string $tenantId,
        int $expectedRevision,
        TenantStatus $targetStatus,
        ?DateTimeImmutable $deletionRequestedAt,
        ?DateTimeImmutable $deletionEffectiveAt,
    ): bool {
        return $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenants
                SET status = :status,
                    revision = revision + 1,
                    deletion_requested_at = :deletion_requested_at,
                    deletion_effective_at = :deletion_effective_at,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :tenant_id
                    AND revision = :expected_revision
                SQL,
            [
                'status' => $targetStatus->value,
                'deletion_requested_at' => $deletionRequestedAt?->format(
                    'Y-m-d H:i:s.uP',
                ),
                'deletion_effective_at' => $deletionEffectiveAt?->format(
                    'Y-m-d H:i:s.uP',
                ),
                'tenant_id' => $tenantId,
                'expected_revision' => $expectedRevision,
            ],
        ) === 1;
    }

    private function detailsSql(): string
    {
        return <<<'SQL'
            SELECT
                tenant.id,
                tenant.name,
                tenant.slug,
                tenant.status,
                tenant.revision,
                tenant.created_at,
                tenant.updated_at,
                tenant.deletion_effective_at,
                (
                    SELECT owner_user.email
                    FROM tenant_memberships owner_membership
                    INNER JOIN users owner_user
                        ON owner_user.id = owner_membership.user_id
                    INNER JOIN tenant_membership_role_assignments owner_assignment
                        ON owner_assignment.tenant_id = owner_membership.tenant_id
                        AND owner_assignment.membership_id = owner_membership.id
                    INNER JOIN tenant_roles owner_role
                        ON owner_role.tenant_id = owner_assignment.tenant_id
                        AND owner_role.id = owner_assignment.role_id
                    WHERE owner_membership.tenant_id = tenant.id
                        AND owner_membership.status = 'ACTIVE'
                        AND owner_role.code = 'TENANT_OWNER'
                    ORDER BY owner_membership.created_at, owner_membership.id
                    LIMIT 1
                ) AS owner_email,
                (
                    SELECT COUNT(*)
                    FROM tenant_memberships membership
                    WHERE membership.tenant_id = tenant.id
                        AND membership.status = 'ACTIVE'
                ) AS active_member_count
            FROM tenants tenant
            SQL;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SystemTenantDetails
    {
        $statusValue = $this->stringValue($row, 'status');

        try {
            $status = TenantStatus::from($statusValue);
        } catch (ValueError $exception) {
            throw new RuntimeException(
                sprintf('Unknown tenant status "%s".', $statusValue),
                previous: $exception,
            );
        }

        return new SystemTenantDetails(
            id: $this->stringValue($row, 'id'),
            name: $this->stringValue($row, 'name'),
            slug: $this->stringValue($row, 'slug'),
            status: $status,
            revision: $this->integerValue($row, 'revision'),
            ownerEmail: $this->nullableStringValue($row, 'owner_email'),
            activeMemberCount: $this->integerValue(
                $row,
                'active_member_count',
            ),
            createdAt: new DateTimeImmutable(
                $this->stringValue($row, 'created_at'),
            ),
            updatedAt: new DateTimeImmutable(
                $this->stringValue($row, 'updated_at'),
            ),
            deletionEffectiveAt: $this->nullableDateTimeValue(
                $row,
                'deletion_effective_at',
            ),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringValue(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableStringValue(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        if ($value !== null && !is_string($value)) {
            throw new RuntimeException(sprintf(
                'Expected database column "%s" to contain a nullable string.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function integerValue(array $row, string $key): int
    {
        $value = $row[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf(
            'Expected database column "%s" to contain an integer.',
            $key,
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableDateTimeValue(
        array $row,
        string $key,
    ): ?DateTimeImmutable {
        $value = $this->nullableStringValue($row, $key);

        return $value === null ? null : new DateTimeImmutable($value);
    }
}
