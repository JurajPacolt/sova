import { TestBed } from '@angular/core/testing';
import {
  ActivatedRouteSnapshot,
  provideRouter,
  Router,
  RouterStateSnapshot,
  UrlTree,
} from '@angular/router';
import { AccessibleTenant } from '../api/api.models';
import { permissionGuard } from './permission.guard';
import { TenantStore } from './tenant.store';

const TENANT: AccessibleTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  name: 'Acme',
  slug: 'acme',
  status: 'ACTIVE',
  access: {
    type: 'MEMBERSHIP',
    membership_id: '019f9f00-0000-7000-8000-000000000002',
  },
};

describe('permissionGuard', () => {
  let store: TenantStore;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideRouter([]), TenantStore],
    });
    store = TestBed.inject(TenantStore);
  });

  function run(...codes: readonly string[]): boolean | UrlTree {
    return TestBed.runInInjectionContext(() =>
      permissionGuard(...codes)({} as ActivatedRouteSnapshot, {} as RouterStateSnapshot),
    ) as boolean | UrlTree;
  }

  it('admits a caller holding one of the accepted permissions', () => {
    store.setActiveTenant(TENANT, ['tenant.audit.view']);

    expect(run('tenant.members.view', 'tenant.audit.view')).toBe(true);
  });

  it('redirects a caller holding none of them to the dashboard', () => {
    store.setActiveTenant(TENANT, ['tenant.view']);

    const result = run('tenant.members.view');

    expect(result).not.toBe(true);
    expect(TestBed.inject(Router).serializeUrl(result as UrlTree)).toBe('/t/acme/dashboards');
  });

  it('sends a caller without a tenant context to tenant selection', () => {
    const result = run('tenant.members.view');

    expect(TestBed.inject(Router).serializeUrl(result as UrlTree)).toBe('/select-tenant');
  });

  it('forgets the permissions once the tenant context is cleared', () => {
    store.setActiveTenant(TENANT, ['tenant.audit.view']);
    expect(store.hasPermission('tenant.audit.view')).toBe(true);

    store.clearActiveTenant();

    expect(store.hasPermission('tenant.audit.view')).toBe(false);
  });

  it('drops the permissions when the active tenant disappears from the list', () => {
    store.setActiveTenant(TENANT, ['tenant.audit.view']);

    store.setTenants([]);

    expect(store.activeTenant()).toBeNull();
    expect(store.hasPermission('tenant.audit.view')).toBe(false);
  });
});
