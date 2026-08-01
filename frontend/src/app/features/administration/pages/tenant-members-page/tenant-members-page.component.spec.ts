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

const INVITATION = {
  id: '019f9f00-0000-7000-8000-000000000006',
  tenant_id: TENANT.id,
  email: 'invitee@example.test',
  status: 'PENDING' as const,
  invited_by_display_name: 'Owner',
  initial_role_code: null,
  expires_at: '2026-08-03T00:00:00+00:00',
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
  accepted_at: null,
  revoked_at: null,
};

describe('TenantMembersPageComponent', () => {
  const api = {
    listTenantMemberships: vi.fn(),
    listTenantInvitations: vi.fn(),
    listTenantRoles: vi.fn(),
    createTenantInvitation: vi.fn(),
    changeTenantInvitationExpiry: vi.fn(),
    resendTenantInvitation: vi.fn(),
    revokeTenantInvitation: vi.fn(),
    changeTenantMembershipStatus: vi.fn(),
    assignTenantRole: vi.fn(),
    unassignTenantRole: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listTenantMemberships.mockReturnValue(of({ memberships: [MEMBERSHIP] }));
    api.listTenantInvitations.mockReturnValue(of({ invitations: [INVITATION] }));
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
    tenantStore.setActiveTenant(TENANT, ['tenant.members.invite']);
    TestBed.inject(AuthSessionStore).setAuthenticated(CURRENT_USER);
  });

  it('loads members and roles for the active tenant and renders the member list', () => {
    const fixture = TestBed.createComponent(TenantMembersPageComponent);
    fixture.detectChanges();
    const text = (fixture.nativeElement as HTMLElement).textContent ?? '';

    expect(api.listTenantMemberships).toHaveBeenCalledWith(TENANT.id);
    expect(api.listTenantInvitations).toHaveBeenCalledWith(TENANT.id);
    expect(api.listTenantRoles).toHaveBeenCalledWith(TENANT.id);
    expect(text).toContain('Regular member');
    expect(text).toContain('member@example.test');
    expect(text).toContain('invitee@example.test');
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

  it('resends a pending invitation through the token-rotating endpoint', () => {
    api.resendTenantInvitation.mockReturnValue(of({ invitation: INVITATION }));

    const fixture = TestBed.createComponent(TenantMembersPageComponent);
    fixture.detectChanges();
    const resend = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('button'),
    ).find((button) => button.textContent?.trim() === 'Resend');
    expect(resend).toBeDefined();
    resend!.click();
    fixture.detectChanges();

    expect(api.resendTenantInvitation).toHaveBeenCalledWith(TENANT.id, INVITATION.id);
    expect((fixture.nativeElement as HTMLElement).textContent ?? '').toContain(
      'The previous link is no longer valid.',
    );
  });

  it('asks for confirmation before revoking a pending invitation', () => {
    api.revokeTenantInvitation.mockReturnValue(
      of({
        invitation: {
          ...INVITATION,
          status: 'REVOKED',
          revoked_at: '2026-07-29T12:00:00+00:00',
        },
      }),
    );

    const fixture = TestBed.createComponent(TenantMembersPageComponent);
    fixture.detectChanges();
    const buttons = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('button'),
    );
    const revoke = buttons.find((button) => button.textContent?.trim() === 'Revoke');
    expect(revoke).toBeDefined();
    revoke!.click();
    fixture.detectChanges();

    expect(api.revokeTenantInvitation).not.toHaveBeenCalled();
    const confirm = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll<HTMLButtonElement>('button'),
    ).find((button) => button.textContent?.trim() === 'Confirm');
    expect(confirm).toBeDefined();
    confirm!.click();
    fixture.detectChanges();

    expect(api.revokeTenantInvitation).toHaveBeenCalledWith(TENANT.id, INVITATION.id);
    expect((fixture.nativeElement as HTMLElement).textContent ?? '').toContain('Revoked');
  });
});
