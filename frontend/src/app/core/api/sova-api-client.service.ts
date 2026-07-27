import { HttpClient, HttpParams, HttpResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AcceptedEmailRequest,
  AcceptNewAccountInvitationRequest,
  ChangeSystemTenantStatusRequest,
  ChangeSystemUserStatusRequest,
  ChangeTenantMembershipStatusRequest,
  ChangeWorkgroupStatusRequest,
  CreatedInvitationResponse,
  CreateInvitationRequest,
  CreateSystemTenantRequest,
  CreateSystemTenantResponse,
  CreateTenantRoleRequest,
  CreateWorkgroupRequest,
  CurrentSessionResponse,
  EmailRequest,
  InvitationAcceptanceResponse,
  InvitationPreviewResponse,
  InvitationTokenRequest,
  LoginRequest,
  LoginResponse,
  ResetPasswordRequest,
  SecurityAuditPage,
  SecurityAuditQuery,
  StartImpersonationRequest,
  StartImpersonationResponse,
  SystemTenantList,
  SystemTenantResponse,
  SystemUserList,
  SystemUserResponse,
  TenantList,
  TenantMembershipList,
  TenantMembershipResponse,
  TenantResponse,
  TenantRoleList,
  TenantRoleResponse,
  UpdateTenantRoleRequest,
  UpsertWorkgroupMemberRequest,
  VerifyEmailRequest,
  VerifyEmailResponse,
  WorkgroupList,
  WorkgroupMemberList,
  WorkgroupMemberResponse,
  WorkgroupResponse,
} from './api.models';

const API_ROOT = '/api/v1';

@Injectable({
  providedIn: 'root',
})
export class SovaApiClient {
  private readonly http = inject(HttpClient);

  login(request: LoginRequest): Observable<LoginResponse> {
    return this.http.post<LoginResponse>(`${API_ROOT}/auth/login`, request);
  }

  getCurrentSession(): Observable<CurrentSessionResponse> {
    return this.http.get<CurrentSessionResponse>(`${API_ROOT}/auth/session`);
  }

  logout(): Observable<void> {
    return this.http.post<void>(`${API_ROOT}/auth/logout`, null);
  }

  requestPasswordReset(request: EmailRequest): Observable<AcceptedEmailRequest> {
    return this.http.post<AcceptedEmailRequest>(`${API_ROOT}/auth/password/forgot`, request);
  }

  resetPassword(request: ResetPasswordRequest): Observable<void> {
    return this.http.post<void>(`${API_ROOT}/auth/password/reset`, request);
  }

  requestEmailVerification(request: EmailRequest): Observable<AcceptedEmailRequest> {
    return this.http.post<AcceptedEmailRequest>(
      `${API_ROOT}/auth/email/verification/request`,
      request,
    );
  }

  verifyEmail(request: VerifyEmailRequest): Observable<VerifyEmailResponse> {
    return this.http.post<VerifyEmailResponse>(`${API_ROOT}/auth/email/verify`, request);
  }

  inspectInvitation(request: InvitationTokenRequest): Observable<InvitationPreviewResponse> {
    return this.http.post<InvitationPreviewResponse>(
      `${API_ROOT}/auth/invitations/inspect`,
      request,
    );
  }

  acceptNewAccountInvitation(
    request: AcceptNewAccountInvitationRequest,
  ): Observable<InvitationAcceptanceResponse> {
    return this.http.post<InvitationAcceptanceResponse>(
      `${API_ROOT}/auth/invitations/accept`,
      request,
    );
  }

  acceptExistingAccountInvitation(
    request: InvitationTokenRequest,
  ): Observable<InvitationAcceptanceResponse> {
    return this.http.post<InvitationAcceptanceResponse>(
      `${API_ROOT}/auth/invitations/accept-existing`,
      request,
    );
  }

  listTenants(): Observable<TenantList> {
    return this.http.get<TenantList>(`${API_ROOT}/tenants`);
  }

  getTenant(tenantId: string): Observable<TenantResponse> {
    return this.http.get<TenantResponse>(`${API_ROOT}/tenants/${encodeURIComponent(tenantId)}`);
  }

  listSystemTenants(): Observable<SystemTenantList> {
    return this.http.get<SystemTenantList>(`${API_ROOT}/system/tenants`);
  }

  createSystemTenant(
    request: CreateSystemTenantRequest,
    idempotencyKey: string,
  ): Observable<CreateSystemTenantResponse> {
    return this.http.post<CreateSystemTenantResponse>(`${API_ROOT}/system/tenants`, request, {
      headers: {
        'Idempotency-Key': idempotencyKey,
      },
    });
  }

  changeSystemTenantStatus(
    tenantId: string,
    request: ChangeSystemTenantStatusRequest,
  ): Observable<SystemTenantResponse> {
    return this.http.patch<SystemTenantResponse>(
      `${API_ROOT}/system/tenants/${encodeURIComponent(tenantId)}`,
      request,
    );
  }

  listSystemSecurityAudit(query: SecurityAuditQuery): Observable<SecurityAuditPage> {
    return this.http.get<SecurityAuditPage>(`${API_ROOT}/system/audit`, {
      params: this.auditParams(query),
    });
  }

  listSystemUsers(): Observable<SystemUserList> {
    return this.http.get<SystemUserList>(`${API_ROOT}/system/users`);
  }

