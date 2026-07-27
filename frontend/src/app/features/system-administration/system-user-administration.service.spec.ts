import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { SystemUser } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { SystemUserAdministrationService } from './system-user-administration.service';

const USER: SystemUser = {
  id: '019f9f00-0000-7000-8000-000000000001',
  email: 'member@example.test',
  display_name: 'Member',
  status: 'ACTIVE',
  preferred_locale: 'en',
  email_verified_at: '2026-07-01T00:00:00+00:00',
  failed_login_count: 0,
  locked_until: null,
  is_superadmin: false,
  created_at: '2026-07-01T00:00:00+00:00',
  updated_at: '2026-07-01T00:00:00+00:00',
};

describe('SystemUserAdministrationService', () => {
  const api = {
    listSystemUsers: vi.fn(),
    changeSystemUserStatus: vi.fn(),
    grantSystemSuperadmin: vi.fn(),
    revokeSystemSuperadmin: vi.fn(),
  };
  let service: SystemUserAdministrationService;

  beforeEach(() => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    TestBed.configureTestingModule({
      providers: [
        SystemUserAdministrationService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
    service = TestBed.inject(SystemUserAdministrationService);
  });

  it('unwraps the system user list envelope', async () => {
    api.listSystemUsers.mockReturnValue(of({ users: [USER] }));

    await expect(firstValueFrom(service.list())).resolves.toEqual([USER]);
  });

  it('sends the target status and unwraps the updated user', async () => {
    const disabled = { ...USER, status: 'DISABLED' as const };
    api.changeSystemUserStatus.mockReturnValue(of({ user: disabled }));

    await expect(firstValueFrom(service.changeStatus(USER.id, 'DISABLED'))).resolves.toEqual(
      disabled,
    );
    expect(api.changeSystemUserStatus).toHaveBeenCalledWith(USER.id, {
      status: 'DISABLED',
    });
  });

  it('grants and revokes the superadmin role by user ID', async () => {
    const granted = { ...USER, is_superadmin: true };
    api.grantSystemSuperadmin.mockReturnValue(of({ user: granted }));
    api.revokeSystemSuperadmin.mockReturnValue(of({ user: USER }));

    await expect(firstValueFrom(service.grantSuperadmin(USER.id))).resolves.toEqual(granted);
    await expect(firstValueFrom(service.revokeSuperadmin(USER.id))).resolves.toEqual(USER);
    expect(api.grantSystemSuperadmin).toHaveBeenCalledWith(USER.id);
    expect(api.revokeSystemSuperadmin).toHaveBeenCalledWith(USER.id);
  });
});
