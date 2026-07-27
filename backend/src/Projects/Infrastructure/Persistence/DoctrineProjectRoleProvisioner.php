<?php

declare(strict_types=1);

namespace Sova\Projects\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Authorization\Domain\DefaultRole;
use Sova\Authorization\Domain\PermissionScope;
use Sova\Projects\Application\ProjectRoleProvisioner;
use Sova\Shared\Domain\ValueObject\UuidV7;

final readonly class DoctrineProjectRoleProvisioner implements ProjectRoleProvisioner
{
    public function __construct(private Connection $connection) {}

    public function provisionDefaults(
        string $tenantId,
        string $projectId,
        ?string $createdByUserId = null,
    ): void {
        foreach (DefaultRole::cases() as $role) {
            if (!in_array(
                PermissionScope::Project,
                $role->assignableScopes(),
                true,
            )) {
                continue;
            }

            $roleId = $this->ensureRole($tenantId, $projectId, $role);

            foreach ($role->permissions(PermissionScope::Project) as $permission) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO project_role_permissions (
                            tenant_id,
                            project_id,
                            role_id,
                            permission_code
                        )
                        VALUES (
                            :tenant_id,
                            :project_id,
                            :role_id,
                            :permission_code
                        )
                        ON CONFLICT (
                            project_id,
                            role_id,
                            permission_code
                        ) DO NOTHING
                        SQL,
                    [
                        'tenant_id' => $tenantId,
                        'project_id' => $projectId,
                        'role_id' => $roleId,
                        'permission_code' => $permission->value,
                    ],
                );
            }
        }
    }

    private function ensureRole(
        string $tenantId,
        string $projectId,
        DefaultRole $role,
    ): string {
        $candidateId = (string) UuidV7::generate();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO project_roles (
                    id,
                    tenant_id,
                    project_id,
                    code,
                    name,
                    description,
                    is_system,
                    is_editable
                )
                VALUES (
                    :id,
                    :tenant_id,
                    :project_id,
                    :code,
                    :name,
                    :description,
                    TRUE,
                    FALSE
                )
                ON CONFLICT (project_id, code) DO NOTHING
                SQL,
            [
                'id' => $candidateId,
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'code' => $role->value,
                'name' => $this->name($role),
                'description' => sprintf(
                    'Immutable SOVA default project role %s.',
                    $role->value,
                ),
            ],
        );
        $roleId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id
                FROM project_roles
                WHERE project_id = :project_id
                    AND code = :code
                SQL,
            ['project_id' => $projectId, 'code' => $role->value],
        );

        if (!is_string($roleId)) {
            throw new RuntimeException(sprintf(
                'Default project role %s could not be provisioned.',
                $role->value,
            ));
        }

        return $roleId;
    }

    private function name(DefaultRole $role): string
    {
        return match ($role) {
            DefaultRole::ProjectManager => 'Project manager',
            DefaultRole::Member => 'Member',
            DefaultRole::Reporter => 'Reporter',
            DefaultRole::Viewer => 'Viewer',
            default => throw new RuntimeException(sprintf(
                'Role %s is not a project default.',
                $role->value,
            )),
        };
    }
}
