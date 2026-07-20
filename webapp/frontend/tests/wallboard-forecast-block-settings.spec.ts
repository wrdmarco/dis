import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardForecastBlockKey, WallboardPage } from '../src/types/api';
import {
  DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS,
  WALLBOARD_FORECAST_BLOCK_KEYS,
  normalizeWallboardForecastPageOptions,
} from '../src/features/wallboards/wallboardPresentation';

function forecastPage(options: WallboardPage['options']): WallboardPage {
  return {
    id: 'forecast-block-settings',
    type: 'uav_forecast',
    name: 'UAV Forecast',
    duration_seconds: 30,
    options,
  };
}

test('defines fourteen supported forecast blocks and a stable twelve-card default', () => {
  expect(WALLBOARD_FORECAST_BLOCK_KEYS).toEqual([
    'weather',
    'daylight',
    'temperature',
    'wind_speed',
    'wind_gust',
    'wind_direction',
    'precipitation_probability',
    'precipitation_outlook',
    'thunderstorm_forecast',
    'cloud_cover',
    'visibility',
    'kp_index',
    'gnss_visible',
    'gnss_usable',
  ]);
  expect(DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS).toEqual(WALLBOARD_FORECAST_BLOCK_KEYS.slice(0, 12));
  expect(new Set(WALLBOARD_FORECAST_BLOCK_KEYS).size).toBe(14);
  expect(DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS).toHaveLength(MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS);
});

test('defaults missing legacy block settings to every visible block', () => {
  expect(normalizeWallboardForecastPageOptions(forecastPage({
    location_mode: 'netherlands',
  })).visible_blocks).toEqual([...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS]);
});

test('upgrades the exact historical default while preserving custom selections', () => {
  expect(normalizeWallboardForecastPageOptions(forecastPage({
    location_mode: 'netherlands',
    visible_blocks: [
      'weather', 'daylight', 'temperature', 'wind_speed', 'wind_gust', 'wind_direction',
      'precipitation_probability', 'cloud_cover', 'visibility', 'gnss_visible', 'kp_index', 'gnss_usable',
    ],
  })).visible_blocks).toEqual([...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS]);
});

test('keeps an explicitly empty selection and removes unknown or duplicate keys', () => {
  expect(normalizeWallboardForecastPageOptions(forecastPage({
    location_mode: 'netherlands',
    visible_blocks: [],
  })).visible_blocks).toEqual([]);

  const untrustedKeys = [
    'kp_index',
    'not_a_forecast_block',
    'weather',
    'kp_index',
  ] as unknown as WallboardForecastBlockKey[];
  expect(normalizeWallboardForecastPageOptions(forecastPage({
    location_mode: 'netherlands',
    visible_blocks: untrustedKeys,
  })).visible_blocks).toEqual(['weather', 'kp_index']);
});

test('offers block visibility controls while keeping the flight advice mandatory', () => {
  const editor = readFileSync(
    new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url),
    'utf8',
  );

  expect(editor).toContain('Zichtbare informatieblokken');
  expect(editor).toContain('checked={forecastVisibleBlocks.includes(option.key)}');
  expect(editor).toContain('Vliegadvies blijft altijd zichtbaar.');
  expect(editor).toContain('ook wanneer je een informatieblok verbergt');
  expect(editor).not.toContain("key: 'advice'");
});
