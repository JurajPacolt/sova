import { HttpClient, HttpParams, HttpResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AcceptedEmailRequest,
  AcceptNewAccountInvitationRequest,
  ArchiveProjectIssueTypeRequest,
  ChangeInvitationExpiryRequest,
  ChangeProjectStatusRequest,
  ChangeProjectVisibilityRequest,
  ChangeSystemTenantStatusRequest,
  ChangeSystemUserStatusRequest,
  ChangeTenantMembershipStatusRequest,
  ChangeWorkgroupStatusRequest,
  CreatedInvitationResponse,
  CreateInvitationRequest,
  CreateProjectIssueTypeRequest,
  CreateProjectRequest,
  CreateSystemTenantRequest,
  CreateSystemTenantResponse,
  CreateTenantRoleRequest,
  CreateIssueLinkRequest,
  CreateIssueRequest,
  CreateWorkgroupRequest,
  IssueAttachmentList,
  IssueAttachmentResponse,
  IssueLinkList,
  IssueQueryMetadata,
  IssueQueryValidationResponse,
  ConfigurationHistoryList,
  ProjectConfiguration,
  ProjectIssueTypeList,
  ProjectIssueTypeResponse,
  PublishedWorkflowResponse,
  PublishWorkflowRequest,
  UpdateWorkflowDraftRequest,
  WorkflowDraftResponse,
  WorkflowImpactResponse,
  WorkflowValidationResponse,
  IssueCommentList,
  IssueCommentRequest,
  IssueCommentResponse,
  IssueHistoryList,
  IssueResponse,
  IssueSearchRequest,
  IssueSearchResponse,
  IssueTransitionList,
  IssueWatcherList,
  IssueWatchState,
  CreateDashboardRequest,
  CreateWidgetRequest,
  CurrentSessionResponse,
  DashboardLayoutRequest,
  DashboardList,
  DashboardResponse,
  DashboardTemplateResponse,
  DashboardWidgetList,
  DashboardWidgetResponse,
  EmailRequest,
  UpdateDashboardRequest,
  UpdateWidgetRequest,
  WidgetTypeList,
  ExecuteIssueTransitionRequest,
  InvitationAcceptanceResponse,
  InvitationPreviewResponse,
  InvitationTokenRequest,
  LoginRequest,
  LoginResponse,
  BeginMfaEnrollmentRequest,
  ConfirmMfaEnrollmentRequest,
  MfaConfirmationResponse,
  MfaEnrollmentResponse,
  MfaRecoveryCodesResponse,
  MfaStatusResponse,
  RegenerateMfaRecoveryCodesRequest,
  MarkNotificationsReadRequest,
  MarkNotificationsReadResponse,
  NotificationList,
  NotificationPreferenceList,
  ReplaceNotificationPreferencesRequest,
  ProjectList,
  ProjectMemberList,
  ProjectResponse,
  ProjectRoleList,
  ProjectWorkgroupLinkList,
  ReplaceSavedQueryGrantsRequest,
  ResetPasswordRequest,
  ArchiveSavedQueryRequest,
  CreateSavedQueryRequest,
  SavedQueryFavouriteState,
  SavedQueryGrantList,
  SavedQueryList,
  SavedQueryResponse,
  UpdateSavedQueryRequest,
  SecurityAuditPage,
  SecurityAuditQuery,
  StartImpersonationRequest,
  StartImpersonationResponse,
  SystemTenantList,
  SystemTenantResponse,
  SystemUserList,
  SystemUserResponse,
  TenantList,
  TenantInvitationList,
  TenantInvitationResponse,
  TenantMembershipList,
  TenantMembershipResponse,
  TenantResponse,
  TenantSettingsResponse,
  TenantRoleList,
  TenantRoleResponse,
  UpdateTenantRoleRequest,
  UpdateTenantGeneralSettingsRequest,
  UpdateTenantLocalizationSettingsRequest,
  UpdateProjectIssueTypeRequest,
  UpsertProjectWorkgroupRequest,
  UpsertWorkgroupMemberRequest,
  VerifyEmailRequest,
  VerifyEmailResponse,
  WorkgroupList,
  WorkgroupMemberList,
  WorkgroupMemberResponse,
  WorkgroupResponse,
  RestoreDashboardTemplateRequest,
  WidgetDataResponse,
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

  getMfaStatus(): Observable<MfaStatusResponse> {
    return this.http.get<MfaStatusResponse>(`${API_ROOT}/auth/mfa`);
  }

  beginMfaEnrollment(request: BeginMfaEnrollmentRequest): Observable<MfaEnrollmentResponse> {
    return this.http.post<MfaEnrollmentResponse>(`${API_ROOT}/auth/mfa/enrollment`, request);
  }

  confirmMfaEnrollment(request: ConfirmMfaEnrollmentRequest): Observable<MfaConfirmationResponse> {
    return this.http.post<MfaConfirmationResponse>(
      `${API_ROOT}/auth/mfa/enrollment/confirm`,
      request,
    );
  }

  regenerateMfaRecoveryCodes(
    request: RegenerateMfaRecoveryCodesRequest,
  ): Observable<MfaRecoveryCodesResponse> {
    return this.http.post<MfaRecoveryCodesResponse>(`${API_ROOT}/auth/mfa/recovery-codes`, request);
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

  getTenantSettings(tenantId: string): Observable<TenantSettingsResponse> {
    return this.http.get<TenantSettingsResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/settings`,
    );
  }

  updateTenantGeneralSettings(
    tenantId: string,
    request: UpdateTenantGeneralSettingsRequest,
  ): Observable<TenantSettingsResponse> {
    return this.http.patch<TenantSettingsResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/settings/general`,
      request,
    );
  }

  updateTenantLocalizationSettings(
    tenantId: string,
    request: UpdateTenantLocalizationSettingsRequest,
  ): Observable<TenantSettingsResponse> {
    return this.http.patch<TenantSettingsResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/settings/localization`,
      request,
    );
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

  listTenantInvitations(tenantId: string): Observable<TenantInvitationList> {
    return this.http.get<TenantInvitationList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/invitations`,
    );
  }

  changeTenantInvitationExpiry(
    tenantId: string,
    invitationId: string,
    request: ChangeInvitationExpiryRequest,
  ): Observable<TenantInvitationResponse> {
    return this.http.patch<TenantInvitationResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/invitations/${encodeURIComponent(invitationId)}`,
      request,
    );
  }

  resendTenantInvitation(
    tenantId: string,
    invitationId: string,
  ): Observable<TenantInvitationResponse> {
    return this.http.post<TenantInvitationResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/invitations/${encodeURIComponent(invitationId)}/resend`,
      null,
    );
  }

  revokeTenantInvitation(
    tenantId: string,
    invitationId: string,
  ): Observable<TenantInvitationResponse> {
    return this.http.delete<TenantInvitationResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/invitations/${encodeURIComponent(invitationId)}`,
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

  listProjects(tenantId: string): Observable<ProjectList> {
    return this.http.get<ProjectList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects`,
    );
  }

  createProject(tenantId: string, request: CreateProjectRequest): Observable<ProjectResponse> {
    return this.http.post<ProjectResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects`,
      request,
    );
  }

  changeProjectStatus(
    tenantId: string,
    projectId: string,
    request: ChangeProjectStatusRequest,
  ): Observable<ProjectResponse> {
    return this.http.patch<ProjectResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects/${encodeURIComponent(projectId)}`,
      request,
    );
  }

  changeProjectVisibility(
    tenantId: string,
    projectId: string,
    request: ChangeProjectVisibilityRequest,
  ): Observable<ProjectResponse> {
    return this.http.patch<ProjectResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects/${encodeURIComponent(projectId)}`,
      request,
    );
  }

  listProjectRoles(tenantId: string, projectId: string): Observable<ProjectRoleList> {
    return this.http.get<ProjectRoleList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects/${encodeURIComponent(projectId)}/roles`,
    );
  }

  listProjectMembers(tenantId: string, projectId: string): Observable<ProjectMemberList> {
    return this.http.get<ProjectMemberList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects/${encodeURIComponent(projectId)}/members`,
    );
  }

  assignProjectRole(
    tenantId: string,
    projectId: string,
    membershipId: string,
    roleId: string,
  ): Observable<void> {
    return this.http.put<void>(this.projectRolePath(tenantId, projectId, membershipId, roleId), {});
  }

  unassignProjectRole(
    tenantId: string,
    projectId: string,
    membershipId: string,
    roleId: string,
  ): Observable<void> {
    return this.http.delete<void>(this.projectRolePath(tenantId, projectId, membershipId, roleId));
  }

  listProjectWorkgroups(tenantId: string, projectId: string): Observable<ProjectWorkgroupLinkList> {
    return this.http.get<ProjectWorkgroupLinkList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/projects/${encodeURIComponent(projectId)}/workgroups`,
    );
  }

  linkProjectWorkgroup(
    tenantId: string,
    projectId: string,
    workgroupId: string,
    request: UpsertProjectWorkgroupRequest,
  ): Observable<void> {
    return this.http.put<void>(
      this.projectWorkgroupPath(tenantId, projectId, workgroupId),
      request,
    );
  }

  unlinkProjectWorkgroup(
    tenantId: string,
    projectId: string,
    workgroupId: string,
  ): Observable<void> {
    return this.http.delete<void>(this.projectWorkgroupPath(tenantId, projectId, workgroupId));
  }

  private projectRolePath(
    tenantId: string,
    projectId: string,
    membershipId: string,
    roleId: string,
  ): string {
    return (
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}` +
      `/projects/${encodeURIComponent(projectId)}` +
      `/members/${encodeURIComponent(membershipId)}` +
      `/roles/${encodeURIComponent(roleId)}`
    );
  }

  searchIssues(tenantId: string, request: IssueSearchRequest): Observable<IssueSearchResponse> {
    return this.http.post<IssueSearchResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/issues/search`,
      request,
    );
  }

  getIssue(tenantId: string, issueId: string): Observable<IssueResponse> {
    return this.http.get<IssueResponse>(this.issuePath(tenantId, issueId));
  }

  listIssueTransitions(tenantId: string, issueId: string): Observable<IssueTransitionList> {
    return this.http.get<IssueTransitionList>(`${this.issuePath(tenantId, issueId)}/transitions`);
  }

  executeIssueTransition(
    tenantId: string,
    issueId: string,
    transitionId: string,
    request: ExecuteIssueTransitionRequest,
  ): Observable<IssueResponse> {
    return this.http.post<IssueResponse>(
      `${this.issuePath(tenantId, issueId)}/transitions/${encodeURIComponent(transitionId)}`,
      request,
    );
  }

  listIssueComments(tenantId: string, issueId: string): Observable<IssueCommentList> {
    return this.http.get<IssueCommentList>(`${this.issuePath(tenantId, issueId)}/comments`);
  }

  createIssueComment(
    tenantId: string,
    issueId: string,
    request: IssueCommentRequest,
  ): Observable<IssueCommentResponse> {
    return this.http.post<IssueCommentResponse>(
      `${this.issuePath(tenantId, issueId)}/comments`,
      request,
    );
  }

  deleteIssueComment(tenantId: string, issueId: string, commentId: string): Observable<void> {
    return this.http.delete<void>(
      `${this.issuePath(tenantId, issueId)}/comments/${encodeURIComponent(commentId)}`,
    );
  }

  listIssueHistory(tenantId: string, issueId: string): Observable<IssueHistoryList> {
    return this.http.get<IssueHistoryList>(`${this.issuePath(tenantId, issueId)}/history`);
  }

  listIssueWatchers(tenantId: string, issueId: string): Observable<IssueWatcherList> {
    return this.http.get<IssueWatcherList>(`${this.issuePath(tenantId, issueId)}/watchers`);
  }

  watchIssue(tenantId: string, issueId: string): Observable<IssueWatchState> {
    return this.http.put<IssueWatchState>(`${this.issuePath(tenantId, issueId)}/watchers/me`, {});
  }

  unwatchIssue(tenantId: string, issueId: string): Observable<IssueWatchState> {
    return this.http.delete<IssueWatchState>(`${this.issuePath(tenantId, issueId)}/watchers/me`);
  }

  validateIssueQuery(tenantId: string, query: string): Observable<IssueQueryValidationResponse> {
    return this.http.post<IssueQueryValidationResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/issue-query/validate`,
      { query },
    );
  }

  getIssueQueryMetadata(tenantId: string): Observable<IssueQueryMetadata> {
    return this.http.get<IssueQueryMetadata>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/issue-query/metadata`,
    );
  }

  getProjectConfiguration(tenantId: string, projectId: string): Observable<ProjectConfiguration> {
    return this.http.get<ProjectConfiguration>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}` +
        `/projects/${encodeURIComponent(projectId)}/configuration`,
    );
  }

  getProjectConfigurationHistory(
    tenantId: string,
    projectId: string,
  ): Observable<ConfigurationHistoryList> {
    return this.http.get<ConfigurationHistoryList>(
      `${this.projectPath(tenantId, projectId)}/configuration/history`,
    );
  }

  listProjectIssueTypes(tenantId: string, projectId: string): Observable<ProjectIssueTypeList> {
    return this.http.get<ProjectIssueTypeList>(this.issueTypesPath(tenantId, projectId));
  }

  createProjectIssueType(
    tenantId: string,
    projectId: string,
    request: CreateProjectIssueTypeRequest,
  ): Observable<ProjectIssueTypeResponse> {
    return this.http.post<ProjectIssueTypeResponse>(
      this.issueTypesPath(tenantId, projectId),
      request,
    );
  }

  updateProjectIssueType(
    tenantId: string,
    projectId: string,
    issueTypeId: string,
    request: UpdateProjectIssueTypeRequest,
  ): Observable<ProjectIssueTypeResponse> {
    return this.http.patch<ProjectIssueTypeResponse>(
      `${this.issueTypesPath(tenantId, projectId)}/${encodeURIComponent(issueTypeId)}`,
      request,
    );
  }

  archiveProjectIssueType(
    tenantId: string,
    projectId: string,
    issueTypeId: string,
    request: ArchiveProjectIssueTypeRequest,
  ): Observable<ProjectIssueTypeResponse> {
    return this.http.post<ProjectIssueTypeResponse>(
      `${this.issueTypesPath(tenantId, projectId)}/${encodeURIComponent(issueTypeId)}/archive`,
      request,
    );
  }

  /** Copies the published version into the single editable draft. */
  createWorkflowDraft(
    tenantId: string,
    projectId: string,
    workflowId: string,
  ): Observable<WorkflowDraftResponse> {
    return this.http.post<WorkflowDraftResponse>(
      `${this.workflowPath(tenantId, projectId, workflowId)}/draft`,
      {},
    );
  }

  /** Replaces the draft's whole content against its optimistic version. */
  updateWorkflowDraft(
    tenantId: string,
    projectId: string,
    workflowId: string,
    request: UpdateWorkflowDraftRequest,
  ): Observable<WorkflowDraftResponse> {
    return this.http.put<WorkflowDraftResponse>(
      `${this.workflowPath(tenantId, projectId, workflowId)}/draft`,
      request,
    );
  }

  validateWorkflowDraft(
    tenantId: string,
    projectId: string,
    workflowId: string,
  ): Observable<WorkflowValidationResponse> {
    return this.http.get<WorkflowValidationResponse>(
      `${this.workflowPath(tenantId, projectId, workflowId)}/validation`,
    );
  }

  getWorkflowImpact(
    tenantId: string,
    projectId: string,
    workflowId: string,
  ): Observable<WorkflowImpactResponse> {
    return this.http.get<WorkflowImpactResponse>(
      `${this.workflowPath(tenantId, projectId, workflowId)}/impact`,
    );
  }

  publishWorkflow(
    tenantId: string,
    projectId: string,
    workflowId: string,
    request: PublishWorkflowRequest,
  ): Observable<PublishedWorkflowResponse> {
    return this.http.post<PublishedWorkflowResponse>(
      `${this.workflowPath(tenantId, projectId, workflowId)}/publish`,
      request,
    );
  }

  private projectPath(tenantId: string, projectId: string): string {
    return (
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}` +
      `/projects/${encodeURIComponent(projectId)}`
    );
  }

  private workflowPath(tenantId: string, projectId: string, workflowId: string): string {
    return `${this.projectPath(tenantId, projectId)}/workflows/${encodeURIComponent(workflowId)}`;
  }

  private issueTypesPath(tenantId: string, projectId: string): string {
    return `${this.projectPath(tenantId, projectId)}/issue-types`;
  }

  createIssue(
    tenantId: string,
    projectId: string,
    request: CreateIssueRequest,
  ): Observable<IssueResponse> {
    return this.http.post<IssueResponse>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}` +
        `/projects/${encodeURIComponent(projectId)}/issues`,
      request,
    );
  }

  listIssueAttachments(tenantId: string, issueId: string): Observable<IssueAttachmentList> {
    return this.http.get<IssueAttachmentList>(`${this.issuePath(tenantId, issueId)}/attachments`);
  }

  uploadIssueAttachment(
    tenantId: string,
    issueId: string,
    file: File,
  ): Observable<IssueAttachmentResponse> {
    const form = new FormData();
    form.append('file', file, file.name);

    // No explicit Content-Type: the browser has to set the multipart boundary
    // itself, and overriding it produces a request the server cannot parse.
    return this.http.post<IssueAttachmentResponse>(
      `${this.issuePath(tenantId, issueId)}/attachments`,
      form,
    );
  }

  /**
   * Downloads go through the HTTP client rather than a plain anchor so they
   * travel the same authenticated path as every other call; the endpoint
   * authorises each one and always answers as a download.
   */
  downloadIssueAttachment(
    tenantId: string,
    issueId: string,
    attachmentId: string,
  ): Observable<Blob> {
    return this.http.get(this.attachmentPath(tenantId, issueId, attachmentId), {
      responseType: 'blob',
    });
  }

  deleteIssueAttachment(tenantId: string, issueId: string, attachmentId: string): Observable<void> {
    return this.http.delete<void>(this.attachmentPath(tenantId, issueId, attachmentId));
  }

  listIssueLinks(tenantId: string, issueId: string): Observable<IssueLinkList> {
    return this.http.get<IssueLinkList>(`${this.issuePath(tenantId, issueId)}/links`);
  }

  createIssueLink(
    tenantId: string,
    issueId: string,
    request: CreateIssueLinkRequest,
  ): Observable<IssueLinkList> {
    return this.http.post<IssueLinkList>(`${this.issuePath(tenantId, issueId)}/links`, request);
  }

  deleteIssueLink(tenantId: string, issueId: string, linkId: string): Observable<void> {
    return this.http.delete<void>(
      `${this.issuePath(tenantId, issueId)}/links/${encodeURIComponent(linkId)}`,
    );
  }

  listSavedQueries(tenantId: string): Observable<SavedQueryList> {
    return this.http.get<SavedQueryList>(this.savedQueriesPath(tenantId));
  }

  createSavedQuery(
    tenantId: string,
    request: CreateSavedQueryRequest,
  ): Observable<SavedQueryResponse> {
    return this.http.post<SavedQueryResponse>(this.savedQueriesPath(tenantId), request);
  }

  updateSavedQuery(
    tenantId: string,
    savedQueryId: string,
    request: UpdateSavedQueryRequest,
  ): Observable<SavedQueryResponse> {
    return this.http.patch<SavedQueryResponse>(
      this.savedQueryPath(tenantId, savedQueryId),
      request,
    );
  }

  archiveSavedQuery(
    tenantId: string,
    savedQueryId: string,
    request: ArchiveSavedQueryRequest,
  ): Observable<SavedQueryResponse> {
    return this.http.post<SavedQueryResponse>(
      `${this.savedQueryPath(tenantId, savedQueryId)}/archive`,
      request,
    );
  }

  listSavedQueryGrants(tenantId: string, savedQueryId: string): Observable<SavedQueryGrantList> {
    return this.http.get<SavedQueryGrantList>(
      `${this.savedQueryPath(tenantId, savedQueryId)}/grants`,
    );
  }

  /** Replaces the whole set: an entry left out really loses access. */
  replaceSavedQueryGrants(
    tenantId: string,
    savedQueryId: string,
    request: ReplaceSavedQueryGrantsRequest,
  ): Observable<SavedQueryGrantList> {
    return this.http.put<SavedQueryGrantList>(
      `${this.savedQueryPath(tenantId, savedQueryId)}/grants`,
      request,
    );
  }

  addSavedQueryFavourite(
    tenantId: string,
    savedQueryId: string,
  ): Observable<SavedQueryFavouriteState> {
    return this.http.put<SavedQueryFavouriteState>(
      `${this.savedQueryPath(tenantId, savedQueryId)}/favourite`,
      {},
    );
  }

  removeSavedQueryFavourite(
    tenantId: string,
    savedQueryId: string,
  ): Observable<SavedQueryFavouriteState> {
    return this.http.delete<SavedQueryFavouriteState>(
      `${this.savedQueryPath(tenantId, savedQueryId)}/favourite`,
    );
  }

  /**
   * The caller's own dashboards. A member who has none yet is given the starter
   * one by this call, so the list is never empty for somebody who may own one.
   */
  listDashboards(tenantId: string): Observable<DashboardList> {
    return this.http.get<DashboardList>(this.dashboardsPath(tenantId));
  }

  /**
   * Remembers where the caller last was. Deliberately its own request: a
   * prefetch or a link preview must never move where somebody lands next.
   */
  setActiveDashboard(tenantId: string, dashboardId: string): Observable<DashboardResponse> {
    return this.http.put<DashboardResponse>(
      `${this.dashboardPath(tenantId, dashboardId)}/active`,
      {},
    );
  }

  getDashboard(tenantId: string, dashboardId: string): Observable<DashboardResponse> {
    return this.http.get<DashboardResponse>(this.dashboardPath(tenantId, dashboardId));
  }

  createDashboard(
    tenantId: string,
    request: CreateDashboardRequest,
  ): Observable<DashboardResponse> {
    return this.http.post<DashboardResponse>(this.dashboardsPath(tenantId), request);
  }

  updateDashboard(
    tenantId: string,
    dashboardId: string,
    request: UpdateDashboardRequest,
  ): Observable<DashboardResponse> {
    return this.http.patch<DashboardResponse>(this.dashboardPath(tenantId, dashboardId), request);
  }

  deleteDashboard(tenantId: string, dashboardId: string): Observable<void> {
    return this.http.delete<void>(this.dashboardPath(tenantId, dashboardId));
  }

  makeDashboardDefault(tenantId: string, dashboardId: string): Observable<DashboardResponse> {
    return this.http.put<DashboardResponse>(
      `${this.dashboardPath(tenantId, dashboardId)}/default`,
      {},
    );
  }

  /** The copy points at the same saved queries; duplicating those as well would
   * double the member's query list every time they copied a dashboard. */
  copyDashboard(
    tenantId: string,
    dashboardId: string,
    request: CreateDashboardRequest,
  ): Observable<DashboardResponse> {
    return this.http.post<DashboardResponse>(
      `${this.dashboardPath(tenantId, dashboardId)}/copy`,
      request,
    );
  }

  listWidgetTypes(tenantId: string): Observable<WidgetTypeList> {
    return this.http.get<WidgetTypeList>(
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/widget-types`,
    );
  }

  createDashboardWidget(
    tenantId: string,
    dashboardId: string,
    request: CreateWidgetRequest,
  ): Observable<DashboardWidgetResponse> {
    return this.http.post<DashboardWidgetResponse>(
      `${this.dashboardPath(tenantId, dashboardId)}/widgets`,
      request,
    );
  }

  updateDashboardWidget(
    tenantId: string,
    dashboardId: string,
    widgetId: string,
    request: UpdateWidgetRequest,
  ): Observable<DashboardWidgetResponse> {
    return this.http.patch<DashboardWidgetResponse>(
      this.widgetPath(tenantId, dashboardId, widgetId),
      request,
    );
  }

  deleteDashboardWidget(tenantId: string, dashboardId: string, widgetId: string): Observable<void> {
    return this.http.delete<void>(this.widgetPath(tenantId, dashboardId, widgetId));
  }

  /**
   * One request for the whole arrangement, against the dashboard's version. A
   * stale version answers `409` and **nothing moves**, so a refused layout is
   * never applied halfway.
   */
  applyDashboardLayout(
    tenantId: string,
    dashboardId: string,
    request: DashboardLayoutRequest,
  ): Observable<DashboardWidgetList> {
    return this.http.put<DashboardWidgetList>(
      `${this.dashboardPath(tenantId, dashboardId)}/layout`,
      request,
    );
  }

  restoreDashboardFromTemplate(
    tenantId: string,
    request: RestoreDashboardTemplateRequest,
  ): Observable<DashboardTemplateResponse> {
    return this.http.post<DashboardTemplateResponse>(
      `${this.dashboardsPath(tenantId)}/from-template`,
      request,
    );
  }

  listDashboardWidgets(tenantId: string, dashboardId: string): Observable<DashboardWidgetList> {
    return this.http.get<DashboardWidgetList>(
      `${this.dashboardPath(tenantId, dashboardId)}/widgets`,
    );
  }

  /**
   * One widget at a time, on purpose: each carries its own result or its own
   * failure, so a single unreachable source cannot blank the whole page.
   */
  getWidgetData(
    tenantId: string,
    dashboardId: string,
    widgetId: string,
  ): Observable<WidgetDataResponse> {
    return this.http.get<WidgetDataResponse>(
      `${this.dashboardPath(tenantId, dashboardId)}/widgets/${encodeURIComponent(widgetId)}/data`,
    );
  }

  /**
   * The caller's own inbox. There is no identifier in the path because there is
   * no other inbox to ask for: the server keys every statement on the caller's
   * membership.
   */
  listNotifications(tenantId: string, unreadOnly = false): Observable<NotificationList> {
    const params = unreadOnly ? new HttpParams().set('unread', 'true') : undefined;

    return this.http.get<NotificationList>(this.notificationsPath(tenantId), { params });
  }

  markNotificationsRead(
    tenantId: string,
    request: MarkNotificationsReadRequest = {},
  ): Observable<MarkNotificationsReadResponse> {
    return this.http.post<MarkNotificationsReadResponse>(
      `${this.notificationsPath(tenantId)}/read`,
      request,
    );
  }

  getNotificationPreferences(tenantId: string): Observable<NotificationPreferenceList> {
    return this.http.get<NotificationPreferenceList>(this.notificationPreferencesPath(tenantId));
  }

  /** Replaces the whole set; the server fills in the defaults it is not sent. */
  replaceNotificationPreferences(
    tenantId: string,
    request: ReplaceNotificationPreferencesRequest,
  ): Observable<NotificationPreferenceList> {
    return this.http.put<NotificationPreferenceList>(
      this.notificationPreferencesPath(tenantId),
      request,
    );
  }

  private notificationsPath(tenantId: string): string {
    return `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/notifications`;
  }

  private notificationPreferencesPath(tenantId: string): string {
    return `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/notification-preferences`;
  }

  private dashboardsPath(tenantId: string): string {
    return `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/dashboards`;
  }

  private dashboardPath(tenantId: string, dashboardId: string): string {
    return `${this.dashboardsPath(tenantId)}/${encodeURIComponent(dashboardId)}`;
  }

  private widgetPath(tenantId: string, dashboardId: string, widgetId: string): string {
    return `${this.dashboardPath(tenantId, dashboardId)}/widgets/${encodeURIComponent(widgetId)}`;
  }

  private savedQueriesPath(tenantId: string): string {
    return `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/saved-queries`;
  }

  private savedQueryPath(tenantId: string, savedQueryId: string): string {
    return `${this.savedQueriesPath(tenantId)}/${encodeURIComponent(savedQueryId)}`;
  }

  private attachmentPath(tenantId: string, issueId: string, attachmentId: string): string {
    return `${this.issuePath(tenantId, issueId)}/attachments/${encodeURIComponent(attachmentId)}`;
  }

  private issuePath(tenantId: string, issueId: string): string {
    return (
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}` +
      `/issues/${encodeURIComponent(issueId)}`
    );
  }

  private projectWorkgroupPath(tenantId: string, projectId: string, workgroupId: string): string {
    return (
      `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}` +
      `/projects/${encodeURIComponent(projectId)}` +
      `/workgroups/${encodeURIComponent(workgroupId)}`
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
