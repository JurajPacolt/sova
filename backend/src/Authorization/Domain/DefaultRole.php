<?php

declare(strict_types=1);

namespace Sova\Authorization\Domain;

use InvalidArgumentException;

enum DefaultRole: string
{
    case Superadmin = 'SUPERADMIN';
    case TenantOwner = 'TENANT_OWNER';
    case TenantAdmin = 'TENANT_ADMIN';
    case ProjectManager = 'PROJECT_MANAGER';
    case GroupManager = 'GROUP_MANAGER';
    case Member = 'MEMBER';
    case Reporter = 'REPORTER';
    case Viewer = 'VIEWER';

    /**
     * @return list<PermissionScope>
     */
    public function assignableScopes(): array
    {
        return match ($this) {
            self::Superadmin => [PermissionScope::System],
            self::TenantOwner,
            self::TenantAdmin => [PermissionScope::Tenant],
            self::ProjectManager,
            self::Reporter => [PermissionScope::Project],
            self::GroupManager => [PermissionScope::Workgroup],
            self::Member,
            self::Viewer => [
                PermissionScope::Tenant,
                PermissionScope::Project,
            ],
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(PermissionScope $assignedScope): array
    {
        if (!in_array($assignedScope, $this->assignableScopes(), true)) {
            throw new InvalidArgumentException(sprintf(
                'Role %s cannot be assigned in %s scope.',
                $this->value,
                $assignedScope->value,
            ));
        }

        return match ($this) {
            self::Superadmin => Permission::cases(),
            self::TenantOwner => self::allNonSystemPermissions(),
            self::TenantAdmin => self::tenantAdminPermissions(),
            self::ProjectManager => self::permissionsInScope(
                PermissionScope::Project,
            ),
            self::GroupManager => self::permissionsInScope(
                PermissionScope::Workgroup,
            ),
            self::Member => self::memberPermissions($assignedScope),
            self::Reporter => self::reporterPermissions(),
            self::Viewer => self::viewerPermissions($assignedScope),
        };
    }

    /**
     * @return list<Permission>
     */
    private static function allNonSystemPermissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            static fn(Permission $permission): bool => $permission->scope()
                !== PermissionScope::System,
        ));
    }

    /**
     * @return list<Permission>
     */
    private static function tenantAdminPermissions(): array
    {
        return array_values(array_filter(
            self::allNonSystemPermissions(),
            static fn(Permission $permission): bool => !in_array(
                $permission,
                [
                    Permission::TenantRolesManage,
                    Permission::TenantAuditExport,
                    Permission::IssueDelete,
                ],
                true,
            ),
        ));
    }

    /**
     * @return list<Permission>
     */
    private static function memberPermissions(
        PermissionScope $assignedScope,
    ): array {
        $projectPermissions = [
            Permission::ProjectView,
            Permission::IssueView,
            Permission::IssueCreate,
            Permission::IssueEdit,
            Permission::IssueAssign,
            Permission::IssueTransition,
            Permission::IssueChangeType,
            Permission::CommentCreate,
            Permission::AttachmentUpload,
        ];

        if ($assignedScope === PermissionScope::Project) {
            return $projectPermissions;
        }

        return [
            Permission::TenantView,
            Permission::TenantMembersView,
            // Saved queries are tenant entities: one query may span several
            // projects, so the permission cannot hang off a single project.
            Permission::SavedQueryCreate,
            Permission::SavedQueryShare,
            // A dashboard is personal: it belongs to one membership and nobody
            // else can reach it, so even a read-only member has one of their
            // own to arrange.
            Permission::DashboardCreate,
            Permission::DashboardUpdateOwn,
            Permission::DashboardDeleteOwn,
            ...$projectPermissions,
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function reporterPermissions(): array
    {
        return [
            Permission::ProjectView,
            Permission::IssueView,
            Permission::IssueCreate,
            Permission::CommentCreate,
            Permission::AttachmentUpload,
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function viewerPermissions(
        PermissionScope $assignedScope,
    ): array {
        $projectPermissions = [
            Permission::ProjectView,
            Permission::IssueView,
        ];

        if ($assignedScope === PermissionScope::Project) {
            return $projectPermissions;
        }

        return [
            Permission::TenantView,
            Permission::TenantMembersView,
            // Saved queries are tenant entities: one query may span several
            // projects, so the permission cannot hang off a single project.
            Permission::SavedQueryCreate,
            Permission::SavedQueryShare,
            // A dashboard is personal: it belongs to one membership and nobody
            // else can reach it, so even a read-only member has one of their
            // own to arrange.
            Permission::DashboardCreate,
            Permission::DashboardUpdateOwn,
            Permission::DashboardDeleteOwn,
            ...$projectPermissions,
        ];
    }

    /**
     * @return list<Permission>
     */
    private static function permissionsInScope(
        PermissionScope $scope,
    ): array {
        return array_values(array_filter(
            Permission::cases(),
            static fn(Permission $permission): bool => $permission->scope()
                === $scope,
        ));
    }
}
