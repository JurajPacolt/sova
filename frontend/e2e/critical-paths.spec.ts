import { expect, test, type Page, type Route } from '@playwright/test';

/**
 * The paths that have to work for SOVA to be usable at all (F8.2): signing in,
 * landing in a tenant, reaching a project, opening an issue and moving it.
 *
 * The API is answered by one router rather than by a route per endpoint. A
 * screen that quietly starts calling something new then shows up as an
 * unanswered request instead of as a silent empty section, which is exactly the
 * kind of drift an end-to-end test is here to catch.
 *
 * Nothing here needs a backend, so the suite runs anywhere `npm start` does.
 */

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const PROJECT_ID = '019f9f00-0000-7000-8000-0000000000b1';
const ISSUE_ID = '019f9f00-0000-7000-8000-0000000000c1';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-0000000000d1';
const OPEN_STATUS = '019f9f00-0000-7000-8000-0000000000e1';
const DOING_STATUS = '019f9f00-0000-7000-8000-0000000000e2';

const TENANT = {
  id: TENANT_ID,
  slug: 'acme',
  name: 'Acme',
  status: 'ACTIVE',
  access: { type: 'MEMBERSHIP', membership_id: '019f9f00-0000-7000-8000-0000000000m1' },
};

const USER = {
  id: '019f9f00-0000-7000-8000-0000000000u1',
  email: 'member@example.test',
  display_name: 'Member',
  preferred_locale: 'en',
  is_superadmin: false,
};

const MFA = {
  enabled: false,
  verified: false,
  enrollment_required: false,
  recovery_codes_remaining: 0,
};

const PROJECT = {
  id: PROJECT_ID,
  tenant_id: TENANT_ID,
  code: 'SOVA',
  name: 'SOVA core',
  description: 'The tracker itself',
  visibility: 'TENANT',
  status: 'ACTIVE',
  lead: null,
  member_count: 1,
  created_at: '2026-07-29T10:00:00+00:00',
  updated_at: '2026-07-29T10:00:00+00:00',
  viewer_roles: ['PROJECT_MANAGER'],
};

const SEARCH_HIT = {
  id: ISSUE_ID,
  key: 'SOVA-1',
  title: 'Login fails on the second attempt',
  project: { id: PROJECT_ID, code: 'SOVA', name: 'SOVA core' },
  issue_type: { code: 'BUG', name: 'Bug', hierarchy_level: 0 },
  status: { code: 'OPEN', name: 'Open', category: 'TO_DO' },
  priority: 'NORMAL',
  assignee: null,
  assignee_workgroup: null,
  parent_key: null,
  blocked: false,
  resolution: null,
  resolved_at: null,
  created_at: '2026-07-29T10:00:00+00:00',
  updated_at: '2026-07-29T10:00:00+00:00',
};

const ISSUE = {
  id: ISSUE_ID,
  tenant_id: TENANT_ID,
  project_id: PROJECT_ID,
  number: 1,
  key: 'SOVA-1',
  title: 'Login fails on the second attempt',
  description: 'Steps to reproduce are in the comments.',
  issue_type: { id: '019f9f00-0000-7000-8000-0000000000t1', code: 'BUG', name: 'Bug' },
  status: { id: OPEN_STATUS, code: 'OPEN', name: 'Open', category: 'TO_DO' },
  parent: null,
  reporter: { membership_id: '019f9f00-0000-7000-8000-0000000000m1', display_name: 'Member' },
  assignee: null,
  assignee_workgroup: null,
  priority: 'NORMAL',
  resolution: null,
  resolved_at: null,
  version: 3,
  created_at: '2026-07-29T10:00:00+00:00',
  updated_at: '2026-07-29T10:00:00+00:00',
};

const TRANSITION = {
  id: '019f9f00-0000-7000-8000-0000000000f1',
  code: 'START',
  name: 'Start work',
  to_status: { id: DOING_STATUS, code: 'DOING', name: 'In progress' },
  is_primary: true,
  position: 0,
  required_fields: [],
};

interface MockOptions {
  /** Tenant-scoped permissions the caller effectively holds. */
  readonly permissions?: readonly string[];
  /** Answers `POST /auth/login` with a refusal instead of a session. */
  readonly rejectLogin?: boolean;
  /** Starts without a session, which is what the sign-in screen needs. */
  readonly startSignedOut?: boolean;
}

