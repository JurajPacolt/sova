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
