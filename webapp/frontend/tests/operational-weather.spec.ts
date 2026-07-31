import { readFileSync } from 'node:fs';
import { Buffer } from 'node:buffer';
import { expect, test, type Locator, type Page, type Route } from 'playwright/test';
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
  normalizeOperationalWeatherRadarState,
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
const liveRadarMap = readFileSync(new URL('../src/features/weather/LiveRadarMap.tsx', import.meta.url), 'utf8');
const radarPlayback = readFileSync(new URL('../src/features/weather/useWeatherRadarPlayback.ts', import.meta.url), 'utf8');
const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
const styles = readFileSync(new URL('../src/features/weather/OperationalForecast.module.css', import.meta.url), 'utf8');
const help = readFileSync(new URL('../src/features/help/HelpPage.tsx', import.meta.url), 'utf8');
const operationManual = readFileSync(new URL('../src/features/help/manuals/operationManual.ts', import.meta.url), 'utf8');
const RADAR_TEST_PNG = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
  'base64',
);

test('weather and UAV Forecast are permission-gated operation routes and preload from the menu', () => {
  expect(navigation).toContain("{ to: '/weather', label: 'Weer', icon: CloudRain, ...webRouteAccess.weather }");
  expect(navigation).toContain("{ to: '/uav-forecast', label: 'UAV Forecast', icon: Plane, ...webRouteAccess.uavForecast }");
  expect(navigation).toContain("'/weather': () => import('../features/weather/WeatherPage')");
  expect(navigation).toContain("'/uav-forecast': () => import('../features/weather/UavForecastPage')");

  expect(weatherRoute).toContain('<ProtectedShell {...webRouteAccess.weather}>');
  expect(uavRoute).toContain('<ProtectedShell {...webRouteAccess.uavForecast}>');
});

