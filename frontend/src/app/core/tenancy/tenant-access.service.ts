import { inject, Injectable } from '@angular/core';
import { finalize, map, Observable, of, switchMap, tap } from 'rxjs';
import { AccessibleTenant } from '../api/api.models';
import { SovaApiClient } from '../api/sova-api-client.service';
import { TenantStore } from './tenant.store';

@Injectable({
  providedIn: 'root',
})
export class TenantAccessService {
  private readonly api = inject(SovaApiClient);
  private readonly store = inject(TenantStore);

  refresh(): Observable<readonly AccessibleTenant[]> {
    this.store.setLoading(true);

    return this.api.listTenants().pipe(
      map((response) => response.tenants),
      tap((tenants) => this.store.setTenants(tenants)),
      finalize(() => this.store.setLoading(false)),
    );
  }

  ensureLoaded(): Observable<readonly AccessibleTenant[]> {
    return this.store.hasLoaded() ? of(this.store.tenants()) : this.refresh();
  }

  activateBySlug(slug: string): Observable<AccessibleTenant | null> {
    return this.refresh().pipe(
      map((tenants) => tenants.find((tenant) => tenant.slug === slug) ?? null),
      switchMap((tenant) => {
        if (tenant === null) {
          this.store.clearActiveTenant();
          return of(null);
        }

        return this.api.getTenant(tenant.id).pipe(
          tap((response) => this.store.setActiveTenant(response.tenant, response.permissions)),
          map((response) => response.tenant),
        );
      }),
    );
  }

  clear(): void {
    this.store.clear();
  }
}
