import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { TenantStore } from './tenant.store';

/**
 * Keeps a screen out of reach when the caller holds none of the listed
 * tenant-scoped permissions. UX only — the guard runs after `tenantGuard` has
 * loaded the tenant context, and the API authorizes every request again.
 *
 * A refusal lands on the tenant's `403` page rather than on the dashboard
 * (webflow `05-STAVY-ROZHRANIA.md` §5). Quietly delivering a different screen
 * than the one asked for reads as a broken link; saying "you do not have access
 * to this" is both true and something the person can act on.
 */
export function permissionGuard(...codes: readonly string[]): CanActivateFn {
  return () => {
    const tenantStore = inject(TenantStore);
    const router = inject(Router);
    const tenant = tenantStore.activeTenant();

    if (tenant === null) {
      return router.createUrlTree(['/select-tenant']);
    }

    if (tenantStore.hasAnyPermission(codes)) {
      return true;
    }

    return router.createUrlTree(['/t', tenant.slug, 'forbidden']);
  };
}
