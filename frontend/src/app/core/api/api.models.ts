/**
 * Types mirror the implemented schemas in docs/openapi.json.
 * Keep contract changes and these compile-time types in the same checkpoint.
 */

export interface LoginRequest {
  readonly email: string;
  readonly password: string;
}

export type LocaleCode = 'sk' | 'cs' | 'en' | 'de' | 'pl' | 'hu';

export interface AuthenticatedUser {
  readonly id: string;
  readonly email: string;
  readonly display_name: string;
  readonly preferred_locale: LocaleCode;
  readonly is_superadmin: boolean;
}

export interface CreatedSession {
  readonly id: string;
  readonly expires_at: string;
}

export interface LoginResponse {
  readonly user: AuthenticatedUser;
  readonly session: CreatedSession;
}

export interface CurrentSessionResponse {
  readonly user: AuthenticatedUser;
  readonly impersonation: ImpersonationContext | null;
}

export type ImpersonationStatus = 'ACTIVE' | 'EXPIRED' | 'INVALIDATED';

export interface ImpersonationActor {
  readonly id: string;
  readonly email: string;
  readonly display_name: string;
}

export interface ImpersonationTenant {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
}

export interface ImpersonationContext {
  readonly id: string;
  readonly status: ImpersonationStatus;
  readonly actor: ImpersonationActor;
  readonly effective_user: ImpersonationActor;
  readonly tenant: ImpersonationTenant;
  readonly reason: string;
  readonly reauthenticated_at: string;
  readonly started_at: string;
  readonly expires_at: string;
}

export interface StartImpersonationRequest {
  readonly tenant_id: string;
  readonly effective_user_id: string;
  readonly reason: string;
  readonly password: string;
}

export interface StartImpersonationResponse {
  readonly user: AuthenticatedUser;
  readonly impersonation: ImpersonationContext;
}

export type TenantStatus = 'PENDING' | 'ACTIVE' | 'SUSPENDED' | 'ARCHIVED' | 'DELETION_PENDING';

export interface TenantAccess {
  readonly type: 'MEMBERSHIP' | 'SUPERADMIN';
  readonly membership_id: string | null;
}

export interface AccessibleTenant {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  readonly status: TenantStatus;
  readonly access: TenantAccess;
}

export interface TenantList {
  readonly tenants: readonly AccessibleTenant[];
}

export interface TenantResponse {
  readonly tenant: AccessibleTenant;
  /**
   * Tenant-scoped permission codes the caller effectively holds. UI affordances
   * only — every endpoint authorizes itself again.
   */
  readonly permissions: readonly string[];
}

export interface SystemTenant {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
  readonly status: TenantStatus;
  readonly revision: number;
  readonly owner_email: string | null;
  readonly active_member_count: number;
  readonly created_at: string;
  readonly updated_at: string;
  readonly deletion_effective_at: string | null;
}

export interface SystemTenantList {
  readonly tenants: readonly SystemTenant[];
}

export interface SystemTenantResponse {
  readonly tenant: SystemTenant;
}

export interface CreateSystemTenantRequest {
  readonly name: string;
  readonly slug: string;
  readonly owner_email: string;
}

export interface CreateSystemTenantResponse extends SystemTenantResponse {
  readonly owner_invitation: {
    readonly email: string;
    readonly status: 'PENDING';
  };
  readonly replayed: boolean;
}

export type UserAccountStatus =
  'PENDING_VERIFICATION' | 'ACTIVE' | 'LOCKED' | 'DISABLED' | 'EXPIRED' | 'DELETED';

