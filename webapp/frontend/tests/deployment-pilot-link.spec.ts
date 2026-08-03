import { expect, test, type Page, type Route } from 'playwright/test';

const deploymentId = 'deployment-1';
const ocpTeam = {
  id: 'team-ocp',
  code: 'OCP',
  name: 'Operationeel',
  type: 'operational',
  is_operational: true,
};

test('links a pilot with an informational push, updates arrival status and only unlinks a manual assignment', async ({ page }) => {
  const api = await mockDeploymentPilotsApi(page);
  await page.goto(`/inzetten/${deploymentId}`);

  const pilotPanel = page.getByRole('region', { name: 'Gekoppelde piloten' });
  await expect(pilotPanel).toBeVisible();
  const alarmLinkedRow = pilotPanel.locator('.deployment-pilot-row').filter({ hasText: 'Alex Alarm' });
  await expect(alarmLinkedRow.getByText('Via alarmering')).toBeVisible();
  await expect(alarmLinkedRow.getByRole('button', { name: 'Ontkoppelen' })).toHaveCount(0);
  const historicalRow = pilotPanel.locator('.deployment-pilot-row').filter({ hasText: 'Verwijderde piloot' });
  await expect(historicalRow.getByRole('button', { name: 'Onderweg', exact: true })).toHaveCount(0);
  await expect(historicalRow.getByRole('button', { name: 'Op locatie', exact: true })).toHaveCount(0);

  await pilotPanel.getByRole('button', { name: 'Piloot koppelen' }).click();
  const dialog = page.getByRole('dialog', { name: 'Piloot koppelen' });
  await expect(dialog).toContainText('ontvangt een informatief pushbericht');
  await expect(dialog).toContainText('Er klinkt geen alarm');
  await dialog.getByLabel('Piloot zoeken').fill('noor');
  await expect.poll(() => api.candidateSearches.at(-1)).toBe('noor');
  await dialog.getByRole('radio', { name: /Noor de Vries/ }).check();
  await dialog.getByLabel('Reden voor koppeling').fill('Telefonisch afgestemd met de piloot.');
  await dialog.getByRole('button', { name: 'Piloot koppelen' }).click();

  await expect.poll(() => api.linkBodies).toEqual([{
    user_id: 'pilot-noor',
    reason: 'Telefonisch afgestemd met de piloot.',
  }]);
  await expect(dialog).toBeHidden();
  await expect(pilotPanel.getByRole('status')).toContainText('informatieve pushbericht is ingepland');

  const manualRow = pilotPanel.locator('.deployment-pilot-row').filter({ hasText: 'Noor de Vries' });
  await expect(manualRow.getByText('Handmatig gekoppeld')).toBeVisible();
  await manualRow.getByRole('button', { name: 'Onderweg', exact: true }).click();
  await expect.poll(() => api.statusBodies).toEqual([{
    userId: 'pilot-noor',
    body: { status: 'en_route', reason: 'Handmatig aangepast vanuit inzetdetail.' },
  }]);
  await expect(manualRow.locator('.status-pill').filter({ hasText: 'Onderweg' })).toBeVisible();

  await manualRow.getByRole('button', { name: 'Op locatie', exact: true }).click();
  await expect.poll(() => api.statusBodies.at(-1)).toEqual({
    userId: 'pilot-noor',
    body: { status: 'on_scene', reason: 'Handmatig aangepast vanuit inzetdetail.' },
  });
  await expect(manualRow.locator('.status-pill').filter({ hasText: 'Op locatie' })).toBeVisible();
  expect(api.mutations.some((request) => request.path === `/api/deployments/${deploymentId}/pilots/pilot-noor/status`)).toBe(true);
  expect(api.mutations.some((request) => request.path.includes('/availability-statuses/'))).toBe(false);

  await manualRow.getByRole('button', { name: 'Ontkoppelen' }).click();
  const confirmation = page.getByRole('alertdialog', { name: 'Piloot ontkoppelen?' });
  await expect(confirmation).toContainText('handmatige koppeling van Noor de Vries');
  await confirmation.getByRole('button', { name: 'Piloot ontkoppelen' }).click();
  await expect.poll(() => api.unlinkedAssignmentIds).toEqual(['assignment-noor']);
  await expect(manualRow).toHaveCount(0);

  expect(api.mutations.filter((request) => request.path.includes('/dispatches'))).toEqual([]);
});

