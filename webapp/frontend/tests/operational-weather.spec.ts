import { readFileSync } from 'node:fs';
import { Buffer } from 'node:buffer';
import { expect, test, type Page, type Route } from 'playwright/test';
import {
  buildForecastResourcePath,
  FORECAST_REFRESH_INTERVAL_MS,
  FORECAST_RETRY_INTERVAL_MS,
  forecastRefreshDeadline,
  normalizeForecastAddress,
  WEATHER_REFRESH_INTERVAL_MS,
} from '../src/features/weather/useForecastResource';
import {
  normalizeOperationalWeatherPage,
  normalizeUavForecastPage,
} from '../src/features/weather/forecastNormalization';

const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
const weatherRoute = readFileSync(new URL('../app/weather/page.tsx', import.meta.url), 'utf8');
const uavRoute = readFileSync(new URL('../app/uav-forecast/page.tsx', import.meta.url), 'utf8');
const weatherPage = readFileSync(new URL('../src/features/weather/WeatherPage.tsx', import.meta.url), 'utf8');
const uavPage = readFileSync(new URL('../src/features/weather/UavForecastPage.tsx', import.meta.url), 'utf8');
const locationControl = readFileSync(new URL('../src/features/weather/ForecastLocationControl.tsx', import.meta.url), 'utf8');
const resourceHook = readFileSync(new URL('../src/features/weather/useForecastResource.ts', import.meta.url), 'utf8');
const radarSection = readFileSync(new URL('../src/features/weather/WeatherRadarSection.tsx', import.meta.url), 'utf8');
const radarPlayback = readFileSync(new URL('../src/features/weather/useWeatherRadarPlayback.ts', import.meta.url), 'utf8');
const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../src/features/weather/OperationalForecast.module.css', import.meta.url), 'utf8');
const help = readFileSync(new URL('../src/features/help/HelpPage.tsx', import.meta.url), 'utf8');
const operationManual = readFileSync(new URL('../src/features/help/manuals/operationManual.ts', import.meta.url), 'utf8');
const RADAR_TEST_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
  'base64',
);

test('weather and UAV Forecast are authenticated operation routes and preload from the menu', () => {
  expect(navigation).toContain("{ to: '/weather', label: 'Weer', icon: CloudRain }");
  expect(navigation).toContain("{ to: '/uav-forecast', label: 'UAV Forecast', icon: Plane }");
  expect(navigation).toContain("'/weather': () => import('../features/weather/WeatherPage')");
  expect(navigation).toContain("'/uav-forecast': () => import('../features/weather/UavForecastPage')");

  expect(weatherRoute).toContain('<ProtectedShell>');
  expect(uavRoute).toContain('<ProtectedShell>');
  expect(weatherRoute).not.toContain('permissions=');
  expect(uavRoute).not.toContain('permissions=');
});

test('forecast queries use only a national scope or a normalized server-side address', () => {
  expect(normalizeForecastAddress('  Stationsplein   1, Utrecht  ')).toBe('Stationsplein 1, Utrecht');
  expect(buildForecastResourcePath('/operational-weather', { mode: 'netherlands', label: 'ignored' }))
    .toBe('/operational-weather?location_mode=netherlands');
  expect(buildForecastResourcePath('/uav-forecast', { mode: 'address', label: ' Stationsplein  1, Utrecht ' }))
    .toBe('/uav-forecast?location_mode=address&location_label=Stationsplein+1%2C+Utrecht');

  expect(locationControl).toContain('maxLength={160}');
  expect(locationControl).toContain('Vul een adres of plaatsnaam in.');
  expect(resourceHook).not.toContain("parameters.set('latitude'");
  expect(resourceHook).not.toContain("parameters.set('longitude'");
});

test('weather uses locally stored KNMI and EUMETSAT products without an embedded external map or flight advice', () => {
  expect(weatherPage).toContain('useForecastResource<OperationalWeatherPageState>(');
  expect(weatherPage).toContain("'/operational-weather'");
  expect(weatherPage).toContain('normalizeOperationalWeatherPage');
  expect(weatherPage).toContain('markOperationalWeatherStale(resource.data)');
  expect(weatherPage).toContain('Lokaal opgeslagen brondata');
  expect(weatherPage).toContain('De browser laadt geen externe weer- of radarkaart.');
  expect(weatherPage).toContain('cloud_cover_high_pct');
  expect(weatherPage).toContain('cloud_base_m');
  expect(weatherPage).toContain('radar_peak_mm_h');
  expect(weatherPage).toContain('third_hour_probability_pct');
  expect(weatherPage).toContain('Lokale radarrasterreeks tot +2 uur');
  expect(weatherPage).toContain('Hoogtereferentie niet door het modelproduct gespecificeerd');
  expect(weatherPage).toContain('<WeatherRadarSection radar={weather.radar} />');
  expect(radarSection).toContain('Buien- en bliksemradar');
  expect(radarSection).toContain('EUMETSAT LI total lightning; geen onderscheid tussen wolk-grond en wolk-wolk');
  expect(radarSection).toContain('Er wordt geen externe kaart ingebed.');
  expect(weatherPage).not.toContain('forecastAdvice(');
});

