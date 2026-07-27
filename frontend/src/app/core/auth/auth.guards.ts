import { inject } from '@angular/core';
import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { TenantStore } from '../tenancy/tenant.store';
import { AuthSessionService } from './auth-session.service';
import { AuthSessionStore } from './auth-session.store';

export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthSessionService);
  const router = inject(Router);

  return auth.ensureAuthenticated().pipe(
    map((authenticated) =>
      authenticated
        ? true
        : router.createUrlTree(['/login'], {
            queryParams: { returnUrl: state.url },
          }),
    ),
    catchError(() =>
      of(
        router.createUrlTree(['/login'], {
          queryParams: {
            returnUrl: state.url,
            serviceUnavailable: '1',
          },
        }),
      ),
    ),
  );
};

export const authChildGuard: CanActivateChildFn = (route, state) => authGuard(route, state);

export const anonymousGuard: CanActivateFn = () => {
  const auth = inject(AuthSessionService);
  const router = inject(Router);
  const session = inject(AuthSessionStore);
  const tenantStore = inject(TenantStore);

  return auth.ensureAuthenticated().pipe(
    map((authenticated) => {
      if (!authenticated) {
        return true;
      }

      if (session.isSuperadmin()) {
        return router.createUrlTree(['/system/tenants']);
      }

      const tenants = tenantStore.tenants();
      const destination =
        tenants.length === 1 ? ['/t', tenants[0].slug, 'dashboard'] : ['/select-tenant'];

      return router.createUrlTree(destination);
    }),
    catchError(() => of(true)),
  );
};

export const superadminGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthSessionService);
  const router = inject(Router);
  const session = inject(AuthSessionStore);

  return auth.ensureAuthenticated().pipe(
    map((authenticated) => {
      if (!authenticated) {
        return router.createUrlTree(['/login'], {
          queryParams: { returnUrl: state.url },
        });
      }

      return session.isSuperadmin() ? true : router.createUrlTree(['/select-tenant']);
    }),
    catchError(() =>
      of(
        router.createUrlTree(['/login'], {
          queryParams: {
            returnUrl: state.url,
            serviceUnavailable: '1',
          },
        }),
      ),
    ),
  );
};
