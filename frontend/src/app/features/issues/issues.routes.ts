import { Routes } from '@angular/router';

export const ISSUE_ROUTES = [
  {
    path: ':issueKey',
    loadComponent: () =>
      import('./pages/issue-detail-page/issue-detail-page.component').then(
        (componentModule) => componentModule.IssueDetailPageComponent,
      ),
  },
] satisfies Routes;
