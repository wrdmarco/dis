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

test('topbar only shows the active menu item and account control', async ({ page }) => {
  await page.goto('/system');

  const topbar = page.locator('header.topbar');
  await expect(topbar.getByRole('heading', { level: 1 })).toHaveText('Systeem');
  await expect(topbar.getByText('Beheer', { exact: true })).toHaveCount(0);
  await expect(topbar.getByText('Testorganisatie - Drone Inzet Systeem', { exact: true })).toHaveCount(0);
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
  await expect.poll(() => sidebar.evaluate((element) => Math.round(element.scrollTop)))
    .toBe(Math.round(beforeNavigation));
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
