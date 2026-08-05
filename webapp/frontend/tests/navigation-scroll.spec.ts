import { expect, test, type Page, type Route } from 'playwright/test';
import { webRouteAccess } from '../src/features/auth/webRouteAccess';

const MENU_PERMISSIONS = [...new Set([
  ...Object.values(webRouteAccess).flatMap((access) => access.permissions),
  'status.view',
  'operational-map.view',
  'deployments.dispatch.view',
  'deployments.dispatch.manage',
  'settings.push.manual.send',
  'users.view',
  'address-book.view',
  'roles.manage',
  'teams.manage',
])];

test.beforeEach(async ({ page }) => {
  await mockCommandNavigationApi(page);
});

test('desktop navigation keeps its scroll position after selecting a menu item', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 600 });
  await page.goto('/queues');

  const navigation = page.getByRole('navigation', { name: 'Hoofdnavigatie' });
  await expect.poll(() => navigation.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
  await navigation.evaluate((element) => {
    element.scrollTop = element.scrollHeight;
  });

  const beforeNavigation = await navigation.evaluate((element) => element.scrollTop);
  expect(beforeNavigation).toBeGreaterThan(100);

  await navigation.getByRole('link', { name: 'Systeem', exact: true }).click();
  await expect(page).toHaveURL(/\/system$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Systeem', exact: true })).toBeVisible();

  await expect.poll(() => navigation.evaluate((element) => Math.round(element.scrollTop)))
    .toBe(Math.round(beforeNavigation));
});

test('topbar only shows the active menu item, personal notifications and account control', async ({ page }) => {
  await page.goto('/system');

  const topbar = page.locator('header.topbar');
  await expect(topbar.getByRole('heading', { level: 1 })).toHaveText('Systeem');
  await expect(topbar.getByText('Beheer', { exact: true })).toHaveCount(0);
  await expect(topbar.getByText('Testorganisatie - Drone Inzet Systeem', { exact: true })).toHaveCount(0);
  await expect(topbar.getByRole('button', { name: /Meldingen openen/ })).toBeVisible();
  await expect(topbar.getByRole('button', { name: 'Accountmenu openen', exact: true })).toBeVisible();

  await page.goto('/aanvragen/new');
  await expect(topbar.getByRole('heading', { level: 1 })).toHaveText('Aanvragen');
  await expect(topbar.getByText('Drone Inzet Systeem', { exact: true })).toHaveCount(0);
  await expect(page).toHaveTitle('Aanvragen | Testorganisatie - Drone Inzet Systeem');
});

test('mobile navigation keeps its scroll position after selecting a menu item', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 700 });
  await page.goto('/queues');

  const sidebar = page.locator('#mobile-navigation');
  await page.getByRole('button', { name: 'Menu openen', exact: true }).click();
  await expect(sidebar).toHaveClass(/sidebar--open/);
  await expect.poll(() => sidebar.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);
  await sidebar.evaluate((element) => {
    element.scrollTop = element.scrollHeight;
  });

  const beforeNavigation = await sidebar.evaluate((element) => element.scrollTop);
  expect(beforeNavigation).toBeGreaterThan(100);

  await sidebar.getByRole('link', { name: 'Systeem', exact: true }).click();
  await expect(page).toHaveURL(/\/system$/);
  await expect(page.getByRole('heading', { level: 1, name: 'Systeem', exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Menu openen', exact: true }).click();
  await expect(sidebar).toHaveClass(/sidebar--open/);
  await expect.poll(async () => Math.abs(
    await sidebar.evaluate((element) => Math.round(element.scrollTop))
    - Math.round(beforeNavigation),
  )).toBeLessThanOrEqual(12);
});

test('mobile navigation is hidden when closed and behaves as a modal drawer', async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 700 });
  await page.goto('/queues');

  const sidebar = page.locator('#mobile-navigation');
  const workspace = page.locator('.workspace');
  const menuTrigger = page.getByRole('button', { name: 'Menu openen', exact: true });
  const closeButton = sidebar.getByRole('button', { name: 'Menu sluiten', exact: true });

  await expect(sidebar).toBeHidden();
  await expect(sidebar.getByRole('link', { name: 'Systeem', exact: true })).toBeHidden();

  await menuTrigger.click();
  await expect(sidebar).toBeVisible();
  await expect(sidebar).toHaveClass(/sidebar--open/);
  await expect(page.locator('body')).toHaveClass(/mobile-navigation-open/);
  await expect(workspace).toHaveAttribute('inert', '');
  await expect(closeButton).toBeFocused();

  await page.keyboard.press('Shift+Tab');
  await expect.poll(() => sidebar.evaluate((element) => element.contains(document.activeElement))).toBe(true);

  await page.keyboard.press('Escape');
  await expect(sidebar).toBeHidden();
  await expect(page.locator('body')).not.toHaveClass(/mobile-navigation-open/);
  await expect(workspace).not.toHaveAttribute('inert', '');
  await expect(menuTrigger).toBeFocused();
});

