import { expect, test, type Page, type Route } from 'playwright/test';
import type {
  CalendarAudienceGroup,
  CalendarEvent,
  CalendarRegistration,
  CalendarRegistrationOption,
} from '../src/types/api';

test.use({ timezoneId: 'America/New_York' });

const groupOptions: CalendarAudienceGroup[] = [
  {
    id: 'group-everyone',
    name: 'Iedereen',
    is_everyone: true,
    effective_member_count: 42,
  },
  {
    id: 'group-pilots',
    name: 'Piloten West',
    is_everyone: false,
    effective_member_count: 8,
  },
  {
    id: 'group-volunteers',
    name: 'Vrijwilligers',
    is_everyone: false,
    effective_member_count: 12,
  },
];

function registration(
  overrides: Partial<NonNullable<CalendarEvent['registration']>> = {},
): NonNullable<CalendarEvent['registration']> {
  return {
    enabled: true,
    status: 'open',
    max_participants: 8,
    participant_count: 2,
    current_user_registered: false,
    can_register: true,
    can_unregister: false,
    can_view_participants: false,
    can_manage_participants: false,
    unavailable_reason: null,
    ...overrides,
  };
}

function calendarEvent(overrides: Partial<CalendarEvent> = {}): CalendarEvent {
  return {
    id: 'calendar-event-1',
    title: 'Avondtraining',
    type: 'training',
    starts_at: '2026-07-30T19:30:00+02:00',
    ends_at: '2026-07-30T21:00:00+02:00',
    location_label: 'Vliegveld Noord',
    description: 'Neem een opgeladen accu mee.',
    group_ids: ['group-everyone', 'group-pilots'],
    audience_groups: groupOptions.slice(0, 2),
    registration: registration(),
    created_by_name: 'Agenda beheerder',
    created_at: '2026-07-20T09:00:00+02:00',
    ...overrides,
  };
}

test('updates an event with multiple groups and capacity without browser-timezone shifts', async ({ page }) => {
  const state = calendarMockState();
  await mockCalendarApi(page, state, [
    'calendar.view',
    'calendar.manage',
    'calendar.groups.manage',
    'calendar.register',
  ]);

  await page.goto('/calendar');
  const card = calendarCard(page, 'Avondtraining');
  await card.getByRole('button', { name: 'Aanpassen', exact: true }).click();

  const dialog = page.getByRole('dialog', { name: 'Agenda-item aanpassen', exact: true });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Titel', { exact: true })).toBeFocused();
  await expect(dialog.getByLabel('Start', { exact: true })).toHaveValue('2026-07-30T19:30');
  await expect(dialog.getByLabel('Einde', { exact: true })).toHaveValue('2026-07-30T21:00');
  await expect(dialog.getByRole('checkbox', { name: /Iedereen/ })).toBeChecked();
  await expect(dialog.getByRole('checkbox', { name: /Piloten West/ })).toBeChecked();

  await dialog.getByLabel('Titel', { exact: true }).fill('Avondtraining gewijzigd');
  await dialog.getByRole('combobox', { name: 'Type', exact: true }).selectOption('meeting');
  await dialog.getByLabel('Start', { exact: true }).fill('2026-07-31T20:00');
  await dialog.getByLabel('Einde', { exact: true }).fill('2026-07-31T21:30');
  await dialog.getByLabel('Locatie', { exact: true }).fill('Meldkamer');
  await dialog.getByRole('checkbox', { name: /Vrijwilligers/ }).check();
  await dialog.getByLabel(/^Maximum deelnemers/).fill('12');
  await dialog.getByRole('textbox', { name: 'Omschrijving', exact: true }).fill('Nieuwe briefing.');
  await dialog.getByRole('button', { name: 'Wijzigingen opslaan', exact: true }).click();

  await expect(dialog).toHaveCount(0);
  await expect(page.getByText('Agenda-item bijgewerkt.', { exact: true })).toBeVisible();
  await expect(calendarCard(page, 'Avondtraining gewijzigd')).toBeVisible();
  expect(state.patchPayloads).toEqual([{
    title: 'Avondtraining gewijzigd',
    type: 'meeting',
    starts_at: '2026-07-31T20:00',
    ends_at: '2026-07-31T21:30',
    location_label: 'Meldkamer',
    description: 'Nieuwe briefing.',
    group_ids: ['group-everyone', 'group-pilots', 'group-volunteers'],
    registration_enabled: true,
    max_participants: 12,
  }]);
});

