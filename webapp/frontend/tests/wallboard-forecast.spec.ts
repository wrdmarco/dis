import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type {
  WallboardForecastMetric,
  WallboardForecastMetricKey,
  WallboardForecastStatus,
} from '../src/types/api';
import {
  normalizeWallboardForecastState,
} from '../src/features/wallboards/WallboardDisplayPage';
import {
  forecastTimeRange,
  formatForecastMetricValue,
  formatWallboardForecastUpdateTime,
  wallboardForecastAllDisplayBlocks,
  wallboardForecastDisplayBlocks,
} from '../src/features/weather/forecastPresentation';
import {
  markWallboardForecastStale,
  normalizeUavForecastPage,
} from '../src/features/weather/forecastNormalization';
import {
  DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  WALLBOARD_FORECAST_BLOCK_KEYS,
} from '../src/features/wallboards/wallboardPresentation';

const source = { name: 'Gecontroleerde bron', url: 'https://example.test/source' };

function metric(
  key: WallboardForecastMetricKey,
  status: WallboardForecastStatus,
  value: number | null,
  overrides: Partial<WallboardForecastMetric> = {},
) {
  return {
    key,
    label: key,
    value,
    unit: 'eenheid',
    display_value: null,
    display_unit: null,
    status,
    stale: false,
    source,
    measured_at: value === null ? null : '2026-07-20T12:10:00Z',
    explanation: 'Centrale drempel.',
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
  };
}

