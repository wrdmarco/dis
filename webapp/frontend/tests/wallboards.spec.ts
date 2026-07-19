import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type {
  Wallboard,
  WallboardConfiguration,
  WallboardControlState,
  WallboardPage,
  WallboardState,
  WallboardTransientAlert,
} from '../src/types/api';
import {
  formatWallboardClock,
  normalizeWallboardState,
  stabilizeWallboardRotationDeadline,
} from '../src/features/wallboards/WallboardDisplayPage';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  buildWallboardMapPresentation,
  clampRefreshSeconds,
  clampWallboardPageDuration,
  createWallboardPage,
  createWallboardTickerSource,
  formatWallboardPilotAvailability,
  normalizeWallboardDisplayProfile,
  selectRecentWallboardIncidents,
  wallboardConfigurationCopy,
  wallboardDisplayProfileLabel,
  wallboardIsOnline,
  wallboardPageMapConfiguration,
  wallboardPlaylistUsageCount,
  wallboardStateIsStale,
  wallboardTickerDurationSeconds,
  wallboardTransientAlertIsActive,
} from '../src/features/wallboards/wallboardPresentation';

const now = Date.parse('2026-07-19T10:00:00Z');

function stateFixture(): WallboardState {
  return {
    generated_at: '2026-07-19T09:59:55Z',
    wallboard: {
      id: 'wallboard-1',
      name: 'Meldkamer',
      layout: 'fullscreen_map',
      display_profile: 'auto',
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
    operational_summary: {
      pilot_availability: { available: 3, total: 8 },
      active_alarm: null,
      recent_incidents: [
        { id: 'recent-older', reference: 'DIS-101', title: 'Oudere melding', status: 'resolved', priority: 'normal', is_test: false, location_label: null, closed_at: '2026-07-18T12:00:00Z' },
        { id: 'recent-test', reference: 'TEST-102', title: 'Proefmelding', status: 'cancelled', priority: 'low', is_test: true, location_label: 'Zwolle', closed_at: '2026-07-19T09:00:00Z' },
        { id: 'recent-latest', reference: 'DIS-103', title: 'Laatste melding zonder kaartpunt', status: 'resolved', priority: 'high', is_test: false, location_label: 'Amersfoort', closed_at: '2026-07-19T09:30:00Z' },
      ],
      transient_alert: null,
    },
    ticker: {
      items: [
        { source_id: 'internal-1', source_type: 'internal', source_label: 'Meldkamer', text: 'Windwaarschuwing actief.' },
      ],
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

function transientAlert(overrides: Partial<WallboardTransientAlert> = {}): WallboardTransientAlert {
  return {
    dispatch_id: 'dispatch-1',
    incident_id: 'incident-1',
    reference: 'DIS-1',
    title: 'Nieuwe inzet',
    priority: 'high',
    location_label: 'Utrecht',
    received_at: '2026-07-19T09:59:50Z',
    expires_at: '2026-07-19T10:05:00Z',
    is_test: false,
    ...overrides,
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
    display_profile: 'auto',
    configuration: { ...DEFAULT_WALLBOARD_CONFIGURATION, map: { ...DEFAULT_WALLBOARD_CONFIGURATION.map } },
    playlist_id: 'playlist-1',
    playlist: { id: 'playlist-1', name: 'Meldkamer', version: 1 },
    is_enabled: true,
    config_version: 1,
    active_sessions_count: 1,
    last_seen_at: '2026-07-19T09:59:30Z',
  };
  expect(wallboardIsOnline(wallboard, now)).toBe(true);
  expect(wallboardIsOnline({ ...wallboard, is_enabled: false }, now)).toBe(false);
  expect(wallboardIsOnline({ ...wallboard, active_sessions_count: 0 }, now)).toBe(false);
  expect(wallboardIsOnline({ ...wallboard, last_seen_at: '2026-07-19T09:58:59Z' }, now)).toBe(true);
  expect(wallboardIsOnline({ ...wallboard, last_seen_at: '2026-07-19T09:58:29Z' }, now)).toBe(false);
  expect(wallboardIsOnline({ ...wallboard, is_online: false }, now)).toBe(false);
  expect(wallboardIsOnline({ ...wallboard, is_online: true, last_seen_at: '2026-07-19T08:00:00Z' }, now)).toBe(true);
  expect(wallboardIsOnline({ ...wallboard, is_online: true, is_enabled: false }, now)).toBe(false);

  const state = stateFixture();
  expect(wallboardStateIsStale(state, now)).toBe(false);
  state.generated_at = '2026-07-19T09:58:00Z';
  expect(wallboardStateIsStale(state, now)).toBe(true);
});

test('normalizes display profiles safely and keeps operator-facing labels explicit', () => {
  expect(normalizeWallboardDisplayProfile('auto')).toBe('auto');
  expect(normalizeWallboardDisplayProfile('1080p')).toBe('1080p');
  expect(normalizeWallboardDisplayProfile('4k')).toBe('4k');
  expect(normalizeWallboardDisplayProfile('8k')).toBe('auto');
  expect(normalizeWallboardDisplayProfile(undefined)).toBe('auto');
  expect(wallboardDisplayProfileLabel('auto')).toBe('Auto');
  expect(wallboardDisplayProfileLabel('1080p')).toBe('1080p');
  expect(wallboardDisplayProfileLabel('4k')).toBe('4K');

  const legacyState = stateFixture();
  (legacyState.wallboard as WallboardState['wallboard'] & { display_profile?: unknown }).display_profile = '8k';
  expect(normalizeWallboardState(legacyState).wallboard.display_profile).toBe('auto');
  delete (legacyState.wallboard as Partial<WallboardState['wallboard']>).display_profile;
  expect(normalizeWallboardState(legacyState).wallboard.display_profile).toBe('auto');
});

test('normalizes legacy wallboard configuration into a safe page program', () => {
  const legacy = {
    theme: 'dark',
    refresh_seconds: 2,
    map: { ...DEFAULT_WALLBOARD_CONFIGURATION.map },
  } as unknown as WallboardConfiguration;

  const normalized = wallboardConfigurationCopy(legacy);

  expect(normalized.refresh_seconds).toBe(5);
  expect(normalized.ticker).toEqual({ enabled: false, sources: [] });
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

test('presents operational pilot availability independently from live location sharing', () => {
  expect(formatWallboardPilotAvailability({ available: 3, total: 8 })).toBe('Operationeel beschikbaar 3 van 8 piloten');
  expect(formatWallboardPilotAvailability({ available: 1, total: 1 })).toBe('Operationeel beschikbaar 1 van 1 piloot');
  expect(formatWallboardPilotAvailability({ available: 9, total: 4 })).toBe('Operationeel beschikbaar 4 van 4 piloten');
  expect(formatWallboardPilotAvailability({ available: 3, total: 8 }, false)).toBe('Operationele beschikbaarheid onbekend');
  expect(formatWallboardPilotAvailability({ available: Number.NaN, total: 8 })).toBe('Operationele beschikbaarheid onbekend');
});

test('selects coordinate-independent recent incidents and includes tests only when configured', () => {
  const incidents = stateFixture().operational_summary.recent_incidents;

  expect(selectRecentWallboardIncidents(incidents).map((incident) => incident.id)).toEqual([
    'recent-latest',
    'recent-older',
  ]);
  expect(selectRecentWallboardIncidents(incidents, true).map((incident) => incident.id)).toEqual([
    'recent-latest',
    'recent-test',
    'recent-older',
  ]);
  expect(selectRecentWallboardIncidents(incidents, true, 2).map((incident) => incident.id)).toEqual([
    'recent-latest',
    'recent-test',
  ]);
  expect(incidents.find((incident) => incident.id === 'recent-latest')).not.toHaveProperty('latitude');
});

test('uses server transient expiry for both real and test alert focus', () => {
  expect(wallboardTransientAlertIsActive(transientAlert(), now)).toBe(true);
  expect(wallboardTransientAlertIsActive(transientAlert({ is_test: true }), now)).toBe(true);
  expect(wallboardTransientAlertIsActive(transientAlert(), Date.parse('2026-07-19T10:05:00Z'))).toBe(false);
  expect(wallboardTransientAlertIsActive(transientAlert({ expires_at: 'invalid' }), now)).toBe(false);
  expect(wallboardTransientAlertIsActive(null, now)).toBe(false);
});

test('formats a seconds clock in the wallboard timezone', () => {
  expect(formatWallboardClock(Date.parse('2026-07-19T10:00:05Z'))).toBe('12:00:05');
  expect(formatWallboardClock(Number.NaN)).toBe('--:--:--');
});

test('creates bounded ticker sources and readable motion timing', () => {
  const rss = createWallboardTickerSource('rss', 1);
  const internal = createWallboardTickerSource('internal', 2);

  expect(rss).toMatchObject({ type: 'rss', label: 'Nieuws- of weer-RSS', url: '' });
  expect(internal).toMatchObject({ type: 'internal', label: 'Intern bericht', text: '' });
  expect(rss.id).toMatch(/^ticker-/);
  expect(wallboardTickerDurationSeconds([])).toBe(24);
  expect(wallboardTickerDurationSeconds(stateFixture().ticker.items)).toBeGreaterThanOrEqual(24);
  expect(wallboardTickerDurationSeconds([{ ...stateFixture().ticker.items[0], text: 'x'.repeat(2000) }])).toBe(120);
});

test('reports linked playlist usage from the definitive management resource field', () => {
  expect(wallboardPlaylistUsageCount({ linked_wallboards_count: 3 })).toBe(3);
  expect(wallboardPlaylistUsageCount({ linked_wallboards_count: -2 })).toBe(0);
  expect(wallboardPlaylistUsageCount({ linked_wallboards_count: Number.NaN })).toBe(0);
});

test('does not postpone a rotation deadline within the same active server phase', () => {
  const current: WallboardControlState = {
    config_version: 4,
    control_version: 7,
    display_profile: 'auto',
    transient_alert: null,
    display: {
      mode: 'rotation',
      page_id: 'map-overview',
      incident_active: false,
      next_change_at: '2026-07-19T10:00:05Z',
    },
  };
  const repeatedPoll: WallboardControlState = {
    ...current,
    display: {
      ...current.display,
      next_change_at: '2026-07-19T10:00:07Z',
    },
  };

  expect(stabilizeWallboardRotationDeadline(current, repeatedPoll, Date.parse('2026-07-19T10:00:03Z')).display.next_change_at)
    .toBe('2026-07-19T10:00:05Z');
  expect(stabilizeWallboardRotationDeadline(current, {
    ...repeatedPoll,
    display: { ...repeatedPoll.display, next_change_at: '2026-07-19T10:00:04Z' },
  }, Date.parse('2026-07-19T10:00:03Z')).display.next_change_at).toBe('2026-07-19T10:00:04Z');
  expect(stabilizeWallboardRotationDeadline(current, {
    ...repeatedPoll,
    display: { ...repeatedPoll.display, page_id: 'incident-list' },
  }, Date.parse('2026-07-19T10:00:03Z')).display.next_change_at).toBe('2026-07-19T10:00:07Z');
  expect(stabilizeWallboardRotationDeadline(current, {
    ...repeatedPoll,
    control_version: 8,
  }, Date.parse('2026-07-19T10:00:03Z')).display.next_change_at).toBe('2026-07-19T10:00:07Z');
  expect(stabilizeWallboardRotationDeadline(
    current,
    repeatedPoll,
    Date.parse('2026-07-19T10:00:06Z'),
  ).display.next_change_at).toBe('2026-07-19T10:00:07Z');
});

test('does not lose an unexpired transient alert to an older state response', () => {
  const current: WallboardControlState = {
    config_version: 4,
    control_version: 7,
    display_profile: 'auto',
    transient_alert: transientAlert(),
    display: {
      mode: 'rotation',
      page_id: 'map-overview',
      incident_active: false,
      next_change_at: null,
    },
  };
  const staleStateResponse: WallboardControlState = { ...current, transient_alert: null };

  expect(stabilizeWallboardRotationDeadline(current, staleStateResponse, now).transient_alert?.dispatch_id)
    .toBe('dispatch-1');
  expect(stabilizeWallboardRotationDeadline(
    current,
    staleStateResponse,
    Date.parse('2026-07-19T10:05:00Z'),
  ).transient_alert).toBeNull();

  const newer = transientAlert({ dispatch_id: 'dispatch-2', received_at: '2026-07-19T10:00:01Z' });
  expect(stabilizeWallboardRotationDeadline(current, { ...current, transient_alert: newer }, now).transient_alert?.dispatch_id)
    .toBe('dispatch-2');
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
  const configurationEditor = readFileSync(new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url), 'utf8');
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

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
  expect(apiTypes).toContain('pilot_availability: WallboardPilotAvailability;');
  expect(apiTypes).toContain('recent_incidents: WallboardStateRecentIncident[];');
  expect(apiTypes).toContain('transient_alert: WallboardTransientAlert | null;');
  expect(apiTypes).toContain('playlist_id: string;');
  expect(apiTypes).toContain("export type WallboardDisplayProfile = 'auto' | '1080p' | '4k';");
  expect(apiTypes).toContain('display_profile: WallboardDisplayProfile;');
  expect(apiTypes).toContain('playlist: WallboardPlaylistReference;');
  expect(apiTypes).toContain('linked_wallboards_count: number;');
  expect(kiosk).toContain('Koppel deze tv');
  expect(kiosk).toContain('Operationeel beschikbaar');
  expect(kiosk).toContain('Laatste meldingen');
  expect(kiosk).toContain('Proefalarmering');
  expect(kiosk).toContain('showTransientAlert');
  expect(kiosk).toContain('formatWallboardClock(clock)');
  expect(kiosk).toContain('wallboard-display__ticker');
  expect(kiosk).not.toContain('autoFocus');
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/pair`");
  expect(admin).toContain('Code op tv');
  expect(admin).not.toContain('/pairing-code');
  expect(admin).not.toContain('WallboardPairingCode');
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/display`");
  expect(admin).toContain('expected_control_version');
  expect(admin).toContain('expected_config_version');
  expect(admin).toContain('display_profile: draftDisplayProfile');
  expect(admin).toContain('Auto (aanbevolen)');
  expect(admin).toContain('TV-, HDMI- of OS-uitvoerresolutie');
  expect(admin).toContain("error.status === 409");
  expect(configurationEditor).toContain('Nieuws- of weer-RSS');
  expect(configurationEditor).toContain("addTickerSource('internal')");
  expect(styles).toContain('.wallboard-display__ticker-track');
  expect(styles).toContain('.wallboard-display__alarm--test');
  expect(kiosk).toContain('wallboard-display--profile-${displayProfile}');
  expect(kiosk).toContain('data-display-profile={displayProfile}');
  expect(styles).toContain('.wallboard-display--profile-4k .wallboard-display__header');
  expect(styles).toContain('.wallboard-display--profile-auto .wallboard-display__header');
  expect(styles).not.toContain('devicePixelRatio');
  expect(styles).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*\.wallboard-display__ticker-track/);
});

test('separates screen control from shared playlist content management', () => {
  const admin = readFileSync(new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url), 'utf8');
  const configurationEditor = readFileSync(new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url), 'utf8');
  const createScreen = admin.slice(admin.indexOf('async function createScreen'), admin.indexOf('async function createPlaylist'));
  const saveScreen = admin.slice(admin.indexOf('async function saveScreen'), admin.indexOf('async function controlDisplay'));

  expect(admin).toContain("useApiResource<WallboardPlaylist[]>('/admin/wallboard-playlists')");
  expect(admin).toContain("api.post<WallboardPlaylist>('/admin/wallboard-playlists'");
  expect(admin).toContain("`/admin/wallboard-playlists/${playlist.id}`");
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/playlist`");
  expect(admin).toContain('expected_version: playlist.version');
  expect(admin).toContain('?expected_version=${encodeURIComponent(String(playlist.version))}');
  expect(admin).toContain('wallboardPlaylistUsageCount');
  expect(admin).toContain('Gedeelde playlist · {usageCount} schermen');
  expect(admin).toContain('await onReloadAll();');
  expect(createScreen).toContain("...(newScreenPlaylistId === '' ? {} : { playlist_id: newScreenPlaylistId })");
  expect(createScreen).toContain('display_profile: newScreenDisplayProfile');
  expect(createScreen).not.toContain('configuration:');
  expect(saveScreen).toContain('name,');
  expect(saveScreen).toContain('is_enabled: draftEnabled');
  expect(saveScreen).toContain('display_profile: draftDisplayProfile');
  expect(saveScreen).toContain('expected_config_version: wallboard.config_version');
  expect(saveScreen).not.toContain('configuration');
  expect(configurationEditor).toContain('Weergave en ritme');
  expect(configurationEditor).toContain('Gegevens en kaartlagen');
  expect(configurationEditor).toContain('Pagina’s en volgorde');
  expect(configurationEditor).toContain('Automatisch bij een actief incident');
  expect(configurationEditor).toContain('Onderticker');
});

test('keeps explicit screen profiles fluid at Full HD and Ultra HD viewports', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
  const measurements: Array<{ overflow: boolean; titleFontSize: number; profile: string | undefined }> = [];
  const cases = [
    { profile: '1080p', width: 1920, height: 1080 },
    { profile: '4k', width: 3840, height: 2160 },
  ] as const;

  for (const screen of cases) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    await page.setContent(`
      <style>
        ${styles}
        html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }
      </style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}" data-display-profile="${screen.profile}">
        <header class="wallboard-display__header">
          <div>
            <span class="wallboard-display__live"><i></i>Live</span>
            <span class="wallboard-display__titles"><small>Meldkamer noord</small><h1>Operationele kaart met lange paginanaam</h1></span>
            <span class="wallboard-display__mode">Automatische rotatie</span>
          </div>
          <div class="wallboard-display__controls">
            <time class="wallboard-display__clock"><span>12:34:56</span><small>zondag 19 juli</small></time>
            <button class="wallboard-display__control" type="button">Volledig scherm</button>
          </div>
        </header>
        <section class="wallboard-display__page">
          <section class="wallboard-display__summary"><div><small>Beschikbaar</small><strong>8 van 12</strong></div></section>
          <div class="wallboard-display__content wallboard-display__content--with-list">
            <section class="wallboard-display__map"></section>
            <aside class="wallboard-display__incidents"><header>Incidenten <strong>2</strong></header><div><article><h2>Actieve melding</h2><p>Utrecht</p></article></div></aside>
          </div>
        </section>
        <footer class="wallboard-display__footer"><span>Pagina 1 van 2</span><span>Scherm blijft actief</span></footer>
        <section class="wallboard-display__ticker"><strong class="wallboard-display__ticker-label">Actueel</strong><div class="wallboard-display__ticker-viewport"><span class="wallboard-display__ticker-item">Intern bericht</span></div></section>
      </main>
    `);

    measurements.push(await page.locator('.wallboard-display').evaluate((element) => ({
      overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
        || element.scrollWidth > element.clientWidth,
      titleFontSize: Number.parseFloat(getComputedStyle(element.querySelector('h1')!).fontSize),
      profile: (element as HTMLElement).dataset.displayProfile,
    })));
  }

  expect(measurements[0]).toMatchObject({ overflow: false, profile: '1080p' });
  expect(measurements[1]).toMatchObject({ overflow: false, profile: '4k' });
  expect(measurements[1].titleFontSize).toBeGreaterThan(measurements[0].titleFontSize);
});

test('reuses the extracted map canvas for the operational map and kiosk', () => {
  const operationalMap = readFileSync(new URL('../src/features/incidents/IncidentMapPage.tsx', import.meta.url), 'utf8');
  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');

  expect(operationalMap).toContain('<OperationalMapCanvas');
  expect(kiosk).toContain('<OperationalMapCanvas');
  expect(operationalMap).not.toContain('function OperationsMap');
});
