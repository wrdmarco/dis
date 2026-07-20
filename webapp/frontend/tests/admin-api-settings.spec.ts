import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  buildAdminApiSettingsPayload,
  DEFAULT_AERET_MAP_URL,
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
  ];

  expect(mapAdminApiSettings(settings)).toEqual({
    form: {
      aeretMapUrl: 'https://aeret.example.test/map',
      aeretApiUrl: 'https://aeret.example.test/api',
      aeretApiKey: '',
    },
    aeretApiKeyConfigured: true,
  });

  expect(mapAdminApiSettings([]).form.aeretMapUrl).toBe(DEFAULT_AERET_MAP_URL);
});

test('builds a scoped API payload and omits empty secret inputs', () => {
  const form: AdminApiSettingsForm = {
    aeretMapUrl: ' https://aeret.example.test/map ',
    aeretApiUrl: ' ',
    aeretApiKey: '',
  };

  expect(buildAdminApiSettingsPayload(form)).toEqual({
    'drone.aeret_map_url': 'https://aeret.example.test/map',
    'drone.aeret_api_url': null,
  });
});

test('preserves unsaved API secrets while public settings reload', () => {
  const current: AdminApiSettingsForm = {
    aeretMapUrl: 'https://old.example.test/map',
    aeretApiUrl: 'https://old.example.test/api',
    aeretApiKey: 'unsaved-aeret-key',
  };
  const incoming: AdminApiSettingsForm = {
    aeretMapUrl: 'https://new.example.test/map',
    aeretApiUrl: 'https://new.example.test/api',
    aeretApiKey: '',
  };

  expect(preserveAdminApiSecrets(current, incoming)).toEqual({
    ...incoming,
    aeretApiKey: 'unsaved-aeret-key',
  });
});

test('keeps the generic API tab scoped to Aeret', () => {
  const source = readFileSync(new URL('../src/features/admin/AdminPage.tsx', import.meta.url), 'utf8');
  const apiSectionStart = source.indexOf("{activeTab === 'api'");
  const systemSectionStart = source.indexOf("{activeTab === 'system'");
  const passwordsSectionStart = source.indexOf("{activeTab === 'passwords'");
  const apiSection = source.slice(apiSectionStart, systemSectionStart);
  const systemSection = source.slice(systemSectionStart, passwordsSectionStart);

  expect(source).toContain("{ id: 'mail', label: 'Mail' },\n  { id: 'api', label: 'API' },\n  { id: 'system', label: 'Systeem' }");
  expect(apiSectionStart).toBeGreaterThan(-1);
  expect(systemSectionStart).toBeGreaterThan(apiSectionStart);
  expect(apiSection).not.toContain('KNMI');
  expect(apiSection).toContain('Aeret dronekaart URL');
  expect(apiSection).toContain('Aeret API endpoint');
  expect(apiSection).toContain('Aeret API-key');
  expect(apiSection).toContain('saveAdminApiSettings');
  expect(systemSection).not.toContain('Aeret');
  expect(systemSection).not.toContain('KNMI');
  expect(systemSection).toContain('saveSystemSettings');
});
