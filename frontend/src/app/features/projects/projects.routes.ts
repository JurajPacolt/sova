import { Routes } from '@angular/router';

export const PROJECT_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/project-list-page/project-list-page.component').then(
        (componentModule) => componentModule.ProjectListPageComponent,
      ),
  },
  {
    // Static segment under the identifier, so the detail route never captures
    // `configuration` as a project id.
    path: ':projectId/configuration',
    loadComponent: () =>
      import('./pages/project-configuration-page/project-configuration-page.component').then(
        (componentModule) => componentModule.ProjectConfigurationPageComponent,
      ),
  },
  {
    path: ':projectId',
    loadComponent: () =>
      import('./pages/project-detail-page/project-detail-page.component').then(
        (componentModule) => componentModule.ProjectDetailPageComponent,
      ),
  },
] satisfies Routes;
