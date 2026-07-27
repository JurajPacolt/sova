import { TestBed } from '@angular/core/testing';
import { firstValueFrom, of } from 'rxjs';
import { TenantRole } from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';
import { TenantRoleAdministrationService } from './tenant-role-administration.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const ROLE_ID = '019f9f00-0000-7000-8000-000000000002';

const ROLE: TenantRole = {
  id: ROLE_ID,
  code: 'CUSTOM_REVIEWER',
  name: 'Reviewer',
  description: 'Reviews issues.',
  status: 'ACTIVE',
  is_system: false,
  is_editable: true,
  revision: 1,
  permissions: ['project.view', 'issue.view'],
  assignment_count: 0,
};

describe('TenantRoleAdministrationService', () => {
  const api = {
    listTenantRoles: vi.fn(),
    createTenantRole: vi.fn(),
    updateTenantRole: vi.fn(),
    archiveTenantRole: vi.fn(),
  };
  let service: TenantRoleAdministrationService;

  beforeEach(() => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    TestBed.configureTestingModule({
      providers: [
        TenantRoleAdministrationService,
        {
          provide: SovaApiClient,
          useValue: api,
        },
      ],
    });
    service = TestBed.inject(TenantRoleAdministrationService);
  });

  it('returns the roles and permission catalog envelope unchanged', async () => {
    const roleList = { roles: [ROLE], permissions: [] };
    api.listTenantRoles.mockReturnValue(of(roleList));

    await expect(firstValueFrom(service.list(TENANT_ID))).resolves.toEqual(roleList);
  });

  it('unwraps the created role from the create request', async () => {
    const request = {
      code: 'CUSTOM_REVIEWER',
      name: 'Reviewer',
      description: 'Reviews issues.',
      permissions: ['project.view', 'issue.view'],
    };
    api.createTenantRole.mockReturnValue(of({ role: ROLE }));

    await expect(firstValueFrom(service.create(TENANT_ID, request))).resolves.toEqual(ROLE);
    expect(api.createTenantRole).toHaveBeenCalledWith(TENANT_ID, request);
  });

  it('unwraps the updated role and forwards the optimistic revision', async () => {
    const updated = { ...ROLE, name: 'Senior reviewer', revision: 2 };
    const request = {
      name: 'Senior reviewer',
      description: 'Reviews issues.',
      permissions: ['project.view', 'issue.view'],
      revision: 1,
    };
    api.updateTenantRole.mockReturnValue(of({ role: updated }));

    await expect(firstValueFrom(service.update(TENANT_ID, ROLE_ID, request))).resolves.toEqual(
      updated,
    );
    expect(api.updateTenantRole).toHaveBeenCalledWith(TENANT_ID, ROLE_ID, request);
  });

  it('delegates archival with the tenant and role IDs', async () => {
    api.archiveTenantRole.mockReturnValue(of(undefined));

    await firstValueFrom(service.archive(TENANT_ID, ROLE_ID));

    expect(api.archiveTenantRole).toHaveBeenCalledWith(TENANT_ID, ROLE_ID);
  });
});