function backendForecast(overrides: Record<string, unknown> = {}) {
  return {
    location: {
      mode: 'netherlands',
      label: 'UAV Nederland',
      latitude: 52.2,
      longitude: 5.3,
    },
    aggregation: {
      type: 'province_average',
      sample_count: 12,
      expected_sample_count: 12,
      complete: true,
      fresh: true,
    },
    visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
    overall_status: 'red',
    generated_at: '2026-07-20T12:15:00Z',
    condition: {
      code: 95,
      label: 'Onweer',
      status: 'red',
      stale: false,
      source,
      measured_at: '2026-07-20T12:10:00Z',
    },
    daylight: {
      timezone: 'Europe/Amsterdam',
      sunrise_earliest: '2026-07-20T05:45:00+02:00',
      sunrise_latest: '2026-07-20T06:05:00+02:00',
      sunset_earliest: '2026-07-20T21:40:00+02:00',
      sunset_latest: '2026-07-20T22:00:00+02:00',
      stale: false,
      source,
    },
    wind_profile: {
      samples: [
        { height_agl_m: 10, speed_kmh: 10 },
        { height_agl_m: 80, speed_kmh: 25 },
        { height_agl_m: 120, speed_kmh: 35 },
      ],
      max_non_red_wind_height_agl_m: 80,
      stale: false,
    },
    metrics: [
      metric('weather_code', 'red', 95, { unit: 'WMO' }),
      metric('temperature_c', 'green', 18, { unit: '°C' }),
      metric('dew_point_c', 'green', 12, { unit: '°C' }),
      metric('wind_speed_kmh', 'green', 35, {
        unit: 'km/u',
        altitude_m: 120,
        source_height_label: '120 m boven maaiveld',
        height_samples_agl_m: [
          { height_agl_m: 10, speed_kmh: 10 },
          { height_agl_m: 80, speed_kmh: 25 },
          { height_agl_m: 120, speed_kmh: 35 },
        ],
        max_non_red_wind_height_agl_m: 80,
      }),
      metric('wind_gust_kmh', 'green', 20, { unit: 'km/u', altitude_m: 10 }),
      metric('wind_direction_degrees', 'green', 225, { unit: '°', altitude_m: 120 }),
      metric('precipitation_probability_pct', 'green', 10, { unit: '%' }),
      metric('precipitation_mm', 'green', 0, { unit: 'mm' }),
      metric('precipitation_outlook', 'orange', 0.8, {
        label: 'Buien +3 uur',
        unit: 'mm/u',
        precipitation_outlook: {
          radar_peak_mm_h: 0.8,
          radar_status: 'orange',
          radar_first_precipitation_at: '2026-07-20T12:45:00Z',
          radar_until: '2026-07-20T14:15:00Z',
          third_hour_probability_pct: 42,
          third_hour_probability_status: 'orange',
          third_hour_from: '2026-07-20T14:20:00Z',
          forecast_until: '2026-07-20T15:15:00Z',
          reference_time: '2026-07-20T12:15:00Z',
          sample_count: 12,
          expected_sample_count: 12,
          attribution: 'KNMI',
        },
      }),
      metric('thunderstorm_forecast', 'green', 0, {
        label: 'Onweer +3 uur',
        unit: null,
        thunderstorm_outlook: {
          expected: false,
          first_expected_at: null,
          forecast_until: '2026-07-20T15:15:00Z',
          sample_count: 12,
          expected_sample_count: 12,
          attribution: 'OPEN_METEO',
        },
      }),
      metric('cloud_cover_pct', 'red', 100, {
        label: 'Totale modelbewolking',
        unit: '%',
        source_height_label: 'Totale hemelkolom; geen vaste meethoogte',
      }),
      metric('low_cloud_cover_pct', 'green', 20, {
        label: 'Lage bewolking',
        unit: '%',
        source_height_label: 'KNMI HARMONIE-categorie lage bewolking; KNMI publiceert hiervoor geen vaste hoogteband',
        cloud_layers: { low_pct: 20, mid_pct: 40, high_pct: 60, total_pct: 100 },
        cloud_base_forecast: {
          status: 'forecast',
          base_height_m: 850,
          height_reference: 'model_unspecified',
          aggregation: 'minimum_of_province_samples',
          sample_count: 12,
          model_run_at: '2026-07-20T09:00:00Z',
          valid_at: '2026-07-20T12:00:00Z',
          attribution: 'KNMI_HARMONIE',
        },
        cloud_base_observation: {
          status: 'measured',
          base_height_m: 640,
          height_reference: 'mean_sea_level',
          layers: [{ height_m: 640, cover_okta: 6 }],
          station: { id: '0-20000-0-06260', name: 'De Bilt', distance_km: 5.2 },
          observed_at: '2026-07-20T12:10:00Z',
          period_minutes: 30,
          attribution: 'KNMI',
        },
      }),
      metric('visibility_m', 'green', 12_000, {
        unit: 'm',
        display_value: '12.00',
        display_unit: 'km',
      }),
      metric('kp_index', 'green', 2, { unit: 'Kp' }),
      metric('gnss_satellites', 'unknown', null, { unit: null }),
      metric('gnss_satellites_fix', 'unknown', null, { unit: null }),
    ],
    scope_note: 'Rekenkundig gemiddelde van exact alle 12 Nederlandse provincies.',
    disclaimer: 'Operationele limieten gaan voor.',
    ...overrides,
  };
}

function greenBackendForecast() {
  const payload = backendForecast({ overall_status: 'green' });
  payload.condition = { ...payload.condition, status: 'green' };
  payload.metrics = payload.metrics.map((candidate) => ({
    ...candidate,
    status: 'green',
    value: candidate.value ?? (candidate.key === 'gnss_satellites_fix' ? 8 : 12),
    measured_at: '2026-07-20T12:10:00Z',
  }));
  return payload;
}

test('downgrades contradictory or incomplete green advice to unknown', () => {
  const orangePayload = greenBackendForecast();
  orangePayload.metrics = orangePayload.metrics.map((candidate) => candidate.key === 'wind_gust_kmh'
    ? { ...candidate, status: 'orange' }
    : candidate);

  expect(normalizeUavForecastPage(orangePayload)?.overall_status).toBe('unknown');

  const missingProvenance = greenBackendForecast();
  missingProvenance.metrics = missingProvenance.metrics.map((candidate) => candidate.key === 'visibility_m'
    ? { ...candidate, measured_at: null }
    : candidate);
  const normalized = normalizeUavForecastPage(missingProvenance);

  expect(normalized?.overall_status).toBe('unknown');
  expect(normalized?.metrics.find((candidate) => candidate.key === 'visibility_m')?.status).toBe('unknown');
});

