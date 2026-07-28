import { expect, test, type Page, type Route } from 'playwright/test';
import { webRouteAccess } from '../src/features/auth/webRouteAccess';
import { safeNotificationActionUrl } from '../src/features/notifications/notificationPresentation';
import type {
  Asset,
  ProductRequest,
  UserCertification,
  UserNotification,
} from '../src/types/api';

const ALL_WEB_PERMISSIONS = [...new Set([
  ...Object.values(webRouteAccess).flatMap((access) => access.permissions),
  'product-requests.create',
  'product-requests.resolve',
])];

test('accepts only the three exact first-party notification destinations', () => {
  expect(safeNotificationActionUrl('/profile?asset=asset_7&section=assets'))
    .toBe('/profile?section=assets&asset=asset_7');
  expect(safeNotificationActionUrl('/profile?certification=cert-9&section=certifications'))
    .toBe('/profile?section=certifications&certification=cert-9');
  expect(safeNotificationActionUrl('/verzoeken?request=req-4&tab=mine'))
    .toBe('/verzoeken?tab=mine&request=req-4');

  for (const unsafeUrl of [
    'https://example.test/profile?section=assets&asset=asset_7',
    '//example.test/profile?section=assets&asset=asset_7',
    '/profile?section=assets&asset=asset_7&user=someone-else',
    '/verzoeken?tab=mine&request=req-4&user=someone-else',
    '/profile?section=assets&asset=asset_7#details',
  ]) {
    expect(safeNotificationActionUrl(unsafeUrl)).toBeNull();
  }
});

test('stored unread personal notifications remain visible after a full reload', async ({ page }) => {
  const state = notificationState([
    notification('asset-unread', {
      type: 'asset_maintenance_due',
      tone: 'warning',
      title: 'Onderhoud van jouw drone komt eraan',
      message: 'Asset Alpha heeft binnenkort onderhoud nodig.',
      action_url: '/profile?section=assets&asset=asset-alpha',
    }),
    notification('request-unread', {
      type: 'product_request_status',
      tone: 'success',
      title: 'Jouw verzoek is afgerond',
      message: 'De status van verzoek Radar is bijgewerkt.',
      action_url: '/verzoeken?tab=mine&request=request-radar',
    }),
    notification('already-read', {
      type: 'certification_expiring',
      title: 'Deze gelezen melding blijft verborgen',
      action_url: '/profile?section=certifications&certification=cert-old',
      read_at: '2026-07-28T09:30:00Z',
    }),
  ]);
  await mockNotificationApi(page, state);

  await page.goto('/help');

  const bell = page.getByRole('button', { name: 'Meldingen openen, 2 ongelezen' });
  await expect(bell).toBeVisible();
  await bell.click();

  const popover = page.getByRole('region', { name: 'Meldingen' });
  await expect(popover.getByText('2 ongelezen', { exact: true })).toBeVisible();
  await expect(popover.getByRole('list', { name: 'Ongelezen meldingen' }).getByRole('listitem')).toHaveCount(2);
  await expect(popover.getByText('Onderhoud van jouw drone komt eraan', { exact: true })).toBeVisible();
  await expect(popover.getByText('Jouw verzoek is afgerond', { exact: true })).toBeVisible();
  await expect(popover.getByText('Deze gelezen melding blijft verborgen', { exact: true })).toHaveCount(0);
  expect(state.notificationGetUrls).not.toHaveLength(0);
  expect(state.notificationGetUrls.every((url) => url === '/api/notifications')).toBe(true);

  await page.reload();
  const reloadedBell = page.getByRole('button', { name: 'Meldingen openen, 2 ongelezen' });
  await expect(reloadedBell).toBeVisible();
  await reloadedBell.click();
  await expect(page.getByRole('region', { name: 'Meldingen' })
    .getByText('Onderhoud van jouw drone komt eraan', { exact: true })).toBeVisible();
});

