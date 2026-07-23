import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { Page, Route } from 'playwright/test';
import type {
  KnmiAdminDatasetStatus,
  KnmiAdminStatus,
  KnmiCatalogResponse,
  KnmiDatasetOperation,
} from '../src/types/api';
import {
  buildKnmiKeyPayload,
  formatKnmiBytes,
  KNMI_ACTIVE_POLL_INTERVAL_MS,
  knmiDatasetCategoryLabel,
  knmiDatasetConsumerLabel,
  knmiDatasetStatusLabel,
  knmiDatasetStatusTone,
  knmiDatasetStorageModeLabel,
  knmiKeySourceLabel,
  knmiOperationIsActive,
  knmiOperationStageLabel,
  knmiOperationStateLabel,
  knmiOperationStateTone,
  normalizeKnmiProgress,
  safeKnmiSourceUrl,
} from '../src/features/admin/knmiAdminPresentation';

const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');

test('exposes KNMI directly below Admin as its own protected management page', () => {
  const route = readFileSync(new URL('../app/knmi/page.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const generalAdmin = readFileSync(new URL('../src/features/admin/AdminPage.tsx', import.meta.url), 'utf8');
  const adminIndex = navigation.indexOf("to: '/admin', label: 'Admin'");
  const knmiIndex = navigation.indexOf("to: '/knmi', label: 'KNMI'");

  expect(route).toContain("permissions={['settings.manage']}");
  expect(adminIndex).toBeGreaterThan(-1);
  expect(knmiIndex).toBeGreaterThan(adminIndex);
  expect(navigation.slice(adminIndex, knmiIndex)).not.toContain("to: '/branding'");
  expect(navigation).toContain("'/knmi': () => import('../features/admin/KnmiAdminPage')");
  expect(generalAdmin).not.toContain('KNMI Data Platform');
  expect(generalAdmin).not.toContain('KNMI EDR');
});

test('keeps modelverwachtingen and measured station data visibly separate', () => {
  const page = readFileSync(new URL('../src/features/admin/KnmiAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain('Een modelverwachting voor Nederland, geen meting.');
  expect(page).toContain('EDR · gemeten stationdata');
  expect(page).toContain('mengt ze niet met het modelpercentage');
  expect(page).toContain('Verwachtingsvenster');
  expect(page).toContain('+60 uur');
});

test('maps KNMI import states, stages and bounded progress honestly', () => {
  expect(KNMI_ACTIVE_POLL_INTERVAL_MS).toBe(5_000);
  expect(knmiOperationIsActive('queued')).toBe(true);
  expect(knmiOperationIsActive('running')).toBe(true);
  expect(knmiOperationIsActive('succeeded')).toBe(false);
  expect(knmiOperationStateLabel('succeeded', true)).toBe('Al actueel');
  expect(knmiOperationStateLabel('failed')).toBe('Mislukt');
  expect(knmiOperationStateTone('running')).toBe('warn');
  expect(knmiOperationStateTone('failed')).toBe('bad');
  expect(knmiOperationStageLabel('metadata')).toBe('Actuele modelset controleren');
  expect(knmiOperationStageLabel('downloading')).toBe('Volledig archief downloaden');
  expect(knmiOperationStageLabel('validating')).toBe('KNMI-parameters controleren');
  expect(knmiOperationStageLabel('custom_stage')).toBe('custom stage');
  expect(normalizeKnmiProgress(-10)).toBe(0);
  expect(normalizeKnmiProgress(48.7)).toBe(49);
  expect(normalizeKnmiProgress(180)).toBe(100);
  expect(normalizeKnmiProgress(null)).toBeNull();
});

test('maps every dataset state, category and storage mode without implying unavailable data is current', () => {
  expect(knmiDatasetStatusLabel('current')).toBe('Actueel');
  expect(knmiDatasetStatusLabel('stale')).toBe('Verouderd');
  expect(knmiDatasetStatusLabel('unavailable')).toBe('Niet beschikbaar');
  expect(knmiDatasetStatusLabel('not_configured')).toBe('Niet geconfigureerd');
  expect(knmiDatasetStatusLabel('on_demand')).toBe('Op aanvraag');
  expect(knmiDatasetStatusLabel('available')).toBe('Nog niet gekoppeld');
  expect(knmiDatasetStatusTone('current')).toBe('good');
  expect(knmiDatasetStatusTone('stale')).toBe('warn');
  expect(knmiDatasetStatusTone('unavailable')).toBe('bad');
  expect(knmiDatasetCategoryLabel('active')).toBe('Automatisch actief');
  expect(knmiDatasetCategoryLabel('on_demand')).toBe('Op aanvraag');
  expect(knmiDatasetCategoryLabel('available')).toBe('Broncatalogus');
  expect(knmiDatasetStorageModeLabel('local_snapshot')).toBe('Lokale momentopname');
  expect(knmiDatasetStorageModeLabel('local_cache')).toBe('Lokale cache');
  expect(knmiDatasetStorageModeLabel('remote_on_demand')).toBe('Extern op aanvraag');
  expect(knmiDatasetStorageModeLabel('catalog_only')).toBe('Nog niet lokaal verwerkt');
  expect(knmiDatasetConsumerLabel('uav_forecast')).toBe('UAV Forecast');
  expect(safeKnmiSourceUrl('https://dataplatform.knmi.nl/dataset/test')).toBe('https://dataplatform.knmi.nl/dataset/test');
  expect(safeKnmiSourceUrl('http://onveilig.example/dataset')).toBeNull();
});

test('formats download sizes and key origins for Dutch administrators', () => {
  expect(formatKnmiBytes(0)).toBe('0 B');
  expect(formatKnmiBytes(861_009_920)).toBe('821 MiB');
  expect(formatKnmiBytes(-1)).toBe('-');
  expect(knmiKeySourceLabel('open_data_setting')).toBe('Opgeslagen in D.I.S.');
  expect(knmiKeySourceLabel('open_data_environment')).toBe('Serveromgeving');
  expect(knmiKeySourceLabel('edr_setting')).toBe('Opgeslagen in D.I.S.');
  expect(knmiKeySourceLabel('edr_environment')).toBe('Serveromgeving');
  expect(knmiKeySourceLabel(null)).toBe('Niet ingesteld');
});

test('sends only newly entered KNMI keys and never blanks stored secrets', () => {
  expect(buildKnmiKeyPayload({
    openDataApiKey: ' open-data-key ',
    edrApiKey: ' ',
  })).toEqual({ open_data_api_key: 'open-data-key' });

  expect(buildKnmiKeyPayload({
    openDataApiKey: '',
    edrApiKey: ' edr-key ',
  })).toEqual({ edr_api_key: 'edr-key' });

  expect(buildKnmiKeyPayload({ openDataApiKey: '', edrApiKey: '' })).toEqual({});
});

test('uses the dedicated KNMI endpoints and polls only while an operation is active', () => {
  const page = readFileSync(new URL('../src/features/admin/KnmiAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain("useApiResource<KnmiAdminStatus>('/admin/knmi'");
  expect(page).toContain("api.patch<KnmiAdminStatus>('/admin/knmi', payload)");
  expect(page).toContain("api.post<KnmiForecastOperationStarted>('/admin/knmi/refresh')");
  expect(page).toContain("api.post<KnmiPrecipitationRefreshStarted>('/admin/knmi/precipitation/refresh')");
  expect(page).toContain('Radar en neerslagkans bijwerken');
  expect(page).not.toContain('onweerskans');
  expect(page).toContain('automatisch iedere vijf minuten gecontroleerd');
  expect(page).toContain('if (!pollingActive)');
  expect(page).toContain('KNMI_ACTIVE_POLL_INTERVAL_MS');
});

test('mirrors the server-authoritative dataset inventory and fixed refresh route', () => {
  const page = readFileSync(new URL('../src/features/admin/KnmiAdminPage.tsx', import.meta.url), 'utf8');

  expect(apiTypes).toContain("export type KnmiDatasetCategory = 'active' | 'on_demand' | 'available'");
  expect(apiTypes).toContain("export type KnmiDatasetStatus = 'current' | 'stale' | 'unavailable' | 'not_configured' | 'on_demand' | 'available'");
  expect(apiTypes).toContain("export type KnmiDatasetStorageMode = 'local_snapshot' | 'local_cache' | 'remote_on_demand' | 'catalog_only'");
  expect(apiTypes).toContain('datasets?: KnmiAdminDatasetStatus[];');
  expect(apiTypes).toContain('latest_error: KnmiDatasetError | null;');
  expect(apiTypes).toContain('operation: KnmiDatasetOperation | null;');
  expect(page).toContain('`/admin/knmi/datasets/${encodeURIComponent(dataset.key)}/refresh`');
  expect(page).not.toContain('dataset.refresh_endpoint');
  expect(page).toContain('pollingActive');
  expect(page).toContain('hasNativeInventory');
  expect(page).toContain('useApiResource<KnmiCatalogResponse>');
  expect(page).toContain('/admin/knmi/catalog?');
  expect(page).toContain('Alle KNMI-datasetrecords, rechtstreeks uit de broncatalogus');
});

test('renders runtime status separately from the complete searchable source catalog', async ({ page }) => {
  const status = knmiAdminStatusWithDatasets();
  let requestedDatasetKey: string | null = null;
  let statusRequestCount = 0;
  await page.clock.install({ time: new Date('2026-07-23T10:08:00Z') });
  await mockKnmiAdminApi(page, status, (datasetKey) => {
    requestedDatasetKey = datasetKey;
  }, () => {
    statusRequestCount += 1;
  });

  await page.goto('/knmi');

  await expect(page.getByRole('heading', { name: 'Operationele databronnen' })).toBeVisible();
  await expect.poll(() => statusRequestCount).toBeGreaterThanOrEqual(1);
  const statusRequestBaseline = statusRequestCount;
  await page.clock.fastForward(KNMI_ACTIVE_POLL_INTERVAL_MS);
  await expect.poll(() => statusRequestCount).toBeGreaterThan(statusRequestBaseline);
  await expect(page.getByRole('heading', { name: 'In gebruik door D.I.S.' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Volledige KNMI-broncatalogus' })).toBeVisible();
  await expect(page.getByRole('heading', {
    name: 'Precipitation - 5 minute radar nowcast over The Netherlands up to 2 hours ahead',
  })).toBeVisible();
  await expect(page.getByText('1–1 van 408 datasets')).toBeVisible();
  await expect(page.getByRole('searchbox', { name: 'Zoeken' })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Datasetstatus' })).toBeVisible();
  await expect(page.getByRole('combobox', { name: 'Licentie' })).toBeVisible();

  const current = page.getByRole('article', { name: 'harmonie_arome_cy43_p1, Actueel' });
  await expect(current.getByText('Actueel', { exact: true })).toBeVisible();
  await expect(current.getByText('23-07-2026, 12:00:00', { exact: true })).toBeVisible();
  await expect(current.getByText('23-07-2026, 12:05:00', { exact: true })).toBeVisible();
  await expect(current.getByText('23-07-2026, 12:15:00', { exact: true })).toBeVisible();
  await expect(current.getByRole('button', { name: 'Nu bijwerken' })).toBeEnabled();

  const stale = page.getByRole('article', { name: 'radar_forecast, Verouderd' });
  await expect(stale.getByText('Verouderd', { exact: true })).toBeVisible();
  await expect(stale.getByRole('button', { name: 'Nu bijwerken' })).toBeEnabled();

  const unavailable = page.getByRole('article', {
    name: 'seamless_precipitation_ensemble_forecast_probabilities, Niet beschikbaar',
  });
  await expect(unavailable.getByText('Niet beschikbaar', { exact: true })).toBeVisible();
  await expect(unavailable.getByText('Laatste importfout', { exact: true })).toBeVisible();
  await expect(unavailable.getByText('Archiefcontrole leverde geen bruikbaar bestand op.')).toBeVisible();
  await expect(unavailable.getByText('-', { exact: true })).toHaveCount(2);

  const onDemand = page.getByRole('article', { name: 'KNMI EDR observations, Op aanvraag' });
  await expect(onDemand.getByText('Op aanvraag', { exact: true })).toHaveCount(2);
  await expect(onDemand.getByText('Volgende automatische run')).toBeVisible();
  await expect(onDemand.getByRole('button')).toHaveCount(0);

  const running = page.getByRole('article', { name: 'eumetsat_mtg_li, Actueel' });
  await expect(running.getByText('Wordt bijgewerkt', { exact: true })).toHaveCount(2);
  await expect(running.getByRole('progressbar')).toHaveAttribute('value', '42');
  await expect(running.getByText('42%', { exact: true }).first()).toBeVisible();
  await expect(running.getByRole('button', { name: 'Wordt bijgewerkt' })).toBeDisabled();

  await expect(page.getByRole('link', {
    name: 'Precipitation - 5 minute radar nowcast over The Netherlands up to 2 hours ahead in de KNMI-catalogus openen',
  })).toHaveAttribute(
    'href',
    'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
  );

  await current.getByRole('button', { name: 'Nu bijwerken' }).click();
  await expect.poll(() => requestedDatasetKey).toBe('harmonie_arome_cy43_p1');
  await expect(current.getByRole('progressbar')).toHaveAttribute('value', '0');
  await expect(current.getByText('Wachten op uitvoering')).toBeVisible();
});

test('keeps the newest catalog result when an older search response arrives later', async ({ page }) => {
  let releaseOlderResponse: (() => void) | null = null;
  const olderResponseGate = new Promise<void>((resolve) => {
    releaseOlderResponse = resolve;
  });
  let markOlderResponseFulfilled: (() => void) | null = null;
  const olderResponseFulfilled = new Promise<void>((resolve) => {
    markOlderResponseFulfilled = resolve;
  });

  await mockKnmiAdminApi(
    page,
    knmiAdminStatusWithDatasets(),
    () => undefined,
    () => undefined,
    async (route, url) => {
      const query = url.searchParams.get('query') ?? '';
      if (query === 'oud') {
        await olderResponseGate;
        await fulfillJson(route, 200, {
          data: knmiCatalogResponseFor('oude-dataset', 'Oude dataset'),
        });
        markOlderResponseFulfilled?.();
        return;
      }

      await fulfillJson(route, 200, {
        data: query === 'nieuw'
          ? knmiCatalogResponseFor('nieuwe-dataset', 'Nieuwe dataset')
          : knmiCatalogResponse(),
      });
    },
  );

  await page.goto('/knmi');
  await expect(page.getByRole('heading', {
    name: 'Precipitation - 5 minute radar nowcast over The Netherlands up to 2 hours ahead',
  })).toBeVisible();

  const search = page.getByRole('searchbox', { name: 'Zoeken' });
  const olderRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return url.pathname === '/api/admin/knmi/catalog' && url.searchParams.get('query') === 'oud';
  });
  await search.fill('oud');
  await olderRequest;

  const newerRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return url.pathname === '/api/admin/knmi/catalog' && url.searchParams.get('query') === 'nieuw';
  });
  await search.fill('nieuw');
  await newerRequest;
  await expect(page.getByRole('heading', { name: 'Nieuwe dataset' })).toBeVisible();

  releaseOlderResponse?.();
  await olderResponseFulfilled;
  await expect(page.getByRole('heading', { name: 'Nieuwe dataset' })).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Oude dataset' })).toHaveCount(0);
});

test('keeps the dataset rail readable without horizontal overflow on mobile', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await page.clock.install({ time: new Date('2026-07-23T10:08:00Z') });
  await mockKnmiAdminApi(page, knmiAdminStatusWithDatasets(), () => undefined, () => undefined);

  await page.goto('/knmi');
  await expect(page.getByRole('heading', { name: 'Operationele databronnen' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Nu bijwerken' }).first()).toBeVisible();

  const widths = await page.evaluate(() => ({
    viewport: document.documentElement.clientWidth,
    document: document.documentElement.scrollWidth,
    body: document.body.scrollWidth,
  }));
  expect(Math.max(widths.document, widths.body)).toBeLessThanOrEqual(widths.viewport);
});

test('does not offer a dataset refresh before its required API configuration exists', async ({ page }) => {
  const status = knmiAdminStatusWithDatasets();
  const harmonie = status.datasets?.[0];
  if (!harmonie) throw new Error('HARMONIE fixture ontbreekt.');
  status.configuration.configured = false;
  status.active_snapshot = null;
  status.datasets = [{
    ...harmonie,
    configured: false,
    status: 'not_configured',
    reference_at: null,
    refreshed_at: null,
    availability_note: 'Een aparte KNMI Open Data API-sleutel is vereist.',
  }];
  let refreshRequests = 0;
  await mockKnmiAdminApi(page, status, () => {
    refreshRequests += 1;
  }, () => undefined);

  await page.goto('/knmi');
  const dataset = page.getByRole('article', { name: 'harmonie_arome_cy43_p1, Niet geconfigureerd' });
  await expect(dataset.getByText('Een aparte KNMI Open Data API-sleutel is vereist.')).toBeVisible();
  const button = dataset.getByRole('button', { name: 'Configuratie vereist' });
  await expect(button).toBeDisabled();
  await button.evaluate((element: HTMLButtonElement) => element.click());
  expect(refreshRequests).toBe(0);
});

async function mockKnmiAdminApi(
  page: Page,
  status: KnmiAdminStatus,
  onRefresh: (datasetKey: string) => void,
  onStatusRequest: () => void,
  onCatalogRequest?: (route: Route, url: URL) => Promise<void>,
): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname;
    if (path === '/api/auth/me') {
      await fulfillJson(route, 200, { data: knmiAdminUser() });
      return;
    }
    if (path === '/api/branding') {
      await fulfillJson(route, 200, {
        data: { name: 'DIS', short_name: 'DIS', tenant_name: 'Testorganisatie', logo_data_url: '' },
      });
      return;
    }
    if (path === '/api/auth/csrf-cookie') {
      await route.fulfill({ status: 204, body: '' });
      return;
    }
    if (path === '/api/admin/knmi' && route.request().method() === 'GET') {
      onStatusRequest();
      await fulfillJson(route, 200, { data: status });
      return;
    }
    if (path === '/api/admin/knmi/catalog' && route.request().method() === 'GET') {
      if (onCatalogRequest !== undefined) {
        await onCatalogRequest(route, url);
        return;
      }
      await fulfillJson(route, 200, { data: knmiCatalogResponse() });
      return;
    }

    const refreshMatch = path.match(/^\/api\/admin\/knmi\/datasets\/([^/]+)\/refresh$/);
    if (refreshMatch && route.request().method() === 'POST') {
      const datasetKey = decodeURIComponent(refreshMatch[1]);
      onRefresh(datasetKey);
      const operation: KnmiDatasetOperation = {
        id: 'dataset-operation-requested',
        dataset_keys: [datasetKey],
        state: 'queued',
        stage: 'queued',
        message: 'Datasetverversing staat in de wachtrij.',
        progress_percent: 0,
        started_at: null,
        finished_at: null,
      };
      await fulfillJson(route, 202, { data: { dataset_key: datasetKey, operation } });
      return;
    }

    await fulfillJson(route, 404, {
      error: { code: 'not_found', message: 'Testroute niet gemockt.', details: {} },
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

function knmiAdminUser() {
  return {
    id: 'knmi-admin',
    name: 'KNMI-beheerder',
    email: 'knmi@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    profile_completion_required: false,
    roles: [{
      id: 'knmi-admin-role',
      name: 'knmi_admin',
      display_name: 'KNMI-beheerder',
      can_use_operator_app: false,
      can_use_admin_app: true,
      permissions: [{
        id: 'settings-manage',
        name: 'settings.manage',
        category: 'settings',
        display_name: 'Instellingen beheren',
      }],
    }],
  };
}

function knmiAdminStatusWithDatasets(): KnmiAdminStatus {
  const runningOperation: KnmiDatasetOperation = {
    id: 'eumetsat-running',
    dataset_keys: ['eumetsat_mtg_li'],
    state: 'running',
    stage: 'downloading',
    message: 'Nieuwe bliksembeelden worden lokaal opgeslagen.',
    progress_percent: 42,
    started_at: '2026-07-23T10:06:00Z',
    finished_at: null,
  };

  return {
    configuration: {
      configured: true,
      open_data_api_key_configured: true,
      open_data_api_key_source: 'open_data_setting',
      open_data_endpoint: 'https://api.dataplatform.knmi.nl/open-data/v1',
      edr_api_key_configured: true,
      edr_api_key_source: 'edr_setting',
      edr_collection_endpoint: 'https://api.dataplatform.knmi.nl/edr/v1/collections/observations',
      dataset: 'harmonie_arome_cy43_p1',
      dataset_version: '1.0',
      automatic_interval_hours: 1,
    },
    active_snapshot: {
      id: 'snapshot-1',
      source_filename: 'harmonie.nc',
      source_size_bytes: 861_009_920,
      model_run_at: '2026-07-23T10:00:00Z',
      forecast_start_at: '2026-07-23T10:00:00Z',
      forecast_end_at: '2026-07-25T22:00:00Z',
      member_count: 61,
      activated_at: '2026-07-23T10:05:00Z',
    },
    active_operation: null,
    latest_operation: null,
    datasets: [
      datasetStatus({
        key: 'harmonie_arome_cy43_p1',
        dataset: 'harmonie_arome_cy43_p1',
        version: '1.0',
        status: 'current',
        storage_mode: 'local_snapshot',
        consumers: ['operational_weather', 'uav_forecast'],
        reference_at: '2026-07-23T10:00:00Z',
        refreshed_at: '2026-07-23T10:05:00Z',
        next_update_at: '2026-07-23T10:15:00Z',
        refreshable: true,
      }),
      datasetStatus({
        key: 'radar_forecast',
        dataset: 'radar_forecast',
        version: '2.0',
        status: 'stale',
        storage_mode: 'local_cache',
        consumers: ['operational_weather', 'weather_radar'],
        reference_at: '2026-07-23T09:45:00Z',
        refreshed_at: '2026-07-23T09:47:00Z',
        next_update_at: '2026-07-23T10:10:00Z',
        refreshable: true,
      }),
      datasetStatus({
        key: 'seamless_precipitation_ensemble_forecast_probabilities',
        dataset: 'seamless_precipitation_ensemble_forecast_probabilities',
        version: '1.0',
        status: 'unavailable',
        storage_mode: 'local_cache',
        consumers: ['operational_weather', 'uav_forecast'],
        latest_error: {
          code: 'invalid_archive',
          message: 'Archiefcontrole leverde geen bruikbaar bestand op.',
          at: '2026-07-23T09:51:00Z',
        },
        refreshable: true,
      }),
      datasetStatus({
        key: 'knmi_edr_observations',
        dataset: 'KNMI EDR observations',
        version: 'v1',
        category: 'on_demand',
        status: 'on_demand',
        storage_mode: 'remote_on_demand',
        consumers: ['operational_weather', 'uav_forecast'],
      }),
      datasetStatus({
        key: 'eumetsat_mtg_li',
        provider: 'EUMETSAT',
        dataset: 'eumetsat_mtg_li',
        version: '1.0',
        status: 'current',
        storage_mode: 'local_cache',
        consumers: ['weather_radar'],
        reference_at: '2026-07-23T10:05:00Z',
        refreshed_at: '2026-07-23T10:06:00Z',
        next_update_at: '2026-07-23T10:11:00Z',
        refreshable: true,
        operation: runningOperation,
      }),
    ],
  };
}

function knmiCatalogResponse(): KnmiCatalogResponse {
  return {
    items: [{
      key: 'radar-forecast-2-0',
      title: 'Precipitation - 5 minute radar nowcast over The Netherlands up to 2 hours ahead',
      dataset: 'radar_forecast',
      version: '2.0',
      description: 'Nowcast precipitation forecast up to 2 hours ahead, per 5 minutes, over the Netherlands.',
      status: 'onGoing',
      license_id: 'CC-BY-4.0',
      license_title: 'Creative Commons Attribution 4.0',
      is_open: true,
      formats: ['HDF5'],
      topics: ['Precipitation', 'Radar', 'Nowcast'],
      publication_at: '2024-08-01T00:00:00Z',
      metadata_updated_at: '2026-07-20T08:57:42Z',
      source_url: 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
    }],
    pagination: {
      page: 1,
      per_page: 20,
      total: 408,
      last_page: 21,
      from: 1,
      to: 1,
    },
    filters: {
      statuses: [
        { value: 'ongoing', label: 'Doorlopend' },
        { value: 'completed', label: 'Afgerond' },
      ],
      licenses: [
        { value: 'CC-BY-4.0', label: 'Creative Commons Attribution 4.0', count: 302 },
      ],
    },
    catalog: {
      available: true,
      cache_state: 'fresh',
      fetched_at: '2026-07-23T10:07:00Z',
      source_url: 'https://dataplatform.knmi.nl/dataset/',
      warning: null,
    },
  };
}

function knmiCatalogResponseFor(key: string, title: string): KnmiCatalogResponse {
  const response = knmiCatalogResponse();

  return {
    ...response,
    items: [{
      ...response.items[0],
      key,
      title,
      source_url: `https://dataplatform.knmi.nl/dataset/${key}`,
    }],
    pagination: {
      ...response.pagination,
      total: 1,
      last_page: 1,
    },
  };
}

function datasetStatus(
  overrides: Partial<KnmiAdminDatasetStatus> & Pick<KnmiAdminDatasetStatus, 'key' | 'dataset' | 'version'>,
): KnmiAdminDatasetStatus {
  return {
    key: overrides.key,
    provider: 'KNMI',
    dataset: overrides.dataset,
    version: overrides.version,
    category: 'active',
    consumers: [],
    storage_mode: 'local_cache',
    status: 'not_configured',
    configured: true,
    source_url: 'https://dataplatform.knmi.nl/',
    reference_at: null,
    refreshed_at: null,
    next_update_at: null,
    availability_note: null,
    latest_error: null,
    refreshable: false,
    operation: null,
    ...overrides,
  };
}