test('enforces the canonical location aggregation before allowing green advice', () => {
  const payload = greenBackendForecast();
  payload.aggregation = {
    type: 'single_location',
    sample_count: 1,
    expected_sample_count: 1,
    complete: true,
    fresh: true,
  };

  const forecast = normalizeUavForecastPage(payload);
  expect(forecast?.aggregation).toMatchObject({
    type: 'province_average',
    expected_sample_count: 12,
    complete: false,
    fresh: false,
  });
  expect(forecast?.overall_status).toBe('unknown');

  const invalidLocation = greenBackendForecast();
  invalidLocation.location = { ...invalidLocation.location, mode: 'province' };
  expect(normalizeUavForecastPage(invalidLocation)?.overall_status).toBe('unknown');

  const missingCoordinate = greenBackendForecast();
  missingCoordinate.location = { ...missingCoordinate.location, latitude: null };
  expect(normalizeUavForecastPage(missingCoordinate)?.overall_status).toBe('unknown');
});

test('marks expired data fail-closed and shows every advice input on the standalone page', () => {
  const payload = greenBackendForecast();
  payload.visible_blocks = ['weather'];
  const forecast = normalizeUavForecastPage(payload);
  expect(forecast).not.toBeNull();
  if (forecast === null) return;

  const expired = markWallboardForecastStale(forecast);
  expect(expired.overall_status).toBe('unknown');
  expect(expired.metrics.every((metric) => metric.status === 'unknown' && metric.stale)).toBe(true);
  expect(wallboardForecastDisplayBlocks(forecast)).toHaveLength(1);
  expect(wallboardForecastAllDisplayBlocks(forecast).map((block) => block.key))
    .toEqual(WALLBOARD_FORECAST_BLOCK_KEYS);
});

test('preserves the authoritative backend advice and complete expanded contract', () => {
  const state = normalizeWallboardForecastState({ pages: { forecast: backendForecast() } });
  const forecast = state.pages.forecast;

  expect(forecast.overall_status).toBe('red');
  expect(forecast.metrics).toHaveLength(16);
  expect(forecast.condition).toMatchObject({ code: 95, label: 'Onweer', status: 'red' });
  expect(forecast.aggregation).toMatchObject({ sample_count: 12, expected_sample_count: 12, complete: true });
  expect(wallboardForecastDisplayBlocks(forecast).map((block) => block.key))
    .toEqual(DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS);
});

test('keeps radar intensity and the third-hour probability visibly separate', () => {
  const forecast = normalizeWallboardForecastState({ pages: { forecast: backendForecast() } }).pages.forecast;
  const blocks = wallboardForecastDisplayBlocks(forecast);

  expect(blocks.find((block) => block.key === 'precipitation_outlook')).toMatchObject({
    label: 'Buien +3 uur',
    value: 'Bui vanaf 14:45',
    status: 'orange',
    details: [
      '0–2 uur radar: piek 0,8 mm/u',
      'Uur 3 kansmodel: 42% kans op ≥ 0,1 mm/u',
    ],
  });
  expect(blocks.find((block) => block.key === 'thunderstorm_forecast')).toMatchObject({
    label: 'Onweer +3 uur',
    value: 'Niet verwacht',
    status: 'green',
    details: [
      'WMO-modelverwachting t/m 17:15',
      'Geen live bliksemdetectie',
    ],
  });
});

