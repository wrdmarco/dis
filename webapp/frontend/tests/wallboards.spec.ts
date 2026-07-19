import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { Wallboard, WallboardConfiguration, WallboardPage, WallboardState } from '../src/types/api';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  buildWallboardMapPresentation,
  clampRefreshSeconds,
  clampWallboardPageDuration,
  createWallboardPage,
  wallboardConfigurationCopy,
  wallboardIsOnline,
  wallboardPageMapConfiguration,
  wallboardStateIsStale,
} from '../src/features/wallboards/wallboardPresentation';

const now = Date.parse('2026-07-19T10:00:00Z');

function stateFixture(): WallboardState {
  return {
    generated_at: '2026-07-19T09:59:55Z',
    wallboard: {
      id: 'wallboard-1',
      name: 'Meldkamer',
      layout: 'fullscreen_map',
      configuration: {
        ...DEFAULT_WALLBOARD_CONFIGURATION,
        map: { ...DEFAULT_WALLBOARD_CONFIGURATION.map },
      },
      config_version: 1,
      control_version: 3,
      display: {
        mode: 'rotation',
        page_id: 'map-overview',
        incident_active: false,
        next_change_at: '2026-07-19T10:00:25Z',
      },
      updated_at: '2026-07-19T09:59:00Z',
    },
    map: {
      incidents: [
        { id: 'incident-1', reference: 'DIS-1', title: 'Operationeel', status: 'active', priority: 'high', is_test: false, location_label: 'Utrecht', latitude: 52.09, longitude: 5.12 },
        { id: 'incident-2', reference: 'TEST-2', title: 'Proefmelding', status: 'active', priority: 'normal', is_test: true, location_label: 'Arnhem', latitude: 51.98, longitude: 5.9 },
        { id: 'incident-3', reference: 'DIS-3', title: 'Gesloten', status: 'resolved', priority: 'normal', is_test: false, location_label: 'Breda', latitude: 51.58, longitude: 4.78 },
      ],
      command_centers: [{ id: 'center-1', name: 'Meldkamer', address: null, latitude: 52.1, longitude: 5.1 }],
      historical_incidents: [{ id: 'history-1', reference: 'DIS-H1', title: 'Historie', status: 'resolved', priority: 'low', location_label: null, latitude: 52.2, longitude: 5.2, closed_at: null }],
      live_locations: [
        {
          incident_id: 'incident-1',
          user_id: 'pilot-1',
          user: { id: 'pilot-1', name: 'Piloot Een' },
          sharing_status: 'shared',
          location_is_current: true,
          latitude: 52.08,
          longitude: 5.11,
          route: {
            source: 'navigation',
            duration_seconds: 600,
            distance_meters: 8000,
            geometry: { type: 'LineString', coordinates: [[5.11, 52.08], [5.12, 52.09]] },
          },
        },
        {
          incident_id: 'incident-1',
          user_id: 'pilot-stale',
          user: { id: 'pilot-stale', name: 'Verlopen piloot' },
          sharing_status: 'stale',
          location_is_current: false,
          latitude: 52.07,
          longitude: 5.1,
          route: null,
        },
      ],
    },
  };
}

test('filters wallboard data by server configuration and keeps only current pilot routes', () => {
  const presentation = buildWallboardMapPresentation(stateFixture());

  expect(presentation.models).toHaveLength(1);
  expect(presentation.models[0].incident.id).toBe('incident-1');
  expect(presentation.models[0].liveLocations).toHaveLength(1);
  expect(presentation.models[0].liveLocations[0].route?.points).toEqual([
    { longitude: 5.11, latitude: 52.08 },
    { longitude: 5.12, latitude: 52.09 },
  ]);
  expect(presentation.layers.commandCenters).toHaveLength(1);
  expect(presentation.layers.historicalIncidents).toHaveLength(0);
  expect(presentation.layers.pilotHomes).toEqual([]);
});

test('never exposes pilot locations or routes when live locations are disabled', () => {
  const state = stateFixture();
  state.wallboard.configuration.map.show_live_locations = false;
  state.wallboard.configuration.map.show_routes = true;

  const presentation = buildWallboardMapPresentation(state);
  expect(presentation.linkedUsers).toBe(0);
  expect(presentation.models[0].liveLocations).toEqual([]);
});

test('removes live pilot markers and routes when the feed is not current', () => {
  const presentation = buildWallboardMapPresentation(stateFixture(), false);

  expect(presentation.linkedUsers).toBe(0);
  expect(presentation.models[0].liveLocations).toEqual([]);
  expect(presentation.layers.pilotHomes).toEqual([]);
});

test('uses backend refresh limits and reports online and stale state predictably', () => {
  expect(clampRefreshSeconds(1)).toBe(5);
  expect(clampRefreshSeconds(600)).toBe(60);
  expect(clampRefreshSeconds(Number.NaN)).toBe(10);

  const wallboard: Wallboard = {
    id: 'wallboard-1',
    name: 'Meldkamer',
    layout: 'fullscreen_map',
    configuration: { ...DEFAULT_WALLBOARD_CONFIGURATION, map: { ...DEFAULT_WALLBOARD_CONFIGURATION.map } },
    is_enabled: true,
    config_version: 1,
    active_sessions_count: 1,
    last_seen_at: '2026-07-19T09:59:30Z',
  };
  expect(wallboardIsOnline(wallboard, now)).toBe(true);
  expect(wallboardIsOnline({ ...wallboard, is_enabled: false }, now)).toBe(false);
  expect(wallboardIsOnline({ ...wallboard, active_sessions_count: 0 }, now)).toBe(false);

  const state = stateFixture();
  expect(wallboardStateIsStale(state, now)).toBe(false);
  state.generated_at = '2026-07-19T09:58:00Z';
  expect(wallboardStateIsStale(state, now)).toBe(true);
});

