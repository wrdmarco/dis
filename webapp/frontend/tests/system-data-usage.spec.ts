import { expect, test, type Page, type Route } from 'playwright/test';
import type { SystemDataUsage } from '../src/types/api';

const availableDataUsage = {
  generated_at: '2026-08-03T10:00:00Z',
  stale: false,
  directories: [{
    name: 'private-storage-identifier',
    label: 'storage',
    description: 'Uploads en beheerde media',
    size_bytes: 1024 ** 3,
  }],
} satisfies SystemDataUsage;

test('shows a safe, accessible hourly overview of the data directories', async ({ page }) => {
  let dataUsageRequests = 0;
  await mockSystemApi(page, () => {
    dataUsageRequests += 1;
  });
  await page.setViewportSize({ width: 390, height: 844 });

  await page.goto('/system');

  const panel = page.getByRole('region', { name: 'Gegevensopslag' });
  const table = panel.getByRole('table', { name: 'Omvang van de hoofdmappen in de gegevensopslag' });
  await expect(table).toBeVisible();
  await expect(table.getByRole('columnheader', { name: 'Map' })).toBeVisible();
  await expect(table.getByRole('columnheader', { name: 'Omvang' })).toBeVisible();
  await expect(table.getByRole('rowheader', { name: /storage/ })).toBeVisible();
  await expect(table.getByText('1 GiB', { exact: true })).toBeVisible();
  await expect(panel.getByText(/Elk uur vernieuwd/)).toBeVisible();
  await expect(panel.getByText('Alleen veilige maplabels en totalen worden getoond. Paden en bestandsnamen blijven verborgen.')).toBeVisible();
  await expect(panel.getByText('private-storage-identifier', { exact: true })).toHaveCount(0);
  await expect(panel.getByText('/opt/dis-data', { exact: false })).toHaveCount(0);

  const tableFitsPanel = await table.evaluate((element) => {
    const frame = element.parentElement;
    return frame !== null && frame.scrollWidth <= frame.clientWidth;
  });
  expect(tableFitsPanel).toBe(true);

  const requestsAfterLoad = dataUsageRequests;
  expect(requestsAfterLoad).toBeGreaterThan(0);
  await page.waitForTimeout(3_200);
  expect(dataUsageRequests).toBe(requestsAfterLoad);
});

test('does not call an undated snapshot stale', async ({ page }) => {
  await mockSystemApi(page, () => undefined, {
    ...availableDataUsage,
    generated_at: null,
    stale: true,
  });

  await page.goto('/system');

  const panel = page.getByRole('region', { name: 'Gegevensopslag' });
  await expect(panel.getByText('Nog geen momentopname', { exact: true })).toBeVisible();
  await expect(panel.getByText(/Verouderde momentopname/)).toHaveCount(0);
});

async function mockSystemApi(
  page: Page,
  onDataUsageRequest: () => void,
  dataUsage: SystemDataUsage = availableDataUsage,
): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;

    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, {
        data: {
          id: 'system-data-user',
          name: 'Systeem Beheerder',
          email: 'system-data@example.test',
          account_status: 'active',
          push_enabled: true,
          max_operator_devices: 3,
          two_factor_enabled: true,
          mfa_required: false,
          profile_completion_required: false,
          mail_preferences: { ui: { theme: 'dark' } },
          roles: [{
            id: 'system-data-role',
            name: 'system-data-role',
            display_name: 'Systeembeheer',
            can_use_operator_app: false,
            can_use_admin_app: true,
            permissions: [{
              id: 'system-health-view',
              name: 'system.health.view',
              category: 'system_configuration',
              display_name: 'Systeemstatus bekijken',
            }],
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
      await fulfillJson(route, 200, { data: { notifications: [], unread_count: 0 } });
      return;
    }

    if (path === '/api/admin/health') {
      await fulfillJson(route, 200, {
        data: {
          status: 'ok',
          generated_at: '2026-08-03T10:00:00Z',
          services: {},
          queue: 'redis',
          timestamp: '2026-08-03T10:00:00Z',
        },
      });
      return;
    }

    if (path === '/api/admin/websocket-status') {
      await fulfillJson(route, 200, { data: { status: 'ok' } });
      return;
    }

    if (path === '/api/admin/system/metrics') {
      await fulfillJson(route, 200, {
        data: {
          generated_at: '2026-08-03T10:00:00Z',
          uptime_seconds: 86_400,
          cpu: { usage_percent: 20, logical_processors: 4, load_average_1m: 0.42 },
          memory: {
            total_bytes: 8 * 1024 ** 3,
            used_bytes: 4 * 1024 ** 3,
            available_bytes: 4 * 1024 ** 3,
            usage_percent: 50,
          },
          disk: {
            label: 'DIS data',
            total_bytes: 100 * 1024 ** 3,
            used_bytes: 40 * 1024 ** 3,
            available_bytes: 60 * 1024 ** 3,
            usage_percent: 40,
          },
        },
      });
      return;
    }

    if (path === '/api/admin/system/data-usage') {
      onDataUsageRequest();
      await fulfillJson(route, 200, {
        data: dataUsage,
      });
      return;
    }

    await fulfillJson(route, 503, {
      error: {
        code: 'test_resource_unavailable',
        message: 'Deze inhoud is niet nodig voor de systeemopslagtest.',
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
