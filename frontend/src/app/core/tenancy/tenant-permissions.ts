/**
 * Tenant-scoped permission codes the UI reasons about. The backend catalog is
 * authoritative; these constants only drive navigation and route affordances.
 */

/** Any of these makes some part of the tenant administration reachable. */
export const ADMINISTRATION_PERMISSIONS = [
  'tenant.settings.manage',
  'tenant.members.view',
  'tenant.members.manage',
  'tenant.roles.view',
  'tenant.roles.manage',
  'tenant.workgroups.manage',
  'tenant.audit.view',
] as const;
