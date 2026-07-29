<?php

declare(strict_types=1);

namespace Sova\Authorization\Domain;

final class PermissionCatalog
{
    /**
     * @return list<PermissionDefinition>
     */
    public static function all(): array
    {
        return array_map(
            static fn(Permission $permission): PermissionDefinition => new PermissionDefinition(
                permission: $permission,
                label: self::label($permission),
                description: self::description($permission),
                dependencies: $permission->dependencies(),
            ),
            Permission::cases(),
        );
    }

    private static function label(Permission $permission): string
    {
        return match ($permission) {
            Permission::SystemTenantsView => 'View tenants',
            Permission::SystemTenantsCreate => 'Create tenants',
            Permission::SystemTenantsManage => 'Manage tenant lifecycle',
            Permission::SystemUsersManage => 'Manage system users',
            Permission::SystemSuperadminsManage => 'Manage superadministrators',
            Permission::SystemAuditView => 'View system audit',
            Permission::SystemImpersonate => 'Impersonate users',
            Permission::TenantView => 'Enter tenant',
            Permission::TenantSettingsManage => 'Manage tenant settings',
            Permission::TenantMembersView => 'View tenant members',
            Permission::TenantMembersInvite => 'Invite tenant members',
            Permission::TenantMembersManage => 'Manage tenant members',
            Permission::TenantRolesView => 'View tenant roles',
            Permission::TenantRolesManage => 'Manage tenant roles',
            Permission::TenantRolesAssign => 'Assign tenant roles',
            Permission::TenantWorkgroupsManage => 'Manage workgroups',
            Permission::TenantProjectsCreate => 'Create projects',
            Permission::TenantProjectsManage => 'Manage all projects',
            Permission::TenantAuditView => 'View tenant audit',
            Permission::TenantAuditExport => 'Export tenant audit',
            Permission::ProjectView => 'View project',
            Permission::ProjectSettingsManage => 'Manage project settings',
            Permission::ProjectMembersManage => 'Manage project access',
            Permission::ProjectWorkflowManage => 'Edit project workflows',
            Permission::ProjectWorkflowPublish => 'Publish project workflows',
            Permission::IssueView => 'View issues',
            Permission::IssueCreate => 'Create issues',
            Permission::IssueEdit => 'Edit issues',
            Permission::IssueAssign => 'Assign issues',
            Permission::IssueTransition => 'Transition issues',
            Permission::IssueChangeType => 'Change issue type',
            Permission::IssueDelete => 'Delete issues',
            Permission::CommentCreate => 'Create comments',
            Permission::CommentModerate => 'Moderate comments',
            Permission::AttachmentUpload => 'Upload attachments',
            Permission::AttachmentModerate => 'Moderate attachments',
            Permission::SavedQueryCreate => 'Create saved queries',
            Permission::SavedQueryManage => 'Administer shared saved queries',
            Permission::SavedQueryShare => 'Share saved queries',
            Permission::DashboardCreate => 'Create personal dashboards',
            Permission::DashboardUpdateOwn => 'Arrange own dashboards',
            Permission::DashboardDeleteOwn => 'Delete own dashboards',
            Permission::WorkgroupView => 'View workgroup',
            Permission::WorkgroupManage => 'Manage workgroup',
            Permission::WorkgroupMembersManage => 'Manage workgroup members',
        };
    }

    private static function description(Permission $permission): string
    {
        return match ($permission->scope()) {
            PermissionScope::System => 'Controls an operation across the SOVA installation.',
            PermissionScope::Tenant => 'Controls an operation in one explicit tenant.',
            PermissionScope::Project => 'Controls an operation in one explicit project.',
            PermissionScope::Workgroup => 'Controls an operation in one explicit workgroup.',
        };
    }
}