test('UAV Forecast renders server advice, every advice metric, provenance and fail-closed wording', () => {
  expect(uavPage).toContain('useForecastResource<WallboardForecastPageState>(');
  expect(uavPage).toContain("'/uav-forecast'");
  expect(uavPage).toContain('normalizeUavForecastPage');
  expect(uavPage).toContain('markWallboardForecastStale(resource.data)');
  expect(uavPage).toContain('const advice = forecastAdvice(forecast.overall_status)');
  expect(uavPage).toContain('const blocks = wallboardForecastAllDisplayBlocks(forecast)');
  expect(uavPage).toContain('blocks.map((block)');
  expect(uavPage).toContain('source={forecastSourceForBlock(block.key, forecast)}');
  expect(uavPage).toContain('Ontbrekende, ongeldige of verouderde veiligheidsdata worden nooit als groen advies getoond.');
  expect(uavPage).toContain('{forecast.scope_note}');
  expect(uavPage).toContain('{forecast.disclaimer}');
});

test('API types mirror nullable local KNMI provider fields', () => {
  expect(apiTypes).toContain("export type OperationalWeatherDataStatus = 'current' | 'partial' | 'unavailable'");
  expect(apiTypes).toContain('export interface OperationalWeatherCloudState');
  expect(apiTypes).toContain('cloud_base_m: number | null;');
  expect(apiTypes).toContain('model_run_at: string | null;');
  expect(apiTypes).toContain('export interface OperationalWeatherPrecipitationState');
  expect(apiTypes).toContain('probability_complete: boolean;');
  expect(apiTypes).toContain('radar_peak_mm_h: number | null;');
  expect(apiTypes).toContain('third_hour_probability_pct: number | null;');
  expect(apiTypes).toContain('radar_status: WallboardForecastStatus;');
  expect(apiTypes).toContain('third_hour_probability_status: WallboardForecastStatus;');
  expect(apiTypes).toContain("export type OperationalWeatherRadarLayerStatus = 'available' | 'stale' | 'unavailable'");
  expect(apiTypes).toContain('export interface OperationalWeatherRadarLayer');
  expect(apiTypes).toContain('observed_period_end: string | null;');
  expect(apiTypes).toContain('age_seconds: number | null;');
  expect(apiTypes).toContain('lag_seconds: number | null;');
  expect(apiTypes).toContain('refreshed_at: string | null;');
  expect(apiTypes).toContain('radar: OperationalWeatherRadarState;');
  expect(apiTypes).toContain('export interface OperationalWeatherPageState');
});

test('weather normalization never accepts malformed current data as current', () => {
  const missingTimestamp = currentWeather();
  missingTimestamp.cloud = {
    ...(missingTimestamp.cloud as Record<string, unknown>),
    measured_at: null,
  };
  const incomplete = normalizeOperationalWeatherPage(missingTimestamp);
  expect(incomplete?.data_status).not.toBe('current');
  expect(incomplete?.cloud).toMatchObject({ complete: false, stale: true });

  const onePointNational = currentWeather();
  onePointNational.aggregation = {
    type: 'single_location',
    sample_count: 1,
    expected_sample_count: 1,
    complete: true,
    fresh: true,
  };
  for (const provider of ['cloud', 'precipitation'] as const) {
    onePointNational[provider] = {
      ...(onePointNational[provider] as Record<string, unknown>),
      sample_count: 1,
      expected_sample_count: 1,
    };
  }
  const normalized = normalizeOperationalWeatherPage(onePointNational);
  expect(normalized?.data_status).toBe('unavailable');
  expect(normalized?.aggregation).toMatchObject({
    type: 'province_average',
    expected_sample_count: 12,
    complete: false,
    fresh: false,
  });
});

test('weather keeps the local radar usable while a missing third-hour probability stays unknown', () => {
  const radarOnly = currentWeather();
  radarOnly.data_status = 'partial';
  radarOnly.aggregation = {
    ...(radarOnly.aggregation as Record<string, unknown>),
    complete: false,
    fresh: false,
  };
  radarOnly.precipitation = {
    ...(radarOnly.precipitation as Record<string, unknown>),
    probability_complete: false,
    third_hour_probability_pct: null,
    third_hour_from: null,
    forecast_until: null,
    availability_note: 'De lokale KNMI-radar is beschikbaar; de kans voor uur 3 ontbreekt.',
  };

  const normalized = normalizeOperationalWeatherPage(radarOnly);

  expect(normalized?.data_status).toBe('partial');
  expect(normalized?.aggregation).toMatchObject({ complete: false, fresh: false });
  expect(normalized?.precipitation).toMatchObject({
    complete: true,
    probability_complete: false,
    stale: false,
    radar_peak_mm_h: 0,
    third_hour_probability_pct: null,
    third_hour_from: null,
    forecast_until: null,
  });
});

