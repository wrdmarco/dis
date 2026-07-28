import { readFileSync } from 'node:fs';
import { expect, test, type Page, type Route } from 'playwright/test';
import { scheduleForDatePart, scheduleForDayPart } from '../src/features/users/UserAvailabilitySchedule';
import type { AvailabilitySchedule, Permission, Role, User, UserVacation } from '../src/types/api';

const targetUser: User = {
  id: 'target-user',
  name: 'Planning Gebruiker',
  email: 'planning@example.test',
  account_status: 'active',
  push_enabled: true,
  max_operator_devices: 1,
  two_factor_enabled: true,
  roles: [],
  teams: [],
  certifications: [],
  asset_assignments: [],
  fcm_tokens: [],
};

const schedule: AvailabilitySchedule = {
  user_id: targetUser.id,
  week_pattern: Array.from({ length: 7 }, (_, index) => ({
    day_of_week: index + 1,
    day_part: 'all_day',
    is_available: true,
    note: null,
    source: 'pattern',
  })),
  week_day_parts: Array.from({ length: 7 }, (_, index) => (
    ['morning', 'afternoon', 'evening'] as const
  ).map((dayPart) => ({
    day_of_week: index + 1,
    day_part: dayPart,
    is_available: !(index === 1 && dayPart === 'afternoon'),
    note: null,
    source: 'pattern' as const,
  }))).flat(),
  overrides: [],
  today: {
    is_available: true,
    source: 'week_pattern',
    note: null,
  },
};

test('resolves a day part from the specific pattern before the all-day fallback', () => {
  expect(scheduleForDayPart(schedule, 2, 'afternoon').is_available).toBe(false);

  expect(scheduleForDayPart({
    ...schedule,
    week_day_parts: undefined,
    week_pattern: [{ ...schedule.week_pattern[0], day_of_week: 3, is_available: false }],
  }, 3, 'evening').is_available).toBe(false);

  expect(scheduleForDayPart({
    ...schedule,
    week_day_parts: [],
    week_pattern: [],
  }, 4, 'morning').is_available).toBe(true);

  const scheduleWithOverrides: AvailabilitySchedule = {
    ...schedule,
    overrides: [
      {
        id: 'afternoon-older',
        user_id: targetUser.id,
        starts_at: '2026-07-29',
        ends_at: '2026-07-29',
        day_part: 'afternoon',
        is_available: false,
        updated_at: '2026-07-28T08:00:00.000000Z',
      },
      {
        id: 'afternoon-newer',
        user_id: targetUser.id,
        starts_at: '2026-07-29',
        ends_at: '2026-07-29',
        day_part: 'afternoon',
        is_available: true,
        updated_at: '2026-07-28T09:00:00.000000Z',
      },
      {
        id: 'all-day-newest',
        user_id: targetUser.id,
        starts_at: '2026-07-29',
        ends_at: '2026-07-29',
        day_part: 'all_day',
        is_available: false,
        updated_at: '2026-07-28T10:00:00.000000Z',
      },
    ],
  };
  expect(scheduleForDatePart(scheduleWithOverrides, '2026-07-29', 'afternoon')).toBe(true);
  expect(scheduleForDatePart(scheduleWithOverrides, '2026-07-29', 'morning')).toBe(false);

  expect(scheduleForDatePart({
    ...schedule,
    overrides: [
      { ...scheduleWithOverrides.overrides[1], id: 'same-time-a', is_available: true },
      { ...scheduleWithOverrides.overrides[1], id: 'same-time-z', is_available: false },
    ],
  }, '2026-07-29', 'afternoon')).toBe(false);
});

test('connects weekly planning to status.view independently from vacation RBAC', () => {
  const detailPage = readSource('../src/features/users/UserDetailPage.tsx');
  const operationalDetails = readSource('../src/features/users/UserOperationalDetails.tsx');
  const schedulePanel = readSource('../src/features/users/UserAvailabilitySchedule.tsx');

  expect(detailPage).toContain("const canViewAvailabilitySchedule = hasPermission('status.view');");
  expect(detailPage).toContain('canViewAvailabilitySchedule={canViewAvailabilitySchedule}');
  expect(operationalDetails).toContain('canView={canViewAvailabilitySchedule}');
  expect(schedulePanel).toContain('/availability-statuses/users/${encodeURIComponent(userId ?? \'\')}/availability-schedule');
  expect(schedulePanel).toContain('const enabled = canView && userId !== undefined;');

  expect(detailPage).toContain("const canViewVacations = hasPermission('vacations.view') || canManageVacations;");
  expect(detailPage).not.toContain('const canViewAvailabilitySchedule = canViewVacations');
  expect(detailPage).not.toContain('const canViewAvailabilitySchedule = canManageUsers');
});