for (const destination of [
  {
    label: 'asset',
    id: 'asset-notification',
    title: 'Onderhoud nodig voor Asset Alpha',
    type: 'asset_maintenance_overdue' as const,
    actionUrl: '/profile?section=assets&asset=asset-alpha',
  },
  {
    label: 'certificaat',
    id: 'certification-notification',
    title: 'Certificaat verloopt binnenkort',
    type: 'certification_expiring' as const,
    actionUrl: '/profile?section=certifications&certification=cert-nine',
  },
  {
    label: 'eigen verzoek',
    id: 'request-notification',
    title: 'Verzoekstatus bijgewerkt',
    type: 'product_request_status' as const,
    actionUrl: '/verzoeken?tab=mine&request=request-four',
  },
]) {
  test(`marks a ${destination.label} notification read before exact navigation`, async ({ page }) => {
    const gate = patchGate(destination.id);
    const state = notificationState([
      notification(destination.id, {
        title: destination.title,
        type: destination.type,
        action_url: destination.actionUrl,
      }),
    ]);
    state.patchGate = gate;
    await mockNotificationApi(page, state);

    await page.goto('/help');
    await page.getByRole('button', { name: /Meldingen openen/ }).click();
    await page.getByRole('button', { name: new RegExp(destination.title) }).click();

    await gate.started.promise;
    expect(pathAndQuery(page.url())).toBe('/help');
    await expect(page.getByText(destination.title, { exact: true })).toBeVisible();

    gate.release.resolve();

    await expect.poll(() => pathAndQuery(page.url())).toBe(destination.actionUrl);
    expect(state.readPatchIds).toEqual([destination.id]);
    await expect(page.getByText(destination.title, { exact: true })).toHaveCount(0);
  });
}

