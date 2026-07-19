import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { normalizeWallboardForecastState } from '../src/features/wallboards/WallboardDisplayPage';

function metric(key: string, status: 'green' | 'orange' | 'red' | 'unknown', value: number | null, stale = false) {
  return {
    key,
    label: key,
    value,
    unit: 'eenheid',
    status,
    stale,
    source: { name: 'Gecontroleerde bron', url: 'https://example.test/source' },
    measured_at: '2026-07-19T12:00:00Z',
    explanation: 'Centrale drempel.',
  };
}

test('normalizes forecast fail-closed and recomputes the overall worst factor', () => {
  const state = normalizeWallboardForecastState({
    pages: {
      forecast: {
        location: { label: 'Utrecht', latitude: 52.09, longitude: 5.12 },
        overall_status: 'green',
        generated_at: '2026-07-19T12:00:00Z',
        metrics: [
          metric('wind_speed_kmh', 'green', 10),
          metric('wind_gust_kmh', 'orange', 35),
          metric('precipitation_mm', 'green', 0),
          metric('visibility_m', 'red', 1500),
          metric('kp_index', 'green', 2),
          metric('gnss_satellites', 'green', null),
        ],
        disclaimer: 'Operationele limieten gaan voor.',
      },
    },
  });

  expect(state.pages.forecast.overall_status).toBe('red');
  expect(state.pages.forecast.metrics.find((candidate) => candidate.key === 'gnss_satellites')?.status)
    .toBe('unknown');
});

test('stale forecast values can never remain green', () => {
  const state = normalizeWallboardForecastState({
    pages: {
      forecast: {
        location: { label: 'Utrecht', latitude: 52.09, longitude: 5.12 },
        overall_status: 'green',
        generated_at: '2026-07-19T12:00:00Z',
        metrics: [
          metric('wind_speed_kmh', 'green', 10, true),
          metric('wind_gust_kmh', 'green', 10),
          metric('precipitation_mm', 'green', 0),
          metric('visibility_m', 'green', 10000),
          metric('kp_index', 'green', 2),
          metric('gnss_satellites', 'unknown', null),
        ],
        disclaimer: 'Operationele limieten gaan voor.',
      },
    },
  });

  expect(state.pages.forecast.overall_status).toBe('unknown');
  expect(state.pages.forecast.metrics[0].status).toBe('unknown');
});

test('colors complete forecast blocks and animates focus icons with reduced-motion protection', () => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(styles).toMatch(/\.wallboard-display__forecast-advice,[\s\S]*?\.wallboard-display__forecast-metric\s*\{[\s\S]*?background:/);
  expect(styles).toContain('.wallboard-display__forecast-metric--green');
  expect(styles).toContain('.wallboard-display__forecast-metric--orange');
  expect(styles).toContain('.wallboard-display__forecast-metric--red');
  expect(styles).toContain('@keyframes wallboard-preannouncement-ring');
  expect(styles).toContain('@keyframes wallboard-real-alarm-pulse');
  expect(styles).toContain('@keyframes wallboard-test-alarm-wave');
  expect(styles).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*?wallboard-display__alarm-icon--preannouncement/);
});