test('makes every candidate page reachable and searches without matching letter case', async ({ page }) => {
  const api = await mockDeploymentPilotsApi(page, { candidateCount: 30 });
  await page.goto(`/inzetten/${deploymentId}`);

  await page.getByRole('region', { name: 'Gekoppelde piloten' })
    .getByRole('button', { name: 'Piloot koppelen' })
    .click();
  const dialog = page.getByRole('dialog', { name: 'Piloot koppelen' });
  await expect(dialog.getByText('Koppelbare piloten (30)')).toBeVisible();
  await expect(dialog.getByRole('radio')).toHaveCount(25);
  const candidateList = dialog.locator('.pilot-candidate-list');
  await expect(candidateList).toBeVisible();
  expect(await candidateList.evaluate((element) => ({
    clientHeight: element.clientHeight,
    overflowY: window.getComputedStyle(element).overflowY,
    scrollHeight: element.scrollHeight,
  }))).toEqual(expect.objectContaining({ overflowY: 'hidden' }));
  expect(await candidateList.evaluate((element) => element.clientHeight)).toBe(
    await candidateList.evaluate((element) => element.scrollHeight),
  );

  await dialog.getByRole('button', { name: 'Volgende' }).click();
  await expect(dialog.getByText('Pagina 2 van 2')).toBeVisible();
  await expect(dialog.getByRole('radio')).toHaveCount(5);
  await expect(dialog.getByRole('radio', { name: /Koppelbare Kandidaat 30/ })).toBeVisible();

  await dialog.getByLabel('Piloot zoeken').fill('KANDIDAAT 30');
  await expect.poll(() => api.candidateSearches.at(-1)).toBe('KANDIDAAT 30');
  await expect(dialog.getByText('Koppelbare piloten (1)')).toBeVisible();
  await dialog.getByRole('radio', { name: /Koppelbare Kandidaat 30/ }).check();
  await dialog.getByLabel('Reden voor koppeling').fill('Telefonisch bevestigd na een onjuiste reactie.');
  await dialog.getByRole('button', { name: 'Piloot koppelen' }).click();

  await expect.poll(() => api.linkBodies.at(-1)).toEqual({
    user_id: 'pilot-30',
    reason: 'Telefonisch bevestigd na een onjuiste reactie.',
  });
});

interface MockApiState {
  linkBodies: unknown[];
  statusBodies: Array<{ userId: string; body: unknown }>;
  candidateSearches: string[];
  candidateRequests: Array<{ page: number; perPage: number; search: string }>;
  unlinkedAssignmentIds: string[];
  mutations: Array<{ method: string; path: string }>;
}

async function mockDeploymentPilotsApi(
  page: Page,
  options: { candidateCount?: number } = {},
): Promise<MockApiState> {
  const state: MockApiState = {
    linkBodies: [],
    statusBodies: [],
    candidateSearches: [],
    candidateRequests: [],
    unlinkedAssignmentIds: [],
    mutations: [],
  };
  let pilots = [
    deploymentPilot('recipient-alex', 'pilot-alex', 'Alex Alarm', 'dispatch'),
    deploymentPilot('recipient-deleted', null, 'Verwijderde piloot', 'dispatch'),
  ];
  const candidates = candidateFixtures(options.candidateCount ?? 1);

  await page.context().addCookies([{ name: 'XSRF-TOKEN', value: 'test-csrf-token', url: 'http://127.0.0.1:3000' }]);
  await page.route('**/api/**', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    const path = url.pathname;
    const method = request.method();
    if (method !== 'GET') {
      state.mutations.push({ method, path });
    }

    if (path === '/api/auth/me') {
      await json(route, { data: currentUser() });
      return;
    }
    if (path === '/api/branding') {
      await json(route, { data: { name: 'DIS', short_name: 'DIS', tenant_name: 'Testorganisatie', logo_data_url: '' } });
      return;
    }
    if (path === `/api/deployments/${deploymentId}`) {
      await json(route, { data: deployment() });
      return;
    }
    if (path === `/api/deployments/${deploymentId}/dispatch-preview`) {
      await json(route, { data: { team: ocpTeam, teams: [ocpTeam], recipients: [] } });
      return;
    }
    if (path === `/api/deployments/${deploymentId}/dispatches`) {
      await json(route, { data: [dispatch()] });
      return;
    }
    if (path === `/api/deployments/${deploymentId}/pilots` && method === 'GET') {
      await json(route, { data: pilots });
      return;
    }
    if (path === `/api/deployments/${deploymentId}/pilot-candidates`) {
      const search = url.searchParams.get('search') ?? '';
      const pageNumber = Number(url.searchParams.get('page') ?? 1);
      const perPage = Number(url.searchParams.get('per_page') ?? 25);
      const normalizedSearch = search.trim().toLocaleLowerCase('nl-NL');
      const matchingCandidates = normalizedSearch === ''
        ? candidates
        : candidates.filter((candidate) => [candidate.name, candidate.email]
            .some((value) => value.toLocaleLowerCase('nl-NL').includes(normalizedSearch)));
      const lastPage = Math.max(1, Math.ceil(matchingCandidates.length / perPage));
      const pageCandidates = matchingCandidates.slice((pageNumber - 1) * perPage, pageNumber * perPage);
      state.candidateSearches.push(search);
      state.candidateRequests.push({ page: pageNumber, perPage, search });
      await json(route, {
        data: pageCandidates,
        meta: {
          current_page: pageNumber,
          last_page: lastPage,
          per_page: perPage,
          total: matchingCandidates.length,
        },
      });
      return;
    }
    if (path === `/api/deployments/${deploymentId}/pilots` && method === 'POST') {
      const body = request.postDataJSON();
      state.linkBodies.push(body);
      const candidate = candidates.find((item) => item.id === body.user_id) ?? candidateNoor();
      const assignmentSuffix = candidate.id.replace(/^pilot-/, '');
      const pilot = deploymentPilot(`assignment-${assignmentSuffix}`, candidate.id, candidate.name, 'manual');
      pilots = [...pilots, pilot];
      await json(route, { data: pilot, meta: { notification_queued_tokens: 1 } }, 201);
      return;
    }
    if (path.startsWith(`/api/deployments/${deploymentId}/pilots/`) && method === 'DELETE') {
      const assignmentId = decodeURIComponent(path.slice(path.lastIndexOf('/') + 1));
      state.unlinkedAssignmentIds.push(assignmentId);
      pilots = pilots.filter((pilot) => pilot.id !== assignmentId);
      await json(route, { data: null });
      return;
    }
    if (path.startsWith(`/api/deployments/${deploymentId}/pilots/`) && path.endsWith('/status') && method === 'POST') {
      const userId = path.split('/').at(-2) ?? '';
      const body = request.postDataJSON();
      state.statusBodies.push({ userId, body });
      pilots = pilots.map((pilot) => pilot.user_id === userId
        ? { ...pilot, user: { ...pilot.user, statuses: [availabilityStatus(userId, body.status)] } }
        : pilot);
      await json(route, { data: availabilityStatus(userId, body.status) });
      return;
    }
    if (path === `/api/deployments/${deploymentId}/live-locations`
      || path === `/api/deployments/${deploymentId}/timeline`
      || path === '/api/reports/deployments'
      || path === '/api/teams') {
      await json(route, { data: [] });
      return;
    }
    if (path === '/api/auth/session/touch' && method === 'POST') {
      await json(route, { data: currentUser() });
      return;
    }

    await json(route, { error: { code: 'test_route_missing', message: `Niet gemockt: ${method} ${path}` } }, 404);
  });

  return state;
}