test('mark all read clears the unread list and badge', async ({ page }) => {
  const state = notificationState([
    notification('first', { title: 'Eerste persoonlijke melding' }),
    notification('second', {
      title: 'Tweede persoonlijke melding',
      type: 'product_request_status',
      action_url: '/verzoeken?tab=mine&request=second',
    }),
  ]);
  await mockNotificationApi(page, state);

  await page.goto('/help');
  await page.getByRole('button', { name: 'Meldingen openen, 2 ongelezen' }).click();
  await page.getByRole('button', { name: 'Alles gelezen', exact: true }).click();

  await expect.poll(() => state.markAllPatchCount).toBe(1);
  await expect(page.getByText('Geen ongelezen meldingen', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Meldingen openen, geen ongelezen meldingen' })).toBeVisible();
  await expect(page.getByRole('list', { name: 'Ongelezen meldingen' })).toHaveCount(0);
});

test('all stored unread notifications remain reachable with load more', async ({ page }) => {
  const state = notificationState([
    notification('newest', {
      title: 'Nieuwste bewaarde melding',
      occurred_at: '2026-07-28T12:00:00Z',
    }),
    notification('middle', {
      title: 'Middelste bewaarde melding',
      occurred_at: '2026-07-28T11:00:00Z',
    }),
    notification('oldest', {
      title: 'Oudste bewaarde melding',
      occurred_at: '2026-07-28T10:00:00Z',
    }),
  ]);
  state.pageSize = 2;
  await mockNotificationApi(page, state);

  await page.goto('/help');
  await page.getByRole('button', { name: 'Meldingen openen, 3 ongelezen' }).click();

  const popover = page.getByRole('region', { name: 'Meldingen' });
  const list = popover.getByRole('list', { name: 'Ongelezen meldingen' });
  await expect(list.getByRole('listitem')).toHaveCount(2);
  await popover.getByRole('button', { name: 'Oudere meldingen laden (2 van 3)' }).click();

  await expect(list.getByRole('listitem')).toHaveCount(3);
  await expect(popover.getByText('Oudste bewaarde melding', { exact: true })).toBeVisible();
  await expect(popover.getByRole('button', { name: /Oudere meldingen laden/ })).toHaveCount(0);
  expect(state.notificationGetUrls).toContain('/api/notifications?page=2');
});

test('a failed read PATCH keeps the notification open and does not navigate', async ({ page }) => {
  const state = notificationState([
    notification('read-fails', {
      title: 'Melding die niet mag verdwijnen',
      action_url: '/profile?section=assets&asset=asset-failure',
    }),
  ]);
  state.failReadIds.add('read-fails');
  await mockNotificationApi(page, state);

  await page.goto('/help');
  await page.getByRole('button', { name: /Meldingen openen/ }).click();
  await page.getByRole('button', { name: /Melding die niet mag verdwijnen/ }).click();

  const popover = page.getByRole('region', { name: 'Meldingen' });
  await expect(popover.getByRole('alert')).toHaveText('Markeren als gelezen is tijdelijk niet mogelijk.');
  expect(pathAndQuery(page.url())).toBe('/help');
  await expect(page.getByText('Melding die niet mag verdwijnen', { exact: true })).toBeVisible();
  await expect(popover).toBeVisible();
});

test('focus refresh rings only for a genuinely newer notification', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'no-preference' });
  const state = notificationState([
    notification('initial', {
      title: 'Al aanwezig bij aanmelden',
      occurred_at: '2026-07-28T10:00:00Z',
    }),
  ]);
  await mockNotificationApi(page, state);
  await page.goto('/help');

  await expect(page.getByRole('button', { name: 'Meldingen openen, 1 ongelezen' })).toBeVisible();
  const bellIcon = page.locator('.notification-center__bell');
  const liveStatus = page.locator('.notification-center > .sr-only[role="status"]');
  await expect(bellIcon).not.toHaveClass(/notification-center__bell--ringing/);
  await expect(liveStatus).toHaveText('');

  state.notifications.unshift(notification('newer', {
    title: 'Nieuw binnengekomen',
    occurred_at: '2026-07-28T11:00:00Z',
  }));
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));

  await expect(bellIcon).toHaveClass(/notification-center__bell--ringing/);
  const motion = await bellIcon.evaluate((element) => {
    const animation = element.getAnimations()[0];
    if (animation === undefined) {
      return null;
    }

    animation.pause();
    animation.currentTime = 180;
    const style = window.getComputedStyle(element);

    return {
      animationName: style.animationName,
      transform: style.transform,
    };
  });
  expect(motion?.animationName).toBe('notification-center-ring');
  expect(motion?.transform).not.toBe('none');
  expect(motion?.transform).not.toBe('matrix(1, 0, 0, 1, 0, 0)');
  await expect(liveStatus).toHaveText('Nieuwe persoonlijke melding ontvangen.');
  await expect(page.getByRole('button', { name: 'Meldingen openen, 2 ongelezen' })).toBeVisible();
  await expect(bellIcon).not.toHaveClass(/notification-center__bell--ringing/, { timeout: 3_000 });

  const requestsBeforeBackfill = state.notificationGetUrls.length;
  state.notifications.push(notification('older-backfill', {
    title: 'Oudere melding uit de volgende pagina',
    occurred_at: '2026-07-28T09:00:00Z',
  }));
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect.poll(() => state.notificationGetUrls.length).toBeGreaterThan(requestsBeforeBackfill);
  await page.waitForTimeout(150);
  await expect(bellIcon).not.toHaveClass(/notification-center__bell--ringing/);
  await expect(liveStatus).toHaveText('');
});

test('notification and account popovers are exclusive and Escape restores bell focus', async ({ page }) => {
  await mockNotificationApi(page, notificationState([
    notification('focus-notification', { title: 'Focusmelding' }),
  ]));
  await page.goto('/help');

  const bell = page.getByRole('button', { name: /Meldingen openen/ });
  const account = page.getByRole('button', { name: 'Accountmenu openen', exact: true });

  await bell.click();
  await expect(page.getByRole('region', { name: 'Meldingen' })).toBeVisible();

  await account.click();
  await expect(page.getByRole('region', { name: 'Meldingen' })).toHaveCount(0);
  await expect(page.getByLabel('Accountmenu', { exact: true })).toBeVisible();

  await bell.click();
  await expect(page.getByLabel('Accountmenu', { exact: true })).toHaveCount(0);
  await expect(page.getByRole('region', { name: 'Meldingen' })).toBeVisible();

  await page.keyboard.press('Escape');
  await expect(page.getByRole('region', { name: 'Meldingen' })).toHaveCount(0);
  await expect(bell).toBeFocused();
  await expect(bell).toHaveAttribute('aria-expanded', 'false');
});

