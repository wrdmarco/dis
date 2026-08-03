import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardForecastPageState } from '../src/types/api';
import {
  normalizeOperationalWeatherRadarPage,
  uavForecastIsDegraded,
} from '../src/features/weather/forecastNormalization';
import {
  FORECAST_RETRY_INTERVAL_MS,
  forecastRefreshDeadline,
} from '../src/features/weather/useForecastResource';

const weatherPage = readFileSync(
  new URL('../src/features/weather/WeatherPage.tsx', import.meta.url),
  'utf8',
);
const uavPage = readFileSync(
  new URL('../src/features/weather/UavForecastPage.tsx', import.meta.url),
  'utf8',
);
const resourceHook = readFileSync(
  new URL('../src/features/weather/useForecastResource.ts', import.meta.url),
  'utf8',
);

test('weather uses the radar-only fast path instead of waiting for the UAV provider', () => {
  expect(weatherPage).toContain("'/operational-weather/radar'");
  expect(weatherPage).toContain('normalizeOperationalWeatherRadarPage');
  expect(weatherPage).not.toContain('OperationalWeatherCloudState');
  expect(weatherPage).not.toContain('OperationalWeatherPrecipitationState');
  expect(weatherPage).not.toContain('DMI');
});

test('radar-only responses keep strict location and timestamp validation', () => {
  const normalized = normalizeOperationalWeatherRadarPage({
    location: {
      mode: 'netherlands',
      label: 'Nederland',
      latitude: 52.2,
      longitude: 5.4,
    },
    generated_at: '2026-07-29T12:05:00Z',
    radar: {
      precipitation: {
        status: 'unavailable',
        source: {
          name: 'KNMI neerslagradar',
          license: 'CC BY 4.0',
        },
      },
      lightning: null,
    },
  });

  expect(normalized).toMatchObject({
    location: {
      mode: 'netherlands',
      label: 'Nederland',
      latitude: 52.2,
      longitude: 5.4,
    },
    radar: {
      precipitation: { status: 'unavailable' },
      lightning: null,
    },
  });
  expect(normalizeOperationalWeatherRadarPage({
    location: { mode: 'netherlands', label: 'Nederland', latitude: null, longitude: 5.4 },
    generated_at: '2026-07-29T12:05:00Z',
    radar: {},
  })).toBeNull();
});

test('incomplete UAV data stays visible as degraded and schedules the short retry path', () => {
  const incomplete = {
    aggregation: { complete: false, fresh: false },
    metrics: [],
  } as WallboardForecastPageState;
  const complete = {
    aggregation: { complete: true, fresh: true },
    metrics: [{ key: 'low_cloud_cover_pct', status: 'green' }],
  } as WallboardForecastPageState;
  const incompleteLowCloud = {
    aggregation: { complete: true, fresh: true },
    metrics: [{ key: 'low_cloud_cover_pct', status: 'unknown' }],
  } as WallboardForecastPageState;

  expect(uavForecastIsDegraded(incomplete)).toBe(true);
  expect(uavForecastIsDegraded(complete)).toBe(false);
  expect(uavForecastIsDegraded(incompleteLowCloud)).toBe(true);
  expect(forecastRefreshDeadline(
    1_000,
    2_000,
    uavForecastIsDegraded(incompleteLowCloud),
  )).toBe(2_000 + FORECAST_RETRY_INTERVAL_MS);
  expect(uavPage).toContain('uavForecastIsDegraded');
  expect(uavPage).toContain('binnen een minuut volgt automatisch een nieuwe poging');
  expect(resourceHook).toContain('lastAttemptFailed.current = responseIsDegraded');
  expect(resourceHook).toContain('setDegraded(responseIsDegraded)');
});
