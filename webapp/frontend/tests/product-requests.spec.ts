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

test('builds bounded server-side request filters for all three tabs', () => {
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
});

test('keeps presentation filtering deterministic for ownership, handling and search', () => {
  const requests = [
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
  expect(manual).toContain("id: 'product-request-resolve'");

  expect(styles).toContain('grid-template-columns: minmax(320px, 0.72fr) minmax(440px, 1.28fr);');
  expect(styles).toContain('@media (max-width: 880px)');
  expect(styles).toContain('@media (max-width: 620px)');
  expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
  expect(styles).toContain('.requestCard:focus-visible');
});

test('creates, edits and resolves a request through the rendered page', async ({ page }) => {
  const state = productRequestMockState();
  await mockProductRequestApi(page, state, [
    'product-requests.view',
    'product-requests.create',
    'product-requests.update-own',
    'product-requests.resolve',
  ]);

  await page.goto('/verzoeken');
  await expect(page.getByRole('heading', { name: 'Van signaal naar oplossing' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Bestaand verzoek' })).toBeVisible();

  await page.getByRole('button', { name: 'Nieuw verzoek' }).click();
  const createPanel = page.locator('#product-request-create');
  await createPanel.getByLabel('Feature').check({ force: true });
  await createPanel.getByLabel('Titel', { exact: true }).fill('Nieuwe exportfunctie');
  await createPanel.getByLabel(/^Omschrijving/).fill('Voeg een compacte PDF-export toe.');
  await createPanel.getByRole('button', { name: 'Verzoek indienen' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Verzoek ingediend.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nieuwe exportfunctie' })).toBeVisible();
  expect(state.items).toHaveLength(2);
  expect(state.items[0]).toMatchObject({
    type: 'feature',
    title: 'Nieuwe exportfunctie',
    lock_version: 1,
  });

  await page.getByRole('button', { name: 'Aanpassen' }).click();
  const editForm = page.getByRole('heading', { name: 'Verzoek aanpassen' }).locator('..');
  await editForm.getByLabel('Titel', { exact: true }).fill('Nieuwe PDF-exportfunctie');
  await page.getByRole('textbox', { name: 'Omschrijving', exact: true })
    .fill('Voeg een compacte, toegankelijke PDF-export toe.');
  await editForm.getByRole('button', { name: 'Wijzigingen opslaan' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Wijzigingen opgeslagen.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nieuwe PDF-exportfunctie' })).toBeVisible();
  expect(state.items[0]).toMatchObject({
    title: 'Nieuwe PDF-exportfunctie',
    lock_version: 2,
  });

  await page.getByLabel('Nieuwe status').selectOption('resolved');
  await page.getByLabel('Toelichting').fill('Opgenomen in de eerstvolgende webrelease.');
  await page.getByRole('button', { name: 'Afhandeling opslaan' }).click();

  await expect(page.getByRole('status').filter({ hasText: 'Afhandeling opgeslagen.' })).toBeVisible();
  const detail = page.locator('article').filter({ hasText: 'Nieuwe PDF-exportfunctie' });
  await expect(detail.getByText('Opgelost', { exact: true })).toBeVisible();
  await expect(
    detail
      .getByRole('region', { name: 'Toelichting op de afhandeling' })
      .getByText('Opgenomen in de eerstvolgende webrelease.'),
  ).toBeVisible();
  await expect(detail.getByRole('button', { name: 'Aanpassen' })).toHaveCount(0);
  expect(state.items[0]).toMatchObject({
    status: 'resolved',
    resolution_note: 'Opgenomen in de eerstvolgende webrelease.',
    lock_version: 3,
  });
});

test('hides actions without action rights and reloads the latest version after a stale edit', async ({ page }) => {
  const readOnlyState = productRequestMockState({
    can_update: false,
    can_resolve: false,
  });
  await mockProductRequestApi(page, readOnlyState, ['product-requests.view']);
  await page.goto('/verzoeken');

  await expect(page.getByRole('heading', { name: 'Bestaand verzoek' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Nieuw verzoek' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Aanpassen' })).toHaveCount(0);
  await expect(page.getByRole('heading', { name: 'Afhandeling vastleggen' })).toHaveCount(0);

  const staleState = productRequestMockState();
  staleState.conflictOnNextContentUpdate = true;
  await page.unroute('**/api/**');
  await mockProductRequestApi(page, staleState, [
    'product-requests.view',
    'product-requests.update-own',
  ]);
  await page.reload();
  await page.getByRole('button', { name: 'Aanpassen' }).click();
  const editForm = page.getByRole('heading', { name: 'Verzoek aanpassen' }).locator('..');
  await editForm.getByLabel('Titel', { exact: true }).fill('Lokale wijziging');
  await editForm.getByRole('button', { name: 'Wijzigingen opslaan' }).click();

  await expect(page.getByRole('alert').filter({ hasText: 'Dit verzoek is intussen gewijzigd.' }))
    .toBeVisible();
  await expect(page.getByRole('heading', { name: 'Nieuwste titel van de server' })).toBeVisible();
  expect(staleState.items[0]).toMatchObject({
    title: 'Nieuwste titel van de server',
    lock_version: 2,
  });
});

interface ProductRequestMockState {
  items: ProductRequest[];
  conflictOnNextContentUpdate: boolean;
  nextId: number;
}

function productRequestMockState(
  overrides: Partial<ProductRequest> = {},
): ProductRequestMockState {
  return {
    items: [request('request-1', {
      title: 'Bestaand verzoek',
      description: 'Bestaande omschrijving.',
      can_resolve: true,
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
    nextId: 2,
  };
}

async function mockProductRequestApi(
  page: Page,
  state: ProductRequestMockState,
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
      const pageNumber = Math.max(1, Number(url.searchParams.get('page') ?? '1'));
      await fulfillProductRequestJson(route, 200, {
        data: state.items,
        meta: {
          current_page: pageNumber,
          from: state.items.length === 0 ? null : 1,
          last_page: 1,
          path: '/api/product-requests',
          per_page: 25,
          to: state.items.length,
          total: state.items.length,
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
        };
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
