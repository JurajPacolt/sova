import { ComponentRef } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { of, throwError } from 'rxjs';
import { AccessibleTenant, ProjectListItem } from '../../../../core/api/api.models';
import { SovaApiClient } from '../../../../core/api/sova-api-client.service';
import { TenantStore } from '../../../../core/tenancy/tenant.store';
import { ProjectDetailPageComponent } from './project-detail-page.component';

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

const PROJECT: ProjectListItem = {
  id: '019f9f00-0000-7000-8000-000000000003',
  tenant_id: TENANT.id,
  code: 'APP',
  name: 'Application',
  description: 'Customer facing application.',
  visibility: 'PRIVATE',
  status: 'ACTIVE',
  lead: null,
  member_count: 1,
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
  viewer_roles: ['PROJECT_MANAGER'],
};

const ROLE = {
  id: '019f9f00-0000-7000-8000-000000000004',
  project_id: PROJECT.id,
  code: 'MEMBER',
  name: 'Member',
  description: '',
  status: 'ACTIVE' as const,
  is_system: true,
  is_editable: false,
  revision: 1,
  permissions: ['project.view'],
  assignment_count: 1,
};

const MEMBER = {
  membership_id: '019f9f00-0000-7000-8000-000000000005',
  user: {
    id: '019f9f00-0000-7000-8000-000000000006',
    email: 'petra@example.test',
    display_name: 'Petra Member',
  },
  roles: [{ id: ROLE.id, code: ROLE.code, name: ROLE.name }],
};

const OTHER_MEMBERSHIP = {
  id: '019f9f00-0000-7000-8000-000000000007',
  user: {
    id: '019f9f00-0000-7000-8000-000000000008',
    email: 'jan@example.test',
    display_name: 'Jan Newcomer',
  },
  status: 'ACTIVE' as const,
  joined_at: '2026-07-01T00:00:00+00:00',
  roles: [],
};

const WORKGROUP = {
  id: '019f9f00-0000-7000-8000-000000000009',
  tenant_id: TENANT.id,
  name: 'Auditors',
  description: '',
  status: 'ACTIVE' as const,
  member_count: 1,
  created_at: '2026-07-27T00:00:00+00:00',
  updated_at: '2026-07-27T00:00:00+00:00',
};

