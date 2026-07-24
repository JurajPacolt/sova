import { Routes } from '@angular/router';

export const PROJECT_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/project-list-page/project-list-page.component').then(
        (componentModule) => componentModule.ProjectListPageComponent,
      ),
  },
] satisfies Routes;
