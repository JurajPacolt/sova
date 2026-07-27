<?php

declare(strict_types=1);

namespace Sova\Authorization\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use RuntimeException;
use Sova\Authorization\Application\AuthorizationScope;
use Sova\Authorization\Application\EffectivePermissionProvider;
use Sova\Authorization\Domain\Permission;
use Sova\Authorization\Domain\PermissionScope;

final class DoctrineEffectivePermissionProvider implements EffectivePermissionProvider
{
    private const MAX_DECISIONS_PER_TENANT = 512;

    /**
     * @var array<string, array{revision: int, decisions: array<string, bool>}>
     */
    private array $tenantCaches = [];

    public function __construct(private readonly Connection $connection) {}

    public function hasPermission(
        string $userId,
        Permission $permission,
        AuthorizationScope $scope,
    ): bool {
        if ($scope->tenantId === null || !$this->hasResourceId($scope)) {
            return false;
        }

        $revision = $this->revision($scope->tenantId);

        if ($revision === null) {
            return false;
        }

        $cache = $this->tenantCaches[$scope->tenantId] ?? null;

        if ($cache === null || $cache['revision'] !== $revision) {
            $cache = [
                'revision' => $revision,
                'decisions' => [],
            ];
        }

        $decisionKey = sprintf(
            '%s:%s:%s:%s',
            $userId,
            $permission->value,
            $scope->type->value,
            $scope->workgroupId ?? $scope->projectId ?? '',
        );

        if (array_key_exists($decisionKey, $cache['decisions'])) {
            $decision = $cache['decisions'][$decisionKey];
            $this->tenantCaches[$scope->tenantId] = $cache;

            return $decision;
        }

        $decision = $this->loadDecision($userId, $permission, $scope);

        if (
            count($cache['decisions'])
            >= self::MAX_DECISIONS_PER_TENANT
        ) {
            $cache['decisions'] = [];
        }

        $cache['decisions'][$decisionKey] = $decision;
        $this->tenantCaches[$scope->tenantId] = $cache;

        return $decision;
    }

    private function hasResourceId(AuthorizationScope $scope): bool
    {
        return match ($scope->type) {
            PermissionScope::Tenant => true,
            PermissionScope::Workgroup => $scope->workgroupId !== null,
            PermissionScope::Project => $scope->projectId !== null,
            PermissionScope::System => false,
        };
    }

    private function loadDecision(
        string $userId,
        Permission $permission,
        AuthorizationScope $scope,
    ): bool {
        return match ($scope->type) {
            PermissionScope::Tenant => $this->loadTenantDecision(
                $userId,
                $permission,
                $scope->tenantId ?? '',
            ),
            PermissionScope::Workgroup => $this->loadWorkgroupDecision(
                $userId,
                $permission,
                $scope->tenantId ?? '',
                $scope->workgroupId ?? '',
            ),
            PermissionScope::Project => $this->loadProjectDecision(
                $userId,
                $permission,
                $scope->tenantId ?? '',
                $scope->projectId ?? '',
            ),
            PermissionScope::System => false,
        };
    }

