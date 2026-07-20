import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  buildKnmiKeyPayload,
  formatKnmiBytes,
  KNMI_ACTIVE_POLL_INTERVAL_MS,
  knmiKeySourceLabel,
  knmiOperationIsActive,
  knmiOperationStageLabel,
  knmiOperationStateLabel,
  knmiOperationStateTone,
  normalizeKnmiProgress,
} from '../src/features/admin/knmiAdminPresentation';

test('exposes KNMI directly below Admin as its own protected management page', () => {
  const route = readFileSync(new URL('../app/knmi/page.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const generalAdmin = readFileSync(new URL('../src/features/admin/AdminPage.tsx', import.meta.url), 'utf8');
  const adminIndex = navigation.indexOf("to: '/admin', label: 'Admin'");
  const knmiIndex = navigation.indexOf("to: '/knmi', label: 'KNMI'");

  expect(route).toContain("permissions={['settings.manage']}");
  expect(adminIndex).toBeGreaterThan(-1);
  expect(knmiIndex).toBeGreaterThan(adminIndex);
  expect(navigation.slice(adminIndex, knmiIndex)).not.toContain("to: '/branding'");
  expect(navigation).toContain("'/knmi': () => import('../features/admin/KnmiAdminPage')");
  expect(generalAdmin).not.toContain('KNMI Data Platform');
  expect(generalAdmin).not.toContain('KNMI EDR');
});

test('keeps modelverwachtingen and measured station data visibly separate', () => {
  const page = readFileSync(new URL('../src/features/admin/KnmiAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain('Een modelverwachting voor Nederland, geen meting.');
  expect(page).toContain('EDR · gemeten stationdata');
  expect(page).toContain('mengt ze niet met het modelpercentage');
  expect(page).toContain('Verwachtingsvenster');
  expect(page).toContain('+60 uur');
});

test('maps KNMI import states, stages and bounded progress honestly', () => {
  expect(KNMI_ACTIVE_POLL_INTERVAL_MS).toBe(5_000);
  expect(knmiOperationIsActive('queued')).toBe(true);
  expect(knmiOperationIsActive('running')).toBe(true);
  expect(knmiOperationIsActive('succeeded')).toBe(false);
  expect(knmiOperationStateLabel('succeeded', true)).toBe('Al actueel');
  expect(knmiOperationStateLabel('failed')).toBe('Mislukt');
  expect(knmiOperationStateTone('running')).toBe('warn');
  expect(knmiOperationStateTone('failed')).toBe('bad');
  expect(knmiOperationStageLabel('metadata')).toBe('Actuele modelset controleren');
  expect(knmiOperationStageLabel('downloading')).toBe('Volledig archief downloaden');
  expect(knmiOperationStageLabel('validating')).toBe('KNMI-parameters controleren');
  expect(knmiOperationStageLabel('custom_stage')).toBe('custom stage');
  expect(normalizeKnmiProgress(-10)).toBe(0);
  expect(normalizeKnmiProgress(48.7)).toBe(49);
  expect(normalizeKnmiProgress(180)).toBe(100);
  expect(normalizeKnmiProgress(null)).toBeNull();
});

test('formats download sizes and key origins for Dutch administrators', () => {
  expect(formatKnmiBytes(0)).toBe('0 B');
  expect(formatKnmiBytes(861_009_920)).toBe('821 MiB');
  expect(formatKnmiBytes(-1)).toBe('-');
  expect(knmiKeySourceLabel('open_data_setting')).toBe('Opgeslagen in D.I.S.');
  expect(knmiKeySourceLabel('open_data_environment')).toBe('Serveromgeving');
  expect(knmiKeySourceLabel('edr_setting')).toBe('Opgeslagen in D.I.S.');
  expect(knmiKeySourceLabel('edr_environment')).toBe('Serveromgeving');
  expect(knmiKeySourceLabel(null)).toBe('Niet ingesteld');
});

test('sends only newly entered KNMI keys and never blanks stored secrets', () => {
  expect(buildKnmiKeyPayload({
    openDataApiKey: ' open-data-key ',
    edrApiKey: ' ',
  })).toEqual({ open_data_api_key: 'open-data-key' });

  expect(buildKnmiKeyPayload({
    openDataApiKey: '',
    edrApiKey: ' edr-key ',
  })).toEqual({ edr_api_key: 'edr-key' });

  expect(buildKnmiKeyPayload({ openDataApiKey: '', edrApiKey: '' })).toEqual({});
});

test('uses the dedicated KNMI endpoints and polls only while an operation is active', () => {
  const page = readFileSync(new URL('../src/features/admin/KnmiAdminPage.tsx', import.meta.url), 'utf8');

  expect(page).toContain("useApiResource<KnmiAdminStatus>('/admin/knmi'");
  expect(page).toContain("api.patch<KnmiAdminStatus>('/admin/knmi', payload)");
  expect(page).toContain("api.post<KnmiForecastOperationStarted>('/admin/knmi/refresh')");
  expect(page).toContain('if (!operationActive)');
  expect(page).toContain('KNMI_ACTIVE_POLL_INTERVAL_MS');
});