test('radar normalization accepts only the bounded same-origin atlas contract and preserves stale placeholders', () => {
  const weather = currentWeather();
  const normalized = normalizeOperationalWeatherPage(weather);
  expect(normalized?.data_status).toBe('current');
  expect(normalized?.radar.precipitation).toMatchObject({
    status: 'available',
    observed_period_end: null,
    age_seconds: 90,
    lag_seconds: 20,
    refreshed_at: '2026-07-21T12:05:00Z',
    atlas_columns: 5,
    atlas_rows: 5,
  });
  expect(normalized?.radar.lightning).toMatchObject({
    status: 'available',
    observed_period_end: '2026-07-21T12:05:00Z',
    age_seconds: 30,
    lag_seconds: 15,
    refreshed_at: '2026-07-21T12:05:00Z',
    atlas_columns: 4,
    atlas_rows: 2,
  });
  expect(normalized?.radar.lightning?.frames.map((frame) => frame.lead_minutes))
    .toEqual([-30, -25, -20, -15, -10, -5, 0]);

  const externalAtlas = currentWeather();
  const radar = externalAtlas.radar as Record<string, unknown>;
  radar.precipitation = {
    ...(radar.precipitation as Record<string, unknown>),
    atlas_url: 'https://gratis-radar.example/atlas.png',
  };
  const rejected = normalizeOperationalWeatherPage(externalAtlas);
  expect(rejected?.data_status).toBe('partial');
  expect(rejected?.radar.precipitation).toMatchObject({
    status: 'unavailable',
    atlas_url: null,
    frames: [],
  });

  const malformedRefreshTimestamp = currentWeather();
  const malformedRadar = malformedRefreshTimestamp.radar as Record<string, unknown>;
  malformedRadar.precipitation = {
    ...(malformedRadar.precipitation as Record<string, unknown>),
    refreshed_at: 'geen-tijdstip',
  };
  expect(normalizeOperationalWeatherPage(malformedRefreshTimestamp)?.radar.precipitation).toMatchObject({
    status: 'unavailable',
    refreshed_at: null,
    atlas_url: null,
    frames: [],
  });

  const stalePlaceholder = currentWeather();
  const staleRadar = stalePlaceholder.radar as Record<string, unknown>;
  staleRadar.lightning = {
    status: 'stale',
    reference_time: '2026-07-21T12:00:00Z',
    atlas_url: null,
    atlas_columns: 0,
    atlas_rows: 0,
    frame_width: 0,
    frame_height: 0,
    frames: [],
    source: {
      name: 'EUMETSAT MTG Lightning Imager',
      url: 'https://view.eumetsat.int/',
      license: 'EUMETSAT Data Policy',
    },
  };
  const preserved = normalizeOperationalWeatherPage(stalePlaceholder);
  expect(preserved?.data_status).toBe('partial');
  expect(preserved?.radar.lightning).toMatchObject({
    status: 'stale',
    atlas_url: null,
    frames: [],
  });
});

test('forecast refresh pauses while hidden and remains manually retryable', () => {
  expect(FORECAST_REFRESH_INTERVAL_MS).toBe(15 * 60 * 1000);
  expect(WEATHER_REFRESH_INTERVAL_MS).toBe(5 * 60 * 1000);
  expect(FORECAST_RETRY_INTERVAL_MS).toBe(60 * 1000);
  expect(resourceHook).toContain("document.visibilityState !== 'visible'");
  expect(resourceHook).toContain("document.addEventListener('visibilitychange', scheduleRefresh)");
  expect(resourceHook).toContain('requestSequence.current === sequence');
  expect(locationControl).toContain("type=\"button\"");
  expect(locationControl).toContain('onClick={onRefresh}');
});

test('forecast deadlines expire at exactly fifteen minutes and retry failures after one minute', () => {
  const successfulAt = Date.parse('2026-07-21T10:00:00Z');
  const failedAttemptAt = Date.parse('2026-07-21T10:04:30Z');

  expect(forecastRefreshDeadline(successfulAt, successfulAt, false))
    .toBe(successfulAt + 15 * 60 * 1000);
  expect(forecastRefreshDeadline(successfulAt, successfulAt, false, WEATHER_REFRESH_INTERVAL_MS))
    .toBe(successfulAt + 5 * 60 * 1000);
  expect(forecastRefreshDeadline(successfulAt, failedAttemptAt, true))
    .toBe(failedAttemptAt + 60 * 1000);
  expect(forecastRefreshDeadline(0, failedAttemptAt, false))
    .toBe(failedAttemptAt + 60 * 1000);
});

