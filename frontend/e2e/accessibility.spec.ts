import { expect, test, type Page } from '@playwright/test';

/**
 * The structural accessibility rules and the responsive behaviour, checked in a
 * real browser (webflow `05-STAVY-ROZHRANIA.md` §12, §13).
 *
 * These are the invariants a unit test cannot see: whether the layout survives a
 * 390-pixel screen, whether the skip link actually moves anything, whether the
 * focus ring is painted at all. The API is mocked, so nothing here needs a
 * backend.
 */

const VIEWPORTS = [
  { name: 'mobile', width: 390, height: 844 },
  { name: 'tablet', width: 834, height: 1112 },
  { name: 'desktop', width: 1440, height: 900 },
] as const;

test.describe('public screens', () => {
  test.beforeEach(async ({ page }) => {
    await mockAnonymousSession(page);
  });

  test('the sign-in screen carries one heading and a labelled form', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('main')).toHaveCount(1);

    // Every control a person types into can be reached by its label; a control
    // without one is announced as nothing at all.
    const unlabelled = await page.evaluate(
      () =>
        [...document.querySelectorAll('input, select, textarea')].filter((control) => {
          const id = control.getAttribute('id');
          const labelled =
            (id !== null && document.querySelector(`label[for="${id}"]`) !== null) ||
            control.hasAttribute('aria-label') ||
            control.hasAttribute('aria-labelledby') ||
            control.closest('label') !== null;

          return !labelled;
        }).length,
    );

    expect(unlabelled).toBe(0);
  });

  /** Required fields are announced as required, not only enforced in TypeScript. */
  test('the sign-in form marks the fields it will not accept empty', async ({ page }) => {
    await page.goto('/login');

    await expect(page.locator('#email')).toHaveAttribute('aria-required', 'true');
    await expect(page.locator('#password')).toHaveAttribute('aria-required', 'true');
  });

  /**
   * Reached with the keyboard on purpose: `:focus-visible` is about how focus
   * arrived, so a programmatic `focus()` would measure a ring nobody sees.
   */
  test('focus is visible when the keyboard reaches a control', async ({ page }) => {
    await page.goto('/login');
    await page.locator('body').click();

    for (let step = 0; step < 20; ++step) {
      await page.keyboard.press('Tab');

      if (await page.locator('#email').evaluate((element) => element === document.activeElement)) {
        break;
      }
    }

    await expect(page.locator('#email')).toBeFocused();
    const outlineWidth = await page
      .locator('#email')
      .evaluate((element) => Number.parseFloat(window.getComputedStyle(element).outlineWidth));

    // The ring is drawn, not merely declared: a zero-width outline is no ring.
    expect(outlineWidth).toBeGreaterThan(1);
  });

  for (const viewport of VIEWPORTS) {
    test(`the sign-in screen fits a ${viewport.name} screen`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/login');

      expect(await horizontalOverflow(page)).toBe(false);
    });
  }
});

