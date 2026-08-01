import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ProjectAdministrationService } from './project-administration.service';

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const PROJECT_ID = '019f9f00-0000-7000-8000-000000000002';
const MEMBERSHIP_ID = '019f9f00-0000-7000-8000-000000000003';
const ROLE_ID = '019f9f00-0000-7000-8000-000000000004';
const WORKGROUP_ID = '019f9f00-0000-7000-8000-000000000005';

describe('ProjectAdministrationService', () => {
  let service: ProjectAdministrationService;
  let http: HttpTestingController;

  beforeEach(() => {
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
    service = TestBed.inject(ProjectAdministrationService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => {
    http.verify();
  });

  it('unwraps the project listing', () => {
    let codes: readonly string[] = [];
    service.list(TENANT_ID).subscribe((projects) => {
      codes = projects.map((project) => project.code);
    });

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/projects`);
    expect(request.request.method).toBe('GET');
    request.flush({ projects: [{ code: 'APP', viewer_roles: ['MEMBER'] }] });

    expect(codes).toEqual(['APP']);
  });

  it('assigns a project role through the nested route', () => {
    service.assignRole(TENANT_ID, PROJECT_ID, MEMBERSHIP_ID, ROLE_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}/members/${MEMBERSHIP_ID}/roles/${ROLE_ID}`,
    );
    expect(request.request.method).toBe('PUT');
    request.flush(null);
  });

  it('changes project visibility through the project mutation route', () => {
    service.changeVisibility(TENANT_ID, PROJECT_ID, 'PRIVATE').subscribe();

    const request = http.expectOne(`/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}`);
    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ visibility: 'PRIVATE' });
    request.flush({ project: { id: PROJECT_ID, visibility: 'PRIVATE' } });
  });

  it('unassigns a project role through the nested route', () => {
    service.unassignRole(TENANT_ID, PROJECT_ID, MEMBERSHIP_ID, ROLE_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}/members/${MEMBERSHIP_ID}/roles/${ROLE_ID}`,
    );
    expect(request.request.method).toBe('DELETE');
    request.flush(null);
  });

  it('links a workgroup with the chosen project role', () => {
    service.linkWorkgroup(TENANT_ID, PROJECT_ID, WORKGROUP_ID, ROLE_ID).subscribe();

    const request = http.expectOne(
      `/api/v1/tenants/${TENANT_ID}/projects/${PROJECT_ID}/workgroups/${WORKGROUP_ID}`,
    );
    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ role_id: ROLE_ID });
    request.flush(null);
  });

  it('keeps only active memberships and workgroups for the pickers', () => {
    let membershipIds: readonly string[] = [];
    let workgroupIds: readonly string[] = [];
    service.listActiveMemberships(TENANT_ID).subscribe((memberships) => {
      membershipIds = memberships.map((membership) => membership.id);
    });
    service.listActiveWorkgroups(TENANT_ID).subscribe((workgroups) => {
      workgroupIds = workgroups.map((workgroup) => workgroup.id);
    });

    http.expectOne(`/api/v1/tenants/${TENANT_ID}/memberships`).flush({
      memberships: [
        { id: 'active-membership', status: 'ACTIVE' },
        { id: 'disabled-membership', status: 'DISABLED' },
      ],
    });
    http.expectOne(`/api/v1/tenants/${TENANT_ID}/workgroups`).flush({
      workgroups: [
        { id: 'active-workgroup', status: 'ACTIVE' },
        { id: 'archived-workgroup', status: 'ARCHIVED' },
      ],
    });

    expect(membershipIds).toEqual(['active-membership']);
    expect(workgroupIds).toEqual(['active-workgroup']);
  });
});
