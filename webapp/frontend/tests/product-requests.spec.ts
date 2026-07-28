import { readFileSync } from 'node:fs';
import { expect, test, type Page, type Route } from 'playwright/test';
import type { ProductRequest } from '../src/types/api';
import {
  allowedProductRequestTransitions,
  buildProductRequestsPath,
  filterProductRequests,
  productRequestStatusLabel,
  productRequestTypeLabel,
} from '../src/features/product-requests/productRequestPresentation';

const request = (
  id: string,
  overrides: Partial<ProductRequest> = {},
): ProductRequest => ({
  id,
  type: 'bug',
  status: 'open',
  title: `Verzoek ${id}`,
  description: `Omschrijving ${id}`,
  resolution_note: null,
  requester: { id: 'user-1', name: 'Eigen gebruiker' },
  resolved_by: null,
  resolved_at: null,
  lock_version: 1,
  is_owner: true,
  can_update: true,
  can_resolve: false,
  created_at: '2026-07-27T08:00:00Z',
  updated_at: '2026-07-27T08:00:00Z',
  ...overrides,
});

test('builds bounded server-side request filters for all four tabs', () => {
  expect(buildProductRequestsPath({
    tab: 'all',
    type: 'all',
    status: 'all',
    query: '',
    page: 1,
  })).toBe('/product-requests?page=1&per_page=25');

  expect(buildProductRequestsPath({
    tab: 'mine',
    type: 'bug',
    status: 'resolved',
    query: '  radar   loopt vast ',
    page: 3,
  })).toBe('/product-requests?mine=1&type=bug&status=resolved&search=radar+loopt+vast&page=3&per_page=25');

  expect(buildProductRequestsPath({
    tab: 'handling',
    type: 'all',
    status: 'all',
    query: '',
    page: 1,
  })).toBe('/product-requests?status=open%2Cin_progress&page=1&per_page=25');

  expect(buildProductRequestsPath({
    tab: 'closed',
    type: 'all',
    status: 'all',
    query: '',
    page: 1,
  })).toBe('/product-requests?status=resolved%2Crejected&page=1&per_page=25');
});

test('keeps presentation filtering deterministic for ownership, handling, closed and search', () => {
  const requests = [
    request('rejected-other', {
      status: 'rejected',
      title: 'Afgewezen verzoek',
      requester: { id: 'user-4', name: 'Vierde gebruiker' },
      updated_at: '2026-07-27T13:00:00Z',
    }),
    request('resolved-other', {
      status: 'resolved',
      title: 'Agenda gewijzigd',
      requester: { id: 'user-2', name: 'Andere gebruiker' },
      updated_at: '2026-07-27T12:00:00Z',
    }),
    request('own-open', {
      description: 'Radar loopt vast',
      updated_at: '2026-07-27T10:00:00Z',
    }),
    request('other-progress', {
      status: 'in_progress',
      requester: { id: 'user-3', name: 'Derde gebruiker' },
      updated_at: '2026-07-27T11:00:00Z',
    }),
  ];

  expect(filterProductRequests(requests, {
    tab: 'mine',
    type: 'all',
    status: 'all',
    query: '',
    userId: 'user-1',
  }).map((item) => item.id)).toEqual(['own-open']);
  expect(filterProductRequests(requests, {
    tab: 'handling',
    type: 'all',
    status: 'all',
    query: '',
    userId: 'user-1',
  }).map((item) => item.id)).toEqual(['other-progress', 'own-open']);
  expect(filterProductRequests(requests, {
    tab: 'closed',
    type: 'all',
    status: 'all',
    query: '',
    userId: 'user-1',
  }).map((item) => item.id)).toEqual(['rejected-other', 'resolved-other']);
  expect(filterProductRequests(requests, {
    tab: 'all',
    type: 'all',
    status: 'all',
    query: 'radar',
    userId: 'user-1',
  }).map((item) => item.id)).toEqual(['own-open']);
  expect(productRequestTypeLabel('change')).toBe('Aanpassing');
  expect(productRequestStatusLabel('in_progress')).toBe('In behandeling');
  expect(allowedProductRequestTransitions('open')).toEqual(['in_progress', 'resolved', 'rejected']);
  expect(allowedProductRequestTransitions('in_progress')).toEqual(['open', 'resolved', 'rejected']);
  expect(allowedProductRequestTransitions('resolved')).toEqual(['open']);
  expect(allowedProductRequestTransitions('rejected')).toEqual(['open']);
});