test('weather opens with its live map while the UAV page keeps the compact standard panel', () => {
  expect(weatherPage).toContain('<h1>Live weerkaart</h1>');
  expect(uavPage).toContain('<Panel title="Vluchtvoorbereiding">');
  expect(weatherPage).toContain('styles.weatherPageHero');
  expect(uavPage).not.toContain('styles.pageHero');
  expect(uavPage).not.toContain('<h1>');
  expect(styles).toContain('.weatherPageHero');
  expect(styles).not.toContain('.pageHero');
  expect(styles).not.toContain('.heroIcon');
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

test('weather uses the fast live-radar endpoint without loading UAV forecast models', () => {
  expect(weatherPage).toContain('useForecastResource<OperationalWeatherRadarPageState>(');
  expect(weatherPage).toContain("'/operational-weather/radar'");
  expect(weatherPage).toContain('normalizeOperationalWeatherRadarPage');
  expect(weatherPage).toContain('markOperationalWeatherRadarPageStale(resource.data)');
  expect(weatherPage).toContain('Live weerkaart');
  expect(weatherPage).toContain('Dit kaartbeeld is geen vliegadvies.');
  expect(weatherPage).not.toContain('cloud_cover_high_pct');
  expect(weatherPage).not.toContain('cloud_base_m');
  expect(weatherPage).not.toContain('radar_peak_mm_h');
  expect(weatherPage).not.toContain('DMI');
  expect(weatherPage).toContain('location={weather.location}');
  expect(weatherPage).toContain('locationControl={locationControl}');
  expect(radarSection).toContain('Buien- en bliksemradar');
  expect(radarSection).toContain("dynamic(() => import('./LiveRadarMap')");
  expect(liveRadarMap).toContain('https://tile.openstreetmap.org/{z}/{x}/{y}.png');
  expect(liveRadarMap).toContain('const REGIONAL_VIEW_BOUNDS: [number, number, number, number] = [1, 49, 10, 55];');
  expect(liveRadarMap).toContain("transformExtent(REGIONAL_VIEW_BOUNDS, 'EPSG:4326', 'EPSG:3857')");
  expect(liveRadarMap).toContain('maxZoom: 11');
  expect(liveRadarMap).toContain('projection: bounds.crs');
  expect(liveRadarMap).toContain('bounds.west,');
  expect(liveRadarMap).not.toContain("projection: 'EPSG:3857',");
  expect(liveRadarMap).toContain('const hasRenderedImageRef = useRef(false);');
  expect(liveRadarMap).toContain('if (!hasRenderedImageRef.current) setImageLoading(true);');
  expect(liveRadarMap).not.toContain('VectorLayer');
  expect(liveRadarMap).not.toContain('markerFeature');
  expect(liveRadarMap).toContain('center: fromLonLat([location.longitude, location.latitude])');
  expect(liveRadarMap).toContain("const OPENSTREETMAP_ATTRIBUTION = 'Kaart: © OpenStreetMap-bijdragers';");
  expect(liveRadarMap).toContain('https://www.openstreetmap.org/copyright');
  expect(styles).toContain(':global(.ol-viewport canvas)');
  expect(radarSection).toContain('RadarLicenseLink');
  expect(radarSection).toContain('source.attribution');
  expect(radarPlayback).not.toContain('Promise.all(renderUrls.map');
  expect(radarPlayback).not.toContain('RADAR_FRAME_START_INTERVAL_MS');
  expect(radarPlayback).not.toContain('waitForRadarFrameStart');
  expect(radarPlayback).toContain('radarBackgroundFrameOrder(');
  expect(radarPlayback).toContain('playableFramePositions');
  expect(radarSection).toContain("autoPlay={readOnly || layer?.status === 'available'}");
  expect(radarPlayback).toContain("image.src = '';");
  expect(radarPlayback).toContain('setImageFrameRenderUrls(renderUrls);');
  expect(radarPlayback).toContain('current.renderKey === requestedRenderKey');
  expect(radarSection).toContain("label: '0–0,2'");
  expect(radarSection).toContain("label: '0,2–0,5'");
  expect(radarSection).toContain("label: '>40'");
  expect(radarSection).toContain('styles.radarToolbar');
  expect(radarSection).toContain('Gemeten');
  expect(radarSection).toContain('Verwachting');
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

test('API types mirror nullable weather fields and live radar rendering', () => {
  expect(apiTypes).toContain("export type OperationalWeatherDataStatus = 'current' | 'partial' | 'unavailable'");
  expect(apiTypes).toContain('export interface OperationalWeatherCloudState');
  expect(apiTypes).toContain('cloud_base_m: number | null;');
  expect(apiTypes).toContain('cloud_base_complete: boolean;');
  expect(apiTypes).toContain('cloud_base_sample_count: number | null;');
  expect(apiTypes).toContain('model_run_at: string | null;');
  expect(apiTypes).toContain('export interface OperationalWeatherPrecipitationState');
  expect(apiTypes).toContain('probability_complete: boolean;');
  expect(apiTypes).toContain('radar_peak_mm_h: number | null;');
  expect(apiTypes).toContain('third_hour_probability_pct: number | null;');
  expect(apiTypes).toContain('radar_status: WallboardForecastStatus;');
  expect(apiTypes).toContain('third_hour_probability_status: WallboardForecastStatus;');
  expect(apiTypes).toContain("export type OperationalWeatherRadarLayerStatus = 'available' | 'stale' | 'unavailable'");
  expect(apiTypes).toContain("export type OperationalWeatherRadarRenderMode = 'atlas' | 'image_frames'");
  expect(apiTypes).toContain("export type OperationalWeatherRadarFramePhase = 'observation' | 'forecast'");
  expect(apiTypes).toContain('image_url?: string | null;');
  expect(apiTypes).toContain('bounds?: OperationalWeatherRadarBounds | null;');
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

test('weather accepts a complete three-hour DMI outlook while ordinary precipitation probability stays unknown', () => {
  const dmiWeather = currentWeather();
  dmiWeather.precipitation = {
    ...(dmiWeather.precipitation as Record<string, unknown>),
    probability_complete: false,
    third_hour_probability_pct: null,
    third_hour_from: null,
    forecast_until: null,
    availability_note: 'DMI DINI SF levert hiervoor geen gewone neerslagkans.',
  };

  const normalized = normalizeOperationalWeatherPage(dmiWeather);

  expect(normalized?.data_status).toBe('current');
  expect(normalized?.aggregation).toMatchObject({ complete: true, fresh: true });
  expect(normalized?.precipitation).toMatchObject({
    complete: true,
    probability_complete: false,
    stale: false,
    radar_peak_mm_h: 0,
    radar_until: '2026-07-21T15:00:00Z',
    third_hour_probability_pct: null,
    third_hour_from: null,
    forecast_until: null,
  });
});

test('weather accepts the nearest DMI model step when it is slightly ahead of refresh time', () => {
  const futureModelStep = currentWeather();
  futureModelStep.cloud = {
    ...(futureModelStep.cloud as Record<string, unknown>),
    valid_at: '2026-07-21T13:00:00Z',
    measured_at: '2026-07-21T13:00:00Z',
    refreshed_at: '2026-07-21T12:40:00Z',
  };
  futureModelStep.precipitation = {
    ...(futureModelStep.precipitation as Record<string, unknown>),
    reference_time: '2026-07-21T13:00:00Z',
    measured_at: '2026-07-21T13:00:00Z',
    refreshed_at: '2026-07-21T12:40:00Z',
    radar_until: '2026-07-21T16:00:00Z',
  };

  const normalized = normalizeOperationalWeatherPage(futureModelStep);

  expect(normalized?.data_status).toBe('current');
  expect(normalized?.cloud).toMatchObject({ complete: true, stale: false });
  expect(normalized?.precipitation).toMatchObject({ complete: true, stale: false });
});

test('a known red DMI precipitation peak stays red when ordinary probability is unavailable', () => {
  const dmiForecast = greenUavForecast();
  dmiForecast.overall_status = 'red';
  dmiForecast.metrics = (dmiForecast.metrics as Array<Record<string, unknown>>).map((metric) => (
    metric.key === 'precipitation_outlook'
      ? {
          ...metric,
          value: 5,
          status: 'red',
          precipitation_outlook: {
            radar_peak_mm_h: 5,
            radar_status: 'red',
            radar_first_precipitation_at: '2026-07-21T12:30:00Z',
            radar_until: '2026-07-21T15:00:00Z',
            third_hour_probability_pct: null,
            third_hour_probability_status: 'unknown',
            third_hour_from: null,
            forecast_until: null,
            reference_time: '2026-07-21T12:00:00Z',
            sample_count: 12,
            expected_sample_count: 12,
            attribution: 'DMI',
          },
        }
      : metric
  ));

  const normalized = normalizeUavForecastPage(dmiForecast);
  const precipitation = normalized?.metrics.find((metric) => metric.key === 'precipitation_outlook');

  expect(precipitation?.status).toBe('red');
  expect(precipitation?.precipitation_outlook?.radar_status).toBe('red');
  expect(precipitation?.precipitation_outlook?.third_hour_probability_status).toBe('unknown');
  expect(normalized?.overall_status).toBe('red');
});

test('radar normalization accepts bounded same-origin atlas and live-frame contracts', () => {
  const weather = currentWeather();
  const normalized = normalizeOperationalWeatherPage(weather);
  expect(normalized?.data_status).toBe('current');
  expect(normalized?.cloud).toMatchObject({
    cloud_base_complete: true,
    cloud_base_sample_count: 12,
    cloud_base_expected_sample_count: 12,
    source: {
      license: 'CC BY 4.0',
      license_url: 'https://www.dmi.dk/friedata/dokumentation/terms-of-use',
      attribution: 'Contains modified DMI data',
      modified: true,
      processed_by: 'DIS',
    },
  });
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

  const liveFrames = currentWeather();
  const liveRadar = liveFrames.radar as Record<string, unknown>;
  liveRadar.precipitation = livePrecipitationLayer();
  const normalizedLive = normalizeOperationalWeatherPage(liveFrames);
  expect(normalizedLive?.radar.precipitation).toMatchObject({
    status: 'available',
    render_mode: 'image_frames',
    atlas_url: null,
    atlas_columns: 0,
    atlas_rows: 0,
    bounds: {
      crs: 'EPSG:4326',
      west: 1,
      south: 49,
      east: 10,
      north: 55,
    },
    source: {
      license_url: 'https://creativecommons.org/licenses/by/4.0/',
    },
  });
  expect(normalizedLive?.radar.precipitation?.frames).toMatchObject([
    { lead_minutes: -5, phase: 'observation' },
    { lead_minutes: 0, phase: 'observation' },
    { lead_minutes: 5, phase: 'forecast' },
  ]);

  const legacyFrameToken = livePrecipitationLayer();
  (legacyFrameToken.frames as Array<Record<string, unknown>>)[0].image_url =
    '/api/operational-weather/radar/precipitation/20260721T115500Z-0123456789abcdef.png';
  expect(normalizeOperationalWeatherRadarState({
    precipitation: legacyFrameToken,
    lightning: null,
  }).precipitation).toMatchObject({ status: 'unavailable', frames: [] });

  const externalFrame = currentWeather();
  const externalFrameRadar = externalFrame.radar as Record<string, unknown>;
  const invalidLiveLayer = livePrecipitationLayer();
  invalidLiveLayer.frames = [
    {
      ...(invalidLiveLayer.frames as Array<Record<string, unknown>>)[0],
      image_url: 'https://radar.example/frame.png',
    },
  ];
  externalFrameRadar.precipitation = invalidLiveLayer;
  expect(normalizeOperationalWeatherPage(externalFrame)?.radar.precipitation).toMatchObject({
    status: 'unavailable',
    frames: [],
  });

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
  expect(styles).toContain('height: 55dvh;');
  expect(styles).toContain('.radarNowSeam');
  expect(styles).toContain('.radarMapControls button:focus-visible');
  expect(styles).toContain('.radarTabs button:focus-visible');
  expect(radarPlayback).toContain("document.addEventListener('visibilitychange', handleVisibility)");
  expect(radarPlayback).toContain("window.matchMedia('(prefers-reduced-motion: reduce)')");
});

test('help index and operation manual explain both operational weather pages', () => {
  expect(help).toContain("id: 'weather'");
  expect(help).toContain("href: '/weather'");
  expect(help).toContain("id: 'uav-forecast'");
  expect(help).toContain("href: '/uav-forecast'");
  expect(operationManual).toContain("id: 'weather-read-live-map'");
  expect(operationManual).toContain("id: 'uav-forecast-assess'");
  expect(operationManual).toContain('De pagina Weer geeft geen vliegadvies.');
  expect(operationManual).toContain('EUMETSAT LI toont total lightning');
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
    if (path !== '/api/operational-weather/radar') return notFoundResponse();
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
  await expect(page.getByRole('heading', { name: 'Buien- en bliksemradar' })).toBeVisible();
  await expect(page.getByRole('button', { name: 'Verversen' })).toBeEnabled();
});

test('weather keeps the validated live map active during refresh and an early retry failure', async ({ page }) => {
  let requestCount = 0;
  let releaseRefresh: (() => void) | null = null;
  const refreshGate = new Promise<void>((resolve) => {
    releaseRefresh = resolve;
  });
  await mockForecastApi(page, 'dark', async (path) => {
    if (path !== '/api/operational-weather/radar') return notFoundResponse();
    requestCount += 1;
    if (requestCount === 2) await refreshGate;
    if (requestCount === 3) return errorResponse(503, 'De weerbron is tijdelijk niet bereikbaar.');
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect(page.getByText('Nieuwe radarframes worden gecontroleerd. Het huidige beeld blijft zichtbaar.')).toBeVisible();
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();
  releaseRefresh?.();
  await expect(page.getByText('Nieuwe radarframes worden gecontroleerd. Het huidige beeld blijft zichtbaar.')).toHaveCount(0);

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect.poll(() => requestCount).toBe(3);
  await expect(page.getByText('Bijwerken is niet gelukt.')).toBeVisible();
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();
  await expect(radar.getByText('Verouderd', { exact: true })).toHaveCount(0);
});

test('weather radar starts at now, exposes explicit controls and switches to total lightning by keyboard', async ({ page }) => {
  await mockForecastApi(page, 'dark', async (path) => {
    if (path !== '/api/operational-weather/radar') return notFoundResponse();
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  await expect(page.getByRole('heading', { name: 'Buien- en bliksemradar' })).toBeVisible();
  const precipitationPanel = page.getByRole('tabpanel');
  await expect(precipitationPanel.getByText('Nu', { exact: true }).first()).toBeVisible();
  await precipitationPanel.getByRole('button', { name: 'Radaranimatie pauzeren' }).click();
  await precipitationPanel.getByRole('slider').fill('0');
  await expect(precipitationPanel.getByRole('slider')).toHaveValue('0');
  await expect(precipitationPanel.getByRole('button', { name: 'Radaranimatie afspelen' })).toBeEnabled();

  await page.getByRole('tab', { name: 'Regen' }).focus();
  await page.getByRole('tab', { name: 'Regen' }).press('ArrowRight');
  await expect(page.getByRole('tab', { name: 'Bliksem' })).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByText('Recente bliksemdetectie voor operationele oriëntatie.')).toBeVisible();
  await precipitationPanel.getByRole('button', { name: 'Radaranimatie pauzeren' }).click();
  await precipitationPanel.getByRole('slider').fill('0');
  await expect(precipitationPanel.getByText('−30 min · gemeten').first()).toBeVisible();
  await expect(precipitationPanel.getByRole('button', { name: 'Naar nu' })).toBeEnabled();
  await precipitationPanel.getByRole('button', { name: 'Naar nu' }).click();
  await expect(precipitationPanel.getByRole('slider')).toHaveValue('6');
});

test('live radar starts progressively while its background queue remains sequential', async ({ page }) => {
  const weather = currentWeather();
  (weather.radar as Record<string, unknown>).precipitation = livePrecipitationLayer();
  let releaseBackground: (() => void) | null = null;
  const backgroundGate = new Promise<void>((resolve) => {
    releaseBackground = resolve;
  });
  const backgroundStarts: number[] = [];
  const backgroundPaths: string[] = [];
  let inFlight = 0;
  let maxInFlight = 0;

  await mockForecastApi(
    page,
    'dark',
    async (path) => path === '/api/operational-weather/radar'
      ? successResponse(weather)
      : notFoundResponse(),
    async (route) => {
      const path = new URL(route.request().url()).pathname;
      const isReferenceFrame = path.includes('fedcba9876543210');
      if (isReferenceFrame) {
        await route.fulfill({ status: 200, contentType: 'image/png', body: RADAR_TEST_PNG });
        return;
      }

      inFlight += 1;
      maxInFlight = Math.max(maxInFlight, inFlight);
      backgroundStarts.push(Date.now());
      backgroundPaths.push(path);
      try {
        if (backgroundStarts.length === 1) await backgroundGate;
        if (path.includes('abcdef0123456789')) {
          await route.fulfill({ status: 503, contentType: 'text/plain', body: 'frame ontbreekt' });
          return;
        }
        await route.fulfill({ status: 200, contentType: 'image/png', body: RADAR_TEST_PNG });
      } finally {
        inFlight -= 1;
      }
    },
  );

  await page.goto('/weather', { waitUntil: 'domcontentloaded' });
  const radar = page.locator('[data-radar-kind="precipitation"]');
  const map = radar.getByRole('application');
  await expect(map).toBeVisible();
  await expect(radar.getByText('Animatie voorbereiden · 1 van 3 beelden')).toBeVisible();
  await expect(radar.getByRole('slider')).toBeDisabled();
  await expect.poll(() => backgroundStarts.length).toBe(1);
  expect(maxInFlight).toBe(1);

  const referenceMapLabel = await map.getAttribute('aria-label');
  releaseBackground?.();

  await expect(radar.getByText('Actueel beeld beschikbaar; animatie is niet compleet.')).toBeVisible();
  await expect(radar.getByRole('button', { name: 'Radaranimatie pauzeren' })).toBeEnabled();
  await expect(map).not.toHaveAttribute('aria-label', referenceMapLabel ?? '', { timeout: 3_000 });
  expect(maxInFlight).toBe(1);
  expect(backgroundStarts.length).toBeGreaterThanOrEqual(2);
  expect(backgroundPaths.some((path) => path.includes('0123456789abcdef'))).toBe(true);
  expect(backgroundPaths.some((path) => path.includes('abcdef0123456789'))).toBe(true);
});

test('live radar keeps the previous map until a refreshed reference succeeds and retries safely', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  let weatherRequests = 0;
  let retryRequests = 0;
  let releaseFailedSeries: (() => void) | null = null;
  const failedSeriesGate = new Promise<void>((resolve) => {
    releaseFailedSeries = resolve;
  });
  const initialWeather = currentWeather();
  (initialWeather.radar as Record<string, unknown>).precipitation = livePrecipitationLayer();
  const refreshedWeather = currentWeather();
  (refreshedWeather.radar as Record<string, unknown>).precipitation = livePrecipitationLayer(
    '2026-07-21T12:05:00Z',
    ['1111111111111111', '2222222222222222', '3333333333333333'],
  );

  await mockForecastApi(
    page,
    'dark',
    async (path) => {
      if (path !== '/api/operational-weather/radar') return notFoundResponse();
      weatherRequests += 1;
      return successResponse(weatherRequests === 1 ? initialWeather : refreshedWeather);
    },
    async (route) => {
      const url = new URL(route.request().url());
      const isRefreshedSeries = /-(?:1111111111111111|2222222222222222|3333333333333333)\.png$/.test(url.pathname);
      const isRefreshedReference = url.pathname.includes('2222222222222222');
      if (isRefreshedReference && !url.searchParams.has('retry')) {
        await failedSeriesGate;
        await route.fulfill({ status: 503, contentType: 'text/plain', body: 'tijdelijk niet beschikbaar' });
        return;
      }
      if (isRefreshedSeries && url.searchParams.get('retry') === '1') retryRequests += 1;
      await route.fulfill({ status: 200, contentType: 'image/png', body: RADAR_TEST_PNG });
    },
  );

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  const map = radar.getByRole('application');
  const attribution = radar.getByRole('link', { name: 'Kaart: © OpenStreetMap-bijdragers' });
  const legend = radar.locator('[data-radar-legend]');
  const status = radar.locator('[data-radar-status]');
  const layers = radar.locator('[data-radar-layers]');
  await expect(map).toBeVisible();
  await expectLocatorsNotToOverlap(status, legend);
  await expectLocatorsNotToOverlap(layers, attribution);
  await expectLocatorsNotToOverlap(legend, attribution);
  await radar.getByRole('button', { name: 'Radaranimatie pauzeren' }).click();
  const previousMapLabel = await map.getAttribute('aria-label');
  expect(previousMapLabel).not.toBeNull();

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect(radar.getByText('Nieuwe beeldreeks laden')).toHaveCount(0);
  await expect(radar.locator('[data-radar-overlay]')).toHaveCount(0);
  await expect(radar.getByText('Radarbeeld laden')).toHaveCount(0);
  await expectLocatorsNotToOverlap(status, legend);
  await expect(map).toBeVisible();

  releaseFailedSeries?.();
  await expect(radar.getByText('Nieuwe beeldreeks niet geladen')).toBeVisible();
  await expectLocatorsNotToOverlap(radar.locator('[data-radar-overlay]'), attribution);
  await expect(radar.getByText('Het vorige geldige beeld blijft beschikbaar.')).toBeVisible();
  await expect(map).toBeVisible();
  await expect(radar.getByText('Radarbeeld laden')).toHaveCount(0);

  await radar.getByRole('button', { name: 'Opnieuw laden' }).click();
  await expect.poll(() => retryRequests).toBeGreaterThanOrEqual(1);
  await expect(map).toBeVisible();
  await expect(radar.getByText('Nieuwe beeldreeks niet geladen')).toHaveCount(0);
});

test('live radar keeps its location controls, time and OpenStreetMap attribution separate at 1280px', async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 900 });
  const weather = currentWeather();
  (weather.radar as Record<string, unknown>).precipitation = livePrecipitationLayer();
  await mockForecastApi(page, 'dark', async (path) => path === '/api/operational-weather/radar'
    ? successResponse(weather)
    : notFoundResponse());

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  const locationForm = radar.getByRole('form', { name: 'Forecastgebied kiezen' });
  const moment = radar.locator('[data-radar-map-moment]');
  const legend = radar.locator('[data-radar-legend]');
  const attribution = radar.getByRole('link', { name: 'Kaart: © OpenStreetMap-bijdragers' });
  await expect(attribution).toBeVisible();
  await expect(attribution).toHaveAttribute(
    'href',
    'https://www.openstreetmap.org/copyright',
  );

  const rectangles = await Promise.all([
    locationForm.boundingBox(),
    moment.boundingBox(),
    legend.boundingBox(),
    attribution.boundingBox(),
  ]);
  const [locationRect, momentRect, legendRect, attributionRect] = rectangles;
  expect(locationRect).not.toBeNull();
  expect(momentRect).not.toBeNull();
  expect(legendRect).not.toBeNull();
  expect(attributionRect).not.toBeNull();
  if (locationRect === null || momentRect === null || legendRect === null || attributionRect === null) {
    throw new Error('Radar-overlay kon niet worden gemeten.');
  }
  expect(locationRect.y + locationRect.height).toBeLessThanOrEqual(momentRect.y);
  expect(rectanglesOverlap(legendRect, attributionRect)).toBe(false);
});

test('weather radar keeps its loading state honest until the atlas has decoded', async ({ page }) => {
  let releaseAtlas: (() => void) | null = null;
  const atlasGate = new Promise<void>((resolve) => {
    releaseAtlas = resolve;
  });
  await mockForecastApi(
    page,
    'dark',
    async (path) => path === '/api/operational-weather/radar'
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
  await expect(radar.getByRole('img', { name: /KNMI RTCOR \+ radar forecast 2\.0 · neerslagradar/ })).toBeVisible();
  await expect(radar.getByText('Actueel', { exact: true })).toBeVisible();
});

test('weather radar never brings the loader back between decoded animation frames', async ({ page }) => {
  const weather = currentWeather();
  (weather.radar as Record<string, unknown>).precipitation = livePrecipitationLayer();
  await mockForecastApi(page, 'dark', async (path) => path === '/api/operational-weather/radar'
    ? successResponse(weather)
    : notFoundResponse());

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  const map = radar.getByRole('application');
  await expect(map).toBeVisible();
  await expect(radar.getByText('Radarbeeld laden')).toHaveCount(0);
  const initialLabel = await map.getAttribute('aria-label');

  await page.evaluate(() => {
    const radarElement = document.querySelector('[data-radar-kind="precipitation"]');
    const observedWindow = window as typeof window & { __radarLoaderReappeared?: boolean };
    observedWindow.__radarLoaderReappeared = false;
    if (radarElement === null) return;
    new MutationObserver(() => {
      if (radarElement.textContent?.includes('Radarbeeld laden') === true) {
        observedWindow.__radarLoaderReappeared = true;
      }
    }).observe(radarElement, { childList: true, subtree: true });
  });

  await expect.poll(() => map.getAttribute('aria-label')).not.toBe(initialLabel);
  expect(await page.evaluate(() => (
    window as typeof window & { __radarLoaderReappeared?: boolean }
  ).__radarLoaderReappeared)).toBe(false);
});

test('weather radar retries a failed atlas without discarding the page data', async ({ page }) => {
  let atlasRequests = 0;
  await mockForecastApi(
    page,
    'light',
    async (path) => path === '/api/operational-weather/radar'
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
  await expect(radar.getByRole('img', { name: /KNMI RTCOR \+ radar forecast 2\.0 · neerslagradar/ })).toBeVisible();
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

  await mockForecastApi(page, 'dark', async (path) => path === '/api/operational-weather/radar'
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

test('automatic radar playback stops when a refresh marks the live series stale', async ({ page }) => {
  let requestCount = 0;
  const liveWeather = currentWeather();
  const staleWeather = currentWeather();
  staleWeather.data_status = 'partial';
  const staleRadar = staleWeather.radar as Record<string, unknown>;
  staleRadar.precipitation = {
    ...(staleRadar.precipitation as Record<string, unknown>),
    status: 'stale',
    age_seconds: 3_600,
    lag_seconds: 420,
    availability_note: 'De laatst gevalideerde KNMI-reeks is ouder dan toegestaan.',
  };

  await mockForecastApi(page, 'dark', async (path) => {
    if (path !== '/api/operational-weather/radar') return notFoundResponse();
    requestCount += 1;
    return successResponse(requestCount === 1 ? liveWeather : staleWeather);
  });

  await page.goto('/weather');
  const radar = page.locator('[data-radar-kind="precipitation"]');
  await expect(radar.getByRole('button', { name: 'Radaranimatie pauzeren' })).toBeEnabled();

  await page.getByRole('button', { name: 'Verversen' }).click();
  await expect(radar.getByText('Verouderd', { exact: true })).toBeVisible();
  await expect(radar.getByRole('button', { name: 'Radaranimatie afspelen' })).toBeEnabled();
});

test('reduced motion keeps radar animation off while manual time steps remain available', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await mockForecastApi(page, 'light', async (path) => {
    if (path !== '/api/operational-weather/radar') return notFoundResponse();
    return successResponse(currentWeather());
  });

  await page.goto('/weather');
  const radarPanel = page.getByRole('tabpanel');
  await expect(radarPanel.getByText('Automatisch afspelen is uitgeschakeld vanwege de instelling voor minder beweging.')).toBeVisible();
  await expect(radarPanel.getByRole('button', { name: 'Radaranimatie afspelen' })).toBeDisabled();
  await expect(radarPanel.getByRole('button', { name: 'Volgende' })).toBeEnabled();
});

for (const scenario of [
  { path: '/weather', theme: 'light', heading: 'Buien- en bliksemradar' },
  { path: '/uav-forecast', theme: 'dark', heading: 'Advies onvolledig' },
] as const) {
  test(`${scenario.path} renders without horizontal overflow at 375px in ${scenario.theme} mode`, async ({ page }) => {
    const staleUav = greenUavForecast();
    const metrics = staleUav.metrics as Array<Record<string, unknown>>;
    staleUav.metrics = metrics.map((metric) => metric.key === 'wind_speed_kmh'
      ? { ...metric, status: 'green', stale: true }
      : metric);

    await mockForecastApi(page, scenario.theme, async (path) => {
      if (path === '/api/operational-weather/radar') return successResponse(currentWeather());
      if (path === '/api/uav-forecast') return successResponse(staleUav);
      return notFoundResponse();
    });
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto(scenario.path);

    await expect(page.getByRole('heading', { name: scenario.heading })).toBeVisible();
    await expect(page.getByRole('heading', {
      exact: true,
      level: 1,
      name: scenario.path === '/weather' ? 'Weer' : 'UAV Forecast',
    })).toHaveCount(1);
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
      await expect(page.getByText('Berekende GNSS-satellieten boven horizon', { exact: true })).toBeVisible();
      await expect(page.getByText('Berekende GNSS-satellieten boven 10°', { exact: true })).toBeVisible();
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
    const radarMatch = /^\/api\/operational-weather\/radar\/(precipitation|lightning)\/\d{8}T\d{6}Z-(?:o|f\d{8}T\d{6}Z)-[a-f0-9]{16}\.png$/.exec(path);
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
    roles: [{
      id: 'forecast-web-role',
      name: 'forecast-web-role',
      display_name: 'Forecast webrol',
      can_use_operator_app: false,
      can_use_admin_app: true,
      permissions: [
        { id: 'weather-view', name: 'operational-weather.view', category: 'weather_configuration', display_name: 'Weer bekijken' },
        { id: 'uav-view', name: 'uav-forecast.view', category: 'weather_configuration', display_name: 'UAV Forecast bekijken' },
      ],
    }],
  };
}

function currentWeather(): Record<string, unknown> {
  const source = {
    name: 'DMI HARMONIE DINI',
    url: 'https://www.dmi.dk/friedata/dokumentation/forecast-data-edr-api',
    license: 'CC BY 4.0',
    license_url: 'https://www.dmi.dk/friedata/dokumentation/terms-of-use',
    attribution: 'Contains modified DMI data',
    modified: true,
    processed_by: 'DIS',
  };
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
      cloud_base_complete: true,
      cloud_base_sample_count: 12,
      cloud_base_expected_sample_count: 12,
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
      probability_complete: false,
      stale: false,
      radar_peak_mm_h: 0,
      radar_first_precipitation_at: null,
      radar_until: '2026-07-21T15:00:00Z',
      third_hour_probability_pct: null,
      third_hour_from: null,
      forecast_until: null,
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
        atlas_url: '/api/operational-weather/radar/precipitation/20260721T120000Z-o-0123456789abcdef.png',
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
          name: 'KNMI RTCOR + radar forecast 2.0',
          url: 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
          license: 'CC BY 4.0',
          license_url: 'https://creativecommons.org/licenses/by/4.0/',
          attribution: 'KNMI nl_rdr_data_rtcor_5m en radar_forecast_2.0',
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
        atlas_url: '/api/operational-weather/radar/lightning/20260721T120000Z-o-fedcba9876543210.png',
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
          license: 'CC BY 4.0',
          license_url: 'https://user.eumetsat.int/resources/user-guides/data-registration-and-licensing',
          attribution: 'Contains modified EUMETSAT Meteosat data 2026',
          modified: true,
          processed_by: 'DIS',
        },
        availability_note: null,
      },
    },
    scope_note: 'Live KNMI-radar, EUMETSAT-bliksemdetectie en DMI-modelwaarden.',
    disclaimer: 'Dit weerbeeld is geen vliegadvies.',
  };
}

function livePrecipitationLayer(
  referenceTime = '2026-07-21T12:00:00Z',
  hashes = ['0123456789abcdef', 'fedcba9876543210', 'abcdef0123456789'],
): Record<string, unknown> {
  const reference = Date.parse(referenceTime);
  const referenceToken = radarTokenTimestamp(reference);
  return {
    status: 'available',
    render_mode: 'image_frames',
    bounds: {
      crs: 'EPSG:4326',
      west: 1,
      south: 49,
      east: 10,
      north: 55,
    },
    reference_time: referenceTime,
    observed_period_end: null,
    age_seconds: 90,
    lag_seconds: 20,
    refreshed_at: new Date(reference + 60_000).toISOString(),
    atlas_url: null,
    atlas_columns: 0,
    atlas_rows: 0,
    frame_width: 960,
    frame_height: 720,
    frames: [-5, 0, 5].map((leadMinutes, index) => {
      const validAt = reference + leadMinutes * 60_000;
      const token = leadMinutes <= 0
        ? `${radarTokenTimestamp(validAt)}-o-${hashes[index]}`
        : `${radarTokenTimestamp(validAt)}-f${referenceToken}-${hashes[index]}`;
      return {
        index,
        valid_at: new Date(validAt).toISOString(),
        lead_minutes: leadMinutes,
        phase: leadMinutes <= 0 ? 'observation' : 'forecast',
        image_url: `/api/operational-weather/radar/precipitation/${token}.png`,
      };
    }),
    source: {
      name: 'KNMI RTCOR + radar forecast 2.0',
      url: 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
      license: 'CC BY 4.0',
      license_url: 'https://creativecommons.org/licenses/by/4.0/',
      attribution: 'KNMI nl_rdr_data_rtcor_5m en radar_forecast_2.0',
    },
    availability_note: null,
  };
}

function radarTokenTimestamp(timestamp: number): string {
  return new Date(timestamp).toISOString().replaceAll('-', '').replaceAll(':', '').replace('.000', '');
}

function rectanglesOverlap(
  left: { x: number; y: number; width: number; height: number },
  right: { x: number; y: number; width: number; height: number },
): boolean {
  return left.x < right.x + right.width
    && left.x + left.width > right.x
    && left.y < right.y + right.height
    && left.y + left.height > right.y;
}

async function expectLocatorsNotToOverlap(left: Locator, right: Locator): Promise<void> {
  const [leftRect, rightRect] = await Promise.all([left.boundingBox(), right.boundingBox()]);
  expect(leftRect).not.toBeNull();
  expect(rightRect).not.toBeNull();
  if (leftRect === null || rightRect === null) throw new Error('Radar-overlay kon niet worden gemeten.');
  expect(rectanglesOverlap(leftRect, rightRect)).toBe(false);
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
      metric('gnss_satellites', 'Berekende GNSS-satellieten boven horizon', 22, {
        unit: 'satellieten',
        source_height_label: 'GPS 12 · Galileo 10 · open-skyberekening',
      }),
      metric('gnss_satellites_fix', 'Berekende GNSS-satellieten boven 10°', 17, {
        unit: 'satellieten',
        source_height_label: 'GPS 10 · Galileo 7 · PDOP 1,37 · elevatiemasker 10°',
      }),
    ],
    scope_note: 'Landelijk overzicht op basis van twaalf provinciepunten.',
    disclaimer: 'Operationele en wettelijke limieten gaan altijd voor.',
  };
}