test('keeps radar-only precipitation visible but never presents the missing third hour as green', () => {
  const payload = backendForecast();
  const precipitation = payload.metrics.find((candidate) => candidate.key === 'precipitation_outlook');
  if (precipitation?.precipitation_outlook === null || precipitation?.precipitation_outlook === undefined) {
    throw new Error('Missing precipitation outlook fixture.');
  }
  precipitation.status = 'unknown';
  precipitation.value = 0;
  precipitation.precipitation_outlook = {
    ...precipitation.precipitation_outlook,
    radar_peak_mm_h: 0,
    radar_status: 'green',
    radar_first_precipitation_at: null,
    third_hour_probability_pct: null,
    third_hour_probability_status: 'unknown',
    third_hour_from: null,
    forecast_until: null,
  };

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const metricState = forecast.metrics.find((candidate) => candidate.key === 'precipitation_outlook');
  const block = wallboardForecastDisplayBlocks(forecast)
    .find((candidate) => candidate.key === 'precipitation_outlook');

  expect(metricState).toMatchObject({
    status: 'unknown',
    value: 0,
    precipitation_outlook: {
      radar_peak_mm_h: 0,
      radar_status: 'green',
      third_hour_probability_pct: null,
      third_hour_probability_status: 'unknown',
    },
  });
  expect(block).toMatchObject({
    status: 'unknown',
    value: 'Droog tot +2 uur',
    details: [
      '0–2 uur radar: piek 0 mm/u',
      'Uur 3 kansmodel: niet beschikbaar',
    ],
  });
});

test('never presents stale precipitation or thunderstorm outlooks as definitive', () => {
  const payload = backendForecast();
  payload.metrics = payload.metrics.map((candidate) => (
    ['precipitation_outlook', 'thunderstorm_forecast'].includes(candidate.key)
      ? { ...candidate, status: 'unknown' as const, stale: true }
      : candidate
  ));

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const blocks = wallboardForecastDisplayBlocks(forecast);

  expect(blocks.find((block) => block.key === 'precipitation_outlook')).toMatchObject({
    value: 'Verouderd',
    status: 'unknown',
  });
  expect(blocks.find((block) => block.key === 'thunderstorm_forecast')).toMatchObject({
    value: 'Verouderd',
    status: 'unknown',
  });
});

test('downgrades malformed structured outlooks instead of showing green numeric fallbacks', () => {
  const payload = backendForecast();
  const precipitation = payload.metrics.find((candidate) => candidate.key === 'precipitation_outlook');
  const thunder = payload.metrics.find((candidate) => candidate.key === 'thunderstorm_forecast');
  if (precipitation === undefined || thunder === undefined) throw new Error('Missing outlook fixtures.');
  precipitation.precipitation_outlook = null;
  precipitation.status = 'green';
  thunder.thunderstorm_outlook = null;
  thunder.status = 'green';

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const blocks = wallboardForecastDisplayBlocks(forecast);

  expect(forecast.metrics.find((candidate) => candidate.key === 'precipitation_outlook'))
    .toMatchObject({ value: null, status: 'unknown' });
  expect(forecast.metrics.find((candidate) => candidate.key === 'thunderstorm_forecast'))
    .toMatchObject({ value: null, status: 'unknown' });
  expect(blocks.find((block) => block.key === 'precipitation_outlook')?.value).toBe('Onbekend');
  expect(blocks.find((block) => block.key === 'thunderstorm_forecast')?.value).toBe('Onbekend');
});

test('shows low cloud cover as the operational card while retaining total and higher model layers', () => {
  const forecast = normalizeWallboardForecastState({ pages: { forecast: backendForecast() } }).pages.forecast;
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(cloud).toMatchObject({ label: 'Lage bewolking', value: '20 %', status: 'green' });
  expect(cloud?.details).toEqual([
    'Modelverwachting wolkenbasis: 850 m (hoogtereferentie niet gespecificeerd)',
    'Geldig 14:00; modelrun 11:00; minimum van 12 provinciepunten',
    'Gemeten wolkenbasis: 640 m boven zeeniveau (6/8 bewolkt) (laagste in 30 min)',
    'Meetstation De Bilt (5,2 km), 14:10; kaartkleur volgt model',
    'Modelbewolking: laag 20%; middelbaar 40%; hoog 60%; totaal 100%',
  ]);
});

test('keeps the total-cloud fallback when an older backend has no low-cloud metric yet', () => {
  const payload = backendForecast();
  payload.metrics = payload.metrics.filter((candidate) => candidate.key !== 'low_cloud_cover_pct');

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(forecast.metrics).toHaveLength(15);
  expect(cloud).toMatchObject({ label: 'Totale modelbewolking', value: '100 %', status: 'red' });
  expect(cloud?.details).toEqual(['Totale hemelkolom; geen vaste meethoogte']);
});

