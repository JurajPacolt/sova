import { computed, Injectable, signal } from '@angular/core';
import { AccessibleTenant } from '../api/api.models';

@Injectable({
  providedIn: 'root',
})
export class TenantStore {
  private readonly availableTenants = signal<readonly AccessibleTenant[]>([]);
  private readonly selectedTenant = signal<AccessibleTenant | null>(null);
  private readonly tenantListLoaded = signal(false);
  private readonly tenantListLoading = signal(false);

  readonly tenants = this.availableTenants.asReadonly();
  readonly activeTenant = this.selectedTenant.asReadonly();
  readonly hasLoaded = this.tenantListLoaded.asReadonly();
  readonly loading = this.tenantListLoading.asReadonly();
  readonly activeTenantId = computed(() => this.selectedTenant()?.id ?? null);

  setLoading(loading: boolean): void {
    this.tenantListLoading.set(loading);
  }

  setTenants(tenants: readonly AccessibleTenant[]): void {
    this.availableTenants.set(tenants);
    this.tenantListLoaded.set(true);

    const currentTenantId = this.selectedTenant()?.id;

    if (currentTenantId !== undefined && !tenants.some((tenant) => tenant.id === currentTenantId)) {
      this.selectedTenant.set(null);
    }
  }

  setActiveTenant(tenant: AccessibleTenant): void {
    this.selectedTenant.set(tenant);
  }

  clearActiveTenant(): void {
    this.selectedTenant.set(null);
  }

  clear(): void {
    this.availableTenants.set([]);
    this.selectedTenant.set(null);
    this.tenantListLoaded.set(false);
    this.tenantListLoading.set(false);
  }
}