test('profile notification queries focus the exact matching own row', async ({ page }) => {
  await mockNotificationApi(page, notificationState([]));

  await page.goto('/profile?section=assets&asset=asset-alpha');
  const assetTarget = page.locator('#profile-asset-asset-alpha');
  await expect(assetTarget).toContainText('Asset Alpha');
  await expect(assetTarget).toBeFocused();
  await expect(page.locator('#profile-asset-asset-other')).not.toBeFocused();

  await page.goto('/profile?section=certifications&certification=user-cert-nine');
  const certificationTarget = page.locator('#profile-certification-user-cert-nine');
  await expect(certificationTarget).toContainText('Vliegbewijs A1/A3');
  await expect(certificationTarget).toBeFocused();
  await expect(page.locator('#profile-certification-user-cert-other')).not.toBeFocused();
});

test('request notification query loads its exact detail even when the list is empty', async ({ page }) => {
  const state = notificationState([]);
  await mockNotificationApi(page, state);

  await page.goto('/verzoeken?tab=mine&request=request-four');

  await expect(page.getByRole('dialog', { name: 'Eigen verzoek uit melding' })).toBeVisible();
  await expect(page.getByRole('dialog').getByText('Detail van het eigen verzoek.', { exact: true })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mijn verzoeken', exact: true })).toHaveAttribute('aria-pressed', 'true');
  expect(pathAndQuery(page.url())).toBe('/verzoeken?tab=mine&request=request-four');
  expect(new Set(state.productRequestDetailGets)).toEqual(new Set(['request-four']));
});

test('mobile bell and popover stay inside the viewport without horizontal overflow', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 700 });
  await mockNotificationApi(page, notificationState([
    notification('mobile-asset', {
      title: 'Onderhoud van eigen asset',
      message: 'Een compacte melding die op mobiel volledig bereikbaar blijft.',
    }),
    notification('mobile-request', {
      title: 'Status van eigen verzoek',
      type: 'product_request_status',
      action_url: '/verzoeken?tab=mine&request=mobile-request',
    }),
  ]));

  await page.goto('/help');
  const topbar = page.locator('header.topbar');
  const bell = topbar.getByRole('button', { name: /Meldingen openen/ });
  await expect(bell).toBeVisible();
  await bell.click();

  const popover = page.getByRole('region', { name: 'Meldingen' });
  await expect(popover).toBeVisible();
  const [topbarBox, popoverBox] = await Promise.all([topbar.boundingBox(), popover.boundingBox()]);
  expect(topbarBox).not.toBeNull();
  expect(popoverBox).not.toBeNull();
  expect(popoverBox!.x).toBeGreaterThanOrEqual(0);
  expect(popoverBox!.x + popoverBox!.width).toBeLessThanOrEqual(390);
  expect(popoverBox!.y).toBeGreaterThanOrEqual(topbarBox!.y + topbarBox!.height - 1);
  expect(popoverBox!.y + popoverBox!.height).toBeLessThanOrEqual(700);

  const viewport = await page.evaluate(() => ({
    innerWidth: window.innerWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }));
  expect(viewport.scrollWidth).toBeLessThanOrEqual(viewport.innerWidth);
});

interface Deferred {
  promise: Promise<void>;
  resolve: () => void;
}

interface ReadPatchGate {
  id: string;
  started: Deferred;
  release: Deferred;
}

interface NotificationMockState {
  notifications: UserNotification[];
  notificationGetUrls: string[];
  readPatchIds: string[];
  markAllPatchCount: number;
  failReadIds: Set<string>;
  productRequestDetailGets: string[];
  pageSize?: number;
  patchGate?: ReadPatchGate;
}

function deferred(): Deferred {
  let resolve = () => undefined;
  const promise = new Promise<void>((resolvePromise) => {
    resolve = resolvePromise;
  });

  return { promise, resolve };
}