export interface SystemUser {
  readonly id: string;
  readonly email: string;
  readonly display_name: string;
  readonly status: UserAccountStatus;
  readonly preferred_locale: LocaleCode;
  readonly email_verified_at: string | null;
  readonly failed_login_count: number;
  readonly locked_until: string | null;
  readonly is_superadmin: boolean;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface SystemUserList {
  readonly users: readonly SystemUser[];
}

export interface SystemUserResponse {
  readonly user: SystemUser;
}

export interface ChangeSystemUserStatusRequest {
  readonly status: Extract<UserAccountStatus, 'ACTIVE' | 'DISABLED'>;
}

export interface ChangeSystemTenantStatusRequest {
  readonly status: Exclude<TenantStatus, 'PENDING'>;
  readonly revision: number;
  readonly reason: string;
}

export interface SecurityAuditActor {
  readonly id: string;
  readonly email: string;
  readonly display_name: string;
}

export interface SecurityAuditTenant {
  readonly id: string;
  readonly name: string;
  readonly slug: string;
}

export interface SecurityAuditEvent {
  readonly id: string;
  readonly actor: SecurityAuditActor;
  readonly effective_user: SecurityAuditActor | null;
  readonly tenant: SecurityAuditTenant | null;
  readonly event_type: string;
  readonly outcome: 'SUCCESS' | 'FAILURE';
  readonly reason_code: string;
  readonly request_id: string;
  readonly ip_address: string | null;
  readonly metadata: Readonly<Record<string, boolean | number | string | null>>;
  readonly occurred_at: string;
}

export interface SecurityAuditPage {
  readonly events: readonly SecurityAuditEvent[];
  readonly next_cursor: string | null;
}

export interface SecurityAuditQuery {
  readonly limit?: number;
  readonly cursor?: string;
  readonly from?: string;
  readonly to?: string;
  readonly actor_user_id?: string;
  readonly event_type?: string;
  readonly outcome?: SecurityAuditEvent['outcome'];
  readonly request_id?: string;
}

export interface TenantMembershipRole {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly status: 'ACTIVE' | 'ARCHIVED';
}

export interface TenantMembership {
  readonly id: string;
  readonly user: ImpersonationActor;
  readonly status: 'ACTIVE' | 'DISABLED' | 'REMOVED';
  readonly joined_at: string;
  readonly roles: readonly TenantMembershipRole[];
}

export interface TenantMembershipList {
  readonly memberships: readonly TenantMembership[];
}

export interface TenantMembershipResponse {
  readonly membership: TenantMembership;
}

export interface ChangeTenantMembershipStatusRequest {
  readonly status: TenantMembership['status'];
}

export interface CreateInvitationRequest {
  readonly email: string;
}

export interface CreatedInvitation {
  readonly id: string;
  readonly tenant_id: string;
  readonly email: string;
  readonly status: 'PENDING';
  readonly expires_at: string;
}

export interface CreatedInvitationResponse {
  readonly invitation: CreatedInvitation;
}

export type PermissionScope = 'TENANT' | 'PROJECT' | 'WORKGROUP';

export interface TenantPermissionDefinition {
  readonly code: string;
  readonly scope: PermissionScope;
  readonly label: string;
  readonly description: string;
  readonly sensitive: boolean;
  readonly dependencies: readonly string[];
}

export interface TenantRole {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly description: string;
  readonly status: 'ACTIVE' | 'ARCHIVED';
  readonly is_system: boolean;
  readonly is_editable: boolean;
  readonly revision: number;
  readonly permissions: readonly string[];
  readonly assignment_count: number;
}

export interface TenantRoleList {
  readonly roles: readonly TenantRole[];
  readonly permissions: readonly TenantPermissionDefinition[];
}

export interface TenantRoleResponse {
  readonly role: TenantRole;
}

export interface CreateTenantRoleRequest {
  readonly code: string;
  readonly name: string;
  readonly description?: string;
  readonly permissions: readonly string[];
}

export interface UpdateTenantRoleRequest {
  readonly name: string;
  readonly description: string;
  readonly permissions: readonly string[];
  readonly revision: number;
}

export type WorkgroupStatus = 'ACTIVE' | 'ARCHIVED';
export type WorkgroupMemberRole = 'MEMBER' | 'MANAGER';

export interface Workgroup {
  readonly id: string;
  readonly tenant_id: string;
  readonly name: string;
  readonly description: string;
  readonly status: WorkgroupStatus;
  readonly member_count: number;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface WorkgroupList {
  readonly workgroups: readonly Workgroup[];
}

export interface WorkgroupResponse {
  readonly workgroup: Workgroup;
}

export interface CreateWorkgroupRequest {
  readonly name: string;
  readonly description?: string;
}

export interface ChangeWorkgroupStatusRequest {
  readonly status: WorkgroupStatus;
}

export interface WorkgroupMember {
  readonly membership_id: string;
  readonly user: ImpersonationActor;
  readonly role: WorkgroupMemberRole;
  readonly joined_at: string;
}

export interface WorkgroupMemberList {
  readonly members: readonly WorkgroupMember[];
}

export interface WorkgroupMemberResponse {
  readonly member: WorkgroupMember;
}

export interface UpsertWorkgroupMemberRequest {
  readonly role: WorkgroupMemberRole;
}

export type ProjectStatus = 'ACTIVE' | 'ARCHIVED';
export type ProjectVisibility = 'TENANT' | 'PRIVATE';

export interface ProjectLead {
  readonly membership_id: string;
  readonly display_name: string | null;
  readonly email: string | null;
}

export interface Project {
  readonly id: string;
  readonly tenant_id: string;
  readonly code: string;
  readonly name: string;
  readonly description: string;
  readonly visibility: ProjectVisibility;
  readonly status: ProjectStatus;
  readonly lead: ProjectLead | null;
  readonly member_count: number;
  readonly created_at: string;
  readonly updated_at: string;
}

/** A project as returned by the listing, scoped to what the caller may see. */
export interface ProjectListItem extends Project {
  /** Active project role codes the caller holds, directly or via a workgroup. */
  readonly viewer_roles: readonly string[];
}

export interface ProjectList {
  readonly projects: readonly ProjectListItem[];
}

export interface ProjectResponse {
  readonly project: Project;
}

export interface CreateProjectRequest {
  readonly code: string;
  readonly name: string;
  readonly description?: string;
  readonly visibility?: ProjectVisibility;
  readonly lead_membership_id?: string;
}

export interface ChangeProjectStatusRequest {
  readonly status: ProjectStatus;
}

export interface ProjectRole {
  readonly id: string;
  readonly project_id: string;
  readonly code: string;
  readonly name: string;
  readonly description: string;
  readonly status: 'ACTIVE' | 'ARCHIVED';
  readonly is_system: boolean;
  readonly is_editable: boolean;
  readonly revision: number;
  readonly permissions: readonly string[];
  readonly assignment_count: number;
}

export interface ProjectRoleList {
  readonly roles: readonly ProjectRole[];
}

export interface ProjectMemberRole {
  readonly id: string;
  readonly code: string;
  readonly name: string;
}

export interface ProjectMember {
  readonly membership_id: string;
  readonly user: ImpersonationActor;
  readonly roles: readonly ProjectMemberRole[];
}

export interface ProjectMemberList {
  readonly members: readonly ProjectMember[];
}

export interface ProjectWorkgroupLink {
  readonly workgroup_id: string;
  readonly workgroup_name: string;
  readonly role: ProjectMemberRole;
}

export interface ProjectWorkgroupLinkList {
  readonly workgroups: readonly ProjectWorkgroupLink[];
}

export interface UpsertProjectWorkgroupRequest {
  readonly role_id: string;
}

export interface EmailRequest {
  readonly email: string;
}

export interface AcceptedEmailRequest {
  readonly message: string;
}

export interface ResetPasswordRequest {
  readonly token: string;
  readonly password: string;
  readonly password_confirmation: string;
}

export interface VerifyEmailRequest {
  readonly token: string;
}

export interface VerifyEmailResponse {
  readonly status: 'VERIFIED' | 'ALREADY_VERIFIED';
}

export interface InvitationTokenRequest {
  readonly token: string;
}

export interface InvitationPreview {
  readonly tenant_name: string;
  readonly tenant_slug: string;
  readonly email: string;
  readonly invited_by_display_name: string;
  readonly expires_at: string;
}

export interface InvitationPreviewResponse {
  readonly invitation: InvitationPreview;
}

export interface AcceptNewAccountInvitationRequest {
  readonly token: string;
  readonly display_name: string;
  readonly preferred_locale: LocaleCode;
  readonly password: string;
  readonly password_confirmation: string;
}

export interface InvitationAcceptanceResponse {
  readonly user_id: string;
  readonly tenant_id: string;
  readonly tenant_slug: string;
  readonly membership_created: boolean;
}

export type IssuePriority = 'LOW' | 'NORMAL' | 'HIGH' | 'CRITICAL';

export type IssueStatusCategory = 'TO_DO' | 'IN_PROGRESS' | 'DONE';

export interface IssueStatusRef {
  readonly code: string;
  readonly name: string;
  readonly category: IssueStatusCategory;
}

export interface IssueTypeRef {
  readonly code: string;
  readonly name: string;
  readonly hierarchy_level: number;
}

export interface IssueProjectRef {
  readonly id: string;
  readonly code: string;
  readonly name: string;
}

export interface IssueAssigneeRef {
  readonly membership_id: string;
  readonly display_name: string | null;
}

export interface IssueSearchHit {
  readonly id: string;
  readonly key: string;
  readonly title: string;
  readonly project: IssueProjectRef;
  readonly issue_type: IssueTypeRef;
  readonly status: IssueStatusRef;
  readonly priority: IssuePriority;
  readonly assignee: IssueAssigneeRef | null;
  readonly assignee_workgroup: {
    readonly workgroup_id: string;
    readonly name: string | null;
  } | null;
  readonly parent_key: string | null;
  readonly resolution: string | null;
  readonly resolved_at: string | null;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface IssueSearchRequest {
  readonly query: string;
  readonly page_size?: number;
  readonly cursor?: string | null;
}

export interface IssueSearchResponse {
  readonly issues: readonly IssueSearchHit[];
  readonly canonical_query: string;
  readonly page_size: number;
  readonly next_cursor: string | null;
}

export interface IssueQueryError {
  readonly code: string;
  readonly message_key: string;
  readonly start: number;
  readonly end: number;
  readonly arguments: Readonly<Record<string, string | number>>;
}

export interface IssueQueryField {
  readonly name: string;
  readonly type: string;
  readonly operators: readonly string[];
  readonly supports_set: boolean;
  readonly supports_empty: boolean;
  readonly sortable: boolean;
}

export interface IssueQueryLimits {
  readonly max_query_bytes: number;
  readonly max_ast_nodes: number;
  readonly max_paren_depth: number;
  readonly max_in_values: number;
  readonly max_sort_fields: number;
  readonly default_page_size: number;
  readonly max_page_size: number;
  readonly statement_timeout_ms: number;
}

export interface IssueQueryMetadata {
  readonly fields: readonly IssueQueryField[];
  readonly limits: IssueQueryLimits;
}

export interface IssueQueryCondition {
  readonly field: string;
  readonly operator: string;
  readonly values: readonly string[];
}

export interface IssueQuerySort {
  readonly field: string;
  readonly direction: 'ASC' | 'DESC';
  readonly nulls: 'FIRST' | 'LAST' | null;
}

export interface IssueQueryBasicForm {
  /**
   * False when the query is legal but has no basic-editor shape. The client
   * must then show it read-only rather than simplifying it.
   */
  readonly representable: boolean;
  readonly conditions: readonly IssueQueryCondition[];
  readonly sort: readonly IssueQuerySort[];
}

export interface IssueQueryValidationResponse {
  readonly valid: boolean;
  readonly canonical_query: string | null;
  readonly errors: readonly IssueQueryError[];
  readonly basic_form: IssueQueryBasicForm | null;
}

export type SavedQueryAccess = 'VIEW' | 'EDIT';
export type SavedQueryVisibility = 'PRIVATE' | 'SHARED';

export interface SavedQuery {
  readonly id: string;
  readonly name: string;
  readonly description: string;
  /** Exactly what the author typed, so the editor reopens it unchanged. */
  readonly raw_query: string;
  /** The server's normalised form. Never sent by the client. */
  readonly canonical_query: string;
  readonly language_version: number;
  readonly default_columns: readonly string[];
  /** Derived from the grants, not set directly. */
  readonly visibility: SavedQueryVisibility;
  readonly version: number;
  readonly archived: boolean;
  readonly owner: { readonly membership_id: string; readonly display_name: string | null };
  /**
   * What *this* caller may do. The same query answers differently for its
   * owner, a grant holder and an administrator, so nothing here describes the
   * row itself.
   */
  readonly viewer_access: SavedQueryAccess;
  readonly viewer_is_owner: boolean;
  /** This caller's own bookmark. */
  readonly favourite: boolean;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface SavedQueryList {
  readonly saved_queries: readonly SavedQuery[];
}

export interface SavedQueryResponse {
  readonly saved_query: SavedQuery;
}

export interface CreateSavedQueryRequest {
  readonly name: string;
  readonly description?: string;
  readonly query: string;
  readonly default_columns?: readonly string[];
}

export interface UpdateSavedQueryRequest extends CreateSavedQueryRequest {
  readonly expected_version: number;
}

export interface ArchiveSavedQueryRequest {
  readonly expected_version: number;
}

export interface SavedQueryGrant {
  readonly id: string;
  readonly membership_id: string | null;
  readonly workgroup_id: string | null;
  readonly display_name: string | null;
  readonly access: SavedQueryAccess;
}

export interface SavedQueryGrantList {
  readonly grants: readonly SavedQueryGrant[];
}

/** Exactly one of the two identifiers per entry. */
export interface SavedQueryGrantInput {
  readonly membership_id?: string | null;
  readonly workgroup_id?: string | null;
  readonly access: SavedQueryAccess;
}

/**
 * The complete set — the endpoint replaces rather than patches, so an entry
 * left out really loses access.
 */
export interface ReplaceSavedQueryGrantsRequest {
  readonly grants: readonly SavedQueryGrantInput[];
}

export interface SavedQueryFavouriteState {
  readonly favourite: boolean;
}

export interface Issue {
  readonly id: string;
  readonly tenant_id: string;
  readonly project_id: string;
  readonly number: number;
  readonly key: string;
  readonly title: string;
  readonly description: string;
  readonly issue_type: { readonly id: string; readonly code: string; readonly name: string };
  readonly status: {
    readonly id: string;
    readonly code: string;
    readonly name: string;
    readonly category: IssueStatusCategory;
  };
  readonly parent: { readonly id: string; readonly key: string } | null;
  readonly reporter: IssueAssigneeRef;
  readonly assignee: IssueAssigneeRef | null;
  readonly assignee_workgroup: {
    readonly workgroup_id: string;
    readonly name: string | null;
  } | null;
  readonly priority: IssuePriority;
  readonly resolution: string | null;
  readonly resolved_at: string | null;
  readonly version: number;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface IssueResponse {
  readonly issue: Issue;
}

export interface IssueTransition {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  /** The status the transition leads to, which is what maps a board column to it. */
  readonly to_status: { readonly id: string; readonly code: string; readonly name: string };
  readonly is_primary: boolean;
  readonly position: number;
  readonly required_fields: readonly string[];
}

export interface IssueTransitionList {
  readonly issue_version: number;
  readonly transitions: readonly IssueTransition[];
}

export interface ExecuteIssueTransitionRequest {
  readonly expected_issue_version: number;
  readonly fields?: Readonly<Record<string, string>>;
}

export interface IssueCommentAuthor {
  readonly membership_id: string;
  readonly display_name: string | null;
}

export interface IssueCommentMention {
  readonly membership_id: string;
  readonly display_name: string | null;
}

export interface IssueComment {
  readonly id: string;
  readonly issue_id: string;
  readonly author: IssueCommentAuthor;
  readonly body: string | null;
  readonly version: number;
  readonly deleted: boolean;
  readonly mentions: readonly IssueCommentMention[];
  readonly edited_at: string | null;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface IssueCommentList {
  readonly comments: readonly IssueComment[];
}

export interface IssueCommentResponse {
  readonly comment: IssueComment;
}

export interface IssueCommentRequest {
  readonly body: string;
}

export interface IssueHistoryEntry {
  readonly id: string;
  readonly issue_id: string;
  readonly issue_version: number;
  readonly event_type: string;
  readonly actor: { readonly user_id: string; readonly display_name: string | null } | null;
  readonly from_status: { readonly code: string; readonly name: string | null } | null;
  readonly to_status: { readonly code: string; readonly name: string | null } | null;
  readonly metadata: Readonly<Record<string, unknown>>;
  readonly created_at: string;
}

export interface IssueHistoryList {
  readonly history: readonly IssueHistoryEntry[];
}

export interface IssueWatcher {
  readonly membership_id: string;
  readonly display_name: string | null;
  readonly source: 'EXPLICIT' | 'AUTHOR' | 'ASSIGNEE' | 'COMMENT';
  readonly since: string;
}

export interface IssueWatcherList {
  readonly watchers: readonly IssueWatcher[];
  readonly watching: boolean;
}

export interface IssueWatchState {
  readonly watching: boolean;
}

export type IssueAttachmentScanStatus = 'PENDING' | 'CLEAN' | 'INFECTED' | 'SKIPPED';

export interface IssueAttachment {
  readonly id: string;
  readonly issue_id: string;
  readonly name: string;
  readonly media_type: string;
  readonly byte_size: number;
  readonly checksum: string;
  readonly scan_status: IssueAttachmentScanStatus;
  readonly downloadable: boolean;
  readonly uploaded_by: {
    readonly membership_id: string;
    readonly display_name: string | null;
  };
  readonly created_at: string;
}

export interface IssueAttachmentList {
  readonly attachments: readonly IssueAttachment[];
}

export interface IssueAttachmentResponse {
  readonly attachment: IssueAttachment;
}

export type IssueLinkType = 'BLOCKS' | 'RELATES_TO' | 'DUPLICATES';

export type IssueLinkRelation =
  'BLOCKS' | 'IS_BLOCKED_BY' | 'RELATES_TO' | 'DUPLICATES' | 'IS_DUPLICATED_BY';

export interface IssueLink {
  readonly id: string;
  readonly type: IssueLinkType;
  readonly relation: IssueLinkRelation;
  readonly outward: boolean;
  readonly issue: {
    readonly id: string;
    readonly key: string;
    readonly title: string;
    readonly project_id: string;
    readonly status: { readonly code: string; readonly category: IssueStatusCategory };
  };
  readonly created_at: string;
}

export interface IssueLinkList {
  readonly links: readonly IssueLink[];
}

export interface CreateIssueLinkRequest {
  readonly target_issue_id: string;
  readonly link_type: IssueLinkType;
}

export interface ProjectIssueType {
  readonly id: string;
  readonly project_id: string;
  readonly code: string;
  readonly name: string;
  readonly description: string;
  readonly hierarchy_level: number;
  readonly position: number;
  readonly status: 'ACTIVE' | 'ARCHIVED';
  readonly version: number;
  readonly workflow_id: string | null;
}

export interface ProjectWorkflowStatus {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly category: IssueStatusCategory;
  readonly position: number;
  readonly status: 'ACTIVE' | 'ARCHIVED';
}

export interface ProjectConfiguration {
  readonly revision: number;
  readonly issue_types: readonly ProjectIssueType[];
  readonly statuses: readonly ProjectWorkflowStatus[];
}

export interface CreateIssueRequest {
  readonly issue_type_id: string;
  readonly title: string;
  readonly description?: string;
  readonly priority?: IssuePriority;
  readonly assignee_membership_id?: string | null;
}

export interface ProblemDetails {
  readonly type: string;
  readonly title: string;
  readonly status: number;
  readonly detail: string;
  readonly instance: string;
  readonly request_id: string;
  readonly code: string;
  readonly errors?: Readonly<Record<string, readonly string[]>>;
}

export function isProblemDetails(value: unknown): value is ProblemDetails {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const candidate = value as Partial<ProblemDetails>;

  return (
    typeof candidate.type === 'string' &&
    typeof candidate.title === 'string' &&
    typeof candidate.status === 'number' &&
    typeof candidate.detail === 'string' &&
    typeof candidate.instance === 'string' &&
    typeof candidate.request_id === 'string' &&
    typeof candidate.code === 'string'
  );
}
