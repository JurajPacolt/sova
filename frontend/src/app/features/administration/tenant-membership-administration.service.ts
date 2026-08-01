import { inject, Injectable } from '@angular/core';
import { map, Observable } from 'rxjs';
import {
  ChangeTenantMembershipStatusRequest,
  CreatedInvitation,
  TenantInvitation,
  TenantMembership,
} from '../../core/api/api.models';
import { SovaApiClient } from '../../core/api/sova-api-client.service';

@Injectable({
  providedIn: 'root',
})
export class TenantMembershipAdministrationService {
  private readonly api = inject(SovaApiClient);

  list(tenantId: string): Observable<readonly TenantMembership[]> {
    return this.api.listTenantMemberships(tenantId).pipe(map((response) => response.memberships));
  }

  invite(tenantId: string, email: string): Observable<CreatedInvitation> {
    return this.api
      .createTenantInvitation(tenantId, { email })
      .pipe(map((response) => response.invitation));
  }

  listInvitations(tenantId: string): Observable<readonly TenantInvitation[]> {
    return this.api.listTenantInvitations(tenantId).pipe(map((response) => response.invitations));
  }

  changeInvitationExpiry(
    tenantId: string,
    invitationId: string,
    expiresAt: string,
  ): Observable<TenantInvitation> {
    return this.api
      .changeTenantInvitationExpiry(tenantId, invitationId, {
        expires_at: expiresAt,
      })
      .pipe(map((response) => response.invitation));
  }

  resendInvitation(tenantId: string, invitationId: string): Observable<TenantInvitation> {
    return this.api
      .resendTenantInvitation(tenantId, invitationId)
      .pipe(map((response) => response.invitation));
  }

  revokeInvitation(tenantId: string, invitationId: string): Observable<TenantInvitation> {
    return this.api
      .revokeTenantInvitation(tenantId, invitationId)
      .pipe(map((response) => response.invitation));
  }

  changeStatus(
    tenantId: string,
    membershipId: string,
    status: ChangeTenantMembershipStatusRequest['status'],
  ): Observable<TenantMembership> {
    return this.api
      .changeTenantMembershipStatus(tenantId, membershipId, { status })
      .pipe(map((response) => response.membership));
  }

  assignRole(tenantId: string, membershipId: string, roleId: string): Observable<void> {
    return this.api.assignTenantRole(tenantId, membershipId, roleId);
  }

  unassignRole(tenantId: string, membershipId: string, roleId: string): Observable<void> {
    return this.api.unassignTenantRole(tenantId, membershipId, roleId);
  }
}
