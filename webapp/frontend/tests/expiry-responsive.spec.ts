import { expect, test, type Page, type Route } from 'playwright/test';
import {
  assetEffectiveStatus,
  assetIsEffectivelyReady,
  assetStatusPresentation,
} from '../src/lib/assetStatus';
import { dateInputValueInAmsterdam, daysUntilAmsterdamDate } from '../src/lib/dateTime';
import { statusLabel } from '../src/lib/statusLabels';
import type { ExpiryOverview } from '../src/types/api';

test('uses centralized Dutch status labels and preserves unknown values', () => {
  expect(statusLabel('ready')).toBe('Gereed');
  expect(statusLabel('maintenance')).toBe('Onderhoud');
  expect(statusLabel('retired')).toBe('Uit dienst');
  expect(statusLabel('active')).toBe('Actief');
  expect(statusLabel('expired')).toBe('Verlopen');
  expect(statusLabel('critical')).toBe('Kritiek');
  expect(statusLabel('draft')).toBe('Concept');
  expect(statusLabel('sent')).toBe('Verstuurd');
  expect(statusLabel('custom_server_state')).toBe('Custom server state');
  expect(daysUntilAmsterdamDate('2026-08-04', new Date('2026-08-03T22:30:00Z'))).toBe(0);
  expect(daysUntilAmsterdamDate('2026-08-03', new Date('2026-08-03T22:30:00Z'))).toBe(-1);
});

test('prefers server asset readiness and safely derives it for older responses', () => {
  const now = new Date('2026-08-03T10:00:00Z');
  const authoritative = {
    status: 'ready' as const,
    effective_status: 'maintenance' as const,
    is_effectively_ready: false,
    maintenance_due_at: '2026-08-20',
    maintenance_overdue: true,
  };
  const legacy = {
    status: 'assigned' as const,
    maintenance_due_at: '2026-08-02',
  };
  const unavailable = {
    status: 'unavailable' as const,
    effective_status: 'unavailable' as const,
    is_effectively_ready: false,
    maintenance_due_at: '2026-08-02',
    maintenance_overdue: true,
  };

  expect(assetEffectiveStatus(authoritative, now)).toBe('maintenance');
  expect(assetIsEffectivelyReady(authoritative, now)).toBe(false);
  expect(assetStatusPresentation(authoritative, now)).toEqual({
    effectiveStatus: 'maintenance',
    label: 'Onderhoud verlopen',
    maintenanceOverdue: true,
    tone: 'bad',
  });

  expect(assetEffectiveStatus(legacy, now)).toBe('maintenance');
  expect(assetIsEffectivelyReady(legacy, now)).toBe(false);
  expect(assetStatusPresentation(legacy, now).label).toBe('Onderhoud verlopen');
  expect(assetStatusPresentation(unavailable, now)).toMatchObject({ label: 'Niet beschikbaar', tone: 'bad' });
});

test('keeps every expiry detail visible without horizontal scrolling on a narrow phone', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await mockExpiryApi(page);

  await page.goto('/expiry');

  const assetPanel = page.getByRole('region', { name: 'Assets met onderhoudsdatum' });
  const criticalAssets = assetPanel.getByRole('table', { name: 'Kritieke assets' });
  const criticalRow = criticalAssets.locator('tbody tr').filter({ hasText: 'DJI Dock 3' });

  await expect(criticalRow.getByText('DJI Dock 3', { exact: true })).toBeVisible();
  await expect(criticalRow.getByText('AST-INVHUDN', { exact: true })).toBeVisible();
  await expect(criticalRow.getByText('Ondersteunend materieel', { exact: true })).toBeVisible();
  await expect(criticalRow.getByText('Onderhoud verlopen', { exact: true })).toBeVisible();
  await expect(criticalRow.getByText(/dag\(en\) verlopen/)).toBeVisible();
  await expect(criticalRow.locator('.status-pill--bad')).toHaveText('Onderhoud verlopen');
  await expect(criticalRow.locator('.status-pill--bad')).toHaveCSS('text-transform', 'none');

  const soonAssets = assetPanel.getByRole('table', { name: 'Assets met onderhoud binnen 30 dagen' });
  const soonRow = soonAssets.locator('tbody tr').filter({ hasText: 'DJI Matrice 4TD' });
  await expect(soonRow.getByText('Gereed', { exact: true })).toBeVisible();
  await expect(soonRow.locator('td').first()).toHaveText('DJI Matrice 4TD');
  await expect(soonRow.getByText('AST-5MISS0KD', { exact: true })).toBeVisible();

  const certificationPanel = page.getByRole('region', { name: 'Certificaten die verlopen' });
  const certifications = certificationPanel.getByRole('table', { name: 'Certificaten die binnen 30 dagen verlopen' });
  await expect(certifications.getByText('Actief', { exact: true })).toBeVisible();
  await expect(certifications.getByText('Operationeel vliegbewijs', { exact: true })).toBeVisible();

  for (const table of [criticalAssets, soonAssets, certifications]) {
    const layout = await table.evaluate((element) => {
      const panel = element.closest('.panel');
      const cells = Array.from(element.querySelectorAll('td'));

      return {
        cellsWrap: cells.every((cell) => getComputedStyle(cell).whiteSpace === 'normal'),
        panelFits: panel !== null && panel.scrollWidth <= panel.clientWidth,
        tableFits: element.scrollWidth <= element.clientWidth,
      };
    });

    expect(layout).toEqual({ cellsWrap: true, panelFits: true, tableFits: true });
  }

  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
});

