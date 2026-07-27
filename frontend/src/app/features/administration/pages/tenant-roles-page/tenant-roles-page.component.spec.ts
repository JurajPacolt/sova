import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of } from 'rxjs';
import { AccessibleTenant, TenantPermissionDefinition } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { TenantRolesPageComponent } from './tenant-roles-page.component';

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

const PROJECT_VIEW: TenantPermissionDefinition = {
  code: 'project.view',
  scope: 'PROJECT',
  label: 'View project',
  description: 'View a project.',
  sensitive: false,
  dependencies: [],
};

const ISSUE_VIEW: TenantPermissionDefinition = {
  code: 'issue.view',
  scope: 'PROJECT',
  label: 'View issues',
  description: 'View issues.',
  sensitive: false,
  dependencies: ['project.view'],
};

const ISSUE_CREATE: TenantPermissionDefinition = {
  code: 'issue.create',
  scope: 'PROJECT',
  label: 'Create issues',
  description: 'Create issues.',
  sensitive: false,
  dependencies: ['project.view', 'issue.view'],
};

describe('TenantRolesPageComponent', () => {
  const api = {
    listTenantRoles: vi.fn(),
    createTenantRole: vi.fn(),
    updateTenantRole: vi.fn(),
    archiveTenantRole: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listTenantRoles.mockReturnValue(
      of({
        roles: [],
        permissions: [PROJECT_VIEW, ISSUE_VIEW, ISSUE_CREATE],
      }),
    );

    await TestBed.configureTestingModule({
      imports: [TenantRolesPageComponent],
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

  it('loads the role and permission catalog for the active tenant', () => {
    const fixture = TestBed.createComponent(TenantRolesPageComponent);
    fixture.detectChanges();

    expect(api.listTenantRoles).toHaveBeenCalledWith(TENANT.id);
  });

  it('auto-selects transitive dependencies when a permission is checked', () => {
    const fixture = TestBed.createComponent(TenantRolesPageComponent);
    fixture.detectChanges();
    const element = fixture.nativeElement as HTMLElement;
    const createButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Create role',
    );
    createButton?.dispatchEvent(new Event('click'));
    fixture.detectChanges();

    const issueCreateCheckbox = element.querySelector<HTMLInputElement>(
      '#permission-issue\\.create',
    );
    expect(issueCreateCheckbox).not.toBeNull();
    issueCreateCheckbox!.checked = true;
    issueCreateCheckbox!.dispatchEvent(new Event('change'));
    fixture.detectChanges();

    const projectViewCheckbox = element.querySelector<HTMLInputElement>(
      '#permission-project\\.view',
    );
    const issueViewCheckbox = element.querySelector<HTMLInputElement>('#permission-issue\\.view');

    expect(projectViewCheckbox?.checked).toBe(true);
    expect(issueViewCheckbox?.checked).toBe(true);
  });
});