function patchGate(id: string): ReadPatchGate {
  return {
    id,
    started: deferred(),
    release: deferred(),
  };
}

function notificationState(notifications: UserNotification[]): NotificationMockState {
  return {
    notifications,
    notificationGetUrls: [],
    readPatchIds: [],
    markAllPatchCount: 0,
    failReadIds: new Set<string>(),
    productRequestDetailGets: [],
  };
}

function notification(
  id: string,
  overrides: Partial<UserNotification> = {},
): UserNotification {
  return {
    id,
    type: 'asset_maintenance_due',
    tone: 'warning',
    title: `Persoonlijke melding ${id}`,
    message: 'Deze melding hoort bij jouw eigen gegevens.',
    action_url: `/profile?section=assets&asset=${id}`,
    occurred_at: '2026-07-28T10:00:00Z',
    read_at: null,
    ...overrides,
  };
}

async function mockNotificationApi(page: Page, state: NotificationMockState): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const method = route.request().method();

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204 });
      return;
    }

    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, { data: notificationTestUser() });
      return;
    }

    if (path === '/api/auth/session/touch' && method === 'POST') {
      await fulfillJson(route, 200, { data: notificationTestUser() });
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

    if (path === '/api/notifications' && method === 'GET') {
      state.notificationGetUrls.push(`${path}${url.search}`);
      const unreadNotifications = state.notifications.filter((item) => item.read_at === null);
      const requestedPage = Math.max(1, Number.parseInt(url.searchParams.get('page') ?? '1', 10) || 1);
      const pageSize = state.pageSize ?? Math.max(1, state.notifications.length);
      const pageNotifications = state.pageSize === undefined
        ? state.notifications
        : unreadNotifications.slice((requestedPage - 1) * pageSize, requestedPage * pageSize);
      const lastPage = Math.max(1, Math.ceil(unreadNotifications.length / pageSize));
      await fulfillJson(route, 200, {
        data: {
          notifications: pageNotifications,
          unread_count: unreadNotifications.length,
          current_page: requestedPage,
          last_page: lastPage,
          next_page: requestedPage < lastPage ? requestedPage + 1 : null,
        },
      });
      return;
    }

    if (path === '/api/notifications/read-all' && method === 'PATCH') {
      state.markAllPatchCount += 1;
      const markedRead = state.notifications.filter((item) => item.read_at === null).length;
      state.notifications = [];
      await fulfillJson(route, 200, { data: { marked_read: markedRead } });
      return;
    }

    const notificationReadMatch = /^\/api\/notifications\/([^/]+)\/read$/.exec(path);
    if (notificationReadMatch !== null && method === 'PATCH') {
      const id = decodeURIComponent(notificationReadMatch[1]);
      state.readPatchIds.push(id);

      if (state.failReadIds.has(id)) {
        await fulfillJson(route, 503, {
          error: {
            code: 'notification_read_unavailable',
            message: 'Markeren als gelezen is tijdelijk niet mogelijk.',
            details: {},
          },
        });
        return;
      }

      if (state.patchGate?.id === id) {
        state.patchGate.started.resolve();
        await state.patchGate.release.promise;
      }

      const readNotification = state.notifications.find((item) => item.id === id);
      state.notifications = state.notifications.filter((item) => item.id !== id);
      await fulfillJson(route, 200, {
        data: {
          ...(readNotification ?? notification(id)),
          read_at: '2026-07-28T10:01:00Z',
        },
      });
      return;
    }

    if (path === '/api/product-requests/request-four' && method === 'GET') {
      state.productRequestDetailGets.push('request-four');
      await fulfillJson(route, 200, { data: linkedProductRequest() });
      return;
    }

    if (path === '/api/product-requests' && method === 'GET') {
      await fulfillJson(route, 200, {
        data: [],
        meta: {
          current_page: 1,
          from: null,
          last_page: 1,
          path: '/api/product-requests',
          per_page: 25,
          to: null,
          total: 0,
        },
      });
      return;
    }

    if (path === '/api/assets/mine' && method === 'GET') {
      await fulfillJson(route, 200, { data: ownAssets() });
      return;
    }

    if (path === '/api/certifications/me' && method === 'GET') {
      await fulfillJson(route, 200, { data: ownCertifications() });
      return;
    }

    if (path === '/api/availability-schedule/me' && method === 'GET') {
      await fulfillJson(route, 200, {
        data: {
          user_id: 'notification-user',
          week_pattern: [],
          week_day_parts: [],
          overrides: [],
          today: {
            is_available: true,
            source: 'default',
            note: null,
          },
        },
      });
      return;
    }

    if (method === 'GET') {
      await fulfillJson(route, 200, { data: [] });
      return;
    }

    await fulfillJson(route, 200, { data: {} });
  });
}

