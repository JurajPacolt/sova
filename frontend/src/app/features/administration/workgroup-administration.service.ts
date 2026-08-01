import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  CreateWorkgroupRequest,
  Workgroup,
  WorkgroupMember,
  WorkgroupMemberRole,
  WorkgroupStatus,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

@Injectable({
  providedIn: 'root',
})
export class WorkgroupAdministrationService {
  private readonly api = inject(SovaApiClient);

  list(tenantId: string): Observable<readonly Workgroup[]> {
    return this.api.listWorkgroups(tenantId).pipe(map((response) => response.workgroups));
  }

  create(tenantId: string, request: CreateWorkgroupRequest): Observable<Workgroup> {
    return this.api.createWorkgroup(tenantId, request).pipe(map((response) => response.workgroup));
  }

  changeStatus(
    tenantId: string,
    workgroupId: string,
    status: WorkgroupStatus,
  ): Observable<Workgroup> {
    return this.api
      .changeWorkgroupStatus(tenantId, workgroupId, { status })
      .pipe(map((response) => response.workgroup));
  }

  listMembers(tenantId: string, workgroupId: string): Observable<readonly WorkgroupMember[]> {
    return this.api
      .listWorkgroupMembers(tenantId, workgroupId)
      .pipe(map((response) => response.members));
  }

  upsertMember(
    tenantId: string,
    workgroupId: string,
    membershipId: string,
    role: WorkgroupMemberRole,
  ): Observable<WorkgroupMember> {
    return this.api
      .upsertWorkgroupMember(tenantId, workgroupId, membershipId, { role })
      .pipe(map((response) => response.member));
  }

  removeMember(tenantId: string, workgroupId: string, membershipId: string): Observable<void> {
    return this.api.removeWorkgroupMember(tenantId, workgroupId, membershipId);
  }
}