test.describe('critical paths', () => {
  test('signing in with one tenant lands straight in it', async ({ page }) => {
    await mockApi(page, { startSignedOut: true });
    await page.goto('/login');

    await page.locator('#email').fill(USER.email);
    await page.locator('#password').fill('a long enough passphrase');
    await page.getByRole('button', { name: 'Sign in' }).click();

    // One tenant means no choosing screen: the person is already where they
    // were going.
    await expect(page).toHaveURL(/\/t\/acme\/dashboards/u);
    await expect(page.locator('h1')).toContainText('My work');
  });

  test('a refused sign-in keeps the form and says why', async ({ page }) => {
    await mockApi(page, { rejectLogin: true, startSignedOut: true });
    await page.goto('/login');

    await page.locator('#email').fill(USER.email);
    await page.locator('#password').fill('the wrong passphrase');
    await page.getByRole('button', { name: 'Sign in' }).click();

    await expect(page.getByRole('alert')).toContainText('email or password is incorrect');
    await expect(page).toHaveURL(/\/login/u);
    // What was typed survives a refusal; retyping an address is a punishment.
    await expect(page.locator('#email')).toHaveValue(USER.email);
  });

  test('the tenant leads to its projects and into one of them', async ({ page }) => {
    await mockApi(page);
    await page.goto('/t/acme/dashboards');

    await page.getByRole('link', { name: 'Projects' }).click();

    await expect(page).toHaveURL(/\/t\/acme\/projects/u);
    await expect(page.getByText('SOVA core')).toBeVisible();

    await page.getByRole('link', { name: 'Open project' }).click();

    await expect(page).toHaveURL(new RegExp(`/t/acme/projects/${PROJECT_ID}`, 'u'));
    await expect(page.locator('h1')).toContainText('SOVA core');
  });

  test('an issue opens by its key and its transition is executed against the version it showed', async ({
    page,
  }) => {
    await mockApi(page);
    await page.goto('/t/acme/dashboards');
    await page.getByRole('link', { name: 'Issues' }).click();
    await expect(page).toHaveURL(/\/t\/acme\/issues$/u);

    await page.getByRole('link', { name: 'SOVA-1' }).click();

    await expect(page).toHaveURL(/\/t\/acme\/issues\/SOVA-1/u);
    await expect(page.locator('h1')).toContainText('SOVA-1');

    const execution = page.waitForRequest(
      (request) =>
        request.url().includes(`/issues/${ISSUE_ID}/transitions/${TRANSITION.id}`) &&
        request.method() === 'POST',
    );

    await page.getByRole('button', { name: TRANSITION.name }).click();

    const request = await execution;

    // The version the offer was computed against, so a parallel change is
    // reported rather than overwritten.
    expect(request.postDataJSON()).toEqual({ expected_issue_version: 3 });
  });

  /**
   * A screen somebody may not open is a closed door, not a different room:
   * silently delivering the dashboard instead reads as a broken link.
   */
  test('a screen the caller may not open shows the refusal, not another screen', async ({
    page,
  }) => {
    await mockApi(page, { permissions: ['dashboard.create'] });
    await page.goto('/t/acme/admin/audit');

    await expect(page).toHaveURL(/\/t\/acme\/forbidden/u);
    await expect(page.locator('h1')).toContainText('do not have access');
  });
});

/** One router for the whole API, so an unmocked call fails loudly. */
async function mockApi(page: Page, options: MockOptions = {}): Promise<void> {
  const permissions = options.permissions ?? [
    'dashboard.create',
    'dashboard.update-own',
    'tenant.audit.view',
    'saved-query.create',
  ];

  // Whether a session exists yet. The sign-in screen turns away somebody who is
  // already signed in, so a mock that always answered "you are" would leave the
  // form unreachable — the state has to move the way the real one does.
  const session = { open: options.startSignedOut !== true };

  await page.route('**/api/v1/**', async (route) => {
    const { pathname } = new URL(route.request().url());
    const method = route.request().method();

    if (pathname === '/api/v1/auth/login' && method === 'POST' && options.rejectLogin !== true) {
      session.open = true;
    }

    if (!session.open && (pathname === '/api/v1/tenants' || pathname === '/api/v1/auth/session')) {
      await fulfil(route, problem(401, 'SESSION_REQUIRED', 'Authentication is required.'));

      return;
    }

    const body = answer(pathname, method, permissions, options);

    if (body === null) {
      // Not a silent empty answer: an endpoint nobody taught this suite about
      // should be visible as a failure, not as a blank section.
      await route.fulfill({ status: 501, contentType: 'application/json', body: '{}' });

      return;
    }

    await fulfil(route, body);
  });
}

