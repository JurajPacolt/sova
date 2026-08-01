import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of, throwError } from 'rxjs';
import { AccessibleTenant, LoginResponse } from '../api/api.models';
import { SovaApiClient } from '../api/sova-api-client.service';
import { TenantAccessService } from '../tenancy/tenant-access.service';
import { TenantStore } from '../tenancy/tenant.store';
import { AuthSessionService } from './auth-session.service';
import { AuthSessionStore } from './auth-session.store';

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

const LOGIN: LoginResponse = {
  user: {
    id: '019f9f00-0000-7000-8000-000000000003',
    email: 'member@example.test',
    display_name: 'Member',
    preferred_locale: 'sk',
    is_superadmin: false,
  },
  session: {
    id: '019f9f00-0000-7000-8000-000000000004',
    expires_at: '2026-07-27T00:00:00+00:00',
  },
  mfa: {
    enabled: false,
    verified: false,
    enrollment_required: false,
    recovery_codes_remaining: 0,
  },
};

describe('AuthSessionService', () => {
  const api = {
    getCurrentSession: vi.fn(),
    login: vi.fn(),
    logout: vi.fn(),
  };
  const tenantAccess = {
    refresh: vi.fn(),
    clear: vi.fn(),
  };
  let service: AuthSessionService;
  let sessionStore: AuthSessionStore;

  beforeEach(() => {
    api.getCurrentSession.mockReset();
    api.login.mockReset();
    api.logout.mockReset();
    tenantAccess.refresh.mockReset();
    tenantAccess.clear.mockReset();

    TestBed.configureTestingModule({
      providers: [
        AuthSessionService,
        AuthSessionStore,
        TenantStore,
        {
          provide: SovaApiClient,
          useValue: api,
        },
        {
          provide: TenantAccessService,
          useValue: tenantAccess,
        },
      ],
    });

    service = TestBed.inject(AuthSessionService);
    sessionStore = TestBed.inject(AuthSessionStore);
  });

  it('records the authenticated user and loads tenant access after login', async () => {
    api.login.mockReturnValue(of(LOGIN));
    tenantAccess.refresh.mockReturnValue(of([TENANT]));

    const result = await firstValueFrom(
      service.login({
        email: 'member@example.test',
        password: 'password',
      }),
    );

    expect(result.tenants).toEqual([TENANT]);
    expect(sessionStore.status()).toBe('authenticated');
    expect(sessionStore.user()?.id).toBe(LOGIN.user.id);
  });

  it('clears authentication and tenant cache only after a successful logout', async () => {
    sessionStore.setAuthenticated(LOGIN.user);
    api.logout.mockReturnValue(of(undefined));

    await firstValueFrom(service.logout());

    expect(sessionStore.status()).toBe('anonymous');
    expect(tenantAccess.clear).toHaveBeenCalledOnce();
  });

  it('invalidates local session and tenant state after a session-required response', () => {
    sessionStore.setAuthenticated(LOGIN.user);

    service.invalidate();

    expect(sessionStore.status()).toBe('anonymous');
    expect(sessionStore.user()).toBeNull();
    expect(tenantAccess.clear).toHaveBeenCalledOnce();
  });

  it('restores a cookie session from the current-session endpoint', async () => {
    api.getCurrentSession.mockReturnValue(
      of({ user: LOGIN.user, impersonation: null, mfa: LOGIN.mfa }),
    );

    await expect(firstValueFrom(service.ensureAuthenticated())).resolves.toBe(true);
    expect(sessionStore.status()).toBe('authenticated');
    expect(sessionStore.user()).toEqual(LOGIN.user);
    expect(sessionStore.mfa()).toEqual(LOGIN.mfa);
    expect(tenantAccess.refresh).not.toHaveBeenCalled();
  });

  it('does not load tenant access while mandatory MFA enrollment is pending', async () => {
    const restricted = {
      ...LOGIN,
      mfa: {
        ...LOGIN.mfa,
        enrollment_required: true,
      },
    };
    api.login.mockReturnValue(of(restricted));

    const result = await firstValueFrom(
      service.login({
        email: 'admin@example.test',
        password: 'password',
      }),
    );

    expect(result.tenants).toEqual([]);
    expect(sessionStore.mfaEnrollmentRequired()).toBe(true);
    expect(tenantAccess.refresh).not.toHaveBeenCalled();
  });

  it('marks the session anonymous for SESSION_REQUIRED', async () => {
    api.getCurrentSession.mockReturnValue(
      throwError(
        () =>
          new HttpErrorResponse({
            status: 401,
            error: {
              type: 'urn:sova:problem:authentication-required',
              title: 'Authentication Required',
              status: 401,
              detail: 'A valid session is required.',
              instance: '/api/v1/auth/session',
              request_id: 'request-id',
              code: 'SESSION_REQUIRED',
            },
          }),
      ),
    );

    await expect(firstValueFrom(service.ensureAuthenticated())).resolves.toBe(false);
    expect(sessionStore.status()).toBe('anonymous');
    expect(tenantAccess.clear).toHaveBeenCalledOnce();
  });
});