test('forecast layout is responsive, keyboard visible and reduced-motion safe', () => {
  expect(styles).toContain('grid-template-columns: repeat(4, minmax(0, 1fr));');
  expect(styles).toContain('grid-template-columns: repeat(2, minmax(0, 1fr));');
  expect(styles).toContain('@media (max-width: 620px)');
  expect(styles).toContain('.locationModes label:has(input:focus-visible)');
  expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
  expect(styles).toContain('color: var(--dis-blue);');
  expect(styles).toContain('grid-template-columns: minmax(0, 1fr) minmax(300px, 350px);');
  expect(styles).toContain('.radarTabs button:focus-visible');
  expect(radarPlayback).toContain("document.addEventListener('visibilitychange', handleVisibility)");
  expect(radarPlayback).toContain("window.matchMedia('(prefers-reduced-motion: reduce)')");
});

test('help index and operation manual explain both operational weather pages', () => {
  expect(help).toContain("id: 'weather'");
  expect(help).toContain("href: '/weather'");
  expect(help).toContain("id: 'uav-forecast'");
  expect(help).toContain("href: '/uav-forecast'");
  expect(operationManual).toContain("id: 'weather-read-local-knmi'");
  expect(operationManual).toContain("id: 'uav-forecast-assess'");
  expect(operationManual).toContain('De pagina Weer geeft geen vliegadvies.');
  expect(operationManual).toContain('EUMETSAT LI-product toont total lightning');
});

test('a failed UAV refresh immediately fails closed and stays closed during retry', async ({ page }) => {
  let requestCount = 0;
  let releaseRetry: (() => void) | null = null;
  const retryGate = new Promise<void>((resolve) => {
    releaseRetry = resolve;
  });

  await mockForecastApi(page, 'dark', async (path) => {
    if (path !== '/api/uav-forecast') return notFoundResponse();
    requestCount += 1;
    if (requestCount === 2) {
      return errorResponse(503, 'De forecastbron is tijdelijk niet bereikbaar.');
    }
    if (requestCount === 3) await retryGate;
    return successResponse(greenUavForecast());
  });

  await page.goto('/uav-forecast');
  await expect(page.getByRole('heading', { name: 'Binnen standaarddrempels' })).toBeVisible();

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect.poll(() => requestCount).toBe(2);
  await expect(page.getByRole('heading', { name: 'Advies onvolledig' })).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'forecast is verlopen' })).toBeVisible();

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect.poll(() => requestCount).toBe(3);
  await expect(page.getByRole('button', { name: /Bezig/ })).toBeDisabled();
  await expect(page.getByRole('heading', { name: 'Advies onvolledig' })).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'forecast is verlopen' })).toBeVisible();

  releaseRetry?.();
  await expect(page.getByRole('heading', { name: 'Binnen standaarddrempels' })).toBeVisible();
  await expect(page.getByRole('alert').filter({ hasText: 'forecast is verlopen' })).toHaveCount(0);
  expect(requestCount).toBe(3);
});