describe('ProjectDetailPageComponent', () => {
  const api = {
    listProjects: vi.fn(),
    listProjectRoles: vi.fn(),
    listProjectMembers: vi.fn(),
    listProjectWorkgroups: vi.fn(),
    listTenantMemberships: vi.fn(),
    listWorkgroups: vi.fn(),
    changeProjectStatus: vi.fn(),
    changeProjectVisibility: vi.fn(),
    assignProjectRole: vi.fn(),
    unassignProjectRole: vi.fn(),
    linkProjectWorkgroup: vi.fn(),
    unlinkProjectWorkgroup: vi.fn(),
  };

  beforeEach(async () => {
    for (const mock of Object.values(api)) {
      mock.mockReset();
    }
    api.listProjects.mockReturnValue(of({ projects: [PROJECT] }));
    api.listProjectRoles.mockReturnValue(of({ roles: [ROLE] }));
    api.listProjectMembers.mockReturnValue(of({ members: [MEMBER] }));
    api.listProjectWorkgroups.mockReturnValue(of({ workgroups: [] }));
    api.listTenantMemberships.mockReturnValue(of({ memberships: [OTHER_MEMBERSHIP] }));
    api.listWorkgroups.mockReturnValue(of({ workgroups: [WORKGROUP] }));

    await TestBed.configureTestingModule({
      imports: [ProjectDetailPageComponent],
      providers: [provideRouter([]), { provide: SovaApiClient, useValue: api }],
    }).compileComponents();

    const tenantStore = TestBed.inject(TenantStore);
    tenantStore.setTenants([TENANT]);
    tenantStore.setActiveTenant(TENANT);
  });

  function render(): {
    element: HTMLElement;
    detectChanges: () => void;
    componentRef: ComponentRef<ProjectDetailPageComponent>;
  } {
    const fixture = TestBed.createComponent(ProjectDetailPageComponent);
    fixture.componentRef.setInput('projectId', PROJECT.id);
    fixture.detectChanges();

    return {
      element: fixture.nativeElement as HTMLElement,
      detectChanges: () => fixture.detectChanges(),
      componentRef: fixture.componentRef,
    };
  }

  it('resolves the project from the visibility-scoped listing', () => {
    const { element } = render();

    expect(api.listProjects).toHaveBeenCalledWith(TENANT.id);
    expect(api.listProjectMembers).toHaveBeenCalledWith(TENANT.id, PROJECT.id);
    const text = element.textContent ?? '';
    expect(text).toContain('Application');
    expect(text).toContain('Petra Member');
    expect(text).toContain('Private');
  });

  it('reports a project the caller cannot see', () => {
    api.listProjects.mockReturnValue(of({ projects: [] }));

    const { element } = render();

    expect(element.textContent ?? '').toContain('The project was not found or you cannot see it.');
    expect(api.listProjectMembers).not.toHaveBeenCalled();
  });

  it('keeps the header usable when the detail sections are forbidden', () => {
    // What `HttpClient` actually emits: the section decides on the status, so
    // a plain `{ status }` stub would prove nothing about production.
    const forbidden = (): never => {
      throw new HttpErrorResponse({ status: 403, statusText: 'Forbidden' });
    };
    api.listProjectMembers.mockReturnValue(throwError(forbidden));
    api.listProjectRoles.mockReturnValue(throwError(forbidden));
    api.listProjectWorkgroups.mockReturnValue(throwError(forbidden));

    const { element } = render();

    const text = element.textContent ?? '';
    expect(text).toContain('Application');
    expect(text).toContain('You can see this project, but not its members and roles.');
    expect(element.querySelector('#assign-membership')).toBeNull();
  });

  it('assigns a project role to a tenant member', () => {
    api.assignProjectRole.mockReturnValue(of(undefined));

    const { element, detectChanges } = render();

    const membership = element.querySelector<HTMLSelectElement>('#assign-membership');
    membership!.value = OTHER_MEMBERSHIP.id;
    membership!.dispatchEvent(new Event('change'));
    const role = element.querySelector<HTMLSelectElement>('#assign-role');
    role!.value = ROLE.id;
    role!.dispatchEvent(new Event('change'));
    detectChanges();

    const forms = element.querySelectorAll('form');
    forms[0].dispatchEvent(new Event('submit', { cancelable: true }));

    expect(api.assignProjectRole).toHaveBeenCalledWith(
      TENANT.id,
      PROJECT.id,
      OTHER_MEMBERSHIP.id,
      ROLE.id,
    );
  });

  it('links a workgroup with the chosen project role', () => {
    api.linkProjectWorkgroup.mockReturnValue(of(undefined));

    const { element, detectChanges } = render();

    const workgroup = element.querySelector<HTMLSelectElement>('#link-workgroup');
    workgroup!.value = WORKGROUP.id;
    workgroup!.dispatchEvent(new Event('change'));
    const role = element.querySelector<HTMLSelectElement>('#link-role');
    role!.value = ROLE.id;
    role!.dispatchEvent(new Event('change'));
    detectChanges();

    const forms = element.querySelectorAll('form');
    forms[1].dispatchEvent(new Event('submit', { cancelable: true }));

    expect(api.linkProjectWorkgroup).toHaveBeenCalledWith(TENANT.id, PROJECT.id, WORKGROUP.id, {
      role_id: ROLE.id,
    });
  });

  it('archives the project from the header action', () => {
    api.changeProjectStatus.mockReturnValue(of({ project: { ...PROJECT, status: 'ARCHIVED' } }));

    const { element, detectChanges } = render();
    const archiveButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Archive',
    );
    archiveButton!.dispatchEvent(new Event('click'));
    detectChanges();

    expect(api.changeProjectStatus).toHaveBeenCalledWith(TENANT.id, PROJECT.id, {
      status: 'ARCHIVED',
    });
    expect(element.textContent ?? '').toContain('Reactivate');
  });

  it('makes a private project tenant-visible without a destructive confirmation', () => {
    api.changeProjectVisibility.mockReturnValue(
      of({ project: { ...PROJECT, visibility: 'TENANT' } }),
    );

    const { element, detectChanges } = render();
    const visibilityButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Make tenant-wide',
    );
    visibilityButton!.dispatchEvent(new Event('click'));
    detectChanges();

    expect(api.changeProjectVisibility).toHaveBeenCalledWith(TENANT.id, PROJECT.id, {
      visibility: 'TENANT',
    });
    expect(element.textContent ?? '').toContain('Make private');
  });

  it('previews the access impact before making a project private', () => {
    api.listProjects.mockReturnValue(
      of({ projects: [{ ...PROJECT, visibility: 'TENANT' as const }] }),
    );
    api.changeProjectVisibility.mockReturnValue(
      of({ project: { ...PROJECT, visibility: 'PRIVATE' } }),
    );

    const { element, detectChanges } = render();
    const visibilityButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Make private',
    );
    visibilityButton!.dispatchEvent(new Event('click'));
    detectChanges();

    expect(api.changeProjectVisibility).not.toHaveBeenCalled();
    expect(element.textContent ?? '').toContain(
      'Tenant members without an explicit project role or linked workgroup will lose access.',
    );

    const confirmButton = Array.from(element.querySelectorAll('button')).find(
      (button) => button.textContent?.trim() === 'Confirm private visibility',
    );
    confirmButton!.dispatchEvent(new Event('click'));

    expect(api.changeProjectVisibility).toHaveBeenCalledWith(TENANT.id, PROJECT.id, {
      visibility: 'PRIVATE',
    });
  });
});
