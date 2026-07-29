import { readFileSync } from 'node:fs';
import { expect, test, type Page, type Route } from 'playwright/test';
import {
  ADMIN_SYSTEM_LOG_CLIENT_LINE_LIMIT,
  ADMIN_SYSTEM_LOG_POLL_INTERVAL_MS,
  adminSystemLogChunkRequiresReset,
  adminSystemLogPath,
  adminSystemLogShouldFollow,
  mergeAdminSystemLogLines,
  normalizeAdminSystemLogPollInterval,
  startAdminSystemLogPolling,
} from '../src/features/admin/adminSystemLogViewer';

test('adds a permission-gated lazy Logbestanden tab to Admin', () => {
  const adminPage = source('../src/features/admin/AdminPage.tsx');
  const panel = source('../src/features/admin/AdminLogFilesPanel.tsx');
  const access = source('../src/features/auth/webRouteAccess.ts');
  const help = source('../src/features/help/HelpPage.tsx');

  expect(adminPage).toContain("import('./AdminLogFilesPanel')");
  expect(adminPage).toContain("{ id: 'logs', label: 'Logbestanden' }");
  expect(adminPage).toContain("hasPermission('system.logs.view')");
  expect(adminPage).toContain("activeTab === 'logs' && canViewSystemLogs ? <AdminLogFilesPanel />");
  expect(adminPage).toContain('visibleAdminTabs[0]?.id');
  expect(access).toContain("'system.logs.view'");
  expect(help).toContain("permissions: ['system.logs.view']");
  expect(panel).toContain("useApiResource<AdminSystemLogIndex>('/admin/system/logs')");
  expect(panel).not.toContain('/developer/logs');
  expect(panel).not.toContain('X-DIS-Developer-Key');
});

test('opens for a logs-only administrator and follows the newest daily file', async ({ page }) => {
  let latestReads = 0;
  await mockAdminSystemLogsApi(page, () => {
    latestReads += 1;
    if (latestReads === 1) {
      return logChunk({
        name: 'laravel-2026-07-28.log',
        generation: 'a'.repeat(64),
        checkpoint: 'b'.repeat(64),
        cursor: 18,
        lines: ['oude dag'],
      });
    }

    return logChunk({
      name: 'laravel-2026-07-29.log',
      generation: 'c'.repeat(64),
      checkpoint: 'd'.repeat(64),
      cursor: 21,
      reset: true,
      reset_reason: 'rotated',
      lines: ['nieuwe dag', '<script>blijft tekst</script>'],
    });
  });

  await page.goto('/admin');

  await expect(page.getByRole('tab', { name: 'Logbestanden' })).toHaveAttribute('aria-selected', 'true');
  const sourceSelector = page.getByRole('combobox', { name: 'Logbestand' });
  await expect(sourceSelector).toHaveValue('laravel-2026-07-28.log');
  await expect(page.getByLabel('Live logbestand laravel-2026-07-28.log')).toContainText('oude dag');

  await expect(sourceSelector).toHaveValue('laravel-2026-07-29.log', {
    timeout: 5_000,
  });
  const rotatedViewer = page.getByLabel('Live logbestand laravel-2026-07-29.log');
  await expect(rotatedViewer).toContainText('nieuwe dag');
  await expect(rotatedViewer).toContainText('<script>blijft tekst</script>');
  await expect(page.locator('script').filter({ hasText: 'blijft tekst' })).toHaveCount(0);

  const connectionStatus = page.getByRole('status').filter({ hasText: 'Nieuwe logregels' });
  await expect(connectionStatus).not.toContainText('Laatste controle');
});

test('keeps an explicitly selected archive file out of latest-file rotation', async ({ page }) => {
  let latestReads = 0;
  let archiveReads = 0;
  await mockAdminSystemLogsApi(
    page,
    () => {
      latestReads += 1;
      return logChunk({
        name: 'laravel-2026-07-29.log',
        generation: 'a'.repeat(64),
        checkpoint: 'b'.repeat(64),
        cursor: 20,
        lines: latestReads === 1 ? ['actuele dag'] : [],
      });
    },
    {
      inventory: [
        {
          name: 'laravel-2026-07-29.log',
          size_bytes: 20,
          modified_at: '2026-07-29T10:00:00Z',
        },
        {
          name: 'laravel-2026-07-28.log',
          size_bytes: 18,
          modified_at: '2026-07-28T23:59:00Z',
        },
      ],
      nextArchiveChunk: () => {
        archiveReads += 1;
        return logChunk({
          name: 'laravel-2026-07-28.log',
          generation: 'c'.repeat(64),
          checkpoint: 'd'.repeat(64),
          cursor: 18,
          lines: archiveReads === 1 ? ['bewust archief'] : [],
        });
      },
    },
  );

  await page.goto('/admin');
  const sourceSelector = page.getByRole('combobox', { name: 'Logbestand' });
  await expect(page.getByLabel('Live logbestand laravel-2026-07-29.log')).toContainText('actuele dag');
  await sourceSelector.selectOption('laravel-2026-07-28.log');
  await expect(page.getByLabel('Live logbestand laravel-2026-07-28.log')).toContainText('bewust archief');

  const latestReadsAfterSelection = latestReads;
  await expect.poll(() => archiveReads, { timeout: 4_000 }).toBeGreaterThanOrEqual(2);
  expect(latestReads).toBe(latestReadsAfterSelection);
  await expect(sourceSelector).toHaveValue('laravel-2026-07-28.log');
});