test('navigation switches without a tablet-width layout cliff', async ({ page }) => {
  await page.setViewportSize({ width: 960, height: 800 });
  await page.goto('/system');

  const sidebar = page.locator('#mobile-navigation');
  const menuTrigger = page.getByRole('button', { name: 'Menu openen', exact: true });

  await expect(sidebar).toBeHidden();
  await expect(menuTrigger).toBeVisible();

  await page.setViewportSize({ width: 1024, height: 800 });
  await expect(sidebar).toBeVisible();
  await expect(menuTrigger).toBeHidden();
});

test('command shell fits phone, 1080p and 4K viewports and scales its wide-screen rhythm', async ({ page }) => {
  const wideMetrics: Array<{ width: number; sidebar: number; contentPadding: number }> = [];

  for (const viewport of [
    { width: 320, height: 700 },
    { width: 390, height: 844 },
    { width: 430, height: 932 },
    { width: 1920, height: 1080 },
    { width: 3840, height: 2160 },
  ]) {
    await page.setViewportSize(viewport);
    await page.goto('/system');
    await expect(page.getByRole('heading', { level: 1, name: 'Systeem', exact: true })).toBeVisible();

    const overflow = await page.evaluate(() => ({
      viewport: window.innerWidth,
      document: document.documentElement.scrollWidth,
      body: document.body.scrollWidth,
    }));
    expect(overflow.document).toBeLessThanOrEqual(overflow.viewport + 1);
    expect(overflow.body).toBeLessThanOrEqual(overflow.viewport + 1);

    if (viewport.width >= 1920) {
      wideMetrics.push({
        width: viewport.width,
        sidebar: (await page.locator('#mobile-navigation').boundingBox())?.width ?? 0,
        contentPadding: await page.locator('#main-content').evaluate((element) => (
          Number.parseFloat(window.getComputedStyle(element).paddingLeft)
        )),
      });
    }
  }

  const fullHd = wideMetrics.find((entry) => entry.width === 1920);
  const ultraHd = wideMetrics.find((entry) => entry.width === 3840);
  expect(fullHd).toBeDefined();
  expect(ultraHd).toBeDefined();
  expect(ultraHd?.sidebar ?? 0).toBeGreaterThan(fullHd?.sidebar ?? 0);
  expect(ultraHd?.contentPadding ?? 0).toBeGreaterThan(fullHd?.contentPadding ?? 0);
});

test('wide page roots use the full workspace and remain left aligned', async ({ page }) => {
  await page.setViewportSize({ width: 2560, height: 900 });

  for (const [path, rootSelector] of [
    ['/system', '.page-stack'],
    ['/help', '.help-page'],
    ['/wallboards/new', '.wallboard-create-page'],
  ] as const) {
    await page.goto(path);

    const workspaceBox = await page.locator('.workspace').boundingBox();
    const contentBox = await page.locator('#main-content').boundingBox();
    const rootBox = await page.locator(rootSelector).boundingBox();
    const contentPadding = await page.locator('#main-content').evaluate((element) => {
      const styles = window.getComputedStyle(element);
      return {
        left: Number.parseFloat(styles.paddingLeft),
        right: Number.parseFloat(styles.paddingRight),
      };
    });

    expect(workspaceBox).not.toBeNull();
    expect(contentBox).not.toBeNull();
    expect(rootBox).not.toBeNull();
    expect(Math.abs((contentBox?.x ?? 0) - (workspaceBox?.x ?? 0))).toBeLessThanOrEqual(1);
    expect(Math.abs((contentBox?.width ?? 0) - (workspaceBox?.width ?? 0))).toBeLessThanOrEqual(1);
    expect(Math.abs((rootBox?.x ?? 0) - ((contentBox?.x ?? 0) + contentPadding.left))).toBeLessThanOrEqual(1);
    expect(Math.abs(
      (rootBox?.width ?? 0)
      - ((contentBox?.width ?? 0) - contentPadding.left - contentPadding.right),
    )).toBeLessThanOrEqual(2);
  }
});

async function mockCommandNavigationApi(page: Page): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, {
        data: {
          id: 'navigation-scroll-user',
          name: 'Navigatie Testgebruiker',
          email: 'navigation@example.test',
          account_status: 'active',
          push_enabled: true,
          max_operator_devices: 3,
          two_factor_enabled: true,
          mfa_required: false,
          profile_completion_required: false,
          mail_preferences: { ui: { theme: 'dark' } },
          roles: [{
            id: 'navigation-scroll-role',
            name: 'navigation-scroll-role',
            display_name: 'Navigatie testrol',
            can_use_operator_app: false,
            can_use_admin_app: true,
            permissions: MENU_PERMISSIONS.map((name, index) => ({
              id: `navigation-permission-${index}`,
              name,
              category: 'navigation',
              display_name: name,
            })),
          }],
        },
      });
      return;
    }

    if (path === '/api/branding') {
      await fulfillJson(route, 200, {
        data: {
          name: 'Drone Inzet Systeem',
          short_name: 'DIS',
          tenant_name: 'Testorganisatie',
          logo_data_url: '',
        },
      });
      return;
    }

    if (path === '/api/notifications') {
      await fulfillJson(route, 200, {
        data: {
          notifications: [],
          unread_count: 0,
        },
      });
      return;
    }

    await fulfillJson(route, 503, {
      error: {
        code: 'test_resource_unavailable',
        message: 'Deze inhoud is niet nodig voor de navigatietest.',
        details: {},
      },
    });
  });
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}