    private function revision(string $tenantId): ?int
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT revision
                FROM tenant_authorization_revisions
                WHERE tenant_id = :tenant_id
                SQL,
            ['tenant_id' => $tenantId],
        );

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        if ($value === false) {
            return null;
        }

        throw new RuntimeException(
            'Tenant authorization revision must be a positive integer.',
        );
    }

    private function loadTenantDecision(
        string $userId,
        Permission $permission,
        string $tenantId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM tenant_memberships membership
                    INNER JOIN users user_account
                        ON user_account.id = membership.user_id
                    INNER JOIN tenants tenant
                        ON tenant.id = membership.tenant_id
                    INNER JOIN tenant_membership_role_assignments assignment
                        ON assignment.tenant_id = membership.tenant_id
                        AND assignment.membership_id = membership.id
                    INNER JOIN tenant_roles role
                        ON role.tenant_id = assignment.tenant_id
                        AND role.id = assignment.role_id
                    INNER JOIN tenant_role_permissions role_permission
                        ON role_permission.tenant_id = role.tenant_id
                        AND role_permission.role_id = role.id
                    WHERE membership.tenant_id = :tenant_id
                        AND membership.user_id = :user_id
                        AND membership.status = 'ACTIVE'
                        AND user_account.status = 'ACTIVE'
                        AND tenant.status = 'ACTIVE'
                        AND role.status = 'ACTIVE'
                        AND role_permission.permission_code = :permission_code
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'permission_code' => $permission->value,
            ],
        );

        return $this->boolValue($value);
    }

    private function loadWorkgroupDecision(
        string $userId,
        Permission $permission,
        string $tenantId,
        string $workgroupId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM workgroup_members member
                    INNER JOIN tenant_memberships membership
                        ON membership.tenant_id = member.tenant_id
                        AND membership.id = member.membership_id
                    INNER JOIN users user_account
                        ON user_account.id = membership.user_id
                    INNER JOIN tenants tenant
                        ON tenant.id = member.tenant_id
                    INNER JOIN workgroups workgroup
                        ON workgroup.tenant_id = member.tenant_id
                        AND workgroup.id = member.workgroup_id
                    WHERE member.tenant_id = :tenant_id
                        AND member.workgroup_id = :workgroup_id
                        AND membership.user_id = :user_id
                        AND membership.status = 'ACTIVE'
                        AND user_account.status = 'ACTIVE'
                        AND tenant.status = 'ACTIVE'
                        AND workgroup.status = 'ACTIVE'
                        AND (
                            member.member_role = 'MANAGER'
                            OR (
                                member.member_role = 'MEMBER'
                                AND :permission_code = 'workgroup.view'
                            )
                        )
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'workgroup_id' => $workgroupId,
                'user_id' => $userId,
                'permission_code' => $permission->value,
            ],
        );

        return $this->boolValue($value);
    }

    private function loadProjectDecision(
        string $userId,
        Permission $permission,
        string $tenantId,
        string $projectId,
    ): bool {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1
                    FROM project_membership_role_assignments assignment
                    INNER JOIN tenant_memberships membership
                        ON membership.tenant_id = assignment.tenant_id
                        AND membership.id = assignment.membership_id
                    INNER JOIN users user_account
                        ON user_account.id = membership.user_id
                    INNER JOIN tenants tenant
                        ON tenant.id = assignment.tenant_id
                    INNER JOIN projects project
                        ON project.tenant_id = assignment.tenant_id
                        AND project.id = assignment.project_id
                    INNER JOIN project_roles role
                        ON role.tenant_id = assignment.tenant_id
                        AND role.project_id = assignment.project_id
                        AND role.id = assignment.role_id
                    INNER JOIN project_role_permissions role_permission
                        ON role_permission.tenant_id = role.tenant_id
                        AND role_permission.project_id = role.project_id
                        AND role_permission.role_id = role.id
                    WHERE assignment.tenant_id = :tenant_id
                        AND assignment.project_id = :project_id
                        AND membership.user_id = :user_id
                        AND membership.status = 'ACTIVE'
                        AND user_account.status = 'ACTIVE'
                        AND tenant.status = 'ACTIVE'
                        AND project.status = 'ACTIVE'
                        AND role.status = 'ACTIVE'
                        AND role_permission.permission_code = :permission_code
                ) OR EXISTS (
                    SELECT 1
                    FROM project_workgroups linked
                    INNER JOIN workgroup_members member
                        ON member.tenant_id = linked.tenant_id
                        AND member.workgroup_id = linked.workgroup_id
                    INNER JOIN tenant_memberships membership
                        ON membership.tenant_id = member.tenant_id
                        AND membership.id = member.membership_id
                    INNER JOIN users user_account
                        ON user_account.id = membership.user_id
                    INNER JOIN tenants tenant
                        ON tenant.id = linked.tenant_id
                    INNER JOIN workgroups workgroup
                        ON workgroup.tenant_id = linked.tenant_id
                        AND workgroup.id = linked.workgroup_id
                    INNER JOIN projects project
                        ON project.tenant_id = linked.tenant_id
                        AND project.id = linked.project_id
                    INNER JOIN project_roles role
                        ON role.tenant_id = linked.tenant_id
                        AND role.project_id = linked.project_id
                        AND role.id = linked.role_id
                    INNER JOIN project_role_permissions role_permission
                        ON role_permission.tenant_id = role.tenant_id
                        AND role_permission.project_id = role.project_id
                        AND role_permission.role_id = role.id
                    WHERE linked.tenant_id = :tenant_id
                        AND linked.project_id = :project_id
                        AND membership.user_id = :user_id
                        AND membership.status = 'ACTIVE'
                        AND user_account.status = 'ACTIVE'
                        AND tenant.status = 'ACTIVE'
                        AND workgroup.status = 'ACTIVE'
                        AND project.status = 'ACTIVE'
                        AND role.status = 'ACTIVE'
                        AND role_permission.permission_code = :permission_code
                )
                SQL,
            [
                'tenant_id' => $tenantId,
                'project_id' => $projectId,
                'user_id' => $userId,
                'permission_code' => $permission->value,
            ],
        );

        return $this->boolValue($value);
    }

    private function boolValue(mixed $value): bool
    {
        return $value === true
            || $value === 1
            || $value === '1'
            || $value === 't';
    }
}
