import { Routes } from '@angular/router';
import { anonymousGuard } from '../../core/auth/auth.guards';
import { authGuard } from '../../core/auth/auth.guards';

export const AUTHENTICATION_ROUTES = [
  {
    path: 'login',
    canActivate: [anonymousGuard],
    loadComponent: () =>
      import('./pages/login-page/login-page.component').then(
        (componentModule) => componentModule.LoginPageComponent,
      ),
  },
  {
    path: 'mfa/setup',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./pages/mfa-setup-page/mfa-setup-page.component').then(
        (componentModule) => componentModule.MfaSetupPageComponent,
      ),
  },
  {
    path: 'forgot-password',
    canActivate: [anonymousGuard],
    loadComponent: () =>
      import('./pages/forgot-password-page/forgot-password-page.component').then(
        (componentModule) => componentModule.ForgotPasswordPageComponent,
      ),
  },
  {
    path: 'reset-password/:token',
    canActivate: [anonymousGuard],
    loadComponent: () =>
      import('./pages/reset-password-page/reset-password-page.component').then(
        (componentModule) => componentModule.ResetPasswordPageComponent,
      ),
  },
  {
    path: 'reset-password',
    canActivate: [anonymousGuard],
    loadComponent: () =>
      import('./pages/reset-password-page/reset-password-page.component').then(
        (componentModule) => componentModule.ResetPasswordPageComponent,
      ),
  },
  {
    path: 'verify-email/:token',
    loadComponent: () =>
      import('./pages/verify-email-page/verify-email-page.component').then(
        (componentModule) => componentModule.VerifyEmailPageComponent,
      ),
  },
  {
    path: 'verify-email',
    loadComponent: () =>
      import('./pages/verify-email-page/verify-email-page.component').then(
        (componentModule) => componentModule.VerifyEmailPageComponent,
      ),
  },
  {
    path: 'accept-invitation/:token',
    loadComponent: () =>
      import('./pages/accept-invitation-page/accept-invitation-page.component').then(
        (componentModule) => componentModule.AcceptInvitationPageComponent,
      ),
  },
  {
    path: 'accept-invitation',
    loadComponent: () =>
      import('./pages/accept-invitation-page/accept-invitation-page.component').then(
        (componentModule) => componentModule.AcceptInvitationPageComponent,
      ),
  },
] satisfies Routes;
