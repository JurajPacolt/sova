import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  CreateProjectRequest,
  Project,
  ProjectListItem,
  ProjectMember,
  ProjectRole,
  ProjectStatus,
  ProjectVisibility,
  ProjectWorkgroupLink,
  TenantMembership,
  Workgroup,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

/**
 * Project reads and writes for the project feature. Memberships and workgroups
 * are fetched straight from the API client so this feature never reaches into
 * the administration feature.
 */
@Injectable({
  providedIn: 'root',
})
export class ProjectAdministrationService {
  private readonly api = inject(SovaApiClient);

  list(tenantId: string): Observable<readonly ProjectListItem[]> {
    return this.api.listProjects(tenantId).pipe(map((response) => response.projects));
  }

  create(tenantId: string, request: CreateProjectRequest): Observable<Project> {
    return this.api.createProject(tenantId, request).pipe(map((response) => response.project));
  }

  changeStatus(tenantId: string, projectId: string, status: ProjectStatus): Observable<Project> {
    return this.api
      .changeProjectStatus(tenantId, projectId, { status })
      .pipe(map((response) => response.project));
  }

  changeVisibility(
    tenantId: string,
    projectId: string,
    visibility: ProjectVisibility,
  ): Observable<Project> {
    return this.api
      .changeProjectVisibility(tenantId, projectId, { visibility })
      .pipe(map((response) => response.project));
  }

  listRoles(tenantId: string, projectId: string): Observable<readonly ProjectRole[]> {
    return this.api.listProjectRoles(tenantId, projectId).pipe(map((response) => response.roles));
  }

  listMembers(tenantId: string, projectId: string): Observable<readonly ProjectMember[]> {
    return this.api
      .listProjectMembers(tenantId, projectId)
      .pipe(map((response) => response.members));
  }

  assignRole(
    tenantId: string,
    projectId: string,
    membershipId: string,
    roleId: string,
  ): Observable<void> {
    return this.api.assignProjectRole(tenantId, projectId, membershipId, roleId);
  }

  unassignRole(
    tenantId: string,
    projectId: string,
    membershipId: string,
    roleId: string,
  ): Observable<void> {
    return this.api.unassignProjectRole(tenantId, projectId, membershipId, roleId);
  }

  listWorkgroupLinks(
    tenantId: string,
    projectId: string,
  ): Observable<readonly ProjectWorkgroupLink[]> {
    return this.api
      .listProjectWorkgroups(tenantId, projectId)
      .pipe(map((response) => response.workgroups));
  }

  linkWorkgroup(
    tenantId: string,
    projectId: string,
    workgroupId: string,
    roleId: string,
  ): Observable<void> {
    return this.api.linkProjectWorkgroup(tenantId, projectId, workgroupId, { role_id: roleId });
  }

  unlinkWorkgroup(tenantId: string, projectId: string, workgroupId: string): Observable<void> {
    return this.api.unlinkProjectWorkgroup(tenantId, projectId, workgroupId);
  }

  /** Active tenant memberships, the only ones that may lead or join a project. */
  listActiveMemberships(tenantId: string): Observable<readonly TenantMembership[]> {
    return this.api
      .listTenantMemberships(tenantId)
      .pipe(
        map((response) =>
          response.memberships.filter((membership) => membership.status === 'ACTIVE'),
        ),
      );
  }

  /** Active workgroups, the only ones that may be linked to a project. */
  listActiveWorkgroups(tenantId: string): Observable<readonly Workgroup[]> {
    return this.api
      .listWorkgroups(tenantId)
      .pipe(
        map((response) => response.workgroups.filter((workgroup) => workgroup.status === 'ACTIVE')),
      );
  }
}