async function fulfil(route: Route, body: unknown): Promise<void> {
  const refusal = isRefusal(body) ? body : null;

  await route.fulfill({
    status: refusal?.status ?? 200,
    contentType: refusal === null ? 'application/json' : 'application/problem+json',
    body: JSON.stringify(refusal?.body ?? body),
  });
}

interface Refusal {
  readonly refusal: true;
  readonly status: number;
  readonly body: unknown;
}

function isRefusal(value: unknown): value is Refusal {
  return typeof value === 'object' && value !== null && 'refusal' in value;
}

function answer(
  path: string,
  method: string,
  permissions: readonly string[],
  options: MockOptions,
): unknown {
  const tenant = `/api/v1/tenants/${TENANT_ID}`;

  if (path === '/api/v1/auth/login') {
    return options.rejectLogin === true
      ? problem(401, 'INVALID_CREDENTIALS', 'The email or password is incorrect.')
      : {
          user: USER,
          session: { id: 's-1', expires_at: '2026-07-30T10:00:00+00:00' },
          mfa: MFA,
        };
  }

  if (path === '/api/v1/auth/session') {
    return { user: USER, impersonation: null, mfa: MFA };
  }

  if (path === '/api/v1/tenants') {
    return { tenants: [TENANT] };
  }

  if (path === tenant) {
    return { tenant: TENANT, permissions };
  }

  if (path === `${tenant}/dashboards`) {
    return {
      dashboards: [
        {
          id: DASHBOARD_ID,
          name: 'My work',
          position: 0,
          is_default: true,
          is_active: true,
          widget_count: 0,
          version: 1,
          created_at: '2026-07-29T10:00:00+00:00',
          updated_at: '2026-07-29T10:00:00+00:00',
        },
      ],
      active_dashboard_id: DASHBOARD_ID,
    };
  }

  if (path === `${tenant}/dashboards/${DASHBOARD_ID}/widgets`) {
    return { widgets: [] };
  }

  if (path === `${tenant}/projects`) {
    return { projects: [PROJECT] };
  }

  if (path === `${tenant}/projects/${PROJECT_ID}/members`) {
    return { members: [] };
  }

  if (path === `${tenant}/projects/${PROJECT_ID}/roles`) {
    return { roles: [] };
  }

  if (path === `${tenant}/projects/${PROJECT_ID}/workgroups`) {
    return { links: [] };
  }

  if (path === `${tenant}/projects/${PROJECT_ID}/configuration`) {
    return {
      revision: 1,
      issue_types: [],
      statuses: [
        {
          id: OPEN_STATUS,
          code: 'OPEN',
          name: 'Open',
          category: 'TO_DO',
          position: 0,
          status: 'ACTIVE',
        },
        {
          id: DOING_STATUS,
          code: 'DOING',
          name: 'In progress',
          category: 'IN_PROGRESS',
          position: 1,
          status: 'ACTIVE',
        },
      ],
      workflows: [],
    };
  }

  if (path === `${tenant}/memberships`) {
    return { memberships: [] };
  }

  if (path === `${tenant}/workgroups`) {
    return { workgroups: [] };
  }

  if (path === `${tenant}/notifications`) {
    return { notifications: [], unread_count: 0 };
  }

  if (path === `${tenant}/saved-queries`) {
    return { saved_queries: [] };
  }

  if (path === `${tenant}/issue-query/metadata`) {
    return { fields: [], functions: [], limits: {} };
  }

  if (path === `${tenant}/issue-query/validate`) {
    return { valid: true, errors: [], canonical_query: '', basic_form: null };
  }

  if (path === `${tenant}/issues/search`) {
    return { issues: [SEARCH_HIT], next_cursor: null, total: 1 };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}`) {
    return { issue: ISSUE };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/transitions`) {
    return { issue_version: ISSUE.version, transitions: [TRANSITION] };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/transitions/${TRANSITION.id}` && method === 'POST') {
    return { issue: { ...ISSUE, status: TRANSITION.to_status, version: ISSUE.version + 1 } };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/comments`) {
    return { comments: [] };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/history`) {
    return { history: [] };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/watchers`) {
    return { watchers: [], watching: false, count: 0 };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/attachments`) {
    return { attachments: [] };
  }

  if (path === `${tenant}/issues/${ISSUE_ID}/links`) {
    return { links: [] };
  }

  return null;
}

function problem(status: number, code: string, detail: string): Refusal {
  return {
    refusal: true,
    status,
    body: {
      type: 'https://sova.test/problems/error',
      title: 'Error',
      status,
      detail,
      instance: '/api/v1',
      request_id: '019f9f00-0000-7000-8000-000000000003',
      code,
    },
  };
}
