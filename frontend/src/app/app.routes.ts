import { Routes } from '@angular/router';

export const routes = [
  {
    path: '',
    pathMatch: 'full',
    redirectTo: 'login',
  },
  {
    path: 'login',
    loadChildren: () =>
      import('./features/authentication/authentication.routes').then(
        (routesModule) => routesModule.AUTHENTICATION_ROUTES,
      ),
  },
  {
    path: 'select-tenant',
    loadChildren: () =>
      import('./features/tenant-selection/tenant-selection.routes').then(
        (routesModule) => routesModule.TENANT_SELECTION_ROUTES,
      ),
  },
  {
    path: 't/:tenantSlug',
    loadComponent: () =>
      import('./core/layout/tenant-shell/tenant-shell.component').then(
        (componentModule) => componentModule.TenantShellComponent,
      ),
    children: [
      {
        path: '',
        pathMatch: 'full',
        redirectTo: 'dashboard',
      },
      {
        path: 'dashboard',
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
        path: 'admin',
        loadChildren: () =>
          import('./features/administration/administration.routes').then(
            (routesModule) => routesModule.ADMINISTRATION_ROUTES,
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