test('retains the semantic expiry table on desktop', async ({ page }) => {
  await page.setViewportSize({ width: 1180, height: 800 });
  await mockExpiryApi(page);

  await page.goto('/expiry');

  const table = page.getByRole('table', { name: 'Kritieke assets' });
  await expect(table.getByRole('columnheader', { name: 'Asset' })).toBeVisible();
  await expect(table.getByRole('columnheader', { name: 'Termijn' })).toBeVisible();
  expect(await table.evaluate((element) => getComputedStyle(element).display)).toBe('table');
});

async function mockExpiryApi(page: Page): Promise<void> {
  const overview: ExpiryOverview = {
    days: 60,
    until: dateFromToday(60),
    assets: [
      {
        id: 'asset-overdue',
        name: 'DJI Dock 3',
        asset_tag: 'AST-INVHUDN',
        type: 'support_equipment',
        status: 'ready',
        effective_status: 'maintenance',
        is_effectively_ready: false,
        maintenance_due_at: dateFromToday(-7),
        maintenance_overdue: true,
      },
      {
        id: 'asset-soon',
        name: 'DJI Matrice 4TD',
        asset_tag: 'AST-5MISS0KD',
        type: 'drone',
        status: 'ready',
        maintenance_due_at: dateFromToday(14),
        drone_type: { manufacturer: 'DJI', model: 'Matrice 4TD' },
      },
    ],
    certifications: [{
      id: 'certification-soon',
      user_id: 'expiry-user',
      user_name: 'Test Piloot',
      user_email: 'piloot@example.test',
      certification_id: 'operational-certificate',
      certification_name: 'Operationeel vliegbewijs',
      status: 'active',
      certificate_number: 'CERT-2026-001',
      expires_at: dateFromToday(14),
    }],
  };

  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;

    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, {
        data: {
          id: 'expiry-user',
          name: 'Expiry Beheerder',
          email: 'expiry@example.test',
          account_status: 'active',
          push_enabled: true,
          max_operator_devices: 3,
          two_factor_enabled: true,
          mfa_required: false,
          profile_completion_required: false,
          mail_preferences: { ui: { theme: 'dark' } },
          roles: [{
            id: 'expiry-role',
            name: 'expiry-role',
            display_name: 'Verloopbeheer',
            can_use_operator_app: false,
            can_use_admin_app: true,
            permissions: [{
              id: 'expiry-view',
              name: 'expiry.view',
              category: 'assets',
              display_name: 'Verloop bekijken',
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

    if (path === '/api/auth/session/touch') {
      await fulfillJson(route, 200, { data: { touched: true } });
      return;
    }

    if (path === '/api/expiry-overview') {
      await fulfillJson(route, 200, { data: overview });
      return;
    }

    await fulfillJson(route, 503, {
      error: {
        code: 'test_resource_unavailable',
        message: 'Deze inhoud is niet nodig voor de verlooptest.',
        details: {},
      },
    });
  });
}

function dateFromToday(days: number): string {
  return dateInputValueInAmsterdam(new Date(Date.now() + days * 86_400_000));
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}
