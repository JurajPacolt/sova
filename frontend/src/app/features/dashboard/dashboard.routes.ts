import { Routes } from '@angular/router';

export const DASHBOARD_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/dashboard-page/dashboard-page.component').then(
        (componentModule) => componentModule.DashboardPageComponent,
      ),
  },
] satisfies Routes;
