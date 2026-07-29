import { HttpClient, HttpParams, HttpResponse } from '@angular/common/http';
import { inject, Injectable } from '@angular/core';
import { Observable } from 'rxjs';
import {
  AcceptedEmailRequest,
  AcceptNewAccountInvitationRequest,
  ChangeProjectStatusRequest,
  ChangeSystemTenantStatusRequest,
  ChangeSystemUserStatusRequest,
  ChangeTenantMembershipStatusRequest,
  ChangeWorkgroupStatusRequest,
  CreatedInvitationResponse,
  CreateInvitationRequest,
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
  ProjectConfiguration,
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
  CurrentSessionResponse,
  DashboardList,
  DashboardResponse,
  DashboardTemplateResponse,
  DashboardWidgetList,
  EmailRequest,
  ExecuteIssueTransitionRequest,
  InvitationAcceptanceResponse,
  InvitationPreviewResponse,
  InvitationTokenRequest,
  LoginRequest,
  LoginResponse,
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
  TenantMembershipList,
  TenantMembershipResponse,
  TenantResponse,
  TenantRoleList,
  TenantRoleResponse,
  UpdateTenantRoleRequest,
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

  private dashboardsPath(tenantId: string): string {
    return `${API_ROOT}/tenants/${encodeURIComponent(tenantId)}/dashboards`;
  }

  private dashboardPath(tenantId: string, dashboardId: string): string {
    return `${this.dashboardsPath(tenantId)}/${encodeURIComponent(dashboardId)}`;
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
