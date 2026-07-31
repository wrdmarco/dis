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
  expect(scheduleForDatePart(scheduleWithOverrides, '2026-07-29', 'morning')).toBe(false);
  expect(scheduleForDatePart(scheduleWithOverrides, '2026-07-29', 'afternoon')).toBe(false);
  expect(scheduleForDatePart(scheduleWithOverrides, '2026-07-29', 'evening')).toBe(false);

  const scheduleWithDayPartOverrides: AvailabilitySchedule = {
    ...scheduleWithOverrides,
    overrides: scheduleWithOverrides.overrides.filter((override) => override.day_part !== 'all_day'),
  };
  expect(scheduleForDatePart(scheduleWithDayPartOverrides, '2026-07-29', 'afternoon')).toBe(true);
  expect(scheduleForDatePart(scheduleWithDayPartOverrides, '2026-07-29', 'morning')).toBe(true);

  expect(scheduleForDatePart({
    ...schedule,
    overrides: [
      { ...scheduleWithOverrides.overrides[1], id: 'same-time-a', is_available: true },
      { ...scheduleWithOverrides.overrides[1], id: 'same-time-z', is_available: false },
    ],
  }, '2026-07-29', 'afternoon')).toBe(false);
});

test('connects the shared weekly planner to owner and administrator scopes', () => {
  const detailPage = readSource('../src/features/users/UserDetailPage.tsx');
  const operationalDetails = readSource('../src/features/users/UserOperationalDetails.tsx');
  const profilePage = readSource('../src/features/profile/ProfilePage.tsx');
  const schedulePanel = readSource('../src/features/users/UserAvailabilitySchedule.tsx');

  expect(detailPage).toContain("const canManageAvailabilitySchedule = hasPermission('status.override');");
  expect(detailPage).toContain(
    "const canViewAvailabilitySchedule = hasPermission('status.view') || canManageAvailabilitySchedule;",
  );
  expect(detailPage).toContain('canViewAvailabilitySchedule={canViewAvailabilitySchedule}');
  expect(detailPage).toContain('canManageAvailabilitySchedule={canManageAvailabilitySchedule}');
  expect(operationalDetails).toContain('canView={canViewAvailabilitySchedule}');
  expect(operationalDetails).toContain('canManage={canManageAvailabilitySchedule}');
  expect(profilePage).toContain('scope="mine"');
  expect(profilePage).toContain('canManage');
  expect(schedulePanel).toContain('/availability-statuses/users/${encodeURIComponent(userId ?? \'\')}/availability-schedule');
  expect(schedulePanel).toContain("scope === 'mine'");
  expect(schedulePanel).toContain("const enabled = canView && (scope === 'mine' || userId !== undefined);");

  expect(detailPage).toContain("const canViewVacations = hasPermission('vacations.view') || canManageVacations;");
  expect(detailPage).not.toContain('const canViewAvailabilitySchedule = canViewVacations');
  expect(detailPage).not.toContain('const canViewAvailabilitySchedule = canManageUsers');
});

test('shows the target weekly schedule read-only with status.view', async ({ page }) => {
  const requests = await mockUserDetailApi(page, ['users.view', 'status.view', 'vacations.view']);

  await page.goto(`/users/${targetUser.id}`);

  const planningPanel = panelWithTitle(page, 'Wekelijkse planning');
  await expect(planningPanel).toBeVisible();
  const fixedWeekTable = planningPanel.getByRole('table').first();
  await expect(fixedWeekTable.getByRole('columnheader')).toHaveText(['Dag', 'Ochtend', 'Middag', 'Avond']);
  await expect(fixedWeekTable.getByRole('row', { name: /Dinsdag/ })).toContainText('Niet beschikbaar');
  await expect(planningPanel.getByText('Komende 2 weken', { exact: true })).toBeVisible();
  await expect(planningPanel.getByRole('table', { name: 'Beschikbaarheid komende twee weken' }).getByRole('row')).toHaveCount(15);
  await expect(planningPanel.getByRole('button', { name: 'Planning aanpassen' })).toHaveCount(0);
  await expect(page.getByRole('heading', { level: 2, name: 'Vakantieplanning' })).toBeVisible();
  expect(requests.schedule).toBeGreaterThan(0);
  expect(requests.weekPatternUpdates).toEqual([]);
  expect(requests.overrideCreates).toEqual([]);
});