test('lets an eligible user register and unregister without loading management options', async ({ page }) => {
  const state = calendarMockState();
  await mockCalendarApi(page, state, ['calendar.view', 'calendar.register']);

  await page.goto('/calendar');
  const card = calendarCard(page, 'Avondtraining');
  await card.getByRole('button', { name: 'Ik kom', exact: true }).click();

  await expect(page.getByText('Je bent ingeschreven voor Avondtraining.', { exact: true }))
    .toBeVisible();
  await expect(card.getByText('Je komt', { exact: true })).toBeVisible();
  await card.getByRole('button', { name: 'Afmelden', exact: true }).click();

  await expect(page.getByText('Je bent afgemeld voor Avondtraining.', { exact: true }))
    .toBeVisible();
  expect(state.registrationWrites).toEqual(['POST:me', 'DELETE:me']);
  expect(state.groupOptionRequests).toBe(0);
});

test('shows a full event as closed and never sends a registration write', async ({ page }) => {
  const state = calendarMockState();
  state.items = [calendarEvent({
    registration: registration({
      status: 'full',
      max_participants: 2,
      participant_count: 2,
      can_register: false,
      unavailable_reason: 'full',
    }),
  })];
  await mockCalendarApi(page, state, ['calendar.view', 'calendar.register']);

  await page.goto('/calendar');
  const card = calendarCard(page, 'Avondtraining');
  await expect(card.getByRole('button', { name: 'Vol', exact: true })).toBeDisabled();
  await expect(card.getByText('2 van 2 deelnemers', { exact: true })).toBeVisible();
  await expect(card.getByText('Dit agenda-item is vol.', { exact: true })).toBeVisible();
  expect(state.registrationWrites).toHaveLength(0);
});

test('reloads the authoritative full state when the last seat is taken concurrently', async ({ page }) => {
  const state = calendarMockState();
  state.rejectNextRegistrationCode = 'calendar_event_full';
  await mockCalendarApi(page, state, ['calendar.view', 'calendar.register']);

  await page.goto('/calendar');
  const card = calendarCard(page, 'Avondtraining');
  await card.getByRole('button', { name: 'Ik kom', exact: true }).click();

  await expect(page.getByText(
    'Dit agenda-item is zojuist vol geraakt. De actuele status is opnieuw geladen.',
    { exact: true },
  )).toBeVisible();
  await expect(card.getByRole('button', { name: 'Vol', exact: true })).toBeDisabled();
  expect(state.registrationWrites).toEqual(['POST:me']);
});

test('loads the private roster lazily and lets an authorized admin register a user', async ({ page }) => {
  const state = calendarMockState();
  state.items = [calendarEvent({
    registration: registration({
      can_register: false,
      can_view_participants: true,
      can_manage_participants: true,
      unavailable_reason: 'permission_required',
    }),
  })];
  await mockCalendarApi(page, state, [
    'calendar.view',
    'calendar.registrations.view',
    'calendar.registrations.manage',
  ]);

  await page.goto('/calendar');
  expect(state.rosterRequests).toBe(0);
  await calendarCard(page, 'Avondtraining')
    .getByRole('button', { name: 'Deelnemers', exact: true })
    .click();

  const dialog = page.getByRole('dialog', { name: 'Avondtraining', exact: true });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByText('Eerste Deelnemer', { exact: true })).toBeVisible();
  expect(state.rosterRequests).toBeGreaterThanOrEqual(1);

  await dialog.getByRole('button', { name: 'Inschrijven', exact: true }).click();
  await expect.poll(() => state.registrationWrites).toContain('POST:user-new');
  await expect(
    dialog.locator('li').filter({ hasText: 'Nieuwe Deelnemer' }),
  ).toContainText('Ingeschreven');
});