test('keeps an invalid KNMI station observation fail-closed', () => {
  const payload = backendForecast();
  const lowCloud = payload.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  if (lowCloud === undefined || lowCloud.cloud_base_observation === null) throw new Error('Missing cloud fixture.');
  lowCloud.cloud_base_observation.station = { ...lowCloud.cloud_base_observation.station!, distance_km: -1 };

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const normalizedLowCloud = forecast.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(normalizedLowCloud?.cloud_base_observation).toBeNull();
  expect(cloud?.details).toContain('Gemeten wolkenbasis niet beschikbaar');
});

test('shows an explicit no-cloud detection without overriding the authoritative model status', () => {
  const payload = backendForecast();
  const lowCloud = payload.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  if (lowCloud === undefined) throw new Error('Missing cloud fixture.');
  lowCloud.status = 'red';
  lowCloud.value = 90;
  lowCloud.cloud_layers = { low_pct: 90, mid_pct: 40, high_pct: 60, total_pct: 100 };
  lowCloud.cloud_base_observation = {
    status: 'no_cloud_detected',
    base_height_m: null,
    height_reference: 'mean_sea_level',
    layers: [],
    station: { id: '0-20000-0-06260', name: 'De Bilt', distance_km: 5.2 },
    observed_at: '2026-07-20T12:10:00Z',
    period_minutes: 30,
    attribution: 'KNMI',
  };

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(cloud).toMatchObject({ status: 'red', value: '90 %' });
  expect(cloud?.details).toContain('Gemeten: in 30 min geen wolkenbasis gedetecteerd');
});

test('labels fictitious cloud-base observations as demo data without a KNMI claim', () => {
  const payload = backendForecast();
  const lowCloud = payload.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  if (lowCloud === undefined || lowCloud.cloud_base_observation === null) throw new Error('Missing cloud fixture.');
  if (lowCloud.cloud_base_forecast === null) throw new Error('Missing model fixture.');
  lowCloud.cloud_base_forecast.attribution = 'DIS_DEMO';
  lowCloud.cloud_base_observation.attribution = 'DIS_DEMO';

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(cloud?.details).toEqual([
    'Demo-modelverwachting wolkenbasis: 850 m (hoogtereferentie niet gespecificeerd)',
    'Geldig 14:00; modelrun 11:00; minimum van 12 provinciepunten',
    'Demo-meting wolkenbasis: 640 m boven zeeniveau (6/8 bewolkt) (laagste in 30 min)',
    'Demo-meetpunt De Bilt (5,2 km), 14:10; kaartkleur volgt model',
    'Modelbewolking: laag 20%; middelbaar 40%; hoog 60%; totaal 100%',
  ]);
  expect(cloud?.details.join(' ')).not.toContain('KNMI');
});

test('keeps an invalid model cloud base fail-closed while retaining measured station data', () => {
  const payload = backendForecast();
  const lowCloud = payload.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  if (lowCloud === undefined || lowCloud.cloud_base_forecast === null) throw new Error('Missing model fixture.');
  lowCloud.cloud_base_forecast.base_height_m = -50;

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const normalizedLowCloud = forecast.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(normalizedLowCloud?.cloud_base_forecast).toBeNull();
  expect(cloud?.details[0]).toBe('Modelverwachting wolkenbasis niet beschikbaar');
  expect(cloud?.details).toContain('Gemeten wolkenbasis: 640 m boven zeeniveau (6/8 bewolkt) (laagste in 30 min)');
});

test('shows when the model has no cloud base for the selected forecast hour', () => {
  const payload = backendForecast();
  const lowCloud = payload.metrics.find((candidate) => candidate.key === 'low_cloud_cover_pct');
  if (lowCloud === undefined || lowCloud.cloud_base_forecast === null) throw new Error('Missing model fixture.');
  lowCloud.cloud_base_forecast.status = 'not_calculated';
  lowCloud.cloud_base_forecast.base_height_m = null;
  lowCloud.cloud_base_forecast.sample_count = 0;

  const forecast = normalizeWallboardForecastState({ pages: { forecast: payload } }).pages.forecast;
  const cloud = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'cloud_cover');

  expect(cloud?.details[0]).toBe('Modelverwachting: geen wolkenbasis berekend voor dit forecastuur');
  expect(cloud?.details[1]).toBe('Geldig 14:00; modelrun 11:00');
});