test('keeps request actions server-authoritative and optimistic-lock safe', () => {
  const page = readFileSync(new URL('../src/features/product-requests/ProductRequestsPage.tsx', import.meta.url), 'utf8');
  const types = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');

  expect(page).toContain("useApiResource<ProductRequest[]>(resourcePath)");
  expect(page).toContain("useApiResource<ProductRequest>(`/product-requests/${summary.id}`)");
  expect(page).toContain("api.post<ProductRequest>('/product-requests'");
  expect(page).toContain("api.patch<ProductRequest>(`/product-requests/${request.id}`");
  expect(page).toContain("api.patch<ProductRequest>(`/product-requests/${request.id}/status`");
  expect(page).toContain('lock_version: request.lock_version');
  expect(page).toContain('request.can_update');
  expect(page).toContain('request.can_resolve');
  expect(page).toContain("resolutionNote: '',");
  expect(page).not.toContain('error.status === 409');
  expect(page).not.toContain('request.abilities');
  expect(page).not.toContain('attachments');
  expect(page).not.toContain('comments');

  expect(types).toContain('can_update: boolean;');
  expect(types).toContain('can_resolve: boolean;');
  expect(types).toContain('status_history?: ProductRequestStatusHistoryEntry[];');
  expect(types).toContain('lock_version: number;');
});

