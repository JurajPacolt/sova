import { AccessibleTenant } from '../api/api.models';
import { destinationAfterLogin, sanitizeReturnUrl, tenantSlugFromReturnUrl } from './return-url';

const ACME_TENANT: AccessibleTenant = {
  id: '019f9f00-0000-7000-8000-000000000001',
  name: 'Acme',
  slug: 'acme',
  status: 'ACTIVE',
  access: {
    type: 'MEMBERSHIP',
    membership_id: '019f9f00-0000-7000-8000-000000000002',
  },
};

describe('return URL safety', () => {
  it('accepts only current internal authenticated routes', () => {
    expect(sanitizeReturnUrl('/select-tenant')).toBe('/select-tenant');
    expect(sanitizeReturnUrl('/t/acme/projects?view=mine')).toBe('/t/acme/projects?view=mine');
    expect(sanitizeReturnUrl('/system/tenants')).toBe('/system/tenants');

    expect(sanitizeReturnUrl('https://evil.example')).toBeNull();
    expect(sanitizeReturnUrl('//evil.example/path')).toBeNull();
    expect(sanitizeReturnUrl('/\\evil.example/path')).toBeNull();
    expect(sanitizeReturnUrl('/login')).toBeNull();
    expect(sanitizeReturnUrl('/t/acme\u0000/dashboard')).toBeNull();
  });

  it('extracts a valid tenant slug', () => {
    expect(tenantSlugFromReturnUrl('/t/acme/dashboard')).toBe('acme');
    expect(tenantSlugFromReturnUrl('/select-tenant')).toBeNull();
  });

  it('uses a tenant return URL only when the user still has access', () => {
    expect(destinationAfterLogin('/t/acme/projects', [ACME_TENANT])).toBe('/t/acme/projects');
    expect(destinationAfterLogin('/t/foreign/projects', [ACME_TENANT])).toBe('/t/acme/dashboards');
  });

  it('falls back to tenant selection for zero or multiple tenants', () => {
    expect(destinationAfterLogin(null, [])).toBe('/select-tenant');
    expect(destinationAfterLogin(null, [ACME_TENANT, { ...ACME_TENANT, id: '2' }])).toBe(
      '/select-tenant',
    );
  });

  it('permits system return URLs only for a superadmin', () => {
    expect(destinationAfterLogin('/system/tenants', [ACME_TENANT], true)).toBe('/system/tenants');
    expect(destinationAfterLogin('/system/tenants', [ACME_TENANT], false)).toBe(
      '/t/acme/dashboards',
    );
    expect(destinationAfterLogin(null, [ACME_TENANT], true)).toBe('/system/tenants');
  });
});