test('shows the target weekly schedule with status.view', async ({ page }) => {
  const requests = await mockUserDetailApi(page, ['users.view', 'status.view', 'vacations.view']);

  await page.goto(`/users/${targetUser.id}`);

  const planningPanel = page.getByRole('heading', { level: 2, name: 'Wekelijkse planning' }).locator('..').locator('..');
  await expect(planningPanel).toBeVisible();
  const fixedWeekTable = planningPanel.getByRole('table').first();
  await expect(fixedWeekTable.getByRole('columnheader')).toHaveText(['Dag', 'Ochtend', 'Middag', 'Avond']);
  await expect(fixedWeekTable.getByRole('row', { name: /Dinsdag/ })).toContainText('Niet beschikbaar');
  await expect(planningPanel.getByText('Komende 2 weken', { exact: true })).toBeVisible();
  await expect(planningPanel.getByRole('table', { name: 'Beschikbaarheid komende twee weken' }).getByRole('row')).toHaveCount(15);
  await expect(page.getByRole('heading', { level: 2, name: 'Vakantieplanning' })).toBeVisible();
  expect(requests.schedule).toBeGreaterThan(0);
});

test('does not render or request weekly planning without status.view', async ({ page }) => {
  const requests = await mockUserDetailApi(page, ['users.view', 'vacations.view']);

  await page.goto(`/users/${targetUser.id}`);

  await expect(page.getByRole('heading', { level: 2, name: 'Vakantieplanning' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Wekelijkse planning' })).toHaveCount(0);
  expect(requests.schedule).toBe(0);
});

test('refreshes the two-week schedule after a vacation period is added', async ({ page }) => {
  const requests = await mockUserDetailApi(page, [
    'users.view',
    'status.view',
    'vacations.view',
    'vacations.manage',
  ]);

  await page.goto(`/users/${targetUser.id}`);

  const upcomingTable = page.getByRole('table', { name: 'Beschikbaarheid komende twee weken' });
  const todayMorning = upcomingTable.getByRole('row').nth(1).getByRole('cell').first();
  await expect(todayMorning).toHaveText('Beschikbaar');

  const startsAt = page.getByLabel('Begindatum');
  const today = await startsAt.inputValue();
  await page.getByLabel('Einddatum').fill(today);
  const vacationPanel = page.getByRole('heading', { level: 2, name: 'Vakantieplanning' }).locator('..').locator('..');
  await vacationPanel.getByRole('combobox').selectOption('unavailable');
  await page.getByRole('button', { name: 'Periode toevoegen', exact: true }).click();

  await expect(page.getByRole('status')).toHaveText('Periode toegevoegd.');
  await expect.poll(() => requests.schedule).toBeGreaterThan(1);
  await expect(todayMorning).toHaveText('Niet beschikbaar');
  expect(requests.vacationCreate).toBe(1);
});

interface MockRequests {
  schedule: number;
  vacationCreate: number;
}

async function mockUserDetailApi(page: Page, permissionNames: string[]): Promise<MockRequests> {
  const requests: MockRequests = { schedule: 0, vacationCreate: 0 };
  let currentSchedule = schedule;
  let vacations: UserVacation[] = [];

  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204 });
      return;
    }
    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, { data: authenticatedUser(permissionNames) });
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
    if (path === `/api/users/${targetUser.id}`) {
      await fulfillJson(route, 200, { data: targetUser });
      return;
    }
    if (path === `/api/users/${targetUser.id}/vacations` && method === 'GET') {
      await fulfillJson(route, 200, { data: vacations });
      return;
    }
    if (path === `/api/users/${targetUser.id}/vacations` && method === 'POST') {
      const payload = route.request().postDataJSON() as {
        starts_at: string;
        ends_at: string;
        is_available: boolean;
        note: string | null;
      };
      const vacation: UserVacation = {
        id: 'vacation-created',
        user_id: targetUser.id,
        starts_at: payload.starts_at,
        ends_at: payload.ends_at,
        is_available: payload.is_available,
        status: 'active',
        note: payload.note,
      };
      requests.vacationCreate += 1;
      vacations = [vacation];
      currentSchedule = {
        ...schedule,
        overrides: [{
          id: vacation.id,
          user_id: targetUser.id,
          starts_at: vacation.starts_at,
          ends_at: vacation.ends_at,
          day_part: 'all_day',
          is_available: vacation.is_available,
          note: vacation.note,
          updated_at: '2026-07-28T12:00:00.000000Z',
        }],
      };
      await fulfillJson(route, 201, { data: vacation });
      return;
    }
    if (path === `/api/availability-statuses/users/${targetUser.id}/availability-schedule`) {
      requests.schedule += 1;
      await fulfillJson(route, 200, { data: currentSchedule });
      return;
    }

    await fulfillJson(route, 404, {
      error: { code: 'not_found', message: `Onverwachte testroute: ${path}`, details: {} },
    });
  });

  return requests;
}

function authenticatedUser(permissionNames: string[]): User {
  const permissions: Permission[] = permissionNames.map((name, index) => ({
    id: `permission-${index}`,
    name,
    category: 'test',
    display_name: name,
  }));
  const role: Role = {
    id: 'admin-role',
    name: 'admin-role',
    display_name: 'Beheerder',
    can_use_operator_app: false,
    can_use_admin_app: true,
    permissions,
  };

  return {
    ...targetUser,
    id: 'admin-user',
    name: 'Admin Gebruiker',
    email: 'admin@example.test',
    roles: [role],
  };
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}

function readSource(relativePath: string): string {
  return readFileSync(new URL(relativePath, import.meta.url), 'utf8');
}
