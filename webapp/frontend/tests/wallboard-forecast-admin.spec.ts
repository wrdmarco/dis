import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardPage } from '../src/types/api';
import {
  DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
  DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  createWallboardPage,
  normalizeWallboardForecastPageOptions,
} from '../src/features/wallboards/wallboardPresentation';

function forecastPage(options: WallboardPage['options']): WallboardPage {
  return {
    id: 'forecast',
    type: 'uav_forecast',
    name: 'UAV Forecast',
    duration_seconds: 30,
    options,
  };
}

test('creates a forecast page with UAV Nederland as the canonical default', () => {
  expect(createWallboardPage('uav_forecast', 1).options).toEqual({
    location_mode: DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
    visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
  });
  expect(DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE).toBe('netherlands');
});

test('normalizes selected addresses without writing client-supplied coordinates', () => {
  const options = normalizeWallboardForecastPageOptions(forecastPage({
    location_mode: 'address',
    location_label: '  Utrecht Centraal  ',
    latitude: 52.0894,
    longitude: 5.1101,
  }));

  expect(options).toEqual({
    location_mode: 'address',
    location_label: 'Utrecht Centraal',
    visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
  });
  expect(options).not.toHaveProperty('latitude');
  expect(options).not.toHaveProperty('longitude');
});

test('migrates legacy locations and strips stale location data from the Netherlands mode', () => {
  expect(normalizeWallboardForecastPageOptions(forecastPage({
    location_label: 'Woerden',
    latitude: 52.085,
    longitude: 4.883,
  }))).toEqual({
    location_mode: 'address',
    location_label: 'Woerden',
    visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
  });

  expect(normalizeWallboardForecastPageOptions(forecastPage({
    location_mode: 'netherlands',
    location_label: 'Deze waarde hoort niet mee',
    latitude: 0,
    longitude: 0,
  }))).toEqual({
    location_mode: 'netherlands',
    visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
  });
});

test('reuses the DIS address search and exposes no manual coordinate fields', () => {
  const editor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );

  expect(editor).toContain('fetchLocationSuggestions');
  expect(editor).toContain('lookupLocationSuggestion');
  expect(editor).toContain('geocodeAddressLabel');
  expect(editor).toContain('UAV Nederland');
  expect(editor).toContain('Andere locatie');
  expect(editor).toContain('Locatie zoeken');
  expect(editor).toContain('De server controleert de locatie bij het opslaan.');
  expect(editor).not.toContain('<span>Breedtegraad</span>');
  expect(editor).not.toContain('<span>Lengtegraad</span>');
  expect(editor).not.toContain('page.options.latitude');
  expect(editor).not.toContain('page.options.longitude');
});
