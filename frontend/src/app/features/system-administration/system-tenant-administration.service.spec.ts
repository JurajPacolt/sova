import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { SystemTenant } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { SystemTenantAdministrationService } from './system-tenant-administration.service';

const TENANT: SystemTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  name: 'Acme',
  slug: 'acme',
  status: 'ACTIVE',
  revision: 2,
  owner_email: 'owner@example.test',
  active_member_count: 4,
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
  deletion_effective_at: null,
};

describe('SystemTenantAdministrationService', () => {
  const api = {
    listSystemTenants: vi.fn(),
    createSystemTenant: vi.fn(),
    changeSystemTenantStatus: vi.fn(),
    listTenantMemberships: vi.fn(),
  };
  let service: SystemTenantAdministrationService;

  beforeEach(() => {
    api.listSystemTenants.mockReset();
    api.createSystemTenant.mockReset();
    api.changeSystemTenantStatus.mockReset();
    api.listTenantMemberships.mockReset();
    TestBed.configureTestingModule({
      providers: [
        SystemTenantAdministrationService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
    service = TestBed.inject(SystemTenantAdministrationService);
  });

  it('maps the dedicated system tenant list', async () => {
    api.listSystemTenants.mockReturnValue(of({ tenants: [TENANT] }));

    await expect(firstValueFrom(service.list())).resolves.toEqual([TENANT]);
  });

  it('keeps the idempotency key with tenant creation', async () => {
    const request = {
      name: 'Acme',
      slug: 'acme',
      owner_email: 'owner@example.test',
    };
    api.createSystemTenant.mockReturnValue(
      of({
        tenant: TENANT,
        owner_invitation: {
          email: request.owner_email,
          status: 'PENDING',
        },
        replayed: false,
      }),
    );

    await firstValueFrom(service.create(request, '019f9f00-0000-7000-8000-000000000002'));

    expect(api.createSystemTenant).toHaveBeenCalledWith(
      request,
      '019f9f00-0000-7000-8000-000000000002',
    );
  });

  it('maps an optimistic lifecycle response to the updated tenant', async () => {
    const suspended = {
      ...TENANT,
      status: 'SUSPENDED' as const,
      revision: 3,
    };
    api.changeSystemTenantStatus.mockReturnValue(of({ tenant: suspended }));

    await expect(
      firstValueFrom(
        service.changeStatus(TENANT.id, {
          status: 'SUSPENDED',
          revision: TENANT.revision,
          reason: 'Confirmed security response action.',
        }),
      ),
    ).resolves.toEqual(suspended);
  });

  it('returns only active members as impersonation targets', async () => {
    api.listTenantMemberships.mockReturnValue(
      of({
        memberships: [
          {
            id: '019f9f00-0000-7000-8000-000000000010',
            user: {
              id: '019f9f00-0000-7000-8000-000000000011',
              email: 'active@example.test',
              display_name: 'Active member',
            },
            status: 'ACTIVE',
            joined_at: '2026-07-27T00:00:00+00:00',
            roles: [],
          },
          {
            id: '019f9f00-0000-7000-8000-000000000012',
            user: {
              id: '019f9f00-0000-7000-8000-000000000013',
              email: 'disabled@example.test',
              display_name: 'Disabled member',
            },
            status: 'DISABLED',
            joined_at: '2026-07-27T00:00:00+00:00',
            roles: [],
          },
        ],
      }),
    );

    const targets = await firstValueFrom(service.listActiveMembers(TENANT.id));

    expect(targets.map((membership) => membership.user.email)).toEqual(['active@example.test']);
  });
});
