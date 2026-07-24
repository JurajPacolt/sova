import { Routes } from '@angular/router';

export const AUTHENTICATION_ROUTES = [
  {
    path: '',
    loadComponent: () =>
      import('./pages/login-page/login-page.component').then(
        (componentModule) => componentModule.LoginPageComponent,
      ),
  },
] satisfies Routes;