function currentUser() {
  const permissions = [
    'deployments.view',
    'deployments.dispatch.view',
    'deployments.dispatch.manage',
  ].map((name) => ({ id: `permission-${name}`, name, category: 'deployments', display_name: name }));

  return {
    id: 'coordinator-1',
    name: 'Testcoördinator',
    email: 'coordinator@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    profile_completion_required: false,
    roles: [{
      id: 'role-coordinator',
      name: 'coordinator',
      display_name: 'Coördinator',
      can_use_operator_app: true,
      can_use_admin_app: true,
      permissions,
    }],
  };
}

function deployment() {
  return {
    id: deploymentId,
    reference: 'DIS-2026-001',
    title: 'Zoekactie testgebied',
    description: 'Testinzet',
    priority: 'normal',
    status: 'dispatching',
    is_test: false,
    deployment_request_id: null,
    teams: [ocpTeam],
    opened_at: '2026-08-03T10:00:00Z',
  };
}

function dispatch() {
  return {
    id: 'dispatch-1',
    deployment_id: deploymentId,
    target_team_id: ocpTeam.id,
    target_team: ocpTeam,
    status: 'sent',
    priority: 'normal',
    message: 'Alarmtekst',
    sent_at: '2026-08-03T10:05:00Z',
    recipients: [{
      id: 'recipient-alex',
      user_id: 'pilot-alex',
      response_status: 'accepted',
      responded_at: '2026-08-03T10:06:00Z',
      user: pilotUser('pilot-alex', 'Alex Alarm'),
    }],
  };
}

function deploymentPilot(id: string, userId: string | null, name: string, source: 'dispatch' | 'manual') {
  return {
    id,
    user_id: userId,
    source,
    linked_at: '2026-08-03T10:06:00Z',
    user: userId === null
      ? {
          id: null,
          name,
          email: null,
          account_status: 'blocked',
          push_enabled: false,
          max_operator_devices: 0,
          two_factor_enabled: false,
          teams: [],
          statuses: [],
        }
      : pilotUser(userId, name),
  };
}

function candidateNoor() {
  return {
    id: 'pilot-noor',
    name: 'Noor de Vries',
    email: 'noor@example.test',
    teams: [ocpTeam],
    statuses: [availabilityStatus('pilot-noor', 'available')],
  };
}

function candidateFixtures(count: number) {
  return Array.from({ length: Math.max(1, count) }, (_, index) => {
    if (index === 0) {
      return candidateNoor();
    }

    const number = index + 1;
    const id = `pilot-${number}`;
    return {
      id,
      name: `Koppelbare Kandidaat ${String(number).padStart(2, '0')}`,
      email: `candidate-${number}@example.test`,
      teams: [ocpTeam],
      statuses: [availabilityStatus(id, 'available')],
    };
  });
}

function pilotUser(id: string, name: string) {
  return {
    id,
    name,
    email: `${id}@example.test`,
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    teams: [ocpTeam],
    statuses: [availabilityStatus(id, 'available')],
  };
}

function availabilityStatus(userId: string, status: string) {
  return {
    id: `status-${userId}-${status}`,
    user_id: userId,
    status,
    is_available: status === 'available',
    effective_at: '2026-08-03T10:06:00Z',
  };
}

async function json(route: Route, body: unknown, status = 200) {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
}