interface CalendarMockState {
  items: CalendarEvent[];
  patchPayloads: Array<Record<string, unknown>>;
  registrationWrites: string[];
  groupOptionRequests: number;
  rosterRequests: number;
  rejectNextRegistrationCode: string | null;
  participants: CalendarRegistration[];
  registrationOptions: CalendarRegistrationOption[];
}

function calendarMockState(): CalendarMockState {
  return {
    items: [calendarEvent()],
    patchPayloads: [],
    registrationWrites: [],
    groupOptionRequests: 0,
    rosterRequests: 0,
    rejectNextRegistrationCode: null,
    participants: [{
      id: 'registration-first',
      user: {
        id: 'user-first',
        name: 'Eerste Deelnemer',
        email: 'eerste@example.test',
      },
      registered_at: '2026-07-20T10:00:00+02:00',
      registered_by_name: null,
    }],
    registrationOptions: [{
      id: 'user-new',
      name: 'Nieuwe Deelnemer',
      email: 'nieuw@example.test',
    }],
  };
}

async function mockCalendarApi(
  page: Page,
  state: CalendarMockState,
  permissionNames: string[],
): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const method = route.request().method();

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204 });
      return;
    }
    if (path === '/api/auth/me' || path === '/api/auth/session/touch') {
      await fulfillJson(route, 200, { data: calendarUser(permissionNames) });
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
    if (path === '/api/notifications') {
      await fulfillJson(route, 200, { data: { notifications: [], unread_count: 0 } });
      return;
    }
    if (path === '/api/calendar-events/group-options' && method === 'GET') {
      state.groupOptionRequests += 1;
      await fulfillJson(route, 200, { data: groupOptions });
      return;
    }
    if (path === '/api/calendar-events' && method === 'GET') {
      await fulfillJson(route, 200, { data: state.items });
      return;
    }

    const eventMatch = /^\/api\/calendar-events\/([^/]+)$/.exec(path);
    if (eventMatch !== null && method === 'PATCH') {
      const payload = route.request().postDataJSON() as Record<string, unknown>;
      state.patchPayloads.push(payload);
      const index = state.items.findIndex((item) => item.id === eventMatch[1]);
      if (index < 0) {
        await fulfillError(route, 404, 'not_found', 'Agenda-item niet gevonden.');
        return;
      }

      const selectedGroupIds = payload.group_ids as string[];
      state.items[index] = {
        ...state.items[index],
        title: String(payload.title),
        type: payload.type as CalendarEvent['type'],
        starts_at: String(payload.starts_at),
        ends_at: payload.ends_at === null ? null : String(payload.ends_at),
        location_label: payload.location_label === null ? null : String(payload.location_label),
        description: payload.description === null ? null : String(payload.description),
        group_ids: selectedGroupIds,
        audience_groups: groupOptions.filter((group) => selectedGroupIds.includes(group.id)),
        registration: registration({
          ...state.items[index].registration,
          enabled: Boolean(payload.registration_enabled),
          max_participants: payload.max_participants === null
            ? null
            : Number(payload.max_participants),
        }),
      };
      await fulfillJson(route, 200, { data: state.items[index] });
      return;
    }

    const selfRegistrationMatch =
      /^\/api\/calendar-events\/([^/]+)\/registrations\/me$/.exec(path);
    if (selfRegistrationMatch !== null && (method === 'POST' || method === 'DELETE')) {
      const item = findEvent(state, selfRegistrationMatch[1]);
      const wasRegistered = item.registration?.current_user_registered === true;
      const nowRegistered = method === 'POST';
      state.registrationWrites.push(`${method}:me`);
      if (method === 'POST' && state.rejectNextRegistrationCode !== null) {
        const code = state.rejectNextRegistrationCode;
        state.rejectNextRegistrationCode = null;
        item.registration = registration({
          ...item.registration,
          status: code === 'calendar_event_full' ? 'full' : 'closed',
          participant_count: item.registration?.max_participants
            ?? item.registration?.participant_count
            ?? 0,
          can_register: false,
          unavailable_reason: code,
        });
        await fulfillError(
          route,
          409,
          code,
          code === 'calendar_event_full'
            ? 'Dit agenda-item is vol; inschrijven is gesloten.'
            : 'Inschrijven voor dit agenda-item is gesloten.',
        );
        return;
      }
      item.registration = registration({
        ...item.registration,
        participant_count: Math.max(
          0,
          (item.registration?.participant_count ?? 0)
            + (nowRegistered && !wasRegistered ? 1 : !nowRegistered && wasRegistered ? -1 : 0),
        ),
        current_user_registered: nowRegistered,
        can_register: !nowRegistered,
        can_unregister: nowRegistered,
        unavailable_reason: nowRegistered ? 'already_registered' : null,
      });
      await fulfillJson(route, 200, { data: item });
      return;
    }

    const rosterMatch = /^\/api\/calendar-events\/([^/]+)\/registrations$/.exec(path);
    if (rosterMatch !== null && method === 'GET') {
      state.rosterRequests += 1;
      await fulfillJson(route, 200, { data: state.participants });
      return;
    }

    const optionMatch =
      /^\/api\/calendar-events\/([^/]+)\/registration-options$/.exec(path);
    if (optionMatch !== null && method === 'GET') {
      const registeredUserIds = new Set(state.participants.map((item) => item.user.id));
      await fulfillJson(route, 200, {
        data: state.registrationOptions.filter((option) => !registeredUserIds.has(option.id)),
      });
      return;
    }

    const managedRegistrationMatch =
      /^\/api\/calendar-events\/([^/]+)\/registrations\/([^/]+)$/.exec(path);
    if (managedRegistrationMatch !== null && method === 'POST') {
      const userId = managedRegistrationMatch[2];
      const option = state.registrationOptions.find((candidate) => candidate.id === userId);
      state.registrationWrites.push(`POST:${userId}`);
      if (option !== undefined) {
        state.participants.push({
          id: `registration-${userId}`,
          user: option,
          registered_at: '2026-07-21T10:00:00+02:00',
          registered_by_name: 'Agenda beheerder',
        });
      }
      const item = findEvent(state, managedRegistrationMatch[1]);
      item.registration = registration({
        ...item.registration,
        participant_count: state.participants.length,
        can_register: false,
        can_view_participants: true,
        can_manage_participants: true,
      });
      await fulfillJson(route, 200, { data: item });
      return;
    }

    await fulfillError(route, 404, 'not_found', `Testroute niet gemockt: ${method} ${path}`);
  });
}

