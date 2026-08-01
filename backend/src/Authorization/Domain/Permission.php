<?php

declare(strict_types=1);

namespace Sova\Authorization\Domain;

enum Permission: string
{
    case SystemTenantsView = 'system.tenants.view';
    case SystemTenantsCreate = 'system.tenants.create';
    case SystemTenantsManage = 'system.tenants.manage';
    case SystemUsersManage = 'system.users.manage';
    case SystemSuperadminsManage = 'system.superadmins.manage';
    case SystemAuditView = 'system.audit.view';
    case SystemImpersonate = 'system.impersonate';

    case TenantView = 'tenant.view';
    case TenantSettingsManage = 'tenant.settings.manage';
    case TenantMembersView = 'tenant.members.view';
    case TenantMembersInvite = 'tenant.members.invite';
    case TenantMembersManage = 'tenant.members.manage';
    case TenantRolesView = 'tenant.roles.view';
    case TenantRolesManage = 'tenant.roles.manage';
    case TenantRolesAssign = 'tenant.roles.assign';
    case TenantWorkgroupsManage = 'tenant.workgroups.manage';
    case TenantProjectsCreate = 'tenant.projects.create';
    case TenantProjectsManage = 'tenant.projects.manage';
    case TenantAuditView = 'tenant.audit.view';
    case TenantAuditExport = 'tenant.audit.export';

    case ProjectView = 'project.view';
    case ProjectSettingsManage = 'project.settings.manage';
    case ProjectMembersManage = 'project.members.manage';
    case ProjectWorkflowManage = 'project.workflow.manage';
    case ProjectWorkflowPublish = 'project.workflow.publish';
    case IssueView = 'issue.view';
    case IssueCreate = 'issue.create';
    case IssueEdit = 'issue.edit';
    case IssueAssign = 'issue.assign';
    case IssueTransition = 'issue.transition';
    case IssueChangeType = 'issue.change-type';
    case IssueDelete = 'issue.delete';
    case CommentCreate = 'comment.create';
    case CommentModerate = 'comment.moderate';
    case AttachmentUpload = 'attachment.upload';
    case AttachmentModerate = 'attachment.moderate';
    case SavedQueryCreate = 'saved-query.create';
    case SavedQueryShare = 'saved-query.share';
    case SavedQueryManage = 'saved-query.manage';
    case DashboardCreate = 'dashboard.create';
    case DashboardUpdateOwn = 'dashboard.update-own';
    case DashboardDeleteOwn = 'dashboard.delete-own';

    case WorkgroupView = 'workgroup.view';
    case WorkgroupManage = 'workgroup.manage';
    case WorkgroupMembersManage = 'workgroup.members.manage';

    public function scope(): PermissionScope
    {
        return match ($this) {
            self::SystemTenantsView,
            self::SystemTenantsCreate,
            self::SystemTenantsManage,
            self::SystemUsersManage,
            self::SystemSuperadminsManage,
            self::SystemAuditView,
            self::SystemImpersonate => PermissionScope::System,
            self::TenantView,
            self::TenantSettingsManage,
            self::TenantMembersView,
            self::TenantMembersInvite,
            self::TenantMembersManage,
            self::TenantRolesView,
            self::TenantRolesManage,
            self::TenantRolesAssign,
            self::TenantWorkgroupsManage,
            self::TenantProjectsCreate,
            self::TenantProjectsManage,
            self::SavedQueryCreate,
            self::SavedQueryShare,
            self::SavedQueryManage,
            self::DashboardCreate,
            self::DashboardUpdateOwn,
            self::DashboardDeleteOwn,
            self::TenantAuditView,
            self::TenantAuditExport => PermissionScope::Tenant,
            self::ProjectView,
            self::ProjectSettingsManage,
            self::ProjectMembersManage,
            self::ProjectWorkflowManage,
            self::ProjectWorkflowPublish,
            self::IssueView,
            self::IssueCreate,
            self::IssueEdit,
            self::IssueAssign,
            self::IssueTransition,
            self::IssueChangeType,
            self::IssueDelete,
            self::CommentCreate,
            self::CommentModerate,
            self::AttachmentUpload,
            self::AttachmentModerate => PermissionScope::Project,
            self::WorkgroupView,
            self::WorkgroupManage,
            self::WorkgroupMembersManage => PermissionScope::Workgroup,
        };
    }

    public function isSensitive(): bool
    {
        return match ($this) {
            self::SystemTenantsCreate,
            self::SystemTenantsManage,
            self::SystemUsersManage,
            self::SystemSuperadminsManage,
            self::SystemImpersonate,
            self::TenantSettingsManage,
            self::TenantMembersManage,
            self::TenantRolesManage,
            self::TenantRolesAssign,
            self::TenantAuditExport,
            self::ProjectSettingsManage,
            self::ProjectMembersManage,
            self::ProjectWorkflowPublish,
            self::IssueDelete,
            self::CommentModerate,
            self::AttachmentModerate,
            self::WorkgroupManage,
            self::WorkgroupMembersManage => true,
            default => false,
        };
    }

    /**
     * @return list<Permission>
     */
    public function dependencies(): array
    {
        return match ($this) {
            self::SystemTenantsCreate,
            self::SystemTenantsManage => [self::SystemTenantsView],
            self::TenantSettingsManage,
            self::TenantMembersView,
            self::TenantRolesView,
            self::TenantWorkgroupsManage,
            self::TenantProjectsCreate,
            self::TenantProjectsManage,
            self::TenantAuditView => [self::TenantView],
            self::TenantMembersInvite,
            self::TenantMembersManage => [
                self::TenantView,
                self::TenantMembersView,
            ],
            self::TenantRolesManage => [
                self::TenantView,
                self::TenantRolesView,
            ],
            self::TenantRolesAssign => [
                self::TenantView,
                self::TenantMembersView,
                self::TenantRolesView,
            ],
            self::TenantAuditExport => [
                self::TenantView,
                self::TenantAuditView,
            ],
            self::ProjectSettingsManage,
            self::ProjectMembersManage,
            self::ProjectWorkflowManage,
            self::IssueView => [self::ProjectView],
            self::ProjectWorkflowPublish => [
                self::ProjectView,
                self::ProjectWorkflowManage,
            ],
            self::IssueCreate,
            self::IssueEdit,
            self::IssueAssign,
            self::IssueTransition,
            self::IssueChangeType,
            self::IssueDelete,
            self::CommentCreate,
            self::CommentModerate,
            self::AttachmentUpload,
            self::AttachmentModerate => [
                self::ProjectView,
                self::IssueView,
            ],
            self::SavedQueryShare,
            self::SavedQueryManage => [self::SavedQueryCreate],
            self::DashboardUpdateOwn,
            self::DashboardDeleteOwn => [self::DashboardCreate],
            self::WorkgroupManage,
            self::WorkgroupMembersManage => [self::WorkgroupView],
            default => [],
        };
    }
}