  changeSystemUserStatus(
    userId: string,
    request: ChangeSystemUserStatusRequest,
  ): Observable<SystemUserResponse> {
    return this.http.patch<SystemUserResponse>(
      `${API_ROOT}/system/users/${encodeURIComponent(userId)}`,
      request,
    );
  }

  grantSystemSuperadmin(userId: string): Observable<SystemUserResponse> {
    return this.http.put<SystemUserResponse>(
      `${API_ROOT}/system/users/${encodeURIComponent(userId)}/superadmin`,
      null,
    );
  }

  revokeSystemSuperadmin(userId: string): Observable<SystemUserResponse> {
    return this.http.delete<SystemUserResponse>(
      `${API_ROOT}/system/users/${encodeURIComponent(userId)}/superadmin`,
    );
  }

  listTenantMemberships(tenantId: string): Observable<TenantMembershipList> {
    return this.http.get<TenantMembershipList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/memberships`,
    );
  }

  changeTenantMembershipStatus(
    tenantId: string,
    membershipId: string,
    request: ChangeTenantMembershipStatusRequest,
  ): Observable<TenantMembershipResponse> {
    return this.http.patch<TenantMembershipResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/memberships/${encodeURIComponent(membershipId)}`,
      request,
    );
  }

  createTenantInvitation(
    tenantId: string,
    request: CreateInvitationRequest,
  ): Observable<CreatedInvitationResponse> {
    return this.http.post<CreatedInvitationResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/invitations`,
      request,
    );
  }

  listTenantRoles(tenantId: string): Observable<TenantRoleList> {
    return this.http.get<TenantRoleList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/roles`,
    );
  }

  createTenantRole(
    tenantId: string,
    request: CreateTenantRoleRequest,
  ): Observable<TenantRoleResponse> {
    return this.http.post<TenantRoleResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/roles`,
      request,
    );
  }

  updateTenantRole(
    tenantId: string,
    roleId: string,
    request: UpdateTenantRoleRequest,
  ): Observable<TenantRoleResponse> {
    return this.http.put<TenantRoleResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/roles/${encodeURIComponent(roleId)}`,
      request,
    );
  }

  archiveTenantRole(tenantId: string, roleId: string): Observable<void> {
    return this.http.delete<void>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/roles/${encodeURIComponent(roleId)}`,
    );
  }

  assignTenantRole(tenantId: string, membershipId: string, roleId: string): Observable<void> {
    return this.http.put<void>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/memberships/${encodeURIComponent(membershipId)}/roles/${encodeURIComponent(roleId)}`,
      null,
    );
  }

  unassignTenantRole(tenantId: string, membershipId: string, roleId: string): Observable<void> {
    return this.http.delete<void>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/memberships/${encodeURIComponent(membershipId)}/roles/${encodeURIComponent(roleId)}`,
    );
  }

  startImpersonation(request: StartImpersonationRequest): Observable<StartImpersonationResponse> {
    return this.http.post<StartImpersonationResponse>(`${API_ROOT}/system/impersonations`, request);
  }

  endCurrentImpersonation(): Observable<void> {
    return this.http.delete<void>(`${API_ROOT}/system/impersonations/current`);
  }

  listTenantSecurityAudit(
    tenantId: string,
    query: SecurityAuditQuery,
  ): Observable<SecurityAuditPage> {
    return this.http.get<SecurityAuditPage>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/audit`,
      {
        params: this.auditParams(query),
      },
    );
  }

  exportTenantSecurityAudit(
    tenantId: string,
    query: SecurityAuditQuery,
  ): Observable<HttpResponse<Blob>> {
    return this.http.get(`${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/audit/export`, {
      params: this.auditParams(query),
      observe: 'response',
      responseType: 'blob',
    });
  }

  listWorkgroups(tenantId: string): Observable<WorkgroupList> {
    return this.http.get<WorkgroupList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/workgroups`,
    );
  }

  createWorkgroup(
    tenantId: string,
    request: CreateWorkgroupRequest,
  ): Observable<WorkgroupResponse> {
    return this.http.post<WorkgroupResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/workgroups`,
      request,
    );
  }

  changeWorkgroupStatus(
    tenantId: string,
    workgroupId: string,
    request: ChangeWorkgroupStatusRequest,
  ): Observable<WorkgroupResponse> {
    return this.http.patch<WorkgroupResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/workgroups/${encodeURIComponent(workgroupId)}`,
      request,
    );
  }

  listWorkgroupMembers(tenantId: string, workgroupId: string): Observable<WorkgroupMemberList> {
    return this.http.get<WorkgroupMemberList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/workgroups/${encodeURIComponent(workgroupId)}/members`,
    );
  }

  upsertWorkgroupMember(
    tenantId: string,
    workgroupId: string,
    membershipId: string,
    request: UpsertWorkgroupMemberRequest,
  ): Observable<WorkgroupMemberResponse> {
    return this.http.put<WorkgroupMemberResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/workgroups/${encodeURIComponent(workgroupId)}/members/${encodeURIComponent(membershipId)}`,
      request,
    );
  }

  removeWorkgroupMember(
    tenantId: string,
    workgroupId: string,
    membershipId: string,
  ): Observable<void> {
    return this.http.delete<void>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/workgroups/${encodeURIComponent(workgroupId)}/members/${encodeURIComponent(membershipId)}`,
    );
  }

  private auditParams(query: SecurityAuditQuery): HttpParams {
    let params = new HttpParams();

    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    }

    return params;
  }
}
