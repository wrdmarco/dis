import { expect, test } from 'playwright/test';
import { currentLiveLocations, dispatchEtaLabel, isCurrentLiveLocation, liveLocationEtaLabel } from '../src/features/incidents/etaPresentation';
import type { IncidentLiveLocation } from '../src/types/api';

const now = Date.parse('2026-07-15T12:00:00Z');

test('distinguishes navigation ETAs, fallback estimates and legacy responses', () => {
  expect(dispatchEtaLabel(30, 'navigation')).toBe('Navigatie-ETA-ring 30 min');
  expect(dispatchEtaLabel(30, 'fallback')).toBe('Geschatte ETA-ring 30 min');
  expect(dispatchEtaLabel(30, 'unknown')).toBe('ETA-ring 30 min (bron onbekend)');
  expect(dispatchEtaLabel(30, undefined)).toBe('ETA-ring 30 min (bron onbekend)');
  expect(dispatchEtaLabel(null, 'navigation')).toBe('ETA onbekend');
});

test('rejects server-stale locations but trusts an explicit current server decision', () => {
  const stale = liveLocation({
    sharing_status: 'stale',
    location_is_current: true,
    recorded_at: '2026-07-15T11:54:59Z',
    eta_minutes: 12,
    eta_source: 'navigation',
  });

  expect(isCurrentLiveLocation(stale, now)).toBe(false);
  expect(liveLocationEtaLabel(stale, now)).toBe('Niet actueel');

  const outdatedDespiteCurrentFlag = liveLocation({
    sharing_status: 'shared',
    location_is_current: true,
    recorded_at: '2026-07-15T11:54:59Z',
    eta_minutes: 12,
    eta_source: 'navigation',
  });

  expect(isCurrentLiveLocation(outdatedDespiteCurrentFlag, now)).toBe(true);
  expect(liveLocationEtaLabel(outdatedDespiteCurrentFlag, now)).toBe('Navigatie: 12 min');

  expect(isCurrentLiveLocation(liveLocation({ location_is_current: true, recorded_at: null }), now)).toBe(true);

  const freshDespiteFalseFlag = liveLocation({
    sharing_status: 'shared',
    location_is_current: false,
    recorded_at: '2026-07-15T11:59:59Z',
  });

  expect(isCurrentLiveLocation(freshDespiteFalseFlag, now)).toBe(false);
});

test('labels current live navigation and fallback ETAs without upgrading an unknown source', () => {
  const current = liveLocation({ recorded_at: '2026-07-15T11:59:00Z' });

  expect(isCurrentLiveLocation(current, now)).toBe(true);
  expect(liveLocationEtaLabel({ ...current, eta_minutes: 9, eta_source: 'navigation' }, now)).toBe('Navigatie: 9 min');
  expect(liveLocationEtaLabel({ ...current, eta_minutes: 11, eta_source: 'fallback' }, now)).toBe('Schatting: 11 min');
  expect(liveLocationEtaLabel({ ...current, eta_minutes: 10 }, now)).toBe('ETA: 10 min (bron onbekend)');
});

test('uses the timestamp fallback only for legacy responses without a server current flag', () => {
  const legacyCurrent = liveLocation({
    location_is_current: undefined,
    recorded_at: '2026-07-15T11:59:00Z',
  });
  const legacyStale = liveLocation({
    location_is_current: undefined,
    recorded_at: '2026-07-15T11:54:59Z',
  });
  const legacyUnboundedFuture = liveLocation({
    location_is_current: undefined,
    recorded_at: '2026-07-15T12:03:00Z',
  });

  expect(isCurrentLiveLocation(legacyCurrent, now)).toBe(true);
  expect(isCurrentLiveLocation(legacyStale, now)).toBe(false);
  expect(isCurrentLiveLocation(legacyUnboundedFuture, now)).toBe(false);
});

test('keeps stale coordinates out of the live detail map marker set', () => {
  const current = liveLocation({ user_id: 'current', recorded_at: '2026-07-15T11:59:00Z' });
  const serverStale = liveLocation({
    user_id: 'server-stale',
    sharing_status: 'stale',
    location_is_current: false,
    recorded_at: '2026-07-15T11:59:30Z',
  });
  const legacyStale = liveLocation({
    user_id: 'legacy-stale',
    location_is_current: undefined,
    recorded_at: '2026-07-15T11:54:59Z',
  });

  expect(currentLiveLocations([serverStale, current, legacyStale], now).map((location) => location.user_id)).toEqual(['current']);
});

function liveLocation(overrides: Partial<IncidentLiveLocation>): IncidentLiveLocation {
  return {
    user_id: 'pilot-1',
    sharing_status: 'shared',
    location_is_current: true,
    latitude: 52.09,
    longitude: 5.12,
    recorded_at: '2026-07-15T11:59:00Z',
    ...overrides,
  };
}
