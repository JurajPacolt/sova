import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { CurrentSessionResponse, StartImpersonationResponse } from '../api/api.models';
import { SovaApiClient } from '../api/sova-api-client.service';
import { TenantAccessService } from '../tenancy/tenant-access.service';
import { AuthSessionStore } from './auth-session.store';
import { ImpersonationSessionService } from './impersonation-session.service';

const ACTOR = {
  id: '019f9f00-0000-7000-8000-000000000001',
  email: 'admin@example.test',
  display_name: 'System administrator',
};
const EFFECTIVE_USER = {
  id: '019f9f00-0000-7000-8000-000000000002',
  email: 'member@example.test',
  display_name: 'Tenant member',
  preferred_locale: 'sk' as const,
  is_superadmin: false,
};
const STARTED: StartImpersonationResponse = {
  user: EFFECTIVE_USER,
  impersonation: {
    id: '019f9f00-0000-7000-8000-000000000003',
    status: 'ACTIVE',
    actor: ACTOR,
    effective_user: EFFECTIVE_USER,
    tenant: {
      id: '019f9f00-0000-7000-8000-000000000004',
      name: 'Acme',
      slug: 'acme',
    },
    reason: 'Investigating support request SOVA-42.',
    reauthenticated_at: '2026-07-27T10:00:00+00:00',
    started_at: '2026-07-27T10:00:00+00:00',
    expires_at: '2026-07-27T10:15:00+00:00',
  },
};

describe('ImpersonationSessionService', () => {
  const api = {
    startImpersonation: vi.fn(),
    endCurrentImpersonation: vi.fn(),
    getCurrentSession: vi.fn(),
  };
  const tenantAccess = {
    clear: vi.fn(),
  };
  let service: ImpersonationSessionService;
  let store: AuthSessionStore;

  beforeEach(() => {
    api.startImpersonation.mockReset();
    api.endCurrentImpersonation.mockReset();
    api.getCurrentSession.mockReset();
    tenantAccess.clear.mockReset();
    TestBed.configureTestingModule({
      providers: [
        ImpersonationSessionService,
        AuthSessionStore,
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
    service = TestBed.inject(ImpersonationSessionService);
    store = TestBed.inject(AuthSessionStore);
  });

  it('switches local identity only after the server starts impersonation', async () => {
    api.startImpersonation.mockReturnValue(of(STARTED));
    const request = {
      tenant_id: STARTED.impersonation.tenant.id,
      effective_user_id: STARTED.user.id,
      reason: STARTED.impersonation.reason,
      password: 'current administrator password',
    };

    await firstValueFrom(service.start(request));

    expect(api.startImpersonation).toHaveBeenCalledWith(request);
    expect(store.user()).toEqual(EFFECTIVE_USER);
    expect(store.impersonation()).toEqual(STARTED.impersonation);
    expect(tenantAccess.clear).toHaveBeenCalledOnce();
  });

  it('restores the actor session after ending impersonation', async () => {
    const restored: CurrentSessionResponse = {
      user: {
        ...ACTOR,
        preferred_locale: 'sk',
        is_superadmin: true,
      },
      impersonation: null,
    };
    store.setAuthenticated(STARTED.user, STARTED.impersonation);
    api.endCurrentImpersonation.mockReturnValue(of(undefined));
    api.getCurrentSession.mockReturnValue(of(restored));

    await firstValueFrom(service.end());

    expect(store.user()).toEqual(restored.user);
    expect(store.impersonation()).toBeNull();
    expect(tenantAccess.clear).toHaveBeenCalledOnce();
  });
});
