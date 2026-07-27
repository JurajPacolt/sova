import { Routes } from '@angular/router';

export const SYSTEM_ADMINISTRATION_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/system-tenant-list-page/system-tenant-list-page.component').then(
        (componentModule) => componentModule.SystemTenantListPageComponent,
      ),
  },
] satisfies Routes;
