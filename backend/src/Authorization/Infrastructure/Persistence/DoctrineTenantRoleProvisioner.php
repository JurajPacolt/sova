<?php

declare(strict_types=1);

namespace Sova\Authorization\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Authorization\Application\TenantRoleProvisioner;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Authorization\Domain\PermissionScope;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineTenantRoleProvisioner implements TenantRoleProvisioner
{
    public function __construct(private Connection $connection) {}

    public function provisionDefaults(
        string $tenantId,
        ?string $createdByUserId = null,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_authorization_revisions (tenant_id)
                VALUES (:tenant_id)
                ON CONFLICT (tenant_id) DO NOTHING
                SQL,
            ['tenant_id' => $tenantId],
        );

        foreach (DefaultRole::cases() as $role) {
            if (!in_array(
                PermissionScope::Tenant,
                $role->assignableScopes(),
                true,
            )) {
                continue;
            }

            $roleId = $this->ensureRole(
                $tenantId,
                $role,
                $createdByUserId,
            );

            foreach ($role->permissions(PermissionScope::Tenant) as $permission) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO tenant_role_permissions (
                            tenant_id,
                            role_id,
                            permission_code
                        )
                        VALUES (
                            :tenant_id,
                            :role_id,
                            :permission_code
                        )
                        ON CONFLICT (
                            tenant_id,
                            role_id,
                            permission_code
                        ) DO NOTHING
                        SQL,
                    [
                        'tenant_id' => $tenantId,
                        'role_id' => $roleId,
                        'permission_code' => $permission->value,
                    ],
                );
            }
        }
    }

    private function ensureRole(
        string $tenantId,
        DefaultRole $role,
        ?string $createdByUserId,
    ): string {
        $candidateId = (string) UuidV7::generate();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO tenant_roles (
                    id,
                    tenant_id,
                    code,
                    name,
                    description,
                    is_system,
                    is_editable,
                    created_by_user_id
                )
                VALUES (
                    :id,
                    :tenant_id,
                    :code,
                    :name,
                    :description,
                    TRUE,
                    FALSE,
                    :created_by_user_id
                )
                ON CONFLICT (tenant_id, code) DO NOTHING
                SQL,
            [
                'id' => $candidateId,
                'tenant_id' => $tenantId,
                'code' => $role->value,
                'name' => $this->name($role),
                'description' => sprintf(
                    'Immutable SOVA default role %s.',
                    $role->value,
                ),
                'created_by_user_id' => $createdByUserId,
            ],
        );
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT id, is_system, is_editable
                FROM tenant_roles
                WHERE tenant_id = :tenant_id
                    AND code = :code
                SQL,
            [
                'tenant_id' => $tenantId,
                'code' => $role->value,
            ],
        );

        if (
            $row === false
            || !is_string($row['id'] ?? null)
            || $this->boolean($row['is_system'] ?? null) !== true
            || $this->boolean($row['is_editable'] ?? null) !== false
        ) {
            throw new RuntimeException(sprintf(
                'Reserved role %s must be an immutable system role.',
                $role->value,
            ));
        }

        return $row['id'];
    }

    private function boolean(mixed $value): ?bool
    {
        return match ($value) {
            true, 1, '1', 't', 'true' => true,
            false, 0, '0', 'f', 'false' => false,
            default => null,
        };
    }

    private function name(DefaultRole $role): string
    {
        return match ($role) {
            DefaultRole::TenantOwner => 'Tenant owner',
            DefaultRole::TenantAdmin => 'Tenant administrator',
            DefaultRole::Member => 'Member',
            DefaultRole::Viewer => 'Viewer',
            default => throw new RuntimeException(sprintf(
                'Role %s is not a tenant default.',
                $role->value,
            )),
        };
    }
}
