import { Routes } from '@angular/router';
import { permissionGuard } from '../../core/tenancy/permission.guard';
import { ADMINISTRATION_PERMISSIONS } from '../../core/tenancy/tenant-permissions';

export const ADMINISTRATION_ROUTES = [
  {
    path: '',
    canActivate: [permissionGuard(...ADMINISTRATION_PERMISSIONS)],
    loadComponent: () =>
      import('./pages/admin-overview-page/admin-overview-page.component').then(
        (componentModule) => componentModule.AdminOverviewPageComponent,
      ),
  },
  {
    path: 'members',
    canActivate: [permissionGuard('tenant.members.view', 'tenant.members.manage')],
    loadComponent: () =>
      import('./pages/tenant-members-page/tenant-members-page.component').then(
        (componentModule) => componentModule.TenantMembersPageComponent,
      ),
  },
  {
    path: 'roles',
    canActivate: [permissionGuard('tenant.roles.view', 'tenant.roles.manage')],
    loadComponent: () =>
      import('./pages/tenant-roles-page/tenant-roles-page.component').then(
        (componentModule) => componentModule.TenantRolesPageComponent,
      ),
  },
  {
    path: 'audit',
    canActivate: [permissionGuard('tenant.audit.view')],
    loadComponent: () =>
      import('./pages/tenant-audit-page/tenant-audit-page.component').then(
        (componentModule) => componentModule.TenantAuditPageComponent,
      ),
  },
  {
    path: 'workgroups',
    canActivate: [permissionGuard('tenant.workgroups.manage')],
    loadComponent: () =>
      import('./pages/workgroup-list-page/workgroup-list-page.component').then(
        (componentModule) => componentModule.WorkgroupListPageComponent,
      ),
  },
] satisfies Routes;
