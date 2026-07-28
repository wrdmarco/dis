import { readFileSync } from 'node:fs';
import { expect, test, type Page, type Route } from 'playwright/test';
import { webRouteAccess } from '../src/features/auth/webRouteAccess';
import type {
  CalendarGroup,
  CalendarGroupMemberOptions,
} from '../src/types/api';

test('protects the group-management route with both calendar permissions', () => {
  const route = readFileSync(
    new URL('../app/calendar/groups/page.tsx', import.meta.url),
    'utf8',
  );

  expect(webRouteAccess.calendarGroups.permissions)
    .toEqual(['calendar.view', 'calendar.groups.manage']);
  expect(route).toContain('<ProtectedShell {...webRouteAccess.calendarGroups}>');
});

test('creates reusable groups while keeping Iedereen immutable', async ({ page }) => {
  const state = groupMockState();
  await mockGroupApi(page, state);

  await page.goto('/calendar/groups');

  const everyoneCard = groupCard(page, 'Iedereen');
  await expect(everyoneCard.getByText('Systeemgroep', { exact: true })).toBeVisible();
  await expect(everyoneCard).toContainText(
    'Bevat automatisch alle gebruikers en kan niet worden aangepast of verwijderd.',
  );
  await expect(everyoneCard.getByRole('button')).toHaveCount(0);

  await page.getByLabel('Naam', { exact: true }).fill('Oefenleiding');
  await page.getByLabel('Omschrijving', { exact: true }).fill('Organisatie van oefeningen.');
  await page.getByRole('checkbox', { name: /Ada Operator/ }).check();
  await page.getByRole('checkbox', { name: /Team OCP/ }).check();
  await page.getByRole('checkbox', { name: /Team TUI/ }).check();
  await page.getByRole('button', { name: 'Groep toevoegen', exact: true }).click();

  await expect(page.getByText('Agendagroep toegevoegd.', { exact: true })).toBeVisible();
  await expect(groupCard(page, 'Oefenleiding')).toBeVisible();
  expect(state.postPayloads).toEqual([{
    name: 'Oefenleiding',
    description: 'Organisatie van oefeningen.',
    user_ids: ['user-ada'],
    team_ids: ['team-ocp', 'team-tui'],
  }]);
});

test('keeps a member selected while an in-flight search replaces the visible results', async ({ page }) => {
  const state = groupMockState();
  let releaseSearch!: () => void;
  state.searchGate = new Promise<void>((resolve) => {
    releaseSearch = resolve;
  });
  state.searchOptions = {
    users: [{
      id: 'user-bob',
      name: 'Bob Piloot',
      email: 'bob@example.test',
    }],
    teams: [],
  };
  await mockGroupApi(page, state);

  await page.goto('/calendar/groups');
  await page.getByLabel('Naam', { exact: true }).fill('Zoekgroep');
  await page.getByLabel('Gebruikers zoeken').fill('Bob');
  await page.getByLabel('Gebruikers zoeken').press('Enter');
  await expect(page.getByRole('button', { name: 'Zoeken...' })).toBeVisible();

  await page.getByRole('checkbox', { name: /Ada Operator/ }).check();
  releaseSearch();

  await expect(page.getByRole('checkbox', { name: /Ada Operator/ })).toBeChecked();
  await expect(page.getByRole('checkbox', { name: /Bob Piloot/ })).toBeVisible();
  await page.getByRole('button', { name: 'Groep toevoegen', exact: true }).click();
  await expect(page.getByText('Agendagroep toegevoegd.', { exact: true })).toBeVisible();

  expect(state.postPayloads).toHaveLength(1);
  expect(state.postPayloads[0]?.user_ids).toEqual(['user-ada']);
});

interface GroupMockState {
  groups: CalendarGroup[];
  options: CalendarGroupMemberOptions;
  searchGate?: Promise<void>;
  searchOptions?: CalendarGroupMemberOptions;
  postPayloads: Array<Record<string, unknown>>;
}

function groupMockState(): GroupMockState {
  return {
    groups: [{
      id: 'group-everyone',
      name: 'Iedereen',
      description: null,
      is_everyone: true,
      direct_users: [],
      teams: [],
      direct_user_count: 0,
      team_count: 0,
      effective_member_count: 42,
    }],
    options: {
      users: [{
        id: 'user-ada',
        name: 'Ada Operator',
        email: 'ada@example.test',
      }],
      teams: [
        { id: 'team-ocp', code: 'OCP', name: 'Team OCP' },
        { id: 'team-tui', code: 'TUI', name: 'Team TUI' },
      ],
    },
    postPayloads: [],
  };
}

async function mockGroupApi(page: Page, state: GroupMockState): Promise<void> {
  const permissions = ['calendar.view', 'calendar.groups.manage'];
  await page.route('**/api/**', async (route) => {
    const requestUrl = new URL(route.request().url());
    const path = requestUrl.pathname;
    const method = route.request().method();

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204 });
      return;
    }
    if (path === '/api/auth/me' || path === '/api/auth/session/touch') {
      await fulfillJson(route, 200, { data: calendarUser(permissions) });
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
    if (path === '/api/calendar-groups/member-options' && method === 'GET') {
      if (requestUrl.searchParams.has('search')) {
        await state.searchGate;
        await fulfillJson(route, 200, { data: state.searchOptions ?? state.options });
      } else {
        await fulfillJson(route, 200, { data: state.options });
      }
      return;
    }
    if (path === '/api/calendar-groups' && method === 'GET') {
      await fulfillJson(route, 200, { data: state.groups });
      return;
    }
    if (path === '/api/calendar-groups' && method === 'POST') {
      const payload = route.request().postDataJSON() as Record<string, unknown>;
      state.postPayloads.push(payload);
      const selectedUserIds = payload.user_ids as string[];
      const selectedTeamIds = payload.team_ids as string[];
      const created: CalendarGroup = {
        id: 'group-exercise',
        name: String(payload.name),
        description: payload.description === null ? null : String(payload.description),
        is_everyone: false,
        direct_users: state.options.users.filter((user) => selectedUserIds.includes(user.id)),
        teams: state.options.teams.filter((team) => selectedTeamIds.includes(team.id)),
        direct_user_count: selectedUserIds.length,
        team_count: selectedTeamIds.length,
        effective_member_count: 7,
      };
      state.groups.push(created);
      await fulfillJson(route, 201, { data: created });
      return;
    }

    await fulfillJson(route, 404, {
      error: {
        code: 'not_found',
        message: `Testroute niet gemockt: ${method} ${path}`,
        details: {},
      },
    });
  });
}

function groupCard(page: Page, name: string) {
  return page.locator('article').filter({
    has: page.getByRole('heading', { name, exact: true }),
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
