import { Routes } from '@angular/router';

export const ADMINISTRATION_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/admin-overview-page/admin-overview-page.component').then(
        (componentModule) => componentModule.AdminOverviewPageComponent,
      ),
  },
] satisfies Routes;
