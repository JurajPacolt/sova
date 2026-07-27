import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { AccessibleTenant, AuthenticatedUser } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { AuthSessionStore } from '../../../../core/auth/auth-session.store';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { TenantMembersPageComponent } from './tenant-members-page.component';

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

const CURRENT_USER: AuthenticatedUser = {
  id: '019f9f00-0000-7000-8000-000000000003',
  email: 'owner@example.test',
  display_name: 'Owner',
  preferred_locale: 'en',
  is_superadmin: false,
};

const MEMBERSHIP = {
  id: '019f9f00-0000-7000-8000-000000000004',
  user: {
    id: '019f9f00-0000-7000-8000-000000000005',
    email: 'member@example.test',
    display_name: 'Regular member',
  },
  status: 'ACTIVE' as const,
  joined_at: '2026-07-27T00:00:00+00:00',
  roles: [],
};

describe('TenantMembersPageComponent', () => {
  const api = {
    listTenantMemberships: vi.fn(),
    listTenantRoles: vi.fn(),
    createTenantInvitation: vi.fn(),
    changeTenantMembershipStatus: vi.fn(),
    assignTenantRole: vi.fn(),
    unassignTenantRole: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listTenantMemberships.mockReturnValue(of({ memberships: [MEMBERSHIP] }));
    api.listTenantRoles.mockReturnValue(of({ roles: [], permissions: [] }));

    await TestBed.configureTestingModule({
      imports: [TenantMembersPageComponent],
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
    TestBed.inject(AuthSessionStore).setAuthenticated(CURRENT_USER);
  });

  it('loads members and roles for the active tenant and renders the member list', () => {
    const fixture = TestBed.createComponent(TenantMembersPageComponent);
    fixture.detectChanges();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';

    expect(api.listTenantMemberships).toHaveBeenCalledWith(TENANT.id);
    expect(api.listTenantRoles).toHaveBeenCalledWith(TENANT.id);
    expect(text).toContain('Regular member');
    expect(text).toContain('member@example.test');
  });

  it('creates an invitation for the entered email and shows a confirmation', () => {
    api.createTenantInvitation.mockReturnValue(
      of({
        invitation: {
          id: '019f9f00-0000-7000-8000-000000000006',
          tenant_id: TENANT.id,
          email: 'invitee@example.test',
          status: 'PENDING',
          expires_at: '2026-08-03T00:00:00+00:00',
        },
      }),
    );

    const fixture = TestBed.createComponent(TenantMembersPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const emailInput = element.querySelector<HTMLInputElement>('#invite-email');
    expect(emailInput).not.toBeNull();
    emailInput!.value = 'invitee@example.test';
    emailInput!.dispatchEvent(new Event('input'));
    element
      .querySelector<HTMLFormElement>('form')
      ?.dispatchEvent(new Event('submit', { cancelable: true }));
    fixture.detectChanges();

    expect(api.createTenantInvitation).toHaveBeenCalledWith(TENANT.id, {
      email: 'invitee@example.test',
    });
    expect(element.textContent ?? '').toContain('invitee@example.test');
  });
});
