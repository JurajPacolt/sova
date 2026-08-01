import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  ChangeSystemTenantStatusRequest,
  CreateSystemTenantRequest,
  CreateSystemTenantResponse,
  SystemTenant,
  TenantMembership,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

@Injectable({
  providedIn: 'root',
})
export class SystemTenantAdministrationService {
  private readonly api = inject(SovaApiClient);

  list(): Observable<readonly SystemTenant[]> {
    return this.api.listSystemTenants().pipe(map((response) => response.tenants));
  }

  create(
    request: CreateSystemTenantRequest,
    idempotencyKey: string,
  ): Observable<CreateSystemTenantResponse> {
    return this.api.createSystemTenant(request, idempotencyKey);
  }

  changeStatus(
    tenantId: string,
    request: ChangeSystemTenantStatusRequest,
  ): Observable<SystemTenant> {
    return this.api
      .changeSystemTenantStatus(tenantId, request)
      .pipe(map((response) => response.tenant));
  }

  listActiveMembers(tenantId: string): Observable<readonly TenantMembership[]> {
    return this.api
      .listTenantMemberships(tenantId)
      .pipe(
        map((response) =>
          response.memberships.filter((membership) => membership.status === 'ACTIVE'),
        ),
      );
  }
}
