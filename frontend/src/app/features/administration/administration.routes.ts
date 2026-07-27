import { Routes } from '@angular/router';

export const ADMINISTRATION_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/admin-overview-page/admin-overview-page.component').then(
        (componentModule) => componentModule.AdminOverviewPageComponent,
      ),
  },
  {
    path: 'members',
    loadComponent: () =>
      import('./pages/tenant-members-page/tenant-members-page.component').then(
        (componentModule) => componentModule.TenantMembersPageComponent,
      ),
  },
  {
    path: 'roles',
    loadComponent: () =>
      import('./pages/tenant-roles-page/tenant-roles-page.component').then(
        (componentModule) => componentModule.TenantRolesPageComponent,
      ),
  },
  {
    path: 'audit',
    loadComponent: () =>
      import('./pages/tenant-audit-page/tenant-audit-page.component').then(
        (componentModule) => componentModule.TenantAuditPageComponent,
      ),
  },
  {
    path: 'workgroups',
    loadComponent: () =>
      import('./pages/workgroup-list-page/workgroup-list-page.component').then(
        (componentModule) => componentModule.WorkgroupListPageComponent,
      ),
  },
] satisfies Routes;
