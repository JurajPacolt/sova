import { Routes } from '@angular/router';

/**
 * A dashboard is addressed by identifier, because that is what people bookmark
 * and share (spec §7.2). The bare path is an entry point rather than a screen:
 * it resolves the last active dashboard and replaces itself with the canonical
 * address.
 */
export const DASHBOARD_ROUTES = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/dashboard-entry/dashboard-entry.component').then(
        (componentModule) => componentModule.DashboardEntryComponent,
      ),
  },
  {
    path: ':dashboardId',
    loadComponent: () =>
      import('./pages/dashboard-page/dashboard-page.component').then(
        (componentModule) => componentModule.DashboardPageComponent,
      ),
  },
] satisfies Routes;
