import { expect, test, type Page, type Route } from '@playwright/test';

const RESTRICTED_SUPERADMIN = {
  id: '019f9f00-0000-7000-8000-0000000000a1',
  email: 'superadmin@example.test',
  display_name: 'System administrator',
  preferred_locale: 'en',
  is_superadmin: false,
};

const VERIFIED_SUPERADMIN = {
  ...RESTRICTED_SUPERADMIN,
  is_superadmin: true,
};

const ENROLLMENT_REQUIRED = {
  enabled: false,
  verified: false,
  enrollment_required: true,
  recovery_codes_remaining: 0,
};

const MFA_VERIFIED = {
  enabled: true,
  verified: true,
  enrollment_required: false,
  recovery_codes_remaining: 10,
};

test.describe('multi-factor authentication', () => {
  test('a restricted production administrator completes enrollment before entering SOVA', async ({
    page,
  }) => {
    await mockEnrollment(page);
    await page.goto('/login');

    await page.locator('#email').fill(RESTRICTED_SUPERADMIN.email);
    await page.locator('#password').fill('a production administrator passphrase');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/mfa\/setup/u);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Multi-factor authentication');
    await expect(page.getByRole('alert')).toContainText(
      'required before system-administrator access',
    );

    await page.getByLabel('Current password').fill('a production administrator passphrase');
    await page.getByRole('button', { name: 'Create authenticator setup' }).click();

    await expect(
      page.getByRole('heading', { name: 'Add SOVA to your authenticator' }),
    ).toBeVisible();
    await expect(page.getByText('JBSWY3DPEHPK3PXP', { exact: true })).toBeVisible();

    await page.getByLabel('Authentication code').fill('123456');
    await page.getByRole('button', { name: 'Enable multi-factor authentication' }).click();

    await expect(page.getByRole('alert')).toContainText('Save these codes now');
    await expect(page.locator('.mfa-codes code')).toHaveCount(10);
    await expect(page.getByText('AAAA-BBBB-CCCC-DDDD')).toBeVisible();
  });

  test('an enrolled administrator answers the login challenge with a second factor', async ({
    page,
  }) => {
    const submittedCodes: string[] = [];
    await mockChallenge(page, submittedCodes);
    await page.goto('/login');

    await page.locator('#email').fill(VERIFIED_SUPERADMIN.email);
    await page.locator('#password').fill('a production administrator passphrase');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('alert')).toContainText('second factor to finish signing in');
    await page.getByLabel('Authenticator or recovery code').fill('654321');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page).toHaveURL(/\/system\/tenants/u);
    expect(submittedCodes).toEqual(['654321']);
  });
});

async function mockEnrollment(page: Page): Promise<void> {
  let signedIn = false;
  let confirmed = false;

  await page.route('**/api/v1/**', async (route) => {
    const { pathname } = new URL(route.request().url());
    const method = route.request().method();

    if (pathname === '/api/v1/auth/session' && !signedIn) {
      await refuse(route, 401, 'SESSION_REQUIRED', 'Authentication is required.');
      return;
    }

    if (pathname === '/api/v1/auth/login' && method === 'POST') {
      signedIn = true;
      await respond(route, {
        user: RESTRICTED_SUPERADMIN,
        session: { id: 'session-enrollment', expires_at: '2026-07-30T10:00:00+00:00' },
        mfa: ENROLLMENT_REQUIRED,
      });
      return;
    }

    if (pathname === '/api/v1/auth/mfa' && method === 'GET') {
      await respond(route, { mfa: confirmed ? MFA_VERIFIED : ENROLLMENT_REQUIRED });
      return;
    }

    if (pathname === '/api/v1/auth/mfa/enrollment' && method === 'POST') {
      await respond(route, {
        secret: 'JBSWY3DPEHPK3PXP',
        otpauth_uri:
          'otpauth://totp/SOVA%3Asuperadmin%40example.test?secret=JBSWY3DPEHPK3PXP&issuer=SOVA',
      });
      return;
    }

    if (pathname === '/api/v1/auth/mfa/enrollment/confirm' && method === 'POST') {
      confirmed = true;
      await respond(route, {
        mfa: MFA_VERIFIED,
        recovery_codes: [
          'AAAA-BBBB-CCCC-DDDD',
          'AAAB-BBBB-CCCC-DDDD',
          'AAAC-BBBB-CCCC-DDDD',
          'AAAD-BBBB-CCCC-DDDD',
          'AAAE-BBBB-CCCC-DDDD',
          'AAAF-BBBB-CCCC-DDDD',
          'AAAG-BBBB-CCCC-DDDD',
          'AAAH-BBBB-CCCC-DDDD',
          'AAAJ-BBBB-CCCC-DDDD',
          'AAAK-BBBB-CCCC-DDDD',
        ],
      });
      return;
    }

    if (pathname === '/api/v1/auth/session' && signedIn) {
      await respond(route, {
        user: confirmed ? VERIFIED_SUPERADMIN : RESTRICTED_SUPERADMIN,
        impersonation: null,
        mfa: confirmed ? MFA_VERIFIED : ENROLLMENT_REQUIRED,
      });
      return;
    }

    await route.fulfill({ status: 501, contentType: 'application/json', body: '{}' });
  });
}

async function mockChallenge(page: Page, submittedCodes: string[]): Promise<void> {
  let signedIn = false;

  await page.route('**/api/v1/**', async (route) => {
    const { pathname } = new URL(route.request().url());
    const method = route.request().method();

    if (pathname === '/api/v1/auth/session' && !signedIn) {
      await refuse(route, 401, 'SESSION_REQUIRED', 'Authentication is required.');
      return;
    }

    if (pathname === '/api/v1/auth/login' && method === 'POST') {
      const body = route.request().postDataJSON() as Record<string, unknown>;
      const code = body['mfa_code'];

      if (typeof code !== 'string') {
        await refuse(
          route,
          401,
          'MFA_CODE_REQUIRED',
          'A multi-factor authentication code is required.',
        );
        return;
      }

      submittedCodes.push(code);
      signedIn = true;
      await respond(route, {
        user: VERIFIED_SUPERADMIN,
        session: { id: 'session-verified', expires_at: '2026-07-30T10:00:00+00:00' },
        mfa: MFA_VERIFIED,
      });
      return;
    }

    if (pathname === '/api/v1/tenants' && method === 'GET') {
      await respond(route, { tenants: [] });
      return;
    }

    if (pathname === '/api/v1/system/tenants' && method === 'GET') {
      await respond(route, { tenants: [] });
      return;
    }

    await route.fulfill({ status: 501, contentType: 'application/json', body: '{}' });
  });
}

async function respond(route: Route, body: unknown): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}

async function refuse(route: Route, status: number, code: string, detail: string): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/problem+json',
    body: JSON.stringify({
      type: 'https://sova.test/problems/authentication',
      title: 'Authentication failed',
      status,
      detail,
      instance: '/api/v1/auth',
      request_id: '019f9f00-0000-7000-8000-000000000003',
      code,
    }),
  });
}