test('lets status.override manage the target fixed week and fourteen-day overrides', async ({ page }) => {
  const requests = await mockUserDetailApi(page, ['users.view', 'status.override', 'vacations.view']);

  await page.goto(`/users/${targetUser.id}`);

  const expectedOverrideAvailability = await changeFixedWeekAndUpcomingDayPart(
    page,
    'Wekelijkse planning',
  );

  expect(requests.schedule).toBeGreaterThan(0);
  expect(requests.weekPatternUpdates).toHaveLength(1);
  expect(requests.weekPatternUpdates[0].path).toBe(
    `/api/availability-statuses/users/${targetUser.id}/availability-schedule/week-pattern`,
  );
  expect(requests.weekPatternUpdates[0].payload.patterns).toHaveLength(21);
  expect(requests.weekPatternUpdates[0].payload.patterns).toContainEqual({
    day_of_week: 1,
    day_part: 'morning',
    is_available: false,
    note: null,
  });
  expect(requests.overrideCreates).toHaveLength(1);
  expect(requests.overrideCreates[0].path).toBe(
    `/api/availability-statuses/users/${targetUser.id}/availability-schedule/overrides`,
  );
  expect(requests.overrideCreates[0].payload).toEqual({
    starts_at: requests.overrideCreates[0].payload.starts_at,
    ends_at: requests.overrideCreates[0].payload.starts_at,
    day_part: 'morning',
    is_available: expectedOverrideAvailability,
    note: 'Gepland via werkplanning: ochtend',
  });
});

test('does not render or request weekly planning without status.view or status.override', async ({ page }) => {
  const requests = await mockUserDetailApi(page, ['users.view', 'vacations.view']);

  await page.goto(`/users/${targetUser.id}`);

  await expect(page.getByRole('heading', { level: 2, name: 'Vakantieplanning' })).toBeVisible();
  await expect(page.getByRole('heading', { level: 2, name: 'Wekelijkse planning' })).toHaveCount(0);
  expect(requests.schedule).toBe(0);
  expect(requests.weekPatternUpdates).toEqual([]);
  expect(requests.overrideCreates).toEqual([]);
});

test('shows and manages the fixed week and fourteen-day overrides on the own profile', async ({ page }) => {
  const requests = await mockProfileApi(page);

  await page.goto('/profile');

  const expectedOverrideAvailability = await changeFixedWeekAndUpcomingDayPart(
    page,
    'Mijn beschikbaarheid',
  );

  expect(requests.schedule).toBeGreaterThan(0);
  expect(requests.weekPatternUpdates).toHaveLength(1);
  expect(requests.weekPatternUpdates[0].path).toBe('/api/availability-schedule/me/week-pattern');
  expect(requests.weekPatternUpdates[0].payload.patterns).toHaveLength(21);
  expect(requests.weekPatternUpdates[0].payload.patterns).toContainEqual({
    day_of_week: 1,
    day_part: 'morning',
    is_available: false,
    note: null,
  });
  expect(requests.overrideCreates).toHaveLength(1);
  expect(requests.overrideCreates[0].path).toBe('/api/availability-schedule/me/overrides');
  expect(requests.overrideCreates[0].payload).toEqual({
    starts_at: requests.overrideCreates[0].payload.starts_at,
    ends_at: requests.overrideCreates[0].payload.starts_at,
    day_part: 'morning',
    is_available: expectedOverrideAvailability,
    note: 'Gepland via werkplanning: ochtend',
  });
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

  const vacationPanel = page.getByRole('heading', { level: 2, name: 'Vakantieplanning' }).locator('..').locator('..');
  const addVacationButton = vacationPanel.getByRole('button', { name: 'Periode toevoegen', exact: true });
  await addVacationButton.click();
  const vacationDialog = page.getByRole('dialog', { name: 'Periode toevoegen', exact: true });
  await expect(vacationDialog).toBeVisible();
  const startsAt = vacationDialog.getByLabel('Begindatum');
  await expect(startsAt).toBeFocused();
  const today = await startsAt.inputValue();
  await vacationDialog.getByLabel('Einddatum').fill(today);
  await vacationDialog.getByRole('combobox').selectOption('unavailable');
  await vacationDialog.getByRole('button', { name: 'Periode toevoegen', exact: true }).click();

  await expect(vacationDialog).toHaveCount(0);
  await expect(addVacationButton).toBeFocused();
  await expect(page.getByText('Periode toegevoegd.', { exact: true })).toBeVisible();
  await expect.poll(() => requests.schedule).toBeGreaterThan(1);
  await expect(todayMorning).toHaveText('Niet beschikbaar');
  expect(requests.vacationCreate).toBe(1);
});