test('normalizes legacy wallboard configuration into a safe page program', () => {
  const legacy = {
    theme: 'dark',
    refresh_seconds: 2,
    map: { ...DEFAULT_WALLBOARD_CONFIGURATION.map },
  } as unknown as WallboardConfiguration;

  const normalized = wallboardConfigurationCopy(legacy);

  expect(normalized.refresh_seconds).toBe(5);
  expect(normalized.pages).toEqual([{
    id: 'map-overview',
    type: 'map',
    name: 'Operationele kaart',
    duration_seconds: 30,
    options: {},
  }]);
  expect(normalized.rotation_enabled).toBe(true);
  expect(normalized.incident_override).toEqual({ enabled: false, page_id: 'map-overview' });
});

test('bounds page timing and builds typed pages without executable message markup', () => {
  expect(clampWallboardPageDuration(1)).toBe(5);
  expect(clampWallboardPageDuration(7200)).toBe(3600);
  expect(clampWallboardPageDuration(Number.NaN)).toBe(30);

  const page = createWallboardPage('message', 2);
  expect(page.id).toMatch(/^page-/);
  expect(page.type).toBe('message');
  expect(page.duration_seconds).toBe(30);
  expect(page.options).toEqual({ body: '' });
});

test('dedicated incident pages remain operational when the global map layer is hidden', () => {
  const state = stateFixture();
  state.wallboard.configuration.map.show_active_incidents = false;
  const page: WallboardPage = {
    id: 'incidents',
    type: 'incident_list',
    name: 'Actieve meldingen',
    duration_seconds: 20,
    options: { show_test_incidents: false },
  };

  const pageConfiguration = wallboardPageMapConfiguration(state.wallboard.configuration, page);
  expect(pageConfiguration.show_active_incidents).toBe(true);
  expect(buildWallboardMapPresentation(state, true, pageConfiguration).models.map((model) => model.incident.id)).toEqual(['incident-1']);

  page.options.show_test_incidents = true;
  const withTests = wallboardPageMapConfiguration(state.wallboard.configuration, page);
  expect(buildWallboardMapPresentation(state, true, withTests).models.map((model) => model.incident.id)).toEqual(['incident-1', 'incident-2']);
});

test('exposes admin and kiosk routes with separate trust boundaries', () => {
  const adminRoute = readFileSync(new URL('../app/wallboards/page.tsx', import.meta.url), 'utf8');
  const kioskRoute = readFileSync(new URL('../app/wallboard/page.tsx', import.meta.url), 'utf8');
  const providers = readFileSync(new URL('../app/providers.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');
  const admin = readFileSync(new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url), 'utf8');

  expect(adminRoute).toContain("permissions={['wallboards.manage']}");
  expect(navigation).toContain("to: '/wallboards', label: 'Wallboards'");
  expect(navigation).toContain("permissions: ['wallboards.manage']");
  expect(kioskRoute).not.toContain('ProtectedShell');
  expect(providers).toContain("pathname === '/wallboard'");
  expect(kiosk).not.toContain('useAuth');
  expect(kiosk).not.toContain('localStorage');
  expect(kiosk).not.toContain('sessionStorage');
  expect(kiosk).not.toContain('URLSearchParams');
  expect(kiosk).not.toMatch(/wallboardApi\.(?:get|post|patch|delete)\([^\n]*\/incidents\//);
  expect(kiosk.match(/\/wallboard\/state/g)).toHaveLength(1);
  expect(kiosk.match(/\/wallboard\/control/g)).toHaveLength(1);
  expect(kiosk).toContain('CONTROL_POLL_MILLISECONDS = 2000');
  expect(kiosk).not.toContain('nextControlPollDelay');
  expect(kiosk).not.toContain('dangerouslySetInnerHTML');
  expect(kiosk).toContain(".post<WallboardPairingRequest>('/wallboard/pairing/start')");
  expect(kiosk).toContain("wallboardApi.post<WallboardPairingStatus>('/wallboard/pairing/status'");
  expect(kiosk).not.toContain("wallboardApi.post('/wallboard/pair'");
  expect(kiosk).toContain('requestWallboardPairing()');
  expect(kiosk).toContain('pairingStartInFlight');
  expect(kiosk).not.toContain('pairingStartAttemptRef');
  expect(kiosk).not.toContain('expiredPairingCodeRef');
  expect(apiTypes).toContain("status: 'pending' | 'approved';");
  expect(apiTypes).toContain('expires_at: string | null;');
  expect(kiosk).toContain('Koppel deze tv');
  expect(kiosk).not.toContain('autoFocus');
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/pair`");
  expect(admin).toContain('Code op tv');
  expect(admin).not.toContain('/pairing-code');
  expect(admin).not.toContain('WallboardPairingCode');
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/display`");
  expect(admin).toContain('expected_control_version');
  expect(admin).toContain('expected_config_version');
  expect(admin).toContain("error.status === 409");
});

test('reuses the extracted map canvas for the operational map and kiosk', () => {
  const operationalMap = readFileSync(new URL('../src/features/incidents/IncidentMapPage.tsx', import.meta.url), 'utf8');
  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');

  expect(operationalMap).toContain('<OperationalMapCanvas');
  expect(kiosk).toContain('<OperationalMapCanvas');
  expect(operationalMap).not.toContain('function OperationsMap');
});
