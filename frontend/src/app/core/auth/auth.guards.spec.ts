import {
  ActivatedRouteSnapshot,
  convertToParamMap,
  provideRouter,
  Router,
  RouterStateSnapshot,
} from '@angular/router';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom, Observable, of } from 'rxjs';
import { AccessibleTenant } from '../api/api.models';
import { TenantAccessService } from '../tenancy/tenant-access.service';
import { TenantStore } from '../tenancy/tenant.store';
import { authGuard, superadminGuard } from './auth.guards';
import { AuthSessionService } from './auth-session.service';
import { AuthSessionStore } from './auth-session.store';
import { tenantGuard } from '../tenancy/tenant.guard';

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

describe('authentication and tenant guards', () => {
  const auth = {
    ensureAuthenticated: vi.fn(),
  };
  const tenantAccess = {
    activateBySlug: vi.fn(),
  };

  beforeEach(() => {
    auth.ensureAuthenticated.mockReset();
    tenantAccess.activateBySlug.mockReset();

    TestBed.configureTestingModule({
      providers: [
        provideRouter([]),
        AuthSessionStore,
        TenantStore,
        {
          provide: AuthSessionService,
          useValue: auth,
        },
        {
          provide: TenantAccessService,
          useValue: tenantAccess,
        },
      ],
    });
  });

  it('redirects anonymous users to login with an internal return URL', async () => {
    auth.ensureAuthenticated.mockReturnValue(of(false));

    const result = TestBed.runInInjectionContext(() =>
      authGuard({} as ActivatedRouteSnapshot, { url: '/t/acme/dashboard' } as RouterStateSnapshot),
    );
    const resolved = await firstValueFrom(
      result as Observable<true | ReturnType<Router['createUrlTree']>>,
    );

    expect(resolved.toString()).toBe('/login?returnUrl=%2Ft%2Facme%2Fdashboard');
  });

  it('limits an MFA-enrollment session to the setup route', async () => {
    auth.ensureAuthenticated.mockReturnValue(of(true));
    TestBed.inject(AuthSessionStore).setAuthenticated(
      {
        id: '019f9f00-0000-7000-8000-000000000003',
        email: 'admin@example.test',
        display_name: 'Administrator',
        preferred_locale: 'sk',
        is_superadmin: false,
      },
      null,
      {
        enabled: false,
        verified: false,
        enrollment_required: true,
        recovery_codes_remaining: 0,
      },
    );

    const blocked = TestBed.runInInjectionContext(() =>
      authGuard({} as ActivatedRouteSnapshot, { url: '/select-tenant' } as RouterStateSnapshot),
    );
    const blockedResult = await firstValueFrom(
      blocked as Observable<ReturnType<Router['createUrlTree']>>,
    );
    const allowed = TestBed.runInInjectionContext(() =>
      authGuard(
        {} as ActivatedRouteSnapshot,
        {
          url: '/mfa/setup',
        } as RouterStateSnapshot,
      ),
    );

    expect(blockedResult.toString()).toBe('/mfa/setup');
    await expect(firstValueFrom(allowed as Observable<boolean>)).resolves.toBe(true);
  });

  it('establishes the backend-confirmed tenant context', async () => {
    tenantAccess.activateBySlug.mockReturnValue(of(TENANT));
    const route = {
      paramMap: convertToParamMap({ tenantSlug: 'acme' }),
    } as ActivatedRouteSnapshot;

    const result = TestBed.runInInjectionContext(() =>
      tenantGuard(route, {} as RouterStateSnapshot),
    );

    await expect(firstValueFrom(result as Observable<boolean>)).resolves.toBe(true);
    expect(tenantAccess.activateBySlug).toHaveBeenCalledWith('acme');
  });

  it('hides an inaccessible tenant behind the selection route', async () => {
    tenantAccess.activateBySlug.mockReturnValue(of(null));
    const route = {
      paramMap: convertToParamMap({ tenantSlug: 'foreign' }),
    } as ActivatedRouteSnapshot;

    const result = TestBed.runInInjectionContext(() =>
      tenantGuard(route, {} as RouterStateSnapshot),
    );
    const resolved = await firstValueFrom(
      result as Observable<ReturnType<Router['createUrlTree']>>,
    );

    expect(resolved.toString()).toBe('/select-tenant');
  });

  it('allows a superadmin into the system context', async () => {
    auth.ensureAuthenticated.mockReturnValue(of(true));
    TestBed.inject(AuthSessionStore).setAuthenticated({
      id: '019f9f00-0000-7000-8000-000000000003',
      email: 'superadmin@example.test',
      display_name: 'System administrator',
      preferred_locale: 'sk',
      is_superadmin: true,
    });

    const result = TestBed.runInInjectionContext(() =>
      superadminGuard(
        {} as ActivatedRouteSnapshot,
        { url: '/system/tenants' } as RouterStateSnapshot,
      ),
    );

    await expect(firstValueFrom(result as Observable<boolean>)).resolves.toBe(true);
  });

  it('redirects an authenticated non-superadmin away from the system context', async () => {
    auth.ensureAuthenticated.mockReturnValue(of(true));
    TestBed.inject(AuthSessionStore).setAuthenticated({
      id: '019f9f00-0000-7000-8000-000000000003',
      email: 'member@example.test',
      display_name: 'Member',
      preferred_locale: 'sk',
      is_superadmin: false,
    });

    const result = TestBed.runInInjectionContext(() =>
      superadminGuard(
        {} as ActivatedRouteSnapshot,
        { url: '/system/tenants' } as RouterStateSnapshot,
      ),
    );
    const resolved = await firstValueFrom(
      result as Observable<ReturnType<Router['createUrlTree']>>,
    );

    expect(resolved.toString()).toBe('/select-tenant');
  });
});