function notificationTestUser() {
  return {
    id: 'notification-user',
    name: 'Eigen gebruiker',
    email: 'eigen.gebruiker@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    mfa_required: false,
    profile_completion_required: false,
    mail_preferences: { ui: { theme: 'dark' } },
    roles: [{
      id: 'notification-role',
      name: 'notification-role',
      display_name: 'Notificatietestrol',
      can_use_operator_app: false,
      can_use_admin_app: true,
      permissions: ALL_WEB_PERMISSIONS.map((name, index) => ({
        id: `notification-permission-${index}`,
        name,
        category: 'notifications',
        display_name: name,
      })),
    }],
  };
}

function linkedProductRequest(): ProductRequest {
  return {
    id: 'request-four',
    type: 'bug',
    status: 'resolved',
    title: 'Eigen verzoek uit melding',
    description: 'Detail van het eigen verzoek.',
    resolution_note: 'Afgerond.',
    requester: { id: 'notification-user', name: 'Eigen gebruiker' },
    resolved_by: { id: 'resolver', name: 'Behandelaar' },
    resolved_at: '2026-07-28T09:50:00Z',
    lock_version: 2,
    is_owner: true,
    can_update: false,
    can_resolve: true,
    created_at: '2026-07-27T08:00:00Z',
    updated_at: '2026-07-28T09:50:00Z',
  };
}

function ownAssets(): Asset[] {
  return [
    {
      id: 'asset-other',
      asset_tag: 'ASSET-002',
      name: 'Asset Beta',
      type: 'drone',
      drone_type_id: null,
      drone_type: null,
      has_spotlight: false,
      has_speaker: false,
      status: 'ready',
      serial_number: 'BETA-2',
      maintenance_due_at: '2026-10-01',
      notes: null,
      active_assignment: null,
    },
    {
      id: 'asset-alpha',
      asset_tag: 'ASSET-001',
      name: 'Asset Alpha',
      type: 'drone',
      drone_type_id: null,
      drone_type: null,
      has_spotlight: true,
      has_speaker: false,
      status: 'assigned',
      serial_number: 'ALPHA-1',
      maintenance_due_at: '2026-08-01',
      notes: null,
      active_assignment: null,
    },
  ];
}

function ownCertifications(): UserCertification[] {
  return [
    {
      id: 'user-cert-other',
      user_id: 'notification-user',
      certification_id: 'cert-other',
      issued_at: '2025-01-01',
      expires_at: '2027-01-01',
      certificate_number: 'OTHER-1',
      status: 'active',
      certification: {
        id: 'cert-other',
        code: 'OTHER',
        name: 'Overig certificaat',
        description: null,
        is_required_for_dispatch: false,
        warning_days_before_expiry: 30,
      },
    },
    {
      id: 'user-cert-nine',
      user_id: 'notification-user',
      certification_id: 'cert-nine',
      issued_at: '2024-09-01',
      expires_at: '2026-08-15',
      certificate_number: 'A1A3-009',
      status: 'active',
      certification: {
        id: 'cert-nine',
        code: 'A1/A3',
        name: 'Vliegbewijs A1/A3',
        description: null,
        is_required_for_dispatch: true,
        warning_days_before_expiry: 30,
      },
    },
  ];
}

function pathAndQuery(rawUrl: string): string {
  const url = new URL(rawUrl);
  return `${url.pathname}${url.search}`;
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}
