import { expect, test } from 'playwright/test';
import { presentIncidentTimelineItem } from '../src/features/incidents/incidentTimelinePresentation';
import type { IncidentTimelineItem } from '../src/types/api';

test('shows who added a control-room note without repeating the actor', () => {
  const presentation = presentIncidentTimelineItem(timelineItem({
    description: 'Kladblokregel toegevoegd door Noor de Vries',
    actor: { id: 'user-1', name: 'Noor de Vries' },
    actor_name: 'Noor de Vries',
    message: 'Politie vraagt om een tweede drone.',
  }));

  expect(presentation).toEqual({
    action: 'Kladblokregel toegevoegd door Noor de Vries',
    actorLabel: null,
    detail: 'Politie vraagt om een tweede drone.',
  });
});

test('shows the actor separately when the action description does not name them', () => {
  const presentation = presentIncidentTimelineItem(timelineItem({
    label: 'Incident bijgewerkt',
    description: 'Opkomstlocatie aangepast',
    actor: { id: 'user-2', name: 'Samira Jansen' },
  }));

  expect(presentation.action).toBe('Opkomstlocatie aangepast');
  expect(presentation.actorLabel).toBe('Door Samira Jansen');
  expect(presentation.detail).toBeNull();
});

test('uses the immutable actor-name snapshot after an account was removed', () => {
  const presentation = presentIncidentTimelineItem(timelineItem({
    actor: null,
    actor_name: 'Voormalig centralist',
  }));

  expect(presentation.actorLabel).toBe('Door Voormalig centralist');
});

test('keeps legacy timeline responses readable', () => {
  const presentation = presentIncidentTimelineItem(timelineItem({
    label: 'Meldkamer kladblok',
    message: 'Legacy kladblokregel',
  }));

  expect(presentation).toEqual({
    action: 'Meldkamer kladblok',
    actorLabel: null,
    detail: 'Legacy kladblokregel',
  });
});

test('does not render a description again as detail text', () => {
  const presentation = presentIncidentTimelineItem(timelineItem({
    description: 'Alarmering verstuurd door Alex',
    message: '  ALARMERING   VERSTUURD DOOR ALEX ',
  }));

  expect(presentation.action).toBe('Alarmering verstuurd door Alex');
  expect(presentation.detail).toBeNull();
});

function timelineItem(overrides: Partial<IncidentTimelineItem>): IncidentTimelineItem {
  return {
    id: 'timeline-1',
    type: 'internal_notes',
    label: 'Meldkamer kladblok',
    created_at: '2026-07-23T12:00:00Z',
    ...overrides,
  };
}