interface MockRequests {
  schedule: number;
  vacationCreate: number;
  weekPatternUpdates: Array<CapturedRequest<WeekPatternPayload>>;
  overrideCreates: Array<CapturedRequest<ScheduleOverridePayload>>;
}

interface CapturedRequest<T> {
  path: string;
  payload: T;
}

interface WeekPatternPayload {
  patterns: Array<{
    day_of_week: number;
    day_part: 'morning' | 'afternoon' | 'evening';
    is_available: boolean;
    note: string | null;
  }>;
}

interface ScheduleOverridePayload {
  starts_at: string;
  ends_at: string;
  day_part: 'morning' | 'afternoon' | 'evening';
  is_available: boolean;
  note: string | null;
}

interface ScheduleMockState {
  requests: MockRequests;
  currentSchedule: AvailabilitySchedule;
  vacations: UserVacation[];
}

async function mockUserDetailApi(page: Page, permissionNames: string[]): Promise<MockRequests> {
  const state = createScheduleMockState(targetUser.id);

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
      await fulfillJson(route, 200, { data: state.vacations });
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
      state.requests.vacationCreate += 1;
      state.vacations = [vacation];
      state.currentSchedule = {
        ...state.currentSchedule,
        overrides: [{
          id: vacation.id,
          user_id: targetUser.id,
          starts_at: vacation.starts_at,
          ends_at: vacation.ends_at,
          day_part: 'all_day',
          is_available: vacation.is_available,
          note: vacation.note,
          updated_at: '2026-07-28T12:00:00.000000Z',
        }, ...state.currentSchedule.overrides],
      };
      await fulfillJson(route, 201, { data: vacation });
      return;
    }
    if (await handleScheduleApi(
      route,
      path,
      method,
      `/availability-statuses/users/${targetUser.id}/availability-schedule`,
      targetUser.id,
      state,
    )) {
      return;
    }

    await fulfillJson(route, 404, {
      error: { code: 'not_found', message: `Onverwachte testroute: ${path}`, details: {} },
    });
  });

  return state.requests;
}

async function mockProfileApi(page: Page): Promise<MockRequests> {
  const profileUser = authenticatedUser([]);
  const state = createScheduleMockState(profileUser.id);
  const emptyCollectionPaths = new Set([
    '/api/assets/mine',
    '/api/devices',
    '/api/drone-types',
    '/api/certifications/options',
    '/api/certifications/me',
    '/api/vacations/mine',
  ]);

  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const method = route.request().method();

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204 });
      return;
    }
    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, { data: profileUser });
      return;
    }
    if (path === '/api/branding') {
      await fulfillBranding(route);
      return;
    }
    if (method === 'GET' && emptyCollectionPaths.has(path)) {
      await fulfillJson(route, 200, { data: [] });
      return;
    }
    if (await handleScheduleApi(
      route,
      path,
      method,
      '/availability-schedule/me',
      profileUser.id,
      state,
    )) {
      return;
    }

    await fulfillJson(route, 404, {
      error: { code: 'not_found', message: `Onverwachte testroute: ${path}`, details: {} },
    });
  });

  return state.requests;
}