test('never renders or requests log files without system.logs.view', async ({ page }) => {
  let logRequests = 0;
  await mockAdminSystemLogsApi(
    page,
    () => logChunk({
      name: 'laravel.log',
      generation: 'a'.repeat(64),
      checkpoint: 'b'.repeat(64),
      cursor: 0,
      lines: [],
    }),
    {
      permissions: ['system.health.view'],
      onLogRequest: () => {
        logRequests += 1;
      },
    },
  );

  await page.goto('/admin');
  await expect(page.getByRole('tab', { name: 'Logbestanden' })).toHaveCount(0);
  await expect(page.getByText('Systeemlogboeken', { exact: true })).toHaveCount(0);
  await page.waitForTimeout(1_200);
  expect(logRequests).toBe(0);
});

test('encodes log names and rotation generations in bounded cursor paths', () => {
  expect(adminSystemLogPath('laravel.log')).toBe('/admin/system/logs/laravel.log');
  expect(adminSystemLogPath('queue/work.log', 42, 'generation a/b', 'checkpoint c/d')).toBe(
    '/admin/system/logs/queue%2Fwork.log?cursor=42&generation=generation%20a%2Fb&checkpoint=checkpoint%20c%2Fd',
  );
  expect(adminSystemLogPath('laravel.log', -12, 'next', 'checkpoint')).toBe(
    '/admin/system/logs/laravel.log?cursor=0&generation=next&checkpoint=checkpoint',
  );
  expect(adminSystemLogPath('laravel-2026-07-28.log', 10, 'next', 'checkpoint', true)).toBe(
    '/admin/system/logs/latest?cursor=10&generation=next&checkpoint=checkpoint',
  );
});

test('resets on log rotation and keeps at most two thousand recent lines', () => {
  expect(adminSystemLogChunkRequiresReset('', 'generation-1', false)).toBe(false);
  expect(adminSystemLogChunkRequiresReset('generation-1', 'generation-1', false)).toBe(false);
  expect(adminSystemLogChunkRequiresReset('generation-1', 'generation-2', false)).toBe(true);
  expect(adminSystemLogChunkRequiresReset('generation-1', 'generation-1', true)).toBe(true);

  const current = Array.from(
    { length: ADMIN_SYSTEM_LOG_CLIENT_LINE_LIMIT },
    (_, index) => `regel-${index + 1}`,
  );
  const appended = mergeAdminSystemLogLines(current, ['nieuw-1', 'nieuw-2'], false);
  expect(appended.lines).toHaveLength(ADMIN_SYSTEM_LOG_CLIENT_LINE_LIMIT);
  expect(appended.lines[0]).toBe('regel-3');
  expect(appended.lines.at(-1)).toBe('nieuw-2');
  expect(appended.truncated).toBe(true);

  expect(mergeAdminSystemLogLines(appended.lines, ['verse-tail'], true)).toEqual({
    lines: ['verse-tail'],
    truncated: false,
  });

  const unchanged = mergeAdminSystemLogLines(appended.lines, [], false);
  expect(unchanged.lines).toBe(appended.lines);
  expect(unchanged.truncated).toBe(false);
});

test('follows only while the viewport remains near the log bottom', () => {
  expect(adminSystemLogShouldFollow(1_000, 520, 400)).toBe(true);
  expect(adminSystemLogShouldFollow(1_000, 500, 400)).toBe(false);
  expect(adminSystemLogShouldFollow(400, 0, 400)).toBe(true);
});