test('wires the requests page into route access, navigation, help and responsive styling', () => {
  const route = readFileSync(new URL('../app/verzoeken/page.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const help = readFileSync(new URL('../src/features/help/HelpPage.tsx', import.meta.url), 'utf8');
  const manual = readFileSync(new URL('../src/features/help/manuals/accountManual.ts', import.meta.url), 'utf8');
  const styles = readFileSync(new URL('../src/features/product-requests/ProductRequestsPage.module.css', import.meta.url), 'utf8');

  expect(route).toContain('<ProtectedShell {...webRouteAccess.productRequests}>');
  expect(navigation).toContain("to: '/verzoeken', label: 'Verzoeken'");
  expect(navigation).toContain('...webRouteAccess.productRequests');
  expect(navigation).toContain("'/verzoeken': () => import('../features/product-requests/ProductRequestsPage')");
  expect(help).toContain("id: 'product-requests'");
  expect(help).toContain("permissions: ['product-requests.view']");
  expect(manual).toContain("id: 'product-request-submit'");
  expect(manual).toContain("id: 'product-request-update-own'");
  expect(manual).toContain("id: 'product-request-update-any'");
  expect(manual).toContain("id: 'product-request-resolve'");
  expect(help).toContain("'product-requests.update-any'");

  expect(styles).toContain('.tableWrap');
  expect(styles).toContain('.requestTable');
  expect(styles).toContain('.modalBackdrop');
  expect(styles).toContain('overflow-x: auto');
  expect(styles).toContain('@media (max-width: 760px)');
  expect(styles).toContain('@media (max-width: 620px)');
  expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
});

test('orders workflow tabs and loads active versus closed requests', async ({ page }) => {
  const state = productRequestMockState();
  state.items = [
    request('open', {
      title: 'Open verzoek',
      status: 'open',
      updated_at: '2026-07-27T09:00:00Z',
    }),
    request('progress', {
      title: 'Verzoek in behandeling',
      status: 'in_progress',
      updated_at: '2026-07-27T10:00:00Z',
    }),
    request('resolved', {
      title: 'Opgelost verzoek',
      status: 'resolved',
      updated_at: '2026-07-27T11:00:00Z',
    }),
    request('rejected', {
      title: 'Afgewezen verzoek',
      status: 'rejected',
      updated_at: '2026-07-27T12:00:00Z',
    }),
  ];
  const listRequests = await mockProductRequestApi(page, state, [
    'product-requests.view',
    'product-requests.resolve',
  ]);

  await page.goto('/verzoeken');

  const tabs = page.getByRole('group', { name: 'Verzoeken selecteren' });
  await expect(tabs.getByRole('button')).toHaveText([
    'Te behandelen',
    'Mijn verzoeken',
    'Afgesloten verzoeken',
    'Alle verzoeken',
  ]);

  await expect(tabs.getByRole('button', { name: 'Te behandelen', exact: true }))
    .toHaveAttribute('aria-pressed', 'true');
  await expect.poll(() => listRequests.at(-1) ?? '').toContain('status=open%2Cin_progress');
  await expect(productRequestRow(page, 'Open verzoek')).toHaveCount(1);
  await expect(productRequestRow(page, 'Verzoek in behandeling')).toHaveCount(1);
  await expect(productRequestRow(page, 'Opgelost verzoek')).toHaveCount(0);
  await expect(page.getByLabel('Status').locator('option')).toHaveText([
    'Alle statussen',
    'Open',
    'In behandeling',
  ]);

  await tabs.getByRole('button', { name: 'Afgesloten verzoeken', exact: true }).click();
  await expect.poll(() => listRequests.at(-1) ?? '').toContain('status=resolved%2Crejected');
  await expect(productRequestRow(page, 'Open verzoek')).toHaveCount(0);
  await expect(productRequestRow(page, 'Opgelost verzoek')).toHaveCount(1);
  await expect(productRequestRow(page, 'Afgewezen verzoek')).toHaveCount(1);
  await expect(page.getByLabel('Status').locator('option')).toHaveText([
    'Alle statussen',
    'Opgelost',
    'Afgewezen',
  ]);

  await tabs.getByRole('button', { name: 'Alle verzoeken', exact: true }).click();
  await expect.poll(() => listRequests.at(-1) ?? '').toBe('/api/product-requests?page=1&per_page=25');
  await expect(productRequestsTable(page).getByRole('row')).toHaveCount(5);
});

test('creates, edits and resolves a request through the table and dialogs', async ({ page }) => {
  const state = productRequestMockState({ can_resolve: true });
  await mockProductRequestApi(page, state, [
    'product-requests.view',
    'product-requests.create',
    'product-requests.update-own',
    'product-requests.resolve',
  ]);

  await page.goto('/verzoeken');
  await expect(page.getByRole('heading', { level: 1, name: 'Verzoeken', exact: true })).toBeVisible();
  const table = productRequestsTable(page);
  await expect(table).toBeVisible();
  await expect(table.getByRole('columnheader')).toHaveText([
    'Type',
    'Verzoek',
    'Indiener',
    'Status',
    'Bijgewerkt',
    'Acties',
  ]);
  await expect(productRequestRow(page, 'Bestaand verzoek')).toHaveCount(1);

  const createButton = page.getByRole('button', { name: 'Nieuw verzoek', exact: true });
  await createButton.click();
  let createDialog = page.getByRole('dialog', { name: 'Nieuw verzoek', exact: true });
  await expect(createDialog).toBeVisible();
  await expect(createDialog).toHaveAttribute('aria-modal', 'true');
  await expect(createDialog.getByLabel('Titel', { exact: true })).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(createDialog).toHaveCount(0);
  await expect(createButton).toBeFocused();

  await createButton.click();
  createDialog = page.getByRole('dialog', { name: 'Nieuw verzoek', exact: true });
  await createDialog.getByLabel('Type verzoek').selectOption('feature');
  await createDialog.getByLabel('Titel', { exact: true }).fill('Nieuwe exportfunctie');
  await createDialog.getByLabel(/^Omschrijving/).fill('Voeg een compacte PDF-export toe.');
  await createDialog.getByRole('button', { name: 'Verzoek indienen' }).click();

  await expect(createDialog).toHaveCount(0);
  await expect(page.getByRole('status').filter({ hasText: 'Verzoek ingediend.' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Mijn verzoeken', exact: true })).toHaveAttribute('aria-pressed', 'true');
  await expect(productRequestRow(page, 'Nieuwe exportfunctie')).toHaveCount(1);
  expect(state.items).toHaveLength(2);
  expect(state.items[0]).toMatchObject({
    type: 'feature',
    title: 'Nieuwe exportfunctie',
    lock_version: 1,
  });

  const viewButton = productRequestViewButton(page, 'Nieuwe exportfunctie');
  await viewButton.click();
  let detailDialog = page.getByRole('dialog', { name: 'Nieuwe exportfunctie', exact: true });
  await expect(detailDialog).toBeVisible();
  await expect(detailDialog).toHaveAttribute('aria-modal', 'true');
  await page.keyboard.press('Escape');
  await expect(detailDialog).toHaveCount(0);
  await expect(viewButton).toBeFocused();

  await viewButton.click();
  detailDialog = page.getByRole('dialog', { name: 'Nieuwe exportfunctie', exact: true });
  await detailDialog.getByRole('button', { name: 'Aanpassen', exact: true }).click();
  await expect(detailDialog.getByRole('heading', { name: 'Verzoek aanpassen', exact: true })).toBeVisible();
  await detailDialog.getByLabel('Titel', { exact: true }).fill('Nieuwe PDF-exportfunctie');
  await detailDialog.getByRole('textbox', { name: 'Omschrijving', exact: true })
    .fill('Voeg een compacte, toegankelijke PDF-export toe.');
  await detailDialog.getByRole('button', { name: 'Wijzigingen opslaan' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Wijzigingen opgeslagen.' })).toBeVisible();
  await expect(productRequestRow(page, 'Nieuwe PDF-exportfunctie')).toHaveCount(1);
  detailDialog = page.getByRole('dialog', { name: 'Nieuwe PDF-exportfunctie', exact: true });
  await expect(detailDialog).toBeVisible();
  expect(state.items[0]).toMatchObject({
    title: 'Nieuwe PDF-exportfunctie',
    lock_version: 2,
  });

  await detailDialog.getByRole('button', { name: 'Status wijzigen', exact: true }).click();
  await detailDialog.getByLabel('Nieuwe status').selectOption('resolved');
  await detailDialog.getByLabel('Toelichting').fill('Opgenomen in de eerstvolgende webrelease.');
  await detailDialog.getByRole('button', { name: 'Afhandeling opslaan' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Afhandeling opgeslagen.' })).toBeVisible();
  const resolvedRow = productRequestRow(page, 'Nieuwe PDF-exportfunctie');
  await expect(resolvedRow.getByText('Opgelost', { exact: true })).toBeVisible();
  detailDialog = page.getByRole('dialog', { name: 'Nieuwe PDF-exportfunctie', exact: true });
  await expect(detailDialog.getByText('Opgelost', { exact: true })).toBeVisible();
  await expect(
    detailDialog
      .getByRole('region', { name: 'Toelichting op de afhandeling' })
      .getByText('Opgenomen in de eerstvolgende webrelease.'),
  ).toBeVisible();
  await expect(detailDialog.getByRole('button', { name: 'Aanpassen', exact: true })).toHaveCount(0);
  expect(state.items[0]).toMatchObject({
    status: 'resolved',
    resolution_note: 'Opgenomen in de eerstvolgende webrelease.',
    lock_version: 3,
  });
});

test('edits a non-owned request without exposing or changing its requester', async ({ page }) => {
  const state = productRequestMockState({
    type: 'feature',
    requester: { id: 'user-2', name: 'Ria Aanvrager' },
    is_owner: false,
    can_update: true,
  });
  await mockProductRequestApi(page, state, [
    'product-requests.view',
    'product-requests.update-any',
  ]);

  await page.goto('/verzoeken');
  await productRequestViewButton(page, 'Bestaand verzoek').click();
  let detailDialog = page.getByRole('dialog', { name: 'Bestaand verzoek', exact: true });
  await expect(detailDialog.getByText('Ria Aanvrager', { exact: true })).toBeVisible();
  await detailDialog.getByRole('button', { name: 'Aanpassen', exact: true }).click();

  await expect(detailDialog.getByRole('heading', { name: 'Verzoek aanpassen', exact: true })).toBeVisible();
  await detailDialog.getByRole('combobox', { name: 'Type', exact: true }).selectOption('bug');
  await detailDialog.getByLabel('Titel', { exact: true }).fill('Gecorrigeerde bugmelding');
  await detailDialog.getByRole('textbox', { name: 'Omschrijving', exact: true })
    .fill('De inhoud is verduidelijkt door een behandelaar.');
  await detailDialog.getByRole('button', { name: 'Wijzigingen opslaan' }).click();

  detailDialog = page.getByRole('dialog', { name: 'Gecorrigeerde bugmelding', exact: true });
  await expect(detailDialog).toBeVisible();
  await expect(detailDialog.getByText('Ria Aanvrager', { exact: true })).toBeVisible();
  expect(state.contentUpdatePayloads).toEqual([{
    type: 'bug',
    title: 'Gecorrigeerde bugmelding',
    description: 'De inhoud is verduidelijkt door een behandelaar.',
    lock_version: 1,
  }]);
  expect(state.items[0]).toMatchObject({
    type: 'bug',
    title: 'Gecorrigeerde bugmelding',
    requester: { id: 'user-2', name: 'Ria Aanvrager' },
    is_owner: false,
    lock_version: 2,
  });
});

test('keeps update actions controlled by the server capability for update-any roles', async ({ page }) => {
  const state = productRequestMockState({
    requester: { id: 'user-2', name: 'Andere aanvrager' },
    is_owner: false,
    can_update: false,
  });
  await mockProductRequestApi(page, state, [
    'product-requests.view',
    'product-requests.update-any',
  ]);

  await page.goto('/verzoeken');
  await productRequestViewButton(page, 'Bestaand verzoek').click();
  const detailDialog = page.getByRole('dialog', { name: 'Bestaand verzoek', exact: true });
  await expect(detailDialog.getByRole('button', { name: 'Aanpassen', exact: true })).toHaveCount(0);
});

test('hides actions without action rights and reloads the latest version after a stale edit', async ({ page }) => {
  const readOnlyState = productRequestMockState({
    can_update: false,
    can_resolve: false,
  });
  await mockProductRequestApi(page, readOnlyState, ['product-requests.view']);
  await page.goto('/verzoeken');

  await expect(productRequestRow(page, 'Bestaand verzoek')).toHaveCount(1);
  await expect(page.getByRole('button', { name: 'Nieuw verzoek' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Te behandelen', exact: true })).toHaveCount(0);
  await expect(page.getByRole('group', { name: 'Verzoeken selecteren' }).getByRole('button')).toHaveText([
    'Mijn verzoeken',
    'Afgesloten verzoeken',
    'Alle verzoeken',
  ]);
  await productRequestViewButton(page, 'Bestaand verzoek').click();
  let detailDialog = page.getByRole('dialog', { name: 'Bestaand verzoek', exact: true });
  await expect(detailDialog.getByRole('button', { name: 'Aanpassen', exact: true })).toHaveCount(0);
  await expect(detailDialog.getByRole('button', { name: 'Status wijzigen', exact: true })).toHaveCount(0);

  const staleState = productRequestMockState();
  staleState.conflictOnNextContentUpdate = true;
  await page.unroute('**/api/**');
  await mockProductRequestApi(page, staleState, [
    'product-requests.view',
    'product-requests.update-own',
  ]);
  await page.reload();
  await productRequestViewButton(page, 'Bestaand verzoek').click();
  detailDialog = page.getByRole('dialog', { name: 'Bestaand verzoek', exact: true });
  await detailDialog.getByRole('button', { name: 'Aanpassen', exact: true }).click();
  await detailDialog.getByLabel('Titel', { exact: true }).fill('Lokale wijziging');
  await detailDialog.getByRole('button', { name: 'Wijzigingen opslaan' }).click();

  await expect(page.getByRole('alert').filter({ hasText: 'Dit verzoek is intussen gewijzigd.' }))
    .toBeVisible();
  await expect(productRequestRow(page, 'Nieuwste titel van de server')).toHaveCount(1);
  await expect(page.getByRole('dialog', { name: 'Nieuwste titel van de server', exact: true })).toBeVisible();
  expect(staleState.items[0]).toMatchObject({
    title: 'Nieuwste titel van de server',
    lock_version: 2,
  });
});

test('keeps the requests table and create dialog within a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const state = productRequestMockState();
  await mockProductRequestApi(page, state, [
    'product-requests.view',
    'product-requests.create',
    'product-requests.resolve',
  ]);

  await page.goto('/verzoeken');
  await expect(productRequestsTable(page)).toBeVisible();
  await expectNoPageOverflow(page);

  const createButton = page.getByRole('button', { name: 'Nieuw verzoek', exact: true });
  await createButton.click();
  const dialog = page.getByRole('dialog', { name: 'Nieuw verzoek', exact: true });
  await expect(dialog).toBeVisible();
  await expectNoPageOverflow(page);

  const dialogBox = await dialog.boundingBox();
  expect(dialogBox).not.toBeNull();
  expect(dialogBox?.x ?? -1).toBeGreaterThanOrEqual(0);
  expect((dialogBox?.x ?? 0) + (dialogBox?.width ?? 0)).toBeLessThanOrEqual(391);

  await page.keyboard.press('Escape');
  await expect(dialog).toHaveCount(0);
  await expect(createButton).toBeFocused();
});

function productRequestsTable(page: Page) {
  return page.getByRole('table', { name: 'Verzoekenoverzicht', exact: true });
}

function productRequestRow(page: Page, title: string) {
  return productRequestsTable(page)
    .getByRole('row')
    .filter({ has: page.getByText(title, { exact: true }) });
}

function productRequestViewButton(page: Page, title: string) {
  return productRequestRow(page, title)
    .getByRole('button', { name: `Verzoek bekijken: ${title}`, exact: true });
}

async function expectNoPageOverflow(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )).toBe(true);
}

interface ProductRequestMockState {
  items: ProductRequest[];
  conflictOnNextContentUpdate: boolean;
  contentUpdatePayloads: Array<{
    type: ProductRequest['type'];
    title: string;
    description: string;
    lock_version: number;
  }>;
  nextId: number;
}

function productRequestMockState(
  overrides: Partial<ProductRequest> = {},
): ProductRequestMockState {
  return {
    items: [request('request-1', {
      title: 'Bestaand verzoek',
      description: 'Bestaande omschrijving.',
      can_resolve: false,
      status_history: [{
        id: 'history-1',
        from_status: null,
        to_status: 'open',
        note: null,
        changed_by: { id: 'user-1', name: 'Eigen gebruiker' },
        created_at: '2026-07-27T08:00:00Z',
      }],
      ...overrides,
    })],
    conflictOnNextContentUpdate: false,
    contentUpdatePayloads: [],
    nextId: 2,
  };
}

async function mockProductRequestApi(
  page: Page,
  state: ProductRequestMockState,
  permissionNames: string[],
): Promise<string[]> {
  const listRequests: string[] = [];

  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    const method = route.request().method();

    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204 });
      return;
    }
    if (path === '/api/auth/me') {
      await fulfillProductRequestJson(route, 200, {
        data: productRequestUser(permissionNames),
      });
      return;
    }
    if (path === '/api/branding') {
      await fulfillProductRequestJson(route, 200, {
        data: {
          name: 'DIS',
          short_name: 'DIS',
          tenant_name: 'Testorganisatie',
          logo_data_url: '',
        },
      });
      return;
    }

    if (path === '/api/product-requests' && method === 'GET') {
      listRequests.push(`${path}${url.search}`);
      const pageNumber = Math.max(1, Number(url.searchParams.get('page') ?? '1'));
      const items = filterMockProductRequests(state.items, url);
      await fulfillProductRequestJson(route, 200, {
        data: items,
        meta: {
          current_page: pageNumber,
          from: items.length === 0 ? null : 1,
          last_page: 1,
          path: '/api/product-requests',
          per_page: 25,
          to: items.length,
          total: items.length,
        },
      });
      return;
    }

    if (path === '/api/product-requests' && method === 'POST') {
      const payload = route.request().postDataJSON() as {
        type: ProductRequest['type'];
        title: string;
        description: string;
      };
      const id = `request-${state.nextId}`;
      state.nextId += 1;
      const created = request(id, {
        type: payload.type,
        title: payload.title,
        description: payload.description,
        can_resolve: permissionNames.includes('product-requests.resolve'),
        status_history: [{
          id: `history-${id}-1`,
          from_status: null,
          to_status: 'open',
          note: null,
          changed_by: { id: 'user-1', name: 'Eigen gebruiker' },
          created_at: '2026-07-27T09:00:00Z',
        }],
      });
      state.items.unshift(created);
      await fulfillProductRequestJson(route, 201, { data: created });
      return;
    }

    const match = /^\/api\/product-requests\/([^/]+)(\/status)?$/.exec(path);
    if (match !== null) {
      const index = state.items.findIndex((item) => item.id === match[1]);
      if (index < 0) {
        await fulfillProductRequestJson(route, 404, {
          error: { code: 'not_found', message: 'Verzoek niet gevonden.', details: {} },
        });
        return;
      }

      if (method === 'GET') {
        await fulfillProductRequestJson(route, 200, { data: state.items[index] });
        return;
      }

      if (method === 'PATCH' && match[2] === undefined) {
        if (state.conflictOnNextContentUpdate) {
          state.conflictOnNextContentUpdate = false;
          state.items[index] = {
            ...state.items[index],
            title: 'Nieuwste titel van de server',
            description: 'Een andere gebruiker heeft dit verzoek bijgewerkt.',
            lock_version: state.items[index].lock_version + 1,
            updated_at: '2026-07-27T10:00:00Z',
          };
          await fulfillProductRequestJson(route, 409, {
            error: {
              code: 'product_request_version_conflict',
              message: 'Het verzoek is ondertussen gewijzigd.',
              details: {
                current: {
                  id: state.items[index].id,
                  status: state.items[index].status,
                  lock_version: state.items[index].lock_version,
                  updated_at: state.items[index].updated_at,
                },
              },
            },
          });
          return;
        }

        const payload = route.request().postDataJSON() as {
          type: ProductRequest['type'];
          title: string;
          description: string;
          lock_version: number;
        };
        state.contentUpdatePayloads.push(payload);
        state.items[index] = {
          ...state.items[index],
          type: payload.type,
          title: payload.title,
          description: payload.description,
          lock_version: state.items[index].lock_version + 1,
          updated_at: '2026-07-27T09:30:00Z',
        };
        await fulfillProductRequestJson(route, 200, { data: state.items[index] });
        return;
      }

      if (method === 'PATCH' && match[2] === '/status') {
        const payload = route.request().postDataJSON() as {
          status: ProductRequest['status'];
          resolution_note: string;
        };
        const previous = state.items[index];
        state.items[index] = {
          ...previous,
          status: payload.status,
          resolution_note: payload.resolution_note,
          resolved_by: { id: 'user-1', name: 'Eigen gebruiker' },
          resolved_at: '2026-07-27T10:30:00Z',
          can_update: false,
          lock_version: previous.lock_version + 1,
          updated_at: '2026-07-27T10:30:00Z',
          status_history: [
            ...(previous.status_history ?? []),
            {
              id: `history-${previous.id}-${previous.lock_version + 1}`,
              from_status: previous.status,
              to_status: payload.status,
              note: payload.resolution_note,
              changed_by: { id: 'user-1', name: 'Eigen gebruiker' },
              created_at: '2026-07-27T10:30:00Z',
            },
          ],
        };
        await fulfillProductRequestJson(route, 200, { data: state.items[index] });
        return;
      }
    }

    await fulfillProductRequestJson(route, 404, {
      error: { code: 'not_found', message: 'Testroute niet gemockt.', details: {} },
    });
  });

  return listRequests;
}

function filterMockProductRequests(items: readonly ProductRequest[], url: URL): ProductRequest[] {
  const statuses = new Set((url.searchParams.get('status') ?? '').split(',').filter(Boolean));
  const types = new Set((url.searchParams.get('type') ?? '').split(',').filter(Boolean));
  const onlyMine = url.searchParams.get('mine') === '1';
  const search = (url.searchParams.get('search') ?? '').trim().toLocaleLowerCase('nl-NL');

  return items
    .filter((item) => {
      if (statuses.size > 0 && !statuses.has(item.status)) {
        return false;
      }
      if (types.size > 0 && !types.has(item.type)) {
        return false;
      }
      if (onlyMine && item.requester.id !== 'user-1') {
        return false;
      }
      if (search === '') {
        return true;
      }

      return [
        item.title,
        item.description,
        item.requester.name ?? '',
        item.resolution_note ?? '',
      ].join(' ').toLocaleLowerCase('nl-NL').includes(search);
    })
    .sort((left, right) => Date.parse(right.updated_at) - Date.parse(left.updated_at));
}

async function fulfillProductRequestJson(
  route: Route,
  status: number,
  body: unknown,
): Promise<void> {
  await route.fulfill({
    status,
    contentType: 'application/json',
    body: JSON.stringify(body),
  });
}

function productRequestUser(permissionNames: string[]) {
  return {
    id: 'user-1',
    name: 'Eigen gebruiker',
    email: 'product-requests@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    mfa_required: false,
    profile_completion_required: false,
    mail_preferences: { ui: { theme: 'dark' } },
    roles: [{
      id: 'product-request-web-role',
      name: 'product-request-web-role',
      display_name: 'Verzoeken webrol',
      can_use_operator_app: false,
      can_use_admin_app: true,
      permissions: permissionNames.map((name) => ({
        id: `permission-${name}`,
        name,
        category: 'product_request_management',
        display_name: name,
      })),
    }],
  };
}
