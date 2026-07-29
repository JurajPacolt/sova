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
  private readonly activePermissions = signal<ReadonlySet<string>>(new Set());

  readonly tenants = this.availableTenants.asReadonly();
  readonly activeTenant = this.selectedTenant.asReadonly();
  readonly hasLoaded = this.tenantListLoaded.asReadonly();
  readonly loading = this.tenantListLoading.asReadonly();
  readonly activeTenantId = computed(() => this.selectedTenant()?.id ?? null);
  readonly permissions = this.activePermissions.asReadonly();

  setLoading(loading: boolean): void {
    this.tenantListLoading.set(loading);
  }

  setTenants(tenants: readonly AccessibleTenant[]): void {
    this.availableTenants.set(tenants);
    this.tenantListLoaded.set(true);

    const currentTenantId = this.selectedTenant()?.id;

    if (currentTenantId !== undefined && !tenants.some((tenant) => tenant.id === currentTenantId)) {
      this.clearActiveTenant();
    }
  }

  setActiveTenant(tenant: AccessibleTenant, permissions: readonly string[] = []): void {
    this.selectedTenant.set(tenant);
    this.activePermissions.set(new Set(permissions));
  }

  /**
   * Whether the caller holds the tenant-scoped permission. Drives affordances
   * only — the backend authorizes every operation again.
   */
  hasPermission(code: string): boolean {
    return this.activePermissions().has(code);
  }

  hasAnyPermission(codes: readonly string[]): boolean {
    return codes.some((code) => this.activePermissions().has(code));
  }

  clearActiveTenant(): void {
    this.selectedTenant.set(null);
    this.activePermissions.set(new Set());
  }

  clear(): void {
    this.availableTenants.set([]);
    this.selectedTenant.set(null);
    this.activePermissions.set(new Set());
    this.tenantListLoaded.set(false);
    this.tenantListLoading.set(false);
  }
}
