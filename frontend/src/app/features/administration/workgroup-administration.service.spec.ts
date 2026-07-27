import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { Workgroup, WorkgroupMember } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { WorkgroupAdministrationService } from './workgroup-administration.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const WORKGROUP_ID = '019f9f00-0000-7000-8000-000000000002';
const MEMBERSHIP_ID = '019f9f00-0000-7000-8000-000000000003';

const WORKGROUP: Workgroup = {
  id: WORKGROUP_ID,
  tenant_id: TENANT_ID,
  name: 'Platform team',
  description: 'Core platform.',
  status: 'ACTIVE',
  member_count: 0,
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
};

const MEMBER: WorkgroupMember = {
  membership_id: MEMBERSHIP_ID,
  user: {
    id: '019f9f00-0000-7000-8000-000000000004',
    email: 'member@example.test',
    display_name: 'Member',
  },
  role: 'MEMBER',
  joined_at: '2026-07-27T00:00:00+00:00',
};

describe('WorkgroupAdministrationService', () => {
  const api = {
    listWorkgroups: vi.fn(),
    createWorkgroup: vi.fn(),
    changeWorkgroupStatus: vi.fn(),
    listWorkgroupMembers: vi.fn(),
    upsertWorkgroupMember: vi.fn(),
    removeWorkgroupMember: vi.fn(),
  };
  let service: WorkgroupAdministrationService;

  beforeEach(() => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    TestBed.configureTestingModule({
      providers: [
        WorkgroupAdministrationService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
    service = TestBed.inject(WorkgroupAdministrationService);
  });

  it('unwraps the workgroup list envelope', async () => {
    api.listWorkgroups.mockReturnValue(of({ workgroups: [WORKGROUP] }));

    await expect(firstValueFrom(service.list(TENANT_ID))).resolves.toEqual([WORKGROUP]);
  });

  it('creates a workgroup with the given name and description', async () => {
    api.createWorkgroup.mockReturnValue(of({ workgroup: WORKGROUP }));

    await expect(
      firstValueFrom(
        service.create(TENANT_ID, { name: 'Platform team', description: 'Core platform.' }),
      ),
    ).resolves.toEqual(WORKGROUP);
    expect(api.createWorkgroup).toHaveBeenCalledWith(TENANT_ID, {
      name: 'Platform team',
      description: 'Core platform.',
    });
  });

  it('changes the workgroup status', async () => {
    const archived = { ...WORKGROUP, status: 'ARCHIVED' as const };
    api.changeWorkgroupStatus.mockReturnValue(of({ workgroup: archived }));

    await expect(
      firstValueFrom(service.changeStatus(TENANT_ID, WORKGROUP_ID, 'ARCHIVED')),
    ).resolves.toEqual(archived);
    expect(api.changeWorkgroupStatus).toHaveBeenCalledWith(TENANT_ID, WORKGROUP_ID, {
      status: 'ARCHIVED',
    });
  });

  it('manages workgroup members', async () => {
    api.listWorkgroupMembers.mockReturnValue(of({ members: [MEMBER] }));
    api.upsertWorkgroupMember.mockReturnValue(of({ member: MEMBER }));
    api.removeWorkgroupMember.mockReturnValue(of(undefined));

    await expect(firstValueFrom(service.listMembers(TENANT_ID, WORKGROUP_ID))).resolves.toEqual([
      MEMBER,
    ]);
    await expect(
      firstValueFrom(service.upsertMember(TENANT_ID, WORKGROUP_ID, MEMBERSHIP_ID, 'MANAGER')),
    ).resolves.toEqual(MEMBER);
    await firstValueFrom(service.removeMember(TENANT_ID, WORKGROUP_ID, MEMBERSHIP_ID));

    expect(api.upsertWorkgroupMember).toHaveBeenCalledWith(TENANT_ID, WORKGROUP_ID, MEMBERSHIP_ID, {
      role: 'MANAGER',
    });
    expect(api.removeWorkgroupMember).toHaveBeenCalledWith(TENANT_ID, WORKGROUP_ID, MEMBERSHIP_ID);
  });
});
