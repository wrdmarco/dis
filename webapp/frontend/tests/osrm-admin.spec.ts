import { expect, test } from 'playwright/test';
import {
  isOsrmOperationSummary,
  mergeOsrmLogLines,
  nextOsrmPollDelay,
  osrmActionLabel,
  osrmOperationIsActive,
  osrmOperationStageLabel,
  osrmOperationTone,
  osrmStateLabel,
  osrmUpdateGuidance,
  validateOsrmOperationForm,
} from '../src/features/admin/osrmAdminPresentation';

test('builds the exact initial server payload without accepting a client checksum', () => {
  const result = validateOsrmOperationForm('install_activate', {
    longitude: '4.895168',
    latitude: '52.370216',
  });

  expect(result).toEqual({
    valid: true,
    request: {
      action: 'install_activate',
      health_coordinate: {
        longitude: 4.895168,
        latitude: 52.370216,
      },
    },
  });
});

test('requires only a bounded initial probe coordinate', () => {
  expect(validateOsrmOperationForm('update', {
    longitude: '',
    latitude: '',
  })).toEqual({
    valid: true,
    request: {
      action: 'update',
    },
  });

  expect(validateOsrmOperationForm('install_activate', {
    longitude: '181',
    latitude: '52.3',
  })).toMatchObject({ valid: false, message: /-180 en 180/ });
});

test('presents lifecycle and reliable stages without inventing progress percentages', () => {
  expect(osrmStateLabel('not_installed')).toBe('Niet geïnstalleerd');
  expect(osrmStateLabel('installed_inactive')).toBe('Geïnstalleerd, niet actief');
  expect(osrmStateLabel('ready')).toBe('Actief en gezond');
  expect(osrmActionLabel('install_activate')).toBe('OSRM installeren en activeren');
  expect(osrmActionLabel('update')).toBe('Kaartgegevens bijwerken');
  expect(osrmOperationStageLabel('merging')).toBe('Kaartdekking samenvoegen');
  expect(osrmOperationStageLabel('partitioning')).toBe('Routeringsnetwerk partitioneren');
  expect(osrmOperationIsActive('queued')).toBe(true);
  expect(osrmOperationIsActive('running')).toBe(true);
  expect(osrmOperationIsActive('succeeded')).toBe(false);
  expect(osrmOperationTone('failed')).toBe('bad');
});

test('explains automatic supplier verification for updates and repairs accurately', () => {
  expect(osrmUpdateGuidance('ready', true)).toContain('Nederland en België afzonderlijk');
  expect(osrmUpdateGuidance('degraded', false)).toContain('beide downloads afzonderlijk');
});

test('accepts only complete typed realtime operation summaries', () => {
  expect(isOsrmOperationSummary({
    id: '01J00000000000000000000000',
    action: 'update',
    state: 'running',
    stage: 'merging',
    message: 'Kaartdekking samenvoegen.',
  })).toBe(true);
  expect(isOsrmOperationSummary({
    id: '01J00000000000000000000000',
    action: 'update',
    state: 'running',
    stage: 'onbekende_fase',
    message: 'Onbekend.',
  })).toBe(false);
  expect(isOsrmOperationSummary({ state: 'running' })).toBe(false);
});

test('continues cursor history after a full terminal batch and merges repeated lines safely', () => {
  expect(nextOsrmPollDelay('running', 0)).toBe(2000);
  expect(nextOsrmPollDelay('succeeded', 200)).toBe(0);
  expect(nextOsrmPollDelay('succeeded', 12)).toBeNull();

  expect(mergeOsrmLogLines(
    [{ seq: 2, at: '2026-07-16T10:00:02Z', level: 'info', message: 'Oud' }],
    [
      { seq: 1, at: '2026-07-16T10:00:01Z', level: 'info', message: 'Eerste' },
      { seq: 2, at: '2026-07-16T10:00:02Z', level: 'info', message: 'Bijgewerkt' },
    ],
  )).toEqual([
    { seq: 1, at: '2026-07-16T10:00:01Z', level: 'info', message: 'Eerste' },
    { seq: 2, at: '2026-07-16T10:00:02Z', level: 'info', message: 'Bijgewerkt' },
  ]);
});