test('uses server visibility formatting and exposes the AGL wind profile', () => {
  const forecast = normalizeWallboardForecastState({ pages: { forecast: backendForecast() } }).pages.forecast;
  const visibility = forecast.metrics.find((candidate) => candidate.key === 'visibility_m');
  const wind = wallboardForecastDisplayBlocks(forecast).find((block) => block.key === 'wind_speed');

  expect(formatForecastMetricValue(visibility)).toBe('12.00 km');
  expect(wind?.details).toContain('10 m: 10 km/u · 80 m: 25 km/u · 120 m: 35 km/u');
  expect(wind?.details).toContain('Niet-rood t/m 80 m AGL');
});

test('applies visible block settings without weakening the mandatory advice', () => {
  const state = normalizeWallboardForecastState({
    pages: {
      forecast: backendForecast({ visible_blocks: ['visibility', 'kp_index'] }),
    },
  });

  expect(state.pages.forecast.overall_status).toBe('red');
  expect(wallboardForecastDisplayBlocks(state.pages.forecast).map((block) => block.key))
    .toEqual(['visibility', 'kp_index']);
});

test('caps malformed server visibility settings at the supported card limit', () => {
  const state = normalizeWallboardForecastState({
    pages: {
      forecast: backendForecast({ visible_blocks: [...WALLBOARD_FORECAST_BLOCK_KEYS] }),
    },
  });

  expect(state.pages.forecast.visible_blocks).toHaveLength(MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS);
});

test('stale values remain fail-closed while GNSS stays explicitly unknown', () => {
  const forecastPayload = backendForecast();
  forecastPayload.overall_status = 'unknown';
  forecastPayload.metrics = forecastPayload.metrics.map((candidate) => candidate.key === 'wind_speed_kmh'
    ? { ...candidate, status: 'green' as const, stale: true }
    : candidate);
  const forecast = normalizeWallboardForecastState({ pages: { forecast: forecastPayload } }).pages.forecast;

  expect(forecast.overall_status).toBe('unknown');
  expect(forecast.metrics.find((candidate) => candidate.key === 'wind_speed_kmh')?.status).toBe('unknown');
  expect(forecast.metrics.find((candidate) => candidate.key === 'gnss_satellites')?.status).toBe('unknown');
  expect(forecast.metrics.find((candidate) => candidate.key === 'gnss_satellites_fix')?.status).toBe('unknown');
});

test('formats provider timestamps once in Europe/Amsterdam without a double UTC offset', () => {
  expect(formatWallboardForecastUpdateTime('2026-07-20T12:15:00Z')).toBe('14:15');
  expect(forecastTimeRange('2026-07-20T05:45:00+02:00', '2026-07-20T06:05:00+02:00')).toBe('05:45–06:05');
  expect(formatWallboardForecastUpdateTime('ongeldig')).toBe('onbekend');
});

