import { Routes } from '@angular/router';

export const ISSUE_ROUTES = [
  {
    path: '',
    pathMatch: 'full',
    loadComponent: () =>
      import('./pages/issue-list-page/issue-list-page.component').then(
        (componentModule) => componentModule.IssueListPageComponent,
      ),
  },
  {
    // Static segment first: otherwise `board` would be captured as an issue key.
    path: 'board/:projectId',
    loadComponent: () =>
      import('./pages/issue-board-page/issue-board-page.component').then(
        (componentModule) => componentModule.IssueBoardPageComponent,
      ),
  },
  {
    path: ':issueKey',
    loadComponent: () =>
      import('./pages/issue-detail-page/issue-detail-page.component').then(
        (componentModule) => componentModule.IssueDetailPageComponent,
      ),
  },
] satisfies Routes;
