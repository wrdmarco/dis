import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  buildAdminApiSettingsPayload,
  DEFAULT_AERET_MAP_URL,
  KNMI_EDR_COLLECTION_ENDPOINT,
  mapAdminApiSettings,
  preserveAdminApiSecrets,
  type AdminApiSettingsForm,
} from '../src/features/admin/adminApiSettings';
import type { SystemSetting } from '../src/types/api';

test('maps external API settings without exposing stored secrets', () => {
  const settings: SystemSetting[] = [
    { key: 'drone.aeret_map_url', value: 'https://aeret.example.test/map', is_sensitive: false },
    { key: 'drone.aeret_api_url', value: 'https://aeret.example.test/api', is_sensitive: false },
    { key: 'drone.aeret_api_key', value: { configured: true }, is_sensitive: true },
    { key: 'weather.knmi_edr_api_key', value: { configured: false }, is_sensitive: true },
  ];

  expect(mapAdminApiSettings(settings)).toEqual({
    form: {
      aeretMapUrl: 'https://aeret.example.test/map',
      aeretApiUrl: 'https://aeret.example.test/api',
      aeretApiKey: '',
      knmiEdrApiKey: '',
    },
    aeretApiKeyConfigured: true,
    knmiEdrApiKeyConfigured: false,
  });

  expect(mapAdminApiSettings([]).form.aeretMapUrl).toBe(DEFAULT_AERET_MAP_URL);
});

test('builds a scoped API payload and omits empty secret inputs', () => {
  const form: AdminApiSettingsForm = {
    aeretMapUrl: ' https://aeret.example.test/map ',
    aeretApiUrl: ' ',
    aeretApiKey: '',
    knmiEdrApiKey: ' knmi-secret ',
  };

  expect(buildAdminApiSettingsPayload(form)).toEqual({
    'drone.aeret_map_url': 'https://aeret.example.test/map',
    'drone.aeret_api_url': null,
    'weather.knmi_edr_api_key': 'knmi-secret',
  });
});

test('preserves unsaved API secrets while public settings reload', () => {
  const current: AdminApiSettingsForm = {
    aeretMapUrl: 'https://old.example.test/map',
    aeretApiUrl: 'https://old.example.test/api',
    aeretApiKey: 'unsaved-aeret-key',
    knmiEdrApiKey: 'unsaved-knmi-key',
  };
  const incoming: AdminApiSettingsForm = {
    aeretMapUrl: 'https://new.example.test/map',
    aeretApiUrl: 'https://new.example.test/api',
    aeretApiKey: '',
    knmiEdrApiKey: '',
  };

  expect(preserveAdminApiSecrets(current, incoming)).toEqual({
    ...incoming,
    aeretApiKey: 'unsaved-aeret-key',
    knmiEdrApiKey: 'unsaved-knmi-key',
  });
});

test('places KNMI and Aeret together on a dedicated API tab', () => {
  const source = readFileSync(new URL('../src/features/admin/AdminPage.tsx', import.meta.url), 'utf8');
  const apiSectionStart = source.indexOf("{activeTab === 'api'");
  const systemSectionStart = source.indexOf("{activeTab === 'system'");
  const passwordsSectionStart = source.indexOf("{activeTab === 'passwords'");
  const apiSection = source.slice(apiSectionStart, systemSectionStart);
  const systemSection = source.slice(systemSectionStart, passwordsSectionStart);

  expect(source).toContain("{ id: 'mail', label: 'Mail' },\n  { id: 'api', label: 'API' },\n  { id: 'system', label: 'Systeem' }");
  expect(apiSectionStart).toBeGreaterThan(-1);
  expect(systemSectionStart).toBeGreaterThan(apiSectionStart);
  expect(apiSection).toContain('KNMI Data Platform (EDR)');
  expect(apiSection).toContain('KNMI EDR collection endpoint');
  expect(apiSection).toContain('readOnly value={KNMI_EDR_COLLECTION_ENDPOINT}');
  expect(apiSection).toContain('Aeret dronekaart URL');
  expect(apiSection).toContain('Aeret API endpoint');
  expect(apiSection).toContain('Aeret API-key');
  expect(apiSection).toContain('saveAdminApiSettings');
  expect(systemSection).not.toContain('Aeret');
  expect(systemSection).not.toContain('KNMI');
  expect(systemSection).toContain('saveSystemSettings');
  expect(KNMI_EDR_COLLECTION_ENDPOINT).toBe('https://api.dataplatform.knmi.nl/edr/v1/collections/10-minute-in-situ-meteorological-observations');
});
