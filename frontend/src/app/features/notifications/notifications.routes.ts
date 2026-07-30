import { Routes } from '@angular/router';

/**
 * NOT-02 and NOT-03 of webflow §11–12. Both screens are personal, so neither
 * carries a permission guard: the server keys the inbox and the settings on the
 * caller's own membership, and there is no other one to ask for.
 *
 * The settings live under the tenant rather than in a global profile because
 * the preference itself is per membership — the same person can want e-mail in
 * one tenant and silence in another.
 */
export const NOTIFICATION_ROUTES = [
  {
    path: 'preferences',
    loadComponent: () =>
      import('./pages/notification-preferences-page/notification-preferences-page.component').then(
        (componentModule) => componentModule.NotificationPreferencesPageComponent,
      ),
  },
  {
    path: '',
    loadComponent: () =>
      import('./pages/notification-list-page/notification-list-page.component').then(
        (componentModule) => componentModule.NotificationListPageComponent,
      ),
  },
] satisfies Routes;
