import { expect, test } from '@playwright/test';
import type {
  IncidentSpeechPreparationStatus,
  IncidentSpeechPreparations,
} from '../src/types/api';
import {
  INCIDENT_SPEECH_PREPARATION_POLL_INTERVAL_MS,
  incidentSpeechPreparationIsActive,
  incidentSpeechPreparationPhaseLabel,
  incidentSpeechPreparationsAreActive,
  normalizeIncidentSpeechPreparationProgress,
  presentIncidentSpeechPreparation,
} from '../src/features/incidents/incidentSpeechPreparationPresentation';

test('presents every persistent incident speech status in clear Dutch', () => {
  const expected: Record<
    IncidentSpeechPreparationStatus,
    { label: string; tone: 'neutral' | 'good' | 'warn' | 'bad' }
  > = {
    disabled: { label: 'Uitgeschakeld', tone: 'neutral' },
    not_scheduled: { label: 'Niet ingepland', tone: 'neutral' },
    queued: { label: 'In wachtrij', tone: 'warn' },
    processing: { label: 'Wordt gegenereerd', tone: 'warn' },
    ready: { label: 'Gereed', tone: 'good' },
    failed: { label: 'Mislukt', tone: 'bad' },
    cancelled: { label: 'Geannuleerd', tone: 'neutral' },
  };

  for (const [status, presentation] of Object.entries(expected)) {
    expect(presentIncidentSpeechPreparation(status as IncidentSpeechPreparationStatus)).toMatchObject(
      presentation,
    );
  }
});

test('labels pre-alarm and alarm speech preparations separately', () => {
  expect(incidentSpeechPreparationPhaseLabel('availability')).toBe('TTS-vooralarmering');
  expect(incidentSpeechPreparationPhaseLabel('attendance')).toBe('TTS-alarm');
});

test('polls every five seconds only while speech is queued or processing', () => {
  expect(INCIDENT_SPEECH_PREPARATION_POLL_INTERVAL_MS).toBe(5_000);

  const activeStatuses: IncidentSpeechPreparationStatus[] = ['queued', 'processing'];
  const inactiveStatuses: IncidentSpeechPreparationStatus[] = [
    'disabled',
    'not_scheduled',
    'ready',
    'failed',
    'cancelled',
  ];

  for (const status of activeStatuses) {
    expect(incidentSpeechPreparationIsActive(status)).toBe(true);
  }
  for (const status of inactiveStatuses) {
    expect(incidentSpeechPreparationIsActive(status)).toBe(false);
  }

  expect(incidentSpeechPreparationsAreActive(preparations('queued', 'ready'))).toBe(true);
  expect(incidentSpeechPreparationsAreActive(preparations('ready', 'processing'))).toBe(true);
  expect(incidentSpeechPreparationsAreActive(preparations('ready', 'failed'))).toBe(false);
  expect(incidentSpeechPreparationsAreActive(undefined)).toBe(false);
});

test('keeps progress percentages safe for the native progress element', () => {
  expect(normalizeIncidentSpeechPreparationProgress(-8)).toBe(0);
  expect(normalizeIncidentSpeechPreparationProgress(49.6)).toBe(50);
  expect(normalizeIncidentSpeechPreparationProgress(180)).toBe(100);
  expect(normalizeIncidentSpeechPreparationProgress(Number.NaN)).toBe(0);
});

function preparations(
  availability: IncidentSpeechPreparationStatus,
  attendance: IncidentSpeechPreparationStatus,
): IncidentSpeechPreparations {
  return {
    availability: {
      phase: 'availability',
      status: availability,
      progress_percent: 0,
      error_code: null,
      updated_at: null,
    },
    attendance: {
      phase: 'attendance',
      status: attendance,
      progress_percent: 0,
      error_code: null,
      updated_at: null,
    },
  };
}