function findEvent(state: CalendarMockState, eventId: string): CalendarEvent {
  const item = state.items.find((candidate) => candidate.id === eventId);
  if (item === undefined) {
    throw new Error(`Onbekend testagenda-item: ${eventId}`);
  }
  return item;
}

function calendarCard(page: Page, title: string) {
  return page.locator('article').filter({
    has: page.getByRole('heading', { name: title, exact: true }),
  });
}

function calendarUser(permissionNames: string[]) {
  return {
    id: 'calendar-user',
    name: 'Agenda beheerder',
    email: 'calendar@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    mfa_required: false,
    profile_completion_required: false,
    mail_preferences: { ui: { theme: 'dark' } },
    roles: [{
      id: 'calendar-web-role',
      name: 'calendar-web-role',
      display_name: 'Agenda webrol',
      can_use_operator_app: false,
      can_use_admin_app: true,
      permissions: permissionNames.map((name) => ({
        id: `permission-${name}`,
        name,
        category: 'calendar_management',
        display_name: name,
      })),
    }],
  };
}

async function fulfillJson(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}

async function fulfillError(
  route: Route,
  status: number,
  code: string,
  message: string,
): Promise<void> {
  await fulfillJson(route, status, {
    error: {
      code,
      message,
      details: {},
    },
  });
}