test('colors complete forecast cards and keeps reduced-motion protection', () => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(styles).toMatch(/\.wallboard-display__forecast-advice,[\s\S]*?\.wallboard-display__forecast-metric\s*\{[\s\S]*?background:/);
  expect(styles).toContain('.wallboard-display__forecast-metric--green');
  expect(styles).toContain('.wallboard-display__forecast-metric--orange');
  expect(styles).toContain('.wallboard-display__forecast-metric--red');
  expect(styles).toContain('grid-template-columns: repeat(4, minmax(0, 1fr))');
  expect(styles).toContain('grid-auto-rows: minmax(0, 1fr)');
  expect(styles).not.toMatch(/@media \(max-width: 1400px\)[\s\S]{0,180}repeat\(3/);
  expect(styles).toContain('@keyframes wallboard-preannouncement-ring');
  expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
});

test('removes repeated source and disclaimer rows from the forecast presentation', () => {
  const display = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');
  const forecastPresentation = display.slice(
    display.indexOf('function WallboardForecastPage'),
    display.indexOf('function WallboardNewsPage'),
  );

  expect(forecastPresentation).toContain('wallboard-display__forecast-updated');
  expect(forecastPresentation).not.toContain('Bron:');
  expect(forecastPresentation).not.toContain('wallboard-display__forecast-disclaimer');
  expect(forecastPresentation).not.toContain('wallboard-display__forecast-footer');
});

test('keeps all twelve forecast blocks in a four-by-three grid at Full HD and Ultra HD', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
  const screens = [
    { profile: '1080p', width: 1920, height: 1080 },
    { profile: '4k', width: 3840, height: 2160 },
  ] as const;

  for (const screen of screens) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    const cards = Array.from({ length: 12 }, (_, index) => `
      <article class="wallboard-display__forecast-metric wallboard-display__forecast-metric--${index > 8 ? 'unknown' : 'green'}">
        <header><span>Informatieblok ${index + 1}</span></header>
        <strong>${index > 8 ? 'Onbekend' : `${index + 10} km/u`}</strong>
        <ul class="wallboard-display__forecast-details"><li>120 m boven maaiveld</li><li>Operationele detailwaarde</li></ul>
        <p>Zonder gevalideerde receiverdata blijft deze waarde fail-closed onbekend.</p>
      </article>
    `).join('');

    await page.setContent(`
      <style>
        ${styles}
        html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }
      </style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}">
        <header class="wallboard-display__header">
          <div><span class="wallboard-display__titles"><small>Meldkamer noord</small><h1>UAV Forecast Nederland</h1></span></div>
          <time class="wallboard-display__clock"><span>14:15:00</span><small>maandag 20 juli 2026</small></time>
        </header>
        <section class="wallboard-display__page">
          <div class="wallboard-display__forecast">
            <section class="wallboard-display__forecast-advice wallboard-display__forecast-advice--unknown">
              <span aria-hidden="true">☁</span>
              <div class="wallboard-display__forecast-advice-copy">
                <small>Vliegadvies · UAV Nederland</small><h2>Advies onvolledig</h2>
                <p>Minimaal één noodzakelijke waarde ontbreekt of is verouderd.</p>
                <span class="wallboard-display__forecast-scope">Gemiddelde van 12/12 provincies</span>
              </div>
              <time class="wallboard-display__forecast-updated"><span>Laatst bijgewerkt</span><strong>14:15</strong></time>
            </section>
            <div class="wallboard-display__forecast-grid">${cards}</div>
          </div>
        </section>
        <footer class="wallboard-display__footer"><span>Pagina 1 van 1</span><span>Scherm blijft actief</span></footer>
        <section class="wallboard-display__ticker"><strong class="wallboard-display__ticker-label">Actueel</strong><div class="wallboard-display__ticker-viewport"><span class="wallboard-display__ticker-item">Operationeel bericht</span></div></section>
      </main>
    `);

    const measurement = await page.locator('.wallboard-display__forecast-grid').evaluate((grid) => {
      const forecast = grid.parentElement as HTMLElement;
      const cardRects = Array.from(grid.children).map((card) => card.getBoundingClientRect());
      const distinct = (values: number[]) => new Set(values.map((value) => Math.round(value))).size;
      return {
        columns: getComputedStyle(grid).gridTemplateColumns.split(/\s+/).filter(Boolean).length,
        columnPositions: distinct(cardRects.slice(0, 4).map((rect) => rect.left)),
        forecastOverflow: forecast.scrollHeight > forecast.clientHeight + 1,
        pageOverflow: document.documentElement.scrollHeight > document.documentElement.clientHeight + 1,
        rowPositions: distinct(cardRects.map((rect) => rect.top)),
        cardsInsideGrid: cardRects.every((rect) => rect.bottom <= grid.getBoundingClientRect().bottom + 1),
      };
    });

    expect(measurement).toEqual({
      columns: 4,
      columnPositions: 4,
      forecastOverflow: false,
      pageOverflow: false,
      rowPositions: 3,
      cardsInsideGrid: true,
    });
  }
});
