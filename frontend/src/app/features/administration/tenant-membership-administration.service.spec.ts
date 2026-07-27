import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { TenantMembership } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { TenantMembershipAdministrationService } from './tenant-membership-administration.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const MEMBERSHIP_ID = '019f9f00-0000-7000-8000-000000000002';
const ROLE_ID = '019f9f00-0000-7000-8000-000000000003';

const MEMBERSHIP: TenantMembership = {
  id: MEMBERSHIP_ID,
  user: {
    id: '019f9f00-0000-7000-8000-000000000004',
    email: 'member@example.test',
    display_name: 'Member',
  },
  status: 'ACTIVE',
  joined_at: '2026-07-27T00:00:00+00:00',
  roles: [],
};

describe('TenantMembershipAdministrationService', () => {
  const api = {
    listTenantMemberships: vi.fn(),
    createTenantInvitation: vi.fn(),
    changeTenantMembershipStatus: vi.fn(),
    assignTenantRole: vi.fn(),
    unassignTenantRole: vi.fn(),
  };
  let service: TenantMembershipAdministrationService;

  beforeEach(() => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    TestBed.configureTestingModule({
      providers: [
        TenantMembershipAdministrationService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
    service = TestBed.inject(TenantMembershipAdministrationService);
  });

  it('unwraps the membership list envelope', async () => {
    api.listTenantMemberships.mockReturnValue(of({ memberships: [MEMBERSHIP] }));

    await expect(firstValueFrom(service.list(TENANT_ID))).resolves.toEqual([MEMBERSHIP]);
    expect(api.listTenantMemberships).toHaveBeenCalledWith(TENANT_ID);
  });

  it('sends an invitation for the given email', async () => {
    const invitation = {
      id: '019f9f00-0000-7000-8000-000000000005',
      tenant_id: TENANT_ID,
      email: 'invitee@example.test',
      status: 'PENDING' as const,
      expires_at: '2026-08-03T00:00:00+00:00',
    };
    api.createTenantInvitation.mockReturnValue(of({ invitation }));

    await expect(
      firstValueFrom(service.invite(TENANT_ID, 'invitee@example.test')),
    ).resolves.toEqual(invitation);
    expect(api.createTenantInvitation).toHaveBeenCalledWith(TENANT_ID, {
      email: 'invitee@example.test',
    });
  });

  it('maps a lifecycle status change to the updated membership', async () => {
    const disabled = { ...MEMBERSHIP, status: 'DISABLED' as const };
    api.changeTenantMembershipStatus.mockReturnValue(of({ membership: disabled }));

    await expect(
      firstValueFrom(service.changeStatus(TENANT_ID, MEMBERSHIP_ID, 'DISABLED')),
    ).resolves.toEqual(disabled);
    expect(api.changeTenantMembershipStatus).toHaveBeenCalledWith(TENANT_ID, MEMBERSHIP_ID, {
      status: 'DISABLED',
    });
  });

  it('delegates role assignment and removal with the tenant, membership, and role IDs', async () => {
    api.assignTenantRole.mockReturnValue(of(undefined));
    api.unassignTenantRole.mockReturnValue(of(undefined));

    await firstValueFrom(service.assignRole(TENANT_ID, MEMBERSHIP_ID, ROLE_ID));
    await firstValueFrom(service.unassignRole(TENANT_ID, MEMBERSHIP_ID, ROLE_ID));

    expect(api.assignTenantRole).toHaveBeenCalledWith(TENANT_ID, MEMBERSHIP_ID, ROLE_ID);
    expect(api.unassignTenantRole).toHaveBeenCalledWith(TENANT_ID, MEMBERSHIP_ID, ROLE_ID);
  });
});
