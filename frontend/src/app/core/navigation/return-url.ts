import { AccessibleTenant } from '../api/api.models';

const TENANT_PATH = /^\/t\/([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\/|$)/;
const SYSTEM_PATH = /^\/system(?:\/|$)/;
const MAX_RETURN_URL_LENGTH = 2048;

export function sanitizeReturnUrl(value: string | null): string | null {
  if (
    value === null ||
    value.length === 0 ||
    value.length > MAX_RETURN_URL_LENGTH ||
    !value.startsWith('/') ||
    value.startsWith('//') ||
    value.includes('\\') ||
    /[\u0000-\u001f\u007f]/.test(value)
  ) {
    return null;
  }

  const path = value.split(/[?#]/, 1)[0];

  if (path === '/select-tenant' || TENANT_PATH.test(path) || SYSTEM_PATH.test(path)) {
    return value;
  }

  return null;
}

export function tenantSlugFromReturnUrl(returnUrl: string): string | null {
  return TENANT_PATH.exec(returnUrl.split(/[?#]/, 1)[0])?.[1] ?? null;
}

export function destinationAfterLogin(
  requestedReturnUrl: string | null,
  tenants: readonly AccessibleTenant[],
  isSuperadmin = false,
): string {
  const returnUrl = sanitizeReturnUrl(requestedReturnUrl);

  if (returnUrl !== null) {
    const requestedTenantSlug = tenantSlugFromReturnUrl(returnUrl);
    const requestsSystem = SYSTEM_PATH.test(returnUrl.split(/[?#]/, 1)[0]);

    if (
      (requestsSystem && isSuperadmin) ||
      (!requestsSystem && requestedTenantSlug === null) ||
      tenants.some((tenant) => tenant.slug === requestedTenantSlug)
    ) {
      return returnUrl;
    }
  }

  if (isSuperadmin) {
    return '/system/tenants';
  }

  return tenants.length === 1
    ? `/t/${encodeURIComponent(tenants[0].slug)}/dashboard`
    : '/select-tenant';
}
