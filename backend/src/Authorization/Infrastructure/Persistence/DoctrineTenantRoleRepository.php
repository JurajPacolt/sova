<?php

declare(strict_types=1);

namespace Sova\Authorization\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;
use Sova\Authorization\Application\TenantRoleDetails;
use Sova\Authorization\Application\TenantRoleRepository;

final readonly class DoctrineTenantRoleRepository implements TenantRoleRepository
{
    public function __construct(private Connection $connection) {}

    public function listForTenant(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    role.id,
                    role.tenant_id,
                    role.code,
                    role.name,
                    role.description,
                    role.status,
                    role.is_system,
                    role.is_editable,
                    role.revision,
                    COUNT(assignment.membership_id) AS assignment_count
                FROM tenant_roles role
                LEFT JOIN tenant_membership_role_assignments assignment
                    ON assignment.tenant_id = role.tenant_id
                    AND assignment.role_id = role.id
                WHERE role.tenant_id = :tenant_id
                GROUP BY role.id
                ORDER BY role.is_system DESC, role.name, role.id
                SQL,
            ['tenant_id' => $tenantId],
        );

        return array_map(
            fn(array $row): TenantRoleDetails => $this->hydrate($row),
            $rows,
        );
    }

    public function findForTenant(
        string $tenantId,
        string $roleId,
        bool $forUpdate = false,
    ): ?TenantRoleDetails {
        $sql = <<<'SQL'
            SELECT
                role.id,
                role.tenant_id,
                role.code,
                role.name,
                role.description,
                role.status,
                role.is_system,
                role.is_editable,
                role.revision,
                (
                    SELECT COUNT(*)
                    FROM tenant_membership_role_assignments assignment
                    WHERE assignment.tenant_id = role.tenant_id
                        AND assignment.role_id = role.id
                ) AS assignment_count
            FROM tenant_roles role
            WHERE role.tenant_id = :tenant_id
                AND role.id = :role_id
            SQL;

        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->connection->fetchAssociative($sql, [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
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

    public function codeExists(string $tenantId, string $code): bool
    {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :code
                SQL,
            [
                'tenant_id' => $tenantId,
                'code' => $code,
            ],
        ) !== false;
    }

    public function create(
        string $tenantId,
        string $roleId,
        string $code,
        string $name,
        string $description,
        string $actorUserId,
    ): void {
        $this->connection->insert('tenant_roles', [
            'id' => $roleId,
            'tenant_id' => $tenantId,
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'status' => 'ACTIVE',
            'is_system' => false,
            'is_editable' => true,
            'created_by_user_id' => $actorUserId,
        ], [
            'is_system' => ParameterType::BOOLEAN,
            'is_editable' => ParameterType::BOOLEAN,
        ]);
    }

    public function replacePermissions(
        string $tenantId,
        string $roleId,
        array $permissionCodes,
    ): void {
        $this->connection->delete('tenant_role_permissions', [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
        ]);

        foreach ($permissionCodes as $permissionCode) {
            $this->connection->insert('tenant_role_permissions', [
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'permission_code' => $permissionCode,
            ]);
        }
    }

    public function updateDefinition(
        string $tenantId,
        string $roleId,
        string $name,
        string $description,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenant_roles
                SET name = :name,
                    description = :description,
                    revision = revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :role_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
                'name' => $name,
                'description' => $description,
            ],
        );
    }

    public function archive(string $tenantId, string $roleId): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE tenant_roles
                SET status = 'ARCHIVED',
                    revision = revision + 1,
                    updated_at = CURRENT_TIMESTAMP
                WHERE tenant_id = :tenant_id
                    AND id = :role_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
            ],
        );
    }

    public function membershipStatusForUpdate(
        string $tenantId,
        string $membershipId,
    ): ?string {
        $status = $this->connection->fetchOne(
            <<<'SQL'
                SELECT status
                FROM tenant_memberships
                WHERE tenant_id = :tenant_id
                    AND id = :membership_id
                FOR UPDATE
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
            ],
        );

        return is_string($status) ? $status : null;
    }

    public function assignmentExists(
        string $tenantId,
        string $membershipId,
        string $roleId,
    ): bool {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM tenant_membership_role_assignments
                WHERE tenant_id = :tenant_id
                    AND membership_id = :membership_id
                    AND role_id = :role_id
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'role_id' => $roleId,
            ],
        ) !== false;
    }

    public function assign(
        string $tenantId,
        string $membershipId,
        string $roleId,
        string $actorUserId,
    ): void {
        $this->connection->insert('tenant_membership_role_assignments', [
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'role_id' => $roleId,
            'granted_by_user_id' => $actorUserId,
        ]);
    }

    public function unassign(
        string $tenantId,
        string $membershipId,
        string $roleId,
    ): void {
        $this->connection->delete('tenant_membership_role_assignments', [
            'tenant_id' => $tenantId,
            'membership_id' => $membershipId,
            'role_id' => $roleId,
        ]);
    }

    public function membershipHasRoleCode(
        string $tenantId,
        string $membershipId,
        string $roleCode,
    ): bool {
        return $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM tenant_membership_role_assignments assignment
                INNER JOIN tenant_roles role
                    ON role.tenant_id = assignment.tenant_id
                    AND role.id = assignment.role_id
                WHERE assignment.tenant_id = :tenant_id
                    AND assignment.membership_id = :membership_id
                    AND role.code = :role_code
                SQL,
            [
                'tenant_id' => $tenantId,
                'membership_id' => $membershipId,
                'role_code' => $roleCode,
            ],
        ) !== false;
    }

    public function activeOwnerCount(string $tenantId): int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM tenant_membership_role_assignments assignment
                INNER JOIN tenant_roles role
                    ON role.tenant_id = assignment.tenant_id
                    AND role.id = assignment.role_id
                INNER JOIN tenant_memberships membership
                    ON membership.tenant_id = assignment.tenant_id
                    AND membership.id = assignment.membership_id
                WHERE assignment.tenant_id = :tenant_id
                    AND role.code = 'TENANT_OWNER'
                    AND role.status = 'ACTIVE'
                    AND membership.status = 'ACTIVE'
                SQL,
            ['tenant_id' => $tenantId],
        );

        return $this->integer($value, 'active owner count');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TenantRoleDetails
    {
        $id = $row['id'] ?? null;
        $tenantId = $row['tenant_id'] ?? null;
        $code = $row['code'] ?? null;
        $name = $row['name'] ?? null;
        $description = $row['description'] ?? null;
        $status = $row['status'] ?? null;

        if (
            !is_string($id)
            || !is_string($tenantId)
            || !is_string($code)
            || !is_string($name)
            || !is_string($description)
            || !is_string($status)
        ) {
            throw new RuntimeException('Tenant role row is malformed.');
        }

        return new TenantRoleDetails(
            id: $id,
            tenantId: $tenantId,
            code: $code,
            name: $name,
            description: $description,
            status: $status,
            isSystem: $this->boolean($row['is_system'] ?? null),
            isEditable: $this->boolean($row['is_editable'] ?? null),
            revision: $this->integer($row['revision'] ?? null, 'role revision'),
            permissionCodes: $this->permissions($tenantId, $id),
            assignmentCount: $this->integer(
                $row['assignment_count'] ?? null,
                'role assignment count',
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function permissions(string $tenantId, string $roleId): array
    {
        $values = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT permission_code
                FROM tenant_role_permissions
                WHERE tenant_id = :tenant_id
                    AND role_id = :role_id
                ORDER BY permission_code
                SQL,
            [
                'tenant_id' => $tenantId,
                'role_id' => $roleId,
            ],
        );
        $permissions = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new RuntimeException(
                    'Tenant role permission row is malformed.',
                );
            }

            $permissions[] = $value;
        }

        return $permissions;
    }

    private function boolean(mixed $value): bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => throw new RuntimeException(
                'Tenant role boolean value is malformed.',
            ),
        };
    }

    private function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf(
            'Tenant role %s is malformed.',
            $field,
        ));
    }
}
