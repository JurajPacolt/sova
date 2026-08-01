/**
 * Types mirror the implemented schemas in docs/openapi.json.
 * Keep contract changes and these compile-time types in the same checkpoint.
 */

export interface LoginRequest {
  readonly email: string;
  readonly password: string;
  readonly mfa_code?: string;
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

export interface MfaStatus {
  readonly enabled: boolean;
  readonly verified: boolean;
  readonly enrollment_required: boolean;
  readonly recovery_codes_remaining: number;
}

export interface LoginResponse {
  readonly user: AuthenticatedUser;
  readonly session: CreatedSession;
  readonly mfa: MfaStatus;
}

export interface CurrentSessionResponse {
  readonly user: AuthenticatedUser;
  readonly impersonation: ImpersonationContext | null;
  readonly mfa: MfaStatus;
}

export interface MfaStatusResponse {
  readonly mfa: MfaStatus;
}

export interface BeginMfaEnrollmentRequest {
  readonly current_password: string;
}

export interface MfaEnrollmentResponse {
  readonly secret: string;
  readonly otpauth_uri: string;
}

export interface ConfirmMfaEnrollmentRequest {
  readonly code: string;
}

export interface MfaRecoveryCodesResponse {
  readonly recovery_codes: readonly string[];
  readonly recovery_codes_remaining?: number;
}

export interface MfaConfirmationResponse extends MfaRecoveryCodesResponse {
  readonly mfa: MfaStatus;
}

export interface RegenerateMfaRecoveryCodesRequest {
  readonly current_password: string;
  readonly code: string;
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

export interface TenantSettings {
  readonly tenant_id: string;
  readonly name: string;
  readonly slug: string;
  readonly default_locale: LocaleCode;
  readonly timezone: string;
  readonly revision: number;
}

export interface TenantSettingsResponse {
  readonly settings: TenantSettings;
}

export interface UpdateTenantGeneralSettingsRequest {
  readonly name: string;
  readonly expected_revision: number;
}

export interface UpdateTenantLocalizationSettingsRequest {
  readonly default_locale: LocaleCode;
  readonly timezone: string;
  readonly expected_revision: number;
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

export interface TenantInvitation {
  readonly id: string;
  readonly tenant_id: string;
  readonly email: string;
  readonly status: 'PENDING' | 'ACCEPTED' | 'REVOKED' | 'EXPIRED';
  readonly invited_by_display_name: string;
  readonly initial_role_code: string | null;
  readonly expires_at: string;
  readonly created_at: string;
  readonly updated_at: string;
  readonly accepted_at: string | null;
  readonly revoked_at: string | null;
}

export interface TenantInvitationList {
  readonly invitations: readonly TenantInvitation[];
}

export interface TenantInvitationResponse {
  readonly invitation: TenantInvitation;
}

export interface ChangeInvitationExpiryRequest {
  readonly expires_at: string;
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

export interface ChangeProjectVisibilityRequest {
  readonly visibility: ProjectVisibility;
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
  /**
   * Whether an issue the caller may also see blocks this one and is not done
   * yet. It travels with the row rather than being asked for per card — a board
   * of forty cards would otherwise be forty extra requests.
   */
  readonly blocked: boolean;
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
  readonly hierarchy_level: IssueHierarchyLevel;
  readonly position: number;
  readonly icon: string;
  readonly color_token: string;
  readonly status: 'ACTIVE' | 'ARCHIVED';
  readonly version: number;
  readonly workflow_id: string | null;
}

export type IssueHierarchyLevel = -1 | 0 | 1;

export interface ProjectIssueTypeList {
  readonly issue_types: readonly ProjectIssueType[];
}

export interface ProjectIssueTypeResponse {
  readonly issue_type: ProjectIssueType;
}

export interface CreateProjectIssueTypeRequest {
  readonly code: string;
  readonly name: string;
  readonly description: string;
  readonly hierarchy_level: IssueHierarchyLevel;
  readonly position: number;
  readonly icon: string;
  readonly color_token: string;
  readonly workflow_id: string;
  readonly expected_config_version: number;
}

export interface UpdateProjectIssueTypeRequest extends Omit<CreateProjectIssueTypeRequest, 'code'> {
  readonly expected_type_version: number;
}

export interface ArchiveProjectIssueTypeRequest {
  readonly expected_config_version: number;
  readonly expected_type_version: number;
}

export interface ProjectWorkflowStatus {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly category: IssueStatusCategory;
  readonly position: number;
  readonly status: 'ACTIVE' | 'ARCHIVED';
}

export type WorkflowVersionState = 'DRAFT' | 'PUBLISHED' | 'ARCHIVED';

/**
 * A rule the server stores on a transition. The editor does not offer to change
 * one, but it must send them back untouched: `PUT …/draft` replaces the whole
 * transition set, so a rule left out of the payload is a rule deleted.
 */
export interface WorkflowTransitionRule {
  readonly id: string;
  readonly type: string;
  readonly key: string;
  readonly configuration: Readonly<Record<string, unknown>>;
  readonly position: number;
}

export interface WorkflowVersionStatus {
  readonly status_id: string;
  readonly code: string;
  readonly name: string;
  readonly category: IssueStatusCategory;
  readonly color_token: string | null;
  readonly position: number;
}

export interface WorkflowVersionTransition {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly from_status_id: string;
  readonly to_status_id: string;
  readonly permission_code: string | null;
  readonly is_primary: boolean;
  readonly position: number;
  readonly rules: readonly WorkflowTransitionRule[];
}

export interface WorkflowVersion {
  readonly id: string;
  readonly workflow_id: string;
  readonly version_number: number;
  readonly state: WorkflowVersionState;
  /** Optimistic lock of this version; `PUT …/draft` is checked against it. */
  readonly version: number;
  readonly initial_status_id: string | null;
  readonly statuses: readonly WorkflowVersionStatus[];
  readonly transitions: readonly WorkflowVersionTransition[];
}

export interface ProjectWorkflow {
  readonly id: string;
  readonly name: string;
  readonly description: string;
  readonly status: 'ACTIVE' | 'ARCHIVED';
  readonly active_version_id: string | null;
  readonly published_version: WorkflowVersion | null;
  readonly draft_version: WorkflowVersion | null;
}

export interface ProjectWorkflowList {
  readonly workflows: readonly ProjectWorkflow[];
}

export interface WorkflowDraftResponse {
  readonly draft_version: WorkflowVersion;
}

export interface PublishedWorkflowResponse {
  readonly published_version: WorkflowVersion;
}

/** Transitions name statuses by code here, unlike the read model's ids. */
export interface UpdateWorkflowDraftRequest {
  readonly expected_version: number;
  readonly initial_status_code: string;
  readonly statuses: readonly {
    readonly code: string;
    readonly name: string;
    readonly description?: string;
    readonly category: IssueStatusCategory;
    readonly color_token?: string;
    readonly position?: number;
  }[];
  readonly transitions: readonly {
    readonly code: string;
    readonly name: string;
    readonly from: string;
    readonly to: string;
    readonly permission_code?: string | null;
    readonly is_primary?: boolean;
    readonly position?: number;
    readonly rules?: readonly {
      readonly type: string;
      readonly key: string;
      readonly configuration?: Readonly<Record<string, unknown>>;
      readonly position?: number;
    }[];
  }[];
}

export interface WorkflowValidationError {
  readonly code: string;
  readonly detail: string;
}

export interface WorkflowValidationResponse {
  readonly valid: boolean;
  readonly validation_errors: readonly WorkflowValidationError[];
}

export interface WorkflowStatusIssueCount {
  readonly status_id: string;
  readonly status_code: string;
  readonly status_name: string;
  readonly count: number;
}

export interface WorkflowImpact {
  readonly workflow_id: string;
  /** The revision a publish must be sent against. */
  readonly expected_config_version: number;
  readonly publishable: boolean;
  readonly requires_migration: boolean;
  readonly validation_errors: readonly WorkflowValidationError[];
  readonly type_codes_using_workflow: readonly string[];
  readonly added_status_codes: readonly string[];
  readonly removed_status_codes: readonly string[];
  readonly added_transition_codes: readonly string[];
  readonly removed_transition_codes: readonly string[];
  readonly affected_issue_counts: readonly WorkflowStatusIssueCount[];
  /** Removed statuses that still carry issues, so publishing needs a target. */
  readonly required_status_mapping_codes: readonly string[];
}

export interface WorkflowImpactResponse {
  readonly impact: WorkflowImpact;
}

export interface PublishWorkflowRequest {
  readonly expected_config_version: number;
  /** Removed status code → target status code, for issues that still sit there. */
  readonly status_mapping?: Readonly<Record<string, string>>;
}

export interface ConfigurationHistoryEntry {
  readonly id: string;
  readonly revision: number;
  readonly event_type: string;
  readonly workflow_id: string | null;
  readonly workflow_version_id: string | null;
  readonly actor_user_id: string | null;
  readonly metadata: Readonly<Record<string, unknown>>;
  readonly created_at: string;
}

export interface ConfigurationHistoryList {
  readonly history: readonly ConfigurationHistoryEntry[];
}

export interface ProjectConfiguration {
  readonly revision: number;
  readonly issue_types: readonly ProjectIssueType[];
  readonly statuses: readonly ProjectWorkflowStatus[];
  readonly workflows: readonly ProjectWorkflow[];
}

export interface CreateIssueRequest {
  readonly issue_type_id: string;
  readonly title: string;
  readonly description?: string;
  readonly priority?: IssuePriority;
  readonly assignee_membership_id?: string | null;
}

/**
 * A personal dashboard. There is no viewer-access field, and that is the point:
 * a dashboard belongs to exactly one membership and nobody else can reach it.
 */
export interface Dashboard {
  readonly id: string;
  readonly name: string;
  readonly position: number;
  readonly is_default: boolean;
  /** Which one the caller last opened — their preference, not a property of the row. */
  readonly is_active: boolean;
  readonly widget_count: number;
  readonly version: number;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface DashboardList {
  readonly dashboards: readonly Dashboard[];
  /** Where to land when no dashboard was named. Null only before the first one exists. */
  readonly active_dashboard_id: string | null;
}

export interface DashboardResponse {
  readonly dashboard: Dashboard;
}

export interface CreateDashboardRequest {
  readonly name: string;
}

export interface UpdateDashboardRequest extends CreateDashboardRequest {
  readonly expected_version: number;
  readonly position?: number;
}

export interface RestoreDashboardTemplateRequest {
  readonly name?: string;
}

/**
 * The catalogue this deployment ships. It is data — keys, versions, sizes and
 * the fields a type may aggregate by — and carries no component name, so a
 * stored string can never select something to run.
 */
export interface WidgetTypeDefinition {
  readonly type_key: string;
  readonly schema_version: number;
  /** A localisation key. The server ships no user-facing wording. */
  readonly label_key: string;
  readonly description_key: string;
  readonly min_width: number;
  readonly min_height: number;
  readonly default_width: number;
  readonly default_height: number;
  readonly max_width: number;
  readonly max_height: number;
  readonly dimensions: readonly string[];
}

export interface WidgetTypeList {
  readonly widget_types: readonly WidgetTypeDefinition[];
}

export interface CreateWidgetRequest {
  readonly saved_query_id: string;
  readonly type_key: string;
  readonly title?: string;
  readonly configuration?: Readonly<Record<string, unknown>>;
}

export interface UpdateWidgetRequest {
  readonly expected_version: number;
  readonly saved_query_id: string;
  readonly title?: string;
  readonly configuration?: Readonly<Record<string, unknown>>;
}

export interface DashboardWidgetResponse {
  readonly widget: DashboardWidget;
}

export interface WidgetPlacement {
  readonly id: string;
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
}

/**
 * A whole arrangement at once, against the dashboard's version. Moving two
 * widgets past each other is only ever legal as a pair, so the request carries
 * **every** widget of the dashboard — a partial layout is how two of them end
 * up on top of each other.
 */
export interface DashboardLayoutRequest {
  readonly expected_version: number;
  readonly widgets: readonly WidgetPlacement[];
}

export interface DashboardTemplateResponse {
  readonly dashboard: Dashboard;
  readonly widgets: readonly DashboardWidget[];
}

export type WidgetTypeKey =
  'issue_count' | 'issue_list' | 'issue_breakdown' | 'issue_matrix' | 'issue_time_series';

export interface DashboardWidget {
  readonly id: string;
  readonly dashboard_id: string;
  readonly type_key: string;
  /**
   * False when this deployment no longer knows the stored type. The row still
   * arrives so the widget can be removed; nothing guesses what it meant.
   */
  readonly available: boolean;
  readonly schema_version: number;
  readonly title: string;
  readonly saved_query_id: string;
  readonly source_name: string | null;
  readonly source_reachable: boolean;
  readonly configuration: Readonly<Record<string, unknown>>;
  readonly x: number;
  readonly y: number;
  readonly width: number;
  readonly height: number;
  readonly version: number;
  readonly created_at: string;
  readonly updated_at: string;
}

export interface DashboardWidgetList {
  readonly widgets: readonly DashboardWidget[];
}

export interface WidgetCountData {
  readonly count: number;
}

export interface WidgetListData {
  readonly issues: readonly IssueSearchHit[];
}

export interface WidgetBreakdownBucket {
  readonly key: string | null;
  readonly label: string;
  readonly count: number;
}

export interface WidgetBreakdownData {
  readonly buckets: readonly WidgetBreakdownBucket[];
}

export interface WidgetMatrixCell {
  readonly row_key: string | null;
  readonly row_label: string;
  readonly column_key: string | null;
  readonly column_label: string;
  readonly count: number;
}

export interface WidgetMatrixData {
  readonly cells: readonly WidgetMatrixCell[];
}

export interface WidgetTimeSeriesPoint {
  readonly bucket: string;
  readonly count: number;
}

export interface WidgetTimeSeries {
  readonly event: string;
  /** Empty buckets arrive as zero, so a gap is a zero and not a missing point. */
  readonly points: readonly WidgetTimeSeriesPoint[];
}

export interface WidgetTimeSeriesData {
  readonly series: readonly WidgetTimeSeries[];
}

/**
 * The shape depends on the widget's type. The caller already knows the type it
 * asked for, so the narrowing helpers below check the payload rather than trust
 * it — a stored type this build no longer understands must not be read as one
 * that happens to sit next to it.
 */
export type WidgetData =
  WidgetCountData | WidgetListData | WidgetBreakdownData | WidgetMatrixData | WidgetTimeSeriesData;

export interface WidgetDataResponse {
  readonly data: WidgetData;
}

export function isWidgetCountData(data: WidgetData): data is WidgetCountData {
  return typeof (data as Partial<WidgetCountData>).count === 'number';
}

export function isWidgetListData(data: WidgetData): data is WidgetListData {
  return Array.isArray((data as Partial<WidgetListData>).issues);
}

export function isWidgetBreakdownData(data: WidgetData): data is WidgetBreakdownData {
  return Array.isArray((data as Partial<WidgetBreakdownData>).buckets);
}

export function isWidgetMatrixData(data: WidgetData): data is WidgetMatrixData {
  return Array.isArray((data as Partial<WidgetMatrixData>).cells);
}

export function isWidgetTimeSeriesData(data: WidgetData): data is WidgetTimeSeriesData {
  return Array.isArray((data as Partial<WidgetTimeSeriesData>).series);
}

export type NotificationKind =
  'ISSUE_ASSIGNED' | 'ISSUE_MENTIONED' | 'ISSUE_COMMENTED' | 'ISSUE_TRANSITIONED';

/**
 * What the server put in the row when the event was delivered, not what the
 * issue says today. A notification is a record of something that happened, so
 * the key and title it carries are the ones that were true at delivery time.
 */
export interface NotificationPayload {
  readonly issue_key?: string;
  readonly issue_title?: string;
  readonly project_id?: string;
  readonly comment_id?: string;
}

export interface NotificationEntry {
  readonly id: string;
  readonly kind: NotificationKind;
  readonly project_id: string | null;
  readonly issue_id: string | null;
  readonly actor: {
    readonly user_id: string;
    readonly display_name: string;
  } | null;
  readonly payload: NotificationPayload;
  readonly read_at: string | null;
  readonly created_at: string;
}

export interface NotificationList {
  readonly notifications: readonly NotificationEntry[];
  readonly unread_count: number;
}

/** An empty or absent list means every notification of the caller. */
export interface MarkNotificationsReadRequest {
  readonly notification_ids?: readonly string[];
}

export interface MarkNotificationsReadResponse {
  readonly updated: number;
  readonly unread_count: number;
}

export interface NotificationPreference {
  readonly kind: NotificationKind;
  readonly in_app: boolean;
  readonly email: boolean;
  /** Assignment and being addressed by name may not be silently missed. */
  readonly in_app_locked: boolean;
}

export interface NotificationPreferenceList {
  readonly preferences: readonly NotificationPreference[];
}

export interface ReplaceNotificationPreferencesRequest {
  readonly preferences: readonly {
    readonly kind: NotificationKind;
    readonly in_app: boolean;
    readonly email: boolean;
  }[];
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
