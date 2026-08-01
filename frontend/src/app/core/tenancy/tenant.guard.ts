import { HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { CanActivateChildFn, CanActivateFn, Router } from '@angular/router';
import { catchError, map, of } from 'rxjs';
import { isSessionRequiredError } from '../auth/session-error';
import { TenantAccessService } from './tenant-access.service';

export const tenantGuard: CanActivateFn = (route, state) => {
  const tenantAccess = inject(TenantAccessService);
  const router = inject(Router);
  const tenantSlug = route.paramMap.get('tenantSlug');

  if (tenantSlug === null) {
    return router.createUrlTree(['/select-tenant']);
  }

  return tenantAccess.activateBySlug(tenantSlug).pipe(
    map((tenant) => (tenant === null ? router.createUrlTree(['/select-tenant']) : true)),
    catchError((error: unknown) =>
      of(
        error instanceof HttpErrorResponse && isSessionRequiredError(error)
          ? router.createUrlTree(['/login'], {
              queryParams: { returnUrl: state.url },
            })
          : router.createUrlTree(['/select-tenant']),
      ),
    ),
  );
};

export const tenantChildGuard: CanActivateChildFn = (route, state) => {
  let tenantRoute = route;

  while (tenantRoute.paramMap.get('tenantSlug') === null && tenantRoute.parent !== null) {
    tenantRoute = tenantRoute.parent;
  }

  return tenantGuard(tenantRoute, state);
};
