import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  CreateTenantRoleRequest,
  TenantRole,
  TenantRoleList,
  UpdateTenantRoleRequest,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

@Injectable({
  providedIn: 'root',
})
export class TenantRoleAdministrationService {
  private readonly api = inject(SovaApiClient);

  list(tenantId: string): Observable<TenantRoleList> {
    return this.api.listTenantRoles(tenantId);
  }

  create(tenantId: string, request: CreateTenantRoleRequest): Observable<TenantRole> {
    return this.api.createTenantRole(tenantId, request).pipe(map((response) => response.role));
  }

  update(
    tenantId: string,
    roleId: string,
    request: UpdateTenantRoleRequest,
  ): Observable<TenantRole> {
    return this.api
      .updateTenantRole(tenantId, roleId, request)
      .pipe(map((response) => response.role));
  }

  archive(tenantId: string, roleId: string): Observable<void> {
    return this.api.archiveTenantRole(tenantId, roleId);
  }
}
