import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideBrowserGlobalErrorListeners } from '@angular/core';
import { provideRouter, withComponentInputBinding, withInMemoryScrolling } from '@angular/router';

import { routes } from './app.routes';
import { apiCredentialsInterceptor } from './core/api/api-credentials.interceptor';
import { sessionExpiryInterceptor } from './core/auth/session-expiry.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideHttpClient(withInterceptors([apiCredentialsInterceptor, sessionExpiryInterceptor])),
    provideRouter(
      routes,
      withComponentInputBinding(),
      withInMemoryScrolling({
        anchorScrolling: 'enabled',
        scrollPositionRestoration: 'enabled',
      }),
    ),
  ],
};
