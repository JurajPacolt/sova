<?php

declare(strict_types=1);

namespace Sova\Tenancy\Infrastructure\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Exception;
use RuntimeException;
use Sova\Tenancy\Application\Membership\TenantMembershipDetails;
use Sova\Tenancy\Application\Membership\TenantMembershipRepository;
use Sova\Tenancy\Application\Membership\TenantMembershipRoleDetails;

final readonly class DoctrineTenantMembershipRepository implements TenantMembershipRepository
{
    public function __construct(private Connection $connection) {}

    public function listForTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    membership.id,
                    membership.tenant_id,
                    membership.user_id,
                    membership.status,
                    membership.joined_at,
                    users.email,
                    users.display_name
                FROM tenant_memberships membership
                INNER JOIN users ON users.id = membership.user_id
                WHERE membership.tenant_id = :tenant_id
                ORDER BY
                    LOWER(users.display_name),
                    LOWER(users.email),
                    membership.id
                SQL,
            ['tenant_id' => $tenantId],
        );

        return array_map(
            fn(array $row): TenantMembershipDetails => $this->hydrate($row),
            $rows,
        );
    }

    public function findForTenant(
        string $tenantId,
        string $membershipId,
        bool $forUpdate = false,
    ): ?TenantMembershipDetails {
        $sql = <<<'SQL'
            SELECT
                membership.id,
                membership.tenant_id,
                membership.user_id,
                membership.status,
                membership.joined_at,
                users.email,
                users.display_name
            FROM tenant_memberships membership
            INNER JOIN users ON users.id = membership.user_id
            WHERE membership.tenant_id = :tenant_id
                AND membership.id = :membership_id
            SQL;

        if ($forUpdate) {
            $sql .= ' FOR UPDATE OF membership';
        }

        $row = $this->connection->fetchAssociative($sql, [
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
        ]);

        return $row === false ? null : $this->hydrate($row);
    }

    public function lockActiveTenant(string $tenantId): bool
    {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM tenants
                WHERE id = :tenant_id
                    AND status = 'ACTIVE'
                FOR UPDATE
                SQL,
            ['tenant_id' => $tenantId],
        ) !== false;
    }

    public function changeStatus(
        string $tenantId,
        string $membershipId,
        string $status,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenant_memberships
                SET status = :status,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :membership_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'status' => $status,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TenantMembershipDetails
    {
        $id = $this->string($row, 'id');
        $tenantId = $this->string($row, 'tenant_id');

        return new TenantMembershipDetails(
            id: $id,
            tenantId: $tenantId,
            userId: $this->string($row, 'user_id'),
            email: $this->string($row, 'email'),
            displayName: $this->string($row, 'display_name'),
            status: $this->string($row, 'status'),
            joinedAt: $this->date($row, 'joined_at'),
            roles: $this->roles($tenantId, $id),
        );
    }

    /**
     * @return list<TenantMembershipRoleDetails>
     */
    private function roles(string $tenantId, string $membershipId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT role.id, role.code, role.name, role.status
                FROM tenant_membership_role_assignments assignment
                INNER JOIN tenant_roles role
                    ON role.tenant_id = assignment.tenant_id
                    AND role.id = assignment.role_id
                WHERE assignment.tenant_id = :tenant_id
                    AND assignment.membership_id = :membership_id
                ORDER BY role.is_system DESC, role.name, role.id
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
            ],
        );

        return array_map(
            fn(array $row): TenantMembershipRoleDetails => new TenantMembershipRoleDetails(
                id: $this->string($row, 'id'),
                code: $this->string($row, 'code'),
                name: $this->string($row, 'name'),
                status: $this->string($row, 'status'),
            ),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (!is_string($value)) {
            throw new RuntimeException(sprintf(
                'Tenant membership column "%s" is malformed.',
                $key,
            ));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function date(array $row, string $key): DateTimeImmutable
    {
        $value = $this->string($row, $key);

        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf(
                    'Tenant membership column "%s" is not a date.',
                    $key,
                ),
                previous: $exception,
            );
        }
    }
}
