import { expect, test, type Page, type Route } from 'playwright/test';
import type { CalendarEvent, Team } from '../src/types/api';

test.use({ timezoneId: 'America/New_York' });

const teams: Team[] = [
  {
    id: 'team-ocp',
    code: 'OCP',
    name: 'OCP',
    type: 'operational',
    is_operational: true,
  },
  {
    id: 'team-tui',
    code: 'TUI',
    name: 'TUI',
    type: 'operational',
    parent_team_id: 'team-ocp',
    is_operational: true,
  },
];

function calendarEvent(overrides: Partial<CalendarEvent> = {}): CalendarEvent {
  return {
    id: 'calendar-event-1',
    title: 'Avondtraining',
    type: 'training',
    starts_at: '2026-07-30T19:30:00+02:00',
    ends_at: '2026-07-30T21:00:00+02:00',
    location_label: 'Vliegveld Noord',
    description: 'Neem een opgeladen accu mee.',
    team_id: 'team-ocp',
    team: {
      id: 'team-ocp',
      code: 'OCP',
      name: 'OCP',
      type: 'operational',
    },
    created_by_name: 'Agenda beheerder',
    created_at: '2026-07-20T09:00:00+02:00',
    ...overrides,
  };
}

test('prefills and updates an agenda item without browser-timezone shifts', async ({ page }) => {
  const state = calendarMockState();
  await mockCalendarApi(page, state, ['calendar.view', 'calendar.manage']);

  await page.goto('/calendar');

  const row = calendarRow(page, 'Avondtraining');
  await row.getByRole('button', { name: 'Aanpassen', exact: true }).click();

  let dialog = page.getByRole('dialog', { name: 'Agenda-item aanpassen', exact: true });
  await expect(dialog).toBeVisible();
  await expect(dialog).toHaveAttribute('aria-modal', 'true');
  await expect(dialog.getByLabel('Titel', { exact: true })).toBeFocused();
  await expect(dialog.getByLabel('Titel', { exact: true })).toHaveValue('Avondtraining');
  await expect(dialog.getByRole('combobox', { name: 'Type', exact: true })).toHaveValue('training');
  await expect(dialog.getByLabel('Start', { exact: true })).toHaveValue('2026-07-30T19:30');
  await expect(dialog.getByLabel('Einde', { exact: true })).toHaveValue('2026-07-30T21:00');
  await expect(dialog.getByLabel('Locatie', { exact: true })).toHaveValue('Vliegveld Noord');
  await expect(dialog.getByRole('combobox', { name: 'Team', exact: true })).toHaveValue('team-ocp');
  await expect(dialog.getByRole('textbox', { name: 'Omschrijving', exact: true }))
    .toHaveValue('Neem een opgeladen accu mee.');

  await dialog.getByRole('button', { name: 'Annuleren', exact: true }).click();
  await expect(dialog).toHaveCount(0);

  await calendarRow(page, 'Avondtraining')
    .getByRole('button', { name: 'Aanpassen', exact: true })
    .click();
  dialog = page.getByRole('dialog', { name: 'Agenda-item aanpassen', exact: true });
  await dialog.getByLabel('Titel', { exact: true }).fill('Avondtraining gewijzigd');
  await dialog.getByRole('combobox', { name: 'Type', exact: true }).selectOption('meeting');
  await dialog.getByLabel('Start', { exact: true }).fill('2026-07-31T20:00');
  await dialog.getByLabel('Einde', { exact: true }).fill('2026-07-31T21:30');
  await dialog.getByLabel('Locatie', { exact: true }).fill('Meldkamer');
  await dialog.getByRole('combobox', { name: 'Team', exact: true }).selectOption('team-tui');
  await dialog.getByRole('textbox', { name: 'Omschrijving', exact: true }).fill('Nieuwe briefing.');
  await dialog.getByRole('button', { name: 'Wijzigingen opslaan', exact: true }).click();

  await expect(dialog).toHaveCount(0);
  await expect(page.getByText('Agenda-item bijgewerkt.', { exact: true })).toBeVisible();
  await expect(calendarRow(page, 'Avondtraining gewijzigd')).toBeVisible();
  expect(state.patchPayloads).toEqual([{
    title: 'Avondtraining gewijzigd',
    type: 'meeting',
    starts_at: '2026-07-31T20:00',
    ends_at: '2026-07-31T21:30',
    location_label: 'Meldkamer',
    description: 'Nieuwe briefing.',
    team_id: 'team-tui',
  }]);
});