test('polls every two seconds without overlap and pauses while hidden', async () => {
  expect(ADMIN_SYSTEM_LOG_POLL_INTERVAL_MS).toBe(2_000);
  expect(normalizeAdminSystemLogPollInterval(50)).toBe(1_000);
  expect(normalizeAdminSystemLogPollInterval(45_000)).toBe(30_000);
  expect(normalizeAdminSystemLogPollInterval(Number.NaN)).toBe(2_000);

  let hidden = false;
  let interval = 2_500;
  let visibilityListener = () => undefined;
  let nextTimer = 0;
  let loadCount = 0;
  const scheduled = new Map<number, { callback: () => void; delay: number }>();
  const pendingLoads: Array<() => void> = [];

  const polling = startAdminSystemLogPolling({
    load: () => new Promise<void>((resolve) => {
      loadCount += 1;
      pendingLoads.push(resolve);
    }),
    isHidden: () => hidden,
    schedule: (callback, delay) => {
      nextTimer += 1;
      scheduled.set(nextTimer, { callback, delay });
      return nextTimer;
    },
    cancel: (handle) => {
      scheduled.delete(handle);
    },
    subscribeVisibility: (listener) => {
      visibilityListener = listener;
      return () => {
        visibilityListener = () => undefined;
      };
    },
    intervalMs: () => interval,
  });

  expect(loadCount).toBe(1);
  polling.refresh();
  polling.refresh();
  expect(loadCount).toBe(1);

  pendingLoads.shift()?.();
  await settleAsyncWork();
  expect(loadCount).toBe(2);
  expect(scheduled.size).toBe(0);

  pendingLoads.shift()?.();
  await settleAsyncWork();
  expect([...scheduled.values()][0]?.delay).toBe(2_500);

  hidden = true;
  visibilityListener();
  expect(scheduled.size).toBe(0);

  hidden = false;
  visibilityListener();
  expect(loadCount).toBe(3);

  interval = 45_000;
  pendingLoads.shift()?.();
  await settleAsyncWork();
  expect([...scheduled.values()][0]?.delay).toBe(30_000);

  polling.stop();
  expect(scheduled.size).toBe(0);
});

async function settleAsyncWork() {
  await Promise.resolve();
  await Promise.resolve();
}

function source(path: string): string {
  return readFileSync(new URL(path, import.meta.url), 'utf8');
}

interface TestLogChunkOverrides {
  name: string;
  generation: string;
  checkpoint: string;
  cursor: number;
  reset?: boolean;
  reset_reason?: 'rotated' | 'truncated' | 'replaced' | null;
  lines: string[];
}

function logChunk(overrides: TestLogChunkOverrides) {
  return {
    name: overrides.name,
    size_bytes: overrides.cursor,
    modified_at: '2026-07-29T10:00:00Z',
    cursor: overrides.cursor,
    generation: overrides.generation,
    checkpoint: overrides.checkpoint,
    reset: overrides.reset ?? false,
    reset_reason: overrides.reset_reason ?? null,
    truncated: false,
    poll_after_ms: 1_000,
    lines: overrides.lines,
  };
}

async function mockAdminSystemLogsApi(
  page: Page,
  nextChunk: () => ReturnType<typeof logChunk>,
  options: {
    inventory?: Array<{
      name: string;
      size_bytes: number;
      modified_at: string | null;
    }>;
    nextArchiveChunk?: () => ReturnType<typeof logChunk>;
    permissions?: string[];
    onLogRequest?: () => void;
  } = {},
): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path === '/api/auth/me' || path === '/api/auth/session/touch') {
      await fulfillJson(route, 200, {
        data: {
          id: 'system-log-user',
          name: 'Logbeheerder',
          email: 'logs@example.test',
          account_status: 'active',
          push_enabled: true,
          max_operator_devices: 3,
          two_factor_enabled: true,
          mfa_required: false,
          profile_completion_required: false,
          mail_preferences: { ui: { theme: 'dark' } },
          roles: [{
            id: 'system-log-role',
            name: 'system-log-viewer',
            display_name: 'Logbeheerder',
            can_use_operator_app: false,
            can_use_admin_app: true,
            permissions: (options.permissions ?? ['system.logs.view']).map((permission) => ({
              id: permission,
              name: permission,
              category: 'system_configuration',
              display_name: permission,
            })),
          }],
        },
      });
      return;
    }

    if (path === '/api/branding') {
      await fulfillJson(route, 200, {
        data: {
          name: 'DIS',
          short_name: 'DIS',
          tenant_name: 'Testorganisatie',
          logo_data_url: '',
        },
      });
      return;
    }

    if (path === '/api/admin/system/logs') {
      options.onLogRequest?.();
      await fulfillJson(route, 200, {
        data: {
          logs: options.inventory ?? [{
            name: 'laravel-2026-07-28.log',
            size_bytes: 18,
            modified_at: '2026-07-28T23:59:00Z',
          }],
        },
      });
      return;
    }

    if (path === '/api/admin/system/logs/latest') {
      options.onLogRequest?.();
      await fulfillJson(route, 200, { data: nextChunk() });
      return;
    }

    if (path.startsWith('/api/admin/system/logs/') && options.nextArchiveChunk !== undefined) {
      options.onLogRequest?.();
      await fulfillJson(route, 200, { data: options.nextArchiveChunk() });
      return;
    }

    await fulfillJson(route, 404, {
      error: {
        code: 'not_found',
        message: 'Testroute niet gemockt.',
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
