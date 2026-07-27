import { expect, test, type Page } from '@playwright/test';

const resetToken = 'R'.repeat(43);
const verificationToken = 'V'.repeat(43);
const invitationToken = 'I'.repeat(43);
const passphrase = 'a unique browser test passphrase';

test.beforeEach(async ({ page }) => {
  await mockAnonymousSession(page);
});

test('forgot-password keeps the account response generic', async ({ page }) => {
  let requestBody: unknown;
  await page.route('**/api/v1/auth/password/forgot', async (route) => {
    requestBody = route.request().postDataJSON();
    await route.fulfill({
      status: 202,
      contentType: 'application/json',
      body: JSON.stringify({
        message: 'If the account exists, password reset instructions will be sent.',
      }),
    });
  });

  await page.goto('/forgot-password');
  await page.locator('#recovery-email').fill('member@example.test');
  await page.locator('form button[type="submit"]').click();

  await expect(page.locator('[role="status"]')).toBeVisible();
  expect(requestBody).toEqual({ email: 'member@example.test' });
});

test('password reset removes the token from the URL and sends it only in the request body', async ({
  page,
}) => {
  let requestBody: unknown;
  await page.route('**/api/v1/auth/password/reset', async (route) => {
    requestBody = route.request().postDataJSON();
    await route.fulfill({ status: 204 });
  });

  await page.goto(`/reset-password/${resetToken}`);
  await expect(page).toHaveURL(/\/reset-password$/u);
  await page.locator('#new-password').fill(passphrase);
  await page.locator('#password-confirmation').fill(passphrase);
  await page.locator('form button[type="submit"]').click();

  await expect(page.locator('[role="status"]')).toBeVisible();
  expect(requestBody).toEqual({
    token: resetToken,
    password: passphrase,
    password_confirmation: passphrase,
  });
  expect(page.url()).not.toContain(resetToken);
});

test('email verification removes the token and verifies it through the API', async ({ page }) => {
  let requestBody: unknown;
  await page.route('**/api/v1/auth/email/verify', async (route) => {
    requestBody = route.request().postDataJSON();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ status: 'VERIFIED' }),
    });
  });

  await page.goto(`/verify-email/${verificationToken}`);

  await expect(page).toHaveURL(/\/verify-email$/u);
  await expect(page.locator('[role="status"]')).toBeVisible();
  expect(requestBody).toEqual({ token: verificationToken });
  expect(page.url()).not.toContain(verificationToken);
});

test('an anonymous invitee can review an invitation and create the invited account', async ({
  page,
}) => {
  let inspectionBody: unknown;
  let acceptanceBody: unknown;
  await page.route('**/api/v1/auth/invitations/inspect', async (route) => {
    inspectionBody = route.request().postDataJSON();
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        invitation: {
          tenant_name: 'Acme',
          tenant_slug: 'acme',
          email: 'invited@example.test',
          invited_by_display_name: 'Tenant Owner',
          expires_at: '2026-08-02T00:00:00+00:00',
        },
      }),
    });
  });
  await page.route('**/api/v1/auth/invitations/accept', async (route) => {
    acceptanceBody = route.request().postDataJSON();
    await route.fulfill({
      status: 201,
      contentType: 'application/json',
      body: JSON.stringify({
        user_id: '019f9f00-0000-7000-8000-000000000001',
        tenant_id: '019f9f00-0000-7000-8000-000000000002',
        tenant_slug: 'acme',
        membership_created: true,
      }),
    });
  });

  await page.goto(`/accept-invitation/${invitationToken}`);

  await expect(page).toHaveURL(/\/accept-invitation$/u);
  await expect(page.getByText('Acme', { exact: true })).toBeVisible();
  await expect(page.getByText('invited@example.test', { exact: true })).toBeVisible();
  await page.locator('#invitation-display-name').fill('Invited Member');
  await page.locator('#invitation-locale').selectOption('en');
  await page.locator('#invitation-password').fill(passphrase);
  await page.locator('#invitation-password-confirmation').fill(passphrase);
  await page.locator('form button[type="submit"]').click();

  await expect(page.locator('[role="status"]')).toBeVisible();
  expect(inspectionBody).toEqual({ token: invitationToken });
  expect(acceptanceBody).toEqual({
    token: invitationToken,
    display_name: 'Invited Member',
    preferred_locale: 'en',
    password: passphrase,
    password_confirmation: passphrase,
  });
  expect(page.url()).not.toContain(invitationToken);
});

async function mockAnonymousSession(page: Page): Promise<void> {
  await page.route('**/api/v1/tenants', async (route) => {
    await route.fulfill({
      status: 401,
      contentType: 'application/problem+json',
      body: JSON.stringify({
        type: 'https://sova.test/problems/session-required',
        title: 'Authentication required',
        status: 401,
        detail: 'Authentication is required.',
        instance: '/api/v1/tenants',
        request_id: '019f9f00-0000-7000-8000-000000000003',
        code: 'SESSION_REQUIRED',
      }),
    });
  });
}