test('keeps the edit dialog and entered values after a validation error', async ({ page }) => {
  const state = calendarMockState();
  state.rejectNextPatch = true;
  await mockCalendarApi(page, state, ['calendar.view', 'calendar.manage']);

  await page.goto('/calendar');
  await calendarRow(page, 'Avondtraining')
    .getByRole('button', { name: 'Aanpassen', exact: true })
    .click();

  const dialog = page.getByRole('dialog', { name: 'Agenda-item aanpassen', exact: true });
  await dialog.getByLabel('Titel', { exact: true }).fill('Invoer die behouden moet blijven');
  await dialog.getByLabel('Einde', { exact: true }).fill('2026-07-30T22:00');
  await dialog.getByRole('button', { name: 'Wijzigingen opslaan', exact: true }).click();

  await expect(dialog).toBeVisible();
  await expect(dialog.getByLabel('Titel', { exact: true }))
    .toHaveValue('Invoer die behouden moet blijven');
  await expect(dialog.getByLabel('Einde', { exact: true })).toHaveValue('2026-07-30T22:00');
  await expect(dialog.getByRole('alert'))
    .toContainText('De eindtijd moet na de starttijd liggen.');
  expect(state.patchPayloads).toHaveLength(1);
});

test('shows agenda items read-only without loading or exposing mutation controls', async ({ page }) => {
  const state = calendarMockState();
  await mockCalendarApi(page, state, ['calendar.view']);

  await page.goto('/calendar');

  await expect(calendarRow(page, 'Avondtraining')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Agenda-item toevoegen', exact: true })).toHaveCount(0);
  await expect(page.getByRole('columnheader', { name: 'Actie', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Aanpassen', exact: true })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Verwijderen', exact: true })).toHaveCount(0);
  expect(state.teamOptionRequests).toBe(0);
  expect(state.patchPayloads).toHaveLength(0);
});

interface CalendarMockState {
  items: CalendarEvent[];
  patchPayloads: Array<Record<string, unknown>>;
  rejectNextPatch: boolean;
  teamOptionRequests: number;
}

function calendarMockState(): CalendarMockState {
  return {
    items: [calendarEvent()],
    patchPayloads: [],
    rejectNextPatch: false,
    teamOptionRequests: 0,
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
    if (path === '/api/auth/me') {
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
    if (path === '/api/calendar-events/team-options' && method === 'GET') {
      state.teamOptionRequests += 1;
      await fulfillJson(route, 200, { data: teams });
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

      if (state.rejectNextPatch) {
        state.rejectNextPatch = false;
        await fulfillJson(route, 422, {
          message: 'De eindtijd moet na de starttijd liggen.',
          errors: {
            ends_at: ['De eindtijd moet na de starttijd liggen.'],
          },
        });
        return;
      }

      const index = state.items.findIndex((item) => item.id === eventMatch[1]);
      if (index < 0) {
        await fulfillJson(route, 404, {
          error: {
            code: 'not_found',
            message: 'Agenda-item niet gevonden.',
            details: {},
          },
        });
        return;
      }

      const team = teams.find((candidate) => candidate.id === payload.team_id);
      state.items[index] = {
        ...state.items[index],
        title: String(payload.title),
        type: payload.type as CalendarEvent['type'],
        starts_at: String(payload.starts_at),
        ends_at: payload.ends_at === null ? null : String(payload.ends_at),
        location_label: payload.location_label === null ? null : String(payload.location_label),
        description: payload.description === null ? null : String(payload.description),
        team_id: payload.team_id === null ? null : String(payload.team_id),
        team: team === undefined
          ? null
          : {
              id: team.id,
              code: team.code,
              name: team.name,
              type: team.type,
            },
      };
      await fulfillJson(route, 200, { data: state.items[index] });
      return;
    }

    if (path === '/api/auth/session/touch') {
      await fulfillJson(route, 200, { data: calendarUser(permissionNames) });
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

function calendarRow(page: Page, title: string) {
  return page.getByRole('row').filter({ hasText: title });
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
