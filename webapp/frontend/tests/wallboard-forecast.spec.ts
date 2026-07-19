import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type {
  WallboardForecastMetric,
  WallboardForecastMetricKey,
  WallboardForecastStatus,
} from '../src/types/api';
import {
  formatForecastMetricValue,
  normalizeWallboardForecastState,
  wallboardForecastDisplayBlocks,
} from '../src/features/wallboards/WallboardDisplayPage';
import { WALLBOARD_FORECAST_BLOCK_KEYS } from '../src/features/wallboards/wallboardPresentation';

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
    visible_blocks: [...WALLBOARD_FORECAST_BLOCK_KEYS],
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
      timezone: 'UTC',
      sunrise_earliest: '2026-07-20T03:45:00Z',
      sunrise_latest: '2026-07-20T04:05:00Z',
      sunset_earliest: '2026-07-20T19:40:00Z',
      sunset_latest: '2026-07-20T20:00:00Z',
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
      metric('cloud_cover_pct', 'green', 30, { unit: '%' }),
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

test('preserves the authoritative backend advice and complete expanded contract', () => {
  const state = normalizeWallboardForecastState({ pages: { forecast: backendForecast() } });
  const forecast = state.pages.forecast;

  expect(forecast.overall_status).toBe('red');
  expect(forecast.metrics).toHaveLength(13);
  expect(forecast.condition).toMatchObject({ code: 95, label: 'Onweer', status: 'red' });
  expect(forecast.aggregation).toMatchObject({ sample_count: 12, expected_sample_count: 12, complete: true });
  expect(wallboardForecastDisplayBlocks(forecast).map((block) => block.key))
    .toEqual(WALLBOARD_FORECAST_BLOCK_KEYS);
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

test('colors complete forecast cards and keeps reduced-motion protection', () => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(styles).toMatch(/\.wallboard-display__forecast-advice,[\s\S]*?\.wallboard-display__forecast-metric\s*\{[\s\S]*?background:/);
  expect(styles).toContain('.wallboard-display__forecast-metric--green');
  expect(styles).toContain('.wallboard-display__forecast-metric--orange');
  expect(styles).toContain('.wallboard-display__forecast-metric--red');
  expect(styles).toContain('grid-template-columns: repeat(4, minmax(0, 1fr))');
  expect(styles).toContain('@keyframes wallboard-preannouncement-ring');
  expect(styles).toContain('@media (prefers-reduced-motion: reduce)');
});
