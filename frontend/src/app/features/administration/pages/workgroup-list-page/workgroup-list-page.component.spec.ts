import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { AccessibleTenant, Workgroup } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { WorkgroupListPageComponent } from './workgroup-list-page.component';

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

const WORKGROUP: Workgroup = {
  id: '019f9f00-0000-7000-8000-000000000003',
  tenant_id: TENANT.id,
  name: 'Platform team',
  description: 'Core platform.',
  status: 'ACTIVE',
  member_count: 1,
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
};

const MEMBERSHIP = {
  id: '019f9f00-0000-7000-8000-000000000004',
  user: {
    id: '019f9f00-0000-7000-8000-000000000005',
    email: 'petra@example.test',
    display_name: 'Petra Member',
  },
  status: 'ACTIVE' as const,
  joined_at: '2026-07-01T00:00:00+00:00',
  roles: [],
};

describe('WorkgroupListPageComponent', () => {
  const api = {
    listWorkgroups: vi.fn(),
    createWorkgroup: vi.fn(),
    changeWorkgroupStatus: vi.fn(),
    listWorkgroupMembers: vi.fn(),
    upsertWorkgroupMember: vi.fn(),
    removeWorkgroupMember: vi.fn(),
    listTenantMemberships: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listWorkgroups.mockReturnValue(of({ workgroups: [WORKGROUP] }));
    api.listTenantMemberships.mockReturnValue(of({ memberships: [MEMBERSHIP] }));
    api.listWorkgroupMembers.mockReturnValue(of({ members: [] }));

    await TestBed.configureTestingModule({
      imports: [WorkgroupListPageComponent],
      providers: [
        provideRouter([]),
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    }).compileComponents();

    const tenantStore = TestBed.inject(TenantStore);
    tenantStore.setTenants([TENANT]);
    tenantStore.setActiveTenant(TENANT);
  });

  it('loads workgroups and tenant memberships for the active tenant', () => {
    const fixture = TestBed.createComponent(WorkgroupListPageComponent);
    fixture.detectChanges();

    expect(api.listWorkgroups).toHaveBeenCalledWith(TENANT.id);
    expect(api.listTenantMemberships).toHaveBeenCalledWith(TENANT.id);
    expect((fixture.nativeElement as HTMLElement).textContent ?? '').toContain('Platform team');
  });

  it('adds a member to an expanded workgroup', () => {
    api.upsertWorkgroupMember.mockReturnValue(
      of({
        member: {
          membership_id: MEMBERSHIP.id,
          user: MEMBERSHIP.user,
          role: 'MEMBER',
          joined_at: '2026-07-27T00:00:00+00:00',
        },
      }),
    );

    const fixture = TestBed.createComponent(WorkgroupListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const manageButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Manage members',
    );
    manageButton?.dispatchEvent(new Event('click'));
    fixture.detectChanges();

    const select = element.querySelector<HTMLSelectElement>(
      'select[formcontrolname="membership_id"]',
    );
    expect(select).not.toBeNull();
    select!.value = MEMBERSHIP.id;
    select!.dispatchEvent(new Event('change'));
    fixture.detectChanges();
    const forms = element.querySelectorAll('form');
    forms[forms.length - 1].dispatchEvent(new Event('submit', { cancelable: true }));

    expect(api.upsertWorkgroupMember).toHaveBeenCalledWith(TENANT.id, WORKGROUP.id, MEMBERSHIP.id, {
      role: 'MEMBER',
    });
  });
});
