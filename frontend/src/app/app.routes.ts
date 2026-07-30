import { Routes } from '@angular/router';
import { authChildGuard, authGuard, superadminGuard } from './core/auth/auth.guards';
import { tenantChildGuard, tenantGuard } from './core/tenancy/tenant.guard';

export const routes = [
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'login',
  },
  {
    path: '',
    loadChildren: () =>
      import('./features/authentication/authentication.routes').then(
        (routesModule) => routesModule.AUTHENTICATION_ROUTES,
      ),
  },
  {
    path: 'select-tenant',
    canActivate: [authGuard],
    loadChildren: () =>
      import('./features/tenant-selection/tenant-selection.routes').then(
        (routesModule) => routesModule.TENANT_SELECTION_ROUTES,
      ),
  },
  {
    path: 't/:tenantSlug',
    canActivate: [authGuard, tenantGuard],
    canActivateChild: [authChildGuard, tenantChildGuard],
    loadComponent: () =>
      import('./core/layout/tenant-shell/tenant-shell.component').then(
        (componentModule) => componentModule.TenantShellComponent,
      ),
    children: [
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'dashboards',
      },
      {
        // The singular path stays as an entry point (spec §7.2) so older links
        // and anything pointing at "the dashboard" still land somewhere.
        path: 'dashboard',
        pathMatch: 'full',
        redirectTo: 'dashboards',
      },
      {
        path: 'dashboards',
        loadChildren: () =>
          import('./features/dashboard/dashboard.routes').then(
            (routesModule) => routesModule.DASHBOARD_ROUTES,
          ),
      },
      {
        path: 'projects',
        loadChildren: () =>
          import('./features/projects/projects.routes').then(
            (routesModule) => routesModule.PROJECT_ROUTES,
          ),
      },
      {
        path: 'issues',
        loadChildren: () =>
          import('./features/issues/issues.routes').then(
            (routesModule) => routesModule.ISSUE_ROUTES,
          ),
      },
      {
        path: 'notifications',
        loadChildren: () =>
          import('./features/notifications/notifications.routes').then(
            (routesModule) => routesModule.NOTIFICATION_ROUTES,
          ),
      },
      {
        path: 'admin',
        loadChildren: () =>
          import('./features/administration/administration.routes').then(
            (routesModule) => routesModule.ADMINISTRATION_ROUTES,
          ),
      },
      {
        // Where `permissionGuard` sends a caller who may not open a screen. It
        // lives inside the tenant shell on purpose: the navigation and the
        // tenant they are in stay visible, so this is a closed door rather than
        // the floor disappearing.
        path: 'forbidden',
        loadComponent: () =>
          import('./shared/components/forbidden/forbidden.component').then(
            (componentModule) => componentModule.ForbiddenComponent,
          ),
      },
    ],
  },
  {
    path: 'system',
    canActivate: [superadminGuard],
    canActivateChild: [superadminGuard],
    loadComponent: () =>
      import('./core/layout/system-shell/system-shell.component').then(
        (componentModule) => componentModule.SystemShellComponent,
      ),
    children: [
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'tenants',
      },
      {
        path: 'tenants',
        loadChildren: () =>
          import('./features/system-administration/system-administration.routes').then(
            (routesModule) => routesModule.SYSTEM_ADMINISTRATION_ROUTES,
          ),
      },
      {
        path: 'audit',
        loadComponent: () =>
          import('./features/system-administration/pages/system-security-audit-page/system-security-audit-page.component').then(
            (componentModule) => componentModule.SystemSecurityAuditPageComponent,
          ),
      },
      {
        path: 'users',
        loadComponent: () =>
          import('./features/system-administration/pages/system-user-list-page/system-user-list-page.component').then(
            (componentModule) => componentModule.SystemUserListPageComponent,
          ),
      },
    ],
  },
  {
    path: '**',
    loadComponent: () =>
      import('./shared/components/not-found/not-found.component').then(
        (componentModule) => componentModule.NotFoundComponent,
      ),
  },
] satisfies Routes;