test('the initial forecast request disables controls and cannot be duplicated', async ({ page }) => {
  let requestCount = 0;
  let releaseInitial: (() => void) | null = null;
  const initialGate = new Promise<void>((resolve) => {
    releaseInitial = resolve;
  });

  await mockForecastApi(page, 'light', async (path) => {
    if (path !== '/api/operational-weather') return notFoundResponse();
    requestCount += 1;
    await initialGate;
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  await expect.poll(() => requestCount).toBe(1);
  const refreshButton = page.getByRole('button', { name: /Bezig/ });
  await expect(refreshButton).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Toepassen' })).toBeDisabled();
  await refreshButton.evaluate((button: HTMLButtonElement) => button.click());
  await page.waitForTimeout(50);
  expect(requestCount).toBe(1);

  releaseInitial?.();
  await expect(page.getByRole('heading', { name: 'Lokale datasets actueel' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Verversen' })).toBeEnabled();
});

test('weather keeps the validated live map active during refresh and an early retry failure', async ({ page }) => {
  let requestCount = 0;
  let releaseRefresh: (() => void) | null = null;
  const refreshGate = new Promise<void>((resolve) => {
    releaseRefresh = resolve;
  });
  await mockForecastApi(page, 'dark', async (path) => {
    if (path !== '/api/operational-weather') return notFoundResponse();
    requestCount += 1;
    if (requestCount === 2) await refreshGate;
    if (requestCount === 3) return errorResponse(503, 'De weerbron is tijdelijk niet bereikbaar.');
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect(page.getByText('Nieuwe weer- en radargegevens worden gecontroleerd.')).toBeVisible();
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();
  releaseRefresh?.();
  await expect(page.getByText('Nieuwe weer- en radargegevens worden gecontroleerd.')).toHaveCount(0);

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect.poll(() => requestCount).toBe(3);
  await expect(page.getByText('Bijwerken is niet gelukt.')).toBeVisible();
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();
  await expect(radar.getByText('Verouderd', { exact: true })).toHaveCount(0);
});

test('weather radar starts at now, exposes explicit controls and switches to total lightning by keyboard', async ({ page }) => {
  await mockForecastApi(page, 'dark', async (path) => {
    if (path !== '/api/operational-weather') return notFoundResponse();
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  await expect(page.getByRole('heading', { name: 'Buien- en bliksemradar' })).toBeVisible();
  const precipitationPanel = page.getByRole('tabpanel');
  await expect(precipitationPanel.getByText('Nu', { exact: true })).toBeVisible();
  await expect(precipitationPanel.getByRole('slider')).toHaveValue('0');
  await expect(precipitationPanel.getByRole('button', { name: 'Radaranimatie afspelen' })).toBeEnabled();

  await page.getByRole('tab', { name: 'Buien' }).focus();
  await page.getByRole('tab', { name: 'Buien' }).press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Bliksem' })).toHaveAttribute('aria-selected', 'true');
  await expect(precipitationPanel.getByText('EUMETSAT LI total lightning; geen onderscheid tussen wolk-grond en wolk-wolk')).toBeVisible();
  await expect(precipitationPanel.getByRole('slider')).toHaveValue('6');
  await precipitationPanel.getByRole('slider').fill('0');
  await expect(precipitationPanel.getByText('−30 min · waarneming')).toBeVisible();
  await expect(precipitationPanel.getByRole('button', { name: 'Naar nu' })).toBeEnabled();
  await precipitationPanel.getByRole('button', { name: 'Naar nu' }).click();
  await expect(precipitationPanel.getByRole('slider')).toHaveValue('6');
});

test('weather radar keeps its loading state honest until the atlas has decoded', async ({ page }) => {
  let releaseAtlas: (() => void) | null = null;
  const atlasGate = new Promise<void>((resolve) => {
    releaseAtlas = resolve;
  });
  await mockForecastApi(
    page,
    'dark',
    async (path) => path === '/api/operational-weather'
      ? successResponse(currentWeather())
      : notFoundResponse(),
    async (route) => {
      await atlasGate;
      await route.fulfill({ status: 200, contentType: 'image/png', body: RADAR_TEST_PNG });
    },
  );

  await page.goto('/weather', { waitUntil: 'domcontentloaded' });
  const radar = page.locator('[data-radar-kind="precipitation"]');
  await expect(radar.getByText('Radarbeeld laden')).toBeVisible();

  releaseAtlas?.();
  await expect(radar.getByRole('img', { name: /KNMI-neerslagradar/ })).toBeVisible();
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();
});

test('weather radar retries a failed atlas without discarding the page data', async ({ page }) => {
  let atlasRequests = 0;
  await mockForecastApi(
    page,
    'light',
    async (path) => path === '/api/operational-weather'
      ? successResponse(currentWeather())
      : notFoundResponse(),
    async (route) => {
      atlasRequests += 1;
      if (atlasRequests === 1) {
        await route.fulfill({ status: 503, contentType: 'text/plain', body: 'tijdelijk niet beschikbaar' });
        return;
      }
      await route.fulfill({ status: 200, contentType: 'image/png', body: RADAR_TEST_PNG });
    },
  );

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  await expect(radar.getByText('Radarafbeelding niet geladen')).toBeVisible();
  await radar.getByRole('button', { name: 'Opnieuw laden' }).click();
  await expect(radar.getByRole('img', { name: /KNMI-neerslagradar/ })).toBeVisible();
  expect(atlasRequests).toBeGreaterThanOrEqual(2);
  expect(atlasRequests).toBeLessThanOrEqual(3);
});

test('a stale radar atlas stays inspectable and never presents itself as live', async ({ page }) => {
  const staleWeather = currentWeather();
  staleWeather.data_status = 'partial';
  const radarState = staleWeather.radar as Record<string, unknown>;
  radarState.precipitation = {
    ...(radarState.precipitation as Record<string, unknown>),
    status: 'stale',
    age_seconds: 3_600,
    lag_seconds: 420,
    availability_note: 'De laatst gevalideerde KNMI-reeks is ouder dan toegestaan.',
  };

  await mockForecastApi(page, 'dark', async (path) => path === '/api/operational-weather'
    ? successResponse(staleWeather)
    : notFoundResponse());

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  await expect(radar.getByText('Verouderd', { exact: true })).toBeVisible();
  await expect(radar.getByText('Verouderde bronreeks')).toBeVisible();
  await expect(radar.getByText('1 uur oud', { exact: true })).toBeVisible();
  const play = radar.getByRole('button', { name: 'Radaranimatie afspelen' });
  await expect(play).toBeEnabled();
  await play.click();
  await expect(radar.getByRole('button', { name: 'Radaranimatie pauzeren' })).toBeEnabled();
});

test('reduced motion keeps radar animation off while manual time steps remain available', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await mockForecastApi(page, 'light', async (path) => {
    if (path !== '/api/operational-weather') return notFoundResponse();
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  const radarPanel = page.getByRole('tabpanel');
  await expect(radarPanel.getByText('Automatisch afspelen is uitgeschakeld vanwege de instelling voor minder beweging.')).toBeVisible();
  await expect(radarPanel.getByRole('button', { name: 'Radaranimatie afspelen' })).toBeDisabled();
  await expect(radarPanel.getByRole('button', { name: 'Volgende' })).toBeEnabled();
});

for (const scenario of [
  { path: '/weather', theme: 'light', heading: 'Lokale datasets actueel' },
  { path: '/uav-forecast', theme: 'dark', heading: 'Advies onvolledig' },
] as const) {
  test(`${scenario.path} renders without horizontal overflow at 375px in ${scenario.theme} mode`, async ({ page }) => {
    const staleUav = greenUavForecast();
    const metrics = staleUav.metrics as Array<Record<string, unknown>>;
    staleUav.metrics = metrics.map((metric) => metric.key === 'wind_speed_kmh'
      ? { ...metric, status: 'green', stale: true }
      : metric);

    await mockForecastApi(page, scenario.theme, async (path) => {
      if (path === '/api/operational-weather') return successResponse(currentWeather());
      if (path === '/api/uav-forecast') return successResponse(staleUav);
      return notFoundResponse();
    });
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(scenario.path);

    await expect(page.getByRole('heading', { name: scenario.heading })).toBeVisible();
    await expect(page.locator('html')).toHaveAttribute('data-theme', scenario.theme);
    const widths = await page.evaluate(() => ({
      viewport: document.documentElement.clientWidth,
      document: document.documentElement.scrollWidth,
      body: document.body.scrollWidth,
      overflowing: Array.from(document.querySelectorAll<HTMLElement>('body *'))
        .flatMap((element) => {
          const bounds = element.getBoundingClientRect();
          return bounds.right > document.documentElement.clientWidth + 1
            ? [{
                className: element.className,
                right: Math.round(bounds.right),
                tagName: element.tagName,
              }]
            : [];
        })
        .slice(0, 12),
    }));
    expect(
      Math.max(widths.document, widths.body),
      `Overlopende elementen: ${JSON.stringify(widths.overflowing)}`,
    ).toBeLessThanOrEqual(widths.viewport);

    if (scenario.path === '/uav-forecast') {
      await expect(page.getByText('Zichtbare satellieten', { exact: true })).toBeVisible();
      await expect(page.getByText('Bruikbare satellieten', { exact: true })).toBeVisible();
      const normalized = normalizeUavForecastPage(staleUav);
      expect(normalized?.overall_status).toBe('unknown');
    }
  });
}

interface MockApiResponse {
  status: number;
  body: unknown;
}

async function mockForecastApi(
  page: Page,
  theme: 'dark' | 'light',
  forecastResponse: (path: string) => Promise<MockApiResponse>,
  radarResponse?: (route: Route, kind: 'precipitation' | 'lightning') => Promise<void>,
): Promise<void> {
  await page.route('**/api/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    const radarMatch = /^\/api\/operational-weather\/radar\/(precipitation|lightning)\/\d{8}T\d{6}Z-[a-f0-9]{16}\.png$/.exec(path);
    if (radarMatch !== null) {
      if (radarResponse !== undefined) {
        await radarResponse(route, radarMatch[1] as 'precipitation' | 'lightning');
        return;
      }
      await route.fulfill({ status: 200, contentType: 'image/png', body: RADAR_TEST_PNG });
      return;
    }
    if (path === '/api/auth/me') {
      await fulfillJson(route, successResponse(currentUser(theme)));
      return;
    }
    if (path === '/api/branding') {
      await fulfillJson(route, successResponse({
        name: 'DIS',
        short_name: 'DIS',
        tenant_name: 'Testorganisatie',
        logo_data_url: '',
      }));
      return;
    }

    await fulfillJson(route, await forecastResponse(path));
  });
}

async function fulfillJson(
  route: Route,
  response: MockApiResponse,
): Promise<void> {
  await route.fulfill({
    status: response.status,
    contentType: 'application/json',
    body: JSON.stringify(response.body),
  });
}

function successResponse(data: unknown): MockApiResponse {
  return { status: 200, body: { data } };
}

function errorResponse(status: number, message: string): MockApiResponse {
  return { status, body: { error: { code: 'forecast_unavailable', message, details: {} } } };
}

function notFoundResponse(): MockApiResponse {
  return errorResponse(404, 'Testroute niet gemockt.');
}

function currentUser(theme: 'dark' | 'light') {
  return {
    id: 'forecast-test-user',
    name: 'Forecast Testgebruiker',
    email: 'forecast@example.test',
    account_status: 'active',
    push_enabled: true,
    max_operator_devices: 3,
    two_factor_enabled: true,
    mfa_required: false,
    profile_completion_required: false,
    mail_preferences: { ui: { theme } },
    roles: [],
  };
}

function currentWeather(): Record<string, unknown> {
  const source = { name: 'KNMI lokaal', url: null };
  return {
    location: { mode: 'netherlands', label: 'UAV Nederland', latitude: 52.2, longitude: 5.3 },
    aggregation: {
      type: 'province_average',
      sample_count: 12,
      expected_sample_count: 12,
      complete: true,
      fresh: true,
    },
    generated_at: '2026-07-21T12:05:00Z',
    data_status: 'current',
    cloud: {
      complete: true,
      stale: false,
      cloud_cover_pct: 35,
      cloud_cover_low_pct: 15,
      cloud_cover_mid_pct: 25,
      cloud_cover_high_pct: 30,
      cloud_base_m: 1_200,
      model_run_at: '2026-07-21T09:00:00Z',
      valid_at: '2026-07-21T12:00:00Z',
      measured_at: '2026-07-21T12:00:00Z',
      refreshed_at: '2026-07-21T12:05:00Z',
      sample_count: 12,
      expected_sample_count: 12,
      source,
      availability_note: null,
    },
    precipitation: {
      complete: true,
      probability_complete: true,
      stale: false,
      radar_peak_mm_h: 0,
      radar_first_precipitation_at: null,
      radar_until: '2026-07-21T14:00:00Z',
      third_hour_probability_pct: 5,
      third_hour_from: '2026-07-21T14:00:00Z',
      forecast_until: '2026-07-21T15:00:00Z',
      reference_time: '2026-07-21T12:00:00Z',
      measured_at: '2026-07-21T12:00:00Z',
      refreshed_at: '2026-07-21T12:05:00Z',
      sample_count: 12,
      expected_sample_count: 12,
      source,
      availability_note: null,
    },
    radar: {
      precipitation: {
        status: 'available',
        reference_time: '2026-07-21T12:00:00Z',
        observed_period_end: null,
        age_seconds: 90,
        lag_seconds: 20,
        refreshed_at: '2026-07-21T12:05:00Z',
        atlas_url: '/api/operational-weather/radar/precipitation/20260721T120000Z-0123456789abcdef.png',
        atlas_columns: 5,
        atlas_rows: 5,
        frame_width: 140,
        frame_height: 153,
        frames: Array.from({ length: 25 }, (_, index) => ({
          index,
          valid_at: new Date(Date.parse('2026-07-21T12:00:00Z') + index * 5 * 60_000).toISOString(),
          lead_minutes: index * 5,
        })),
        source: {
          name: 'KNMI Precipitation Radar',
          url: 'https://dataplatform.knmi.nl/',
          license: 'CC BY 4.0',
        },
        availability_note: null,
      },
      lightning: {
        status: 'available',
        reference_time: '2026-07-21T12:00:00Z',
        observed_period_end: '2026-07-21T12:05:00Z',
        age_seconds: 30,
        lag_seconds: 15,
        refreshed_at: '2026-07-21T12:05:00Z',
        atlas_url: '/api/operational-weather/radar/lightning/20260721T120000Z-fedcba9876543210.png',
        atlas_columns: 4,
        atlas_rows: 2,
        frame_width: 640,
        frame_height: 384,
        frames: Array.from({ length: 7 }, (_, index) => ({
          index,
          valid_at: new Date(Date.parse('2026-07-21T11:30:00Z') + index * 5 * 60_000).toISOString(),
          lead_minutes: (index - 6) * 5,
        })),
        source: {
          name: 'EUMETSAT MTG Lightning Imager',
          url: 'https://view.eumetsat.int/',
          license: 'EUMETSAT Data Policy',
        },
        availability_note: null,
      },
    },
    scope_note: 'Landelijk overzicht uit lokaal opgeslagen KNMI-producten.',
    disclaimer: 'Dit weerbeeld is geen vliegadvies.',
  };
}

function greenUavForecast(): Record<string, unknown> {
  const source = { name: 'Gecontroleerde bron', url: null };
  const metric = (
    key: string,
    label: string,
    value: number,
    overrides: Record<string, unknown> = {},
  ): Record<string, unknown> => ({
    key,
    label,
    value,
    unit: null,
    display_value: null,
    display_unit: null,
    status: 'green',
    stale: false,
    source,
    measured_at: '2026-07-21T12:00:00Z',
    explanation: 'Binnen de centrale drempel.',
    altitude_m: null,
    source_height_label: null,
    height_samples_agl_m: [],
    max_non_red_wind_height_agl_m: null,
    cloud_layers: null,
    cloud_base_forecast: null,
    cloud_base_observation: null,
    precipitation_outlook: null,
    thunderstorm_outlook: null,
    ...overrides,
  });

  return {
    location: { mode: 'netherlands', label: 'UAV Nederland', latitude: 52.2, longitude: 5.3 },
    aggregation: {
      type: 'province_average',
      sample_count: 12,
      expected_sample_count: 12,
      complete: true,
      fresh: true,
    },
    visible_blocks: ['visibility'],
    overall_status: 'green',
    generated_at: '2026-07-21T12:05:00Z',
    condition: {
      code: 1,
      label: 'Licht bewolkt',
      status: 'green',
      stale: false,
      source,
      measured_at: '2026-07-21T12:00:00Z',
    },
    daylight: {
      timezone: 'Europe/Amsterdam',
      sunrise_earliest: '2026-07-21T05:45:00+02:00',
      sunrise_latest: '2026-07-21T06:05:00+02:00',
      sunset_earliest: '2026-07-21T21:40:00+02:00',
      sunset_latest: '2026-07-21T22:00:00+02:00',
      stale: false,
      source,
    },
    wind_profile: {
      samples: [{ height_agl_m: 10, speed_kmh: 12 }, { height_agl_m: 120, speed_kmh: 20 }],
      max_non_red_wind_height_agl_m: 120,
      stale: false,
    },
    metrics: [
      metric('weather_code', 'Weer', 1),
      metric('temperature_c', 'Temperatuur', 18, { unit: '°C' }),
      metric('dew_point_c', 'Dauwpunt', 11, { unit: '°C' }),
      metric('wind_speed_kmh', 'Windsnelheid', 20, {
        unit: 'km/u',
        altitude_m: 120,
        height_samples_agl_m: [{ height_agl_m: 10, speed_kmh: 12 }, { height_agl_m: 120, speed_kmh: 20 }],
        max_non_red_wind_height_agl_m: 120,
      }),
      metric('wind_gust_kmh', 'Windstoten', 25, { unit: 'km/u', altitude_m: 10 }),
      metric('wind_direction_degrees', 'Windrichting', 180, { unit: '°', altitude_m: 120 }),
      metric('precipitation_probability_pct', 'Neerslagkans', 5, { unit: '%' }),
      metric('precipitation_mm', 'Neerslag', 0, { unit: 'mm' }),
      metric('precipitation_outlook', 'Buien +3 uur', 0, {
        unit: 'mm/u',
        precipitation_outlook: {
          radar_peak_mm_h: 0,
          radar_first_precipitation_at: null,
          radar_until: '2026-07-21T14:00:00Z',
          third_hour_probability_pct: 5,
          third_hour_from: '2026-07-21T14:00:00Z',
          forecast_until: '2026-07-21T15:00:00Z',
          reference_time: '2026-07-21T12:00:00Z',
          sample_count: 12,
          expected_sample_count: 12,
          attribution: 'KNMI',
        },
      }),
      metric('thunderstorm_forecast', 'Onweer +3 uur', 0, {
        thunderstorm_outlook: {
          expected: false,
          first_expected_at: null,
          forecast_until: '2026-07-21T15:00:00Z',
          sample_count: 12,
          expected_sample_count: 12,
          attribution: 'OPEN_METEO',
        },
      }),
      metric('cloud_cover_pct', 'Totale bewolking', 30, { unit: '%' }),
      metric('low_cloud_cover_pct', 'Lage bewolking', 10, {
        unit: '%',
        cloud_layers: { low_pct: 10, mid_pct: 20, high_pct: 25, total_pct: 30 },
      }),
      metric('visibility_m', 'Zichtbaarheid', 15_000, { unit: 'm' }),
      metric('kp_index', 'Kp-index', 2, { unit: 'Kp' }),
      metric('gnss_satellites', 'Zichtbare satellieten', 14),
      metric('gnss_satellites_fix', 'Bruikbare satellieten', 11),
    ],
    scope_note: 'Landelijk overzicht op basis van twaalf provinciepunten.',
    disclaimer: 'Operationele en wettelijke limieten gaan altijd voor.',
  };
}