test.describe('inside a tenant', () => {
  test.beforeEach(async ({ page }) => {
    await mockTenantSession(page);
  });

  /**
   * The skip link is the keyboard's way past the navigation. It is only useful
   * if it becomes visible when focused and actually moves focus to the content.
   */
  test('the skip link is the first stop and lands in the main content', async ({ page }) => {
    await page.goto('/t/acme/dashboards/019f9f00-0000-7000-8000-0000000000d1');
    await page.waitForSelector('h1');

    // First in the document, so the first press of Tab on a fresh page offers
    // it. Asserting the order rather than driving Tab keeps the test about the
    // page instead of about where the previous click happened to leave focus.
    const first = await page.evaluate(() => {
      const tabbable = [
        ...document.querySelectorAll<HTMLElement>(
          'a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])',
        ),
      ].filter((element) => !element.hasAttribute('disabled'));

      return tabbable[0]?.className ?? '';
    });
    expect(first).toContain('skip-link');

    const skipLink = page.locator('.skip-link');
    await skipLink.focus();
    // Hidden until it is needed, and genuinely on screen once it is.
    await expect(skipLink).toBeInViewport();

    await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
    // The address bar is left alone: a fragment here would be a navigation,
    // guards and all, for something that never left the screen.
    expect(page.url()).not.toContain('#');
  });

  test('the dashboard has one heading and a main landmark', async ({ page }) => {
    await page.goto('/t/acme/dashboards/019f9f00-0000-7000-8000-0000000000d1');

    await expect(page.locator('h1')).toHaveCount(1);
    await expect(page.locator('main#main-content')).toHaveCount(1);
  });

  for (const viewport of VIEWPORTS) {
    test(`the dashboard fits a ${viewport.name} screen`, async ({ page }) => {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await page.goto('/t/acme/dashboards/019f9f00-0000-7000-8000-0000000000d1');
      await expect(page.locator('h1')).toBeVisible();

      expect(await horizontalOverflow(page)).toBe(false);
    });
  }

  /** Below the grid's breakpoint the widgets stack; nothing is left off-screen. */
  test('the widget grid becomes a single column on a narrow screen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/t/acme/dashboards/019f9f00-0000-7000-8000-0000000000d1');

    const cells = page.locator('.dashboard__cell');
    await expect(cells).toHaveCount(2);

    const boxes = await cells.evaluateAll((elements) =>
      elements.map((element) => element.getBoundingClientRect().left),
    );
    expect(new Set(boxes).size).toBe(1);
  });
});

/** The page may scroll down; sideways is where content goes missing. */
async function horizontalOverflow(page: Page): Promise<boolean> {
  return page.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  );
}

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

const TENANT_ID = '019f9f00-0000-7000-8000-000000000001';
const DASHBOARD_ID = '019f9f00-0000-7000-8000-0000000000d1';

/** A member of one tenant, with a dashboard holding two unreachable widgets. */
async function mockTenantSession(page: Page): Promise<void> {
  const tenant = {
    id: TENANT_ID,
    slug: 'acme',
    name: 'Acme',
    status: 'ACTIVE',
    access: { type: 'MEMBERSHIP', membership_id: '019f9f00-0000-7000-8000-0000000000m1' },
  };

  await page.route('**/api/v1/auth/session', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        user: {
          id: '019f9f00-0000-7000-8000-0000000000u1',
          email: 'member@example.test',
          display_name: 'Member',
          status: 'ACTIVE',
          is_superadmin: false,
        },
        impersonation: null,
        mfa: {
          enabled: false,
          verified: false,
          enrollment_required: false,
          recovery_codes_remaining: 0,
        },
      }),
    });
  });

  await page.route('**/api/v1/tenants', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ tenants: [tenant] }),
    });
  });

  await page.route(`**/api/v1/tenants/${TENANT_ID}`, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ tenant, permissions: ['dashboard.create', 'dashboard.update-own'] }),
    });
  });

  await page.route(`**/api/v1/tenants/${TENANT_ID}/dashboards`, async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        dashboards: [
          {
            id: DASHBOARD_ID,
            name: 'My work',
            position: 0,
            is_default: true,
            is_active: true,
            widget_count: 2,
            version: 1,
            created_at: '2026-07-29T10:00:00+00:00',
            updated_at: '2026-07-29T10:00:00+00:00',
          },
        ],
        active_dashboard_id: DASHBOARD_ID,
      }),
    });
  });

  await page.route(
    `**/api/v1/tenants/${TENANT_ID}/dashboards/${DASHBOARD_ID}/widgets`,
    async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          widgets: [0, 1].map((index) => ({
            id: `019f9f00-0000-7000-8000-00000000000${index + 1}`,
            dashboard_id: DASHBOARD_ID,
            type_key: 'issue_count',
            available: true,
            schema_version: 1,
            title: `Widget ${index + 1}`,
            saved_query_id: '019f9f00-0000-7000-8000-0000000000aa',
            source_name: 'Assigned to me',
            // Unreachable on purpose: the layout is what this test is about, and
            // a widget that asks for data would only add noise.
            source_reachable: false,
            configuration: {},
            x: index * 6,
            y: 0,
            width: 6,
            height: 2,
            version: 1,
            created_at: '2026-07-29T10:00:00+00:00',
            updated_at: '2026-07-29T10:00:00+00:00',
          })),
        }),
      });
    },
  );
}