function createScheduleMockState(userId: string): ScheduleMockState {
  return {
    requests: {
      schedule: 0,
      vacationCreate: 0,
      weekPatternUpdates: [],
      overrideCreates: [],
    },
    currentSchedule: {
      ...schedule,
      user_id: userId,
      week_pattern: schedule.week_pattern.map((day) => ({ ...day })),
      week_day_parts: schedule.week_day_parts?.map((day) => ({ ...day })),
      overrides: [],
    },
    vacations: [],
  };
}

async function handleScheduleApi(
  route: Route,
  path: string,
  method: string,
  basePath: string,
  userId: string,
  state: ScheduleMockState,
): Promise<boolean> {
  const apiBasePath = `/api${basePath}`;
  if (path === apiBasePath && method === 'GET') {
    state.requests.schedule += 1;
    await fulfillJson(route, 200, { data: state.currentSchedule });
    return true;
  }
  if (path === `${apiBasePath}/week-pattern` && method === 'PATCH') {
    const payload = route.request().postDataJSON() as WeekPatternPayload;
    state.requests.weekPatternUpdates.push({ path, payload });
    state.currentSchedule = {
      ...state.currentSchedule,
      week_day_parts: payload.patterns.map((day) => ({ ...day, source: 'pattern' })),
    };
    await fulfillJson(route, 200, { data: state.currentSchedule });
    return true;
  }
  if (path === `${apiBasePath}/overrides` && method === 'POST') {
    const payload = route.request().postDataJSON() as ScheduleOverridePayload;
    state.requests.overrideCreates.push({ path, payload });
    state.currentSchedule = {
      ...state.currentSchedule,
      overrides: [{
        id: `override-${state.requests.overrideCreates.length}`,
        user_id: userId,
        ...payload,
        updated_at: '2026-07-28T12:00:00.000000Z',
      }, ...state.currentSchedule.overrides],
    };
    await fulfillJson(route, 201, { data: state.currentSchedule });
    return true;
  }

  return false;
}

async function changeFixedWeekAndUpcomingDayPart(
  page: Page,
  panelTitle: 'Wekelijkse planning' | 'Mijn beschikbaarheid',
): Promise<boolean> {
  const planningPanel = panelWithTitle(page, panelTitle);
  await expect(planningPanel).toBeVisible();
  await planningPanel.getByRole('button', { name: 'Planning aanpassen' }).click();

  const editor = page.getByRole('dialog', { name: 'Beschikbaarheid aanpassen' });
  await expect(editor).toBeVisible();
  const mondayMorning = editor.locator('.week-daypart-row').first().getByRole('button', { name: 'Ochtend: Aan' });
  await mondayMorning.click();
  await editor.getByRole('button', { name: 'Vaste weekplanning opslaan' }).click();
  await expect(editor.getByRole('status')).toHaveText('Vaste weekplanning opgeslagen.');

  const upcomingDays = editor.locator('.daypart-planner-row');
  await expect(upcomingDays).toHaveCount(14);
  const upcomingMorning = upcomingDays.first().getByRole('button', { name: /^Ochtend: (Aan|Uit)$/ });
  const wasAvailable = await upcomingMorning.getAttribute('aria-pressed') === 'true';
  await upcomingMorning.click();
  await expect(editor.getByRole('status')).toContainText('is als');

  return !wasAvailable;
}

function panelWithTitle(page: Page, title: string) {
  return page.locator('section.panel').filter({
    has: page.getByRole('heading', { level: 2, name: title, exact: true }),
  });
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

async function fulfillBranding(route: Route): Promise<void> {
  await fulfillJson(route, 200, {
    data: {
      name: 'DIS',
      short_name: 'DIS',
      tenant_name: 'Testorganisatie',
      logo_data_url: '',
    },
  });
}

function readSource(relativePath: string): string {
  return readFileSync(new URL(relativePath, import.meta.url), 'utf8');
}
