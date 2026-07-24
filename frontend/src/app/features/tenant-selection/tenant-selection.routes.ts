import { Routes } from '@angular/router';

export const TENANT_SELECTION_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/tenant-selection-page/tenant-selection-page.component').then(
        (componentModule) => componentModule.TenantSelectionPageComponent,
      ),
  },
] satisfies Routes;
