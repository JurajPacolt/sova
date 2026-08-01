import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { AuthenticatedUser, SystemUser } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { AuthSessionStore } from '../../../../core/auth/auth-session.store';
import { SystemUserListPageComponent } from './system-user-list-page.component';

const CURRENT_USER: AuthenticatedUser = {
  id: '019f9f00-0000-7000-8000-000000000001',
  email: 'superadmin@example.test',
  display_name: 'Superadmin',
  preferred_locale: 'en',
  is_superadmin: true,
};

const OTHER_USER: SystemUser = {
  id: '019f9f00-0000-7000-8000-000000000002',
  email: 'member@example.test',
  display_name: 'Regular member',
  status: 'ACTIVE',
  preferred_locale: 'en',
  email_verified_at: '2026-07-01T00:00:00+00:00',
  failed_login_count: 3,
  locked_until: null,
  is_superadmin: false,
  created_at: '2026-07-01T00:00:00+00:00',
  updated_at: '2026-07-01T00:00:00+00:00',
};

const SELF_USER: SystemUser = {
  id: CURRENT_USER.id,
  email: CURRENT_USER.email,
  display_name: CURRENT_USER.display_name,
  status: 'ACTIVE',
  preferred_locale: 'en',
  email_verified_at: '2026-07-01T00:00:00+00:00',
  failed_login_count: 0,
  locked_until: null,
  is_superadmin: true,
  created_at: '2026-07-01T00:00:00+00:00',
  updated_at: '2026-07-01T00:00:00+00:00',
};

describe('SystemUserListPageComponent', () => {
  const api = {
    listSystemUsers: vi.fn(),
    changeSystemUserStatus: vi.fn(),
    grantSystemSuperadmin: vi.fn(),
    revokeSystemSuperadmin: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listSystemUsers.mockReturnValue(of({ users: [SELF_USER, OTHER_USER] }));

    await TestBed.configureTestingModule({
      imports: [SystemUserListPageComponent],
      providers: [
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    }).compileComponents();

    TestBed.inject(AuthSessionStore).setAuthenticated(CURRENT_USER);
  });

  it('renders every account and hides lifecycle actions for the own row', () => {
    const fixture = TestBed.createComponent(SystemUserListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const articles = element.querySelectorAll('article');

    expect(api.listSystemUsers).toHaveBeenCalledOnce();
    expect(articles).toHaveLength(2);
    expect(element.textContent ?? '').toContain('Regular member');

    const ownArticle = Array.from(articles).find((article) =>
      article.textContent?.includes(CURRENT_USER.display_name),
    );
    expect(ownArticle?.querySelector('button')).toBeNull();
  });

  it('disables another user after confirming the lifecycle change', () => {
    const disabled = { ...OTHER_USER, status: 'DISABLED' as const };
    api.changeSystemUserStatus.mockReturnValue(of({ user: disabled }));

    const fixture = TestBed.createComponent(SystemUserListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const disableButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Disabled',
    );
    disableButton?.dispatchEvent(new Event('click'));
    fixture.detectChanges();

    const confirmButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Confirm',
    );
    confirmButton?.dispatchEvent(new Event('click'));

    expect(api.changeSystemUserStatus).toHaveBeenCalledWith(OTHER_USER.id, {
      status: 'DISABLED',
    });
  });

  it('grants the superadmin role to another user', () => {
    const granted = { ...OTHER_USER, is_superadmin: true };
    api.grantSystemSuperadmin.mockReturnValue(of({ user: granted }));

    const fixture = TestBed.createComponent(SystemUserListPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const grantButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Grant superadmin',
    );
    grantButton?.dispatchEvent(new Event('click'));

    expect(api.grantSystemSuperadmin).toHaveBeenCalledWith(OTHER_USER.id);
  });
});
