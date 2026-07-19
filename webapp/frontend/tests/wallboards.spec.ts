import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type {
  Wallboard,
  WallboardConfiguration,
  WallboardControlState,
  WallboardFocusState,
  WallboardPage,
  WallboardState,
  WallboardTransientAlert,
} from '../src/types/api';
import {
  formatWallboardClock,
  normalizeWallboardMaintenanceNotice,
  normalizeWallboardNewsState,
  normalizeWallboardState,
  stabilizeWallboardRotationDeadline,
  wallboardFocusPilotCounts,
  wallboardMaintenanceNoticeIsActive,
  wallboardRefreshDecision,
  wallboardNewsCarouselIndex,
  wallboardTickerIsVisible,
} from '../src/features/wallboards/WallboardDisplayPage';
import {
  DEFAULT_WALLBOARD_CONFIGURATION,
  buildWallboardMapPresentation,
  clampRefreshSeconds,
  clampWallboardFocusDuration,
  clampWallboardPageDuration,
  clampWallboardNewsMaxItems,
  clampWallboardNewsItemDuration,
  clampWallboardRssMaxItems,
  countActiveOperationalWallboardIncidents,
  createWallboardCustomNewsSource,
  createWallboardPage,
  createWallboardTickerSource,
  formatWallboardPilotAvailability,
  normalizeWallboardDisplayProfile,
  normalizeWallboardCustomNewsSources,
  normalizeWallboardNewsSources,
  requestedWallboardScreenSelection,
  selectRecentWallboardIncidents,
  wallboardConfigurationCopy,
  wallboardDisplayProfileLabel,
  wallboardFocusKindLabel,
  wallboardIsOnline,
  wallboardPageMapConfiguration,
  wallboardPlaylistUsageCount,
  wallboardStateIsStale,
  wallboardTickerDurationSeconds,
  wallboardTransientAlertIsActive,
  normalizeWallboardVideoUrl,
  wallboardVideoEmbedUrl,
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
      refresh_version: 2,
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
    news: {
      pages: {},
      generated_at: '2026-07-19T09:55:00Z',
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

function focusState(overrides: Partial<WallboardFocusState> = {}): WallboardFocusState {
  return {
    kind: 'real_alarm',
    focus_id: 'focus-1',
    dispatch_id: 'dispatch-1',
    incident_id: 'incident-1',
    reference: 'DIS-1',
    title: 'Nieuwe inzet',
    priority: 'high',
    location_label: 'Utrecht',
    started_at: '2026-07-19T09:59:50Z',
    expires_at: null,
    visible: true,
    playlist_page_id: null,
    next_change_at: '2026-07-19T10:00:20Z',
    pilot_counts: { available: 3, relevant: 8, contacted: 6 },
    responses: {
      counts: { targeted: 8, contacted: 8, pending: 5, accepted: 2, declined: 1, no_response: 0 },
      items: [
        { name: 'Piloot Een', response_status: 'accepted', responded_at: '2026-07-19T09:59:58Z' },
      ],
      coming: [
        { name: 'Piloot Een', response_status: 'accepted', responded_at: '2026-07-19T09:59:58Z', eta_minutes: 9, eta_source: 'osrm' },
      ],
    },
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
    refresh_version: 0,
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
  expect(normalized.focus).toEqual({
    preannouncement: { enabled: true, duration_seconds: 120, show_response_feed: true },
    real_alarm: { enabled: true, duration_seconds: 30, show_response_feed: true },
    test_alarm: { enabled: true, duration_seconds: 300, show_response_feed: true },
  });
});

test('bounds focus timing and labels all three server focus types explicitly', () => {
  expect(clampWallboardFocusDuration(1, 120)).toBe(5);
  expect(clampWallboardFocusDuration(7200, 30)).toBe(3600);
  expect(clampWallboardFocusDuration(Number.NaN, 300)).toBe(300);
  expect(wallboardFocusKindLabel('preannouncement')).toBe('Vooraankondiging');
  expect(wallboardFocusKindLabel('real_alarm')).toBe('Alarmering');
  expect(wallboardFocusKindLabel('test_alarm')).toBe('Proefalarmering');
});

test('prefers focus-specific pilot counts and falls back defensively to the operational summary', () => {
  expect(wallboardFocusPilotCounts(
    { available: 5, relevant: 9, contacted: 7 },
    { available: 2, total: 4 },
  )).toEqual({ available: 5, relevant: 9, contacted: 7 });
  expect(wallboardFocusPilotCounts(
    { available: 12, relevant: 9, contacted: 10 },
    { available: 2, total: 4 },
  )).toEqual({ available: 9, relevant: 9, contacted: 10 });
  expect(wallboardFocusPilotCounts(
    { available: Number.NaN, relevant: 9, contacted: 7 },
    { available: 3, total: 8 },
  )).toEqual({ available: 3, relevant: 8, contacted: 0 });
  expect(wallboardFocusPilotCounts(undefined, { available: 3, total: 8 }, false)).toBeNull();
  expect(wallboardFocusPilotCounts(undefined, { available: Number.NaN, total: 8 })).toBeNull();
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

  const summary = createWallboardPage('summary', 3);
  expect(summary.options).toEqual({});

  const news = createWallboardPage('news', 4);
  expect(news).toMatchObject({
    type: 'news',
    name: 'Dronenieuws',
    options: { sources: ['ndt', 'dronewatch'], custom_sources: [], max_items: 6, item_duration_seconds: 12 },
  });
  expect(clampWallboardNewsMaxItems(0)).toBe(1);
  expect(clampWallboardNewsMaxItems(99)).toBe(12);
  expect(clampWallboardNewsMaxItems(Number.NaN)).toBe(6);
  expect(clampWallboardNewsItemDuration(1)).toBe(5);
  expect(clampWallboardNewsItemDuration(600)).toBe(300);
  expect(clampWallboardNewsItemDuration(Number.NaN)).toBe(12);
  expect(wallboardNewsCarouselIndex(12, 10, 0)).toBe(0);
  expect(wallboardNewsCarouselIndex(12, 10, 9_999)).toBe(0);
  expect(wallboardNewsCarouselIndex(12, 10, 10_000)).toBe(1);
  expect(wallboardNewsCarouselIndex(12, 10, 120_000)).toBe(0);
  expect(wallboardNewsCarouselIndex(0, 10, 120_000)).toBe(0);
  expect(normalizeWallboardNewsSources(['dronewatch', 'dronewatch', 'unknown'])).toEqual(['dronewatch']);
  expect(normalizeWallboardNewsSources([])).toEqual(['ndt', 'dronewatch']);
  expect(normalizeWallboardNewsSources([], true)).toEqual([]);

  const video = createWallboardPage('video', 5);
  expect(video).toMatchObject({ type: 'video', name: 'Promovideo', options: { url: '' } });
});

test('canonicalizes only supported wallboard video URLs and builds muted autoplay embeds', () => {
  expect(normalizeWallboardVideoUrl('https://www.youtube.com/watch?v=dQw4w9WgXcQ'))
    .toBe('https://www.youtube.com/embed/dQw4w9WgXcQ');
  expect(normalizeWallboardVideoUrl('https://youtu.be/dQw4w9WgXcQ'))
    .toBe('https://www.youtube.com/embed/dQw4w9WgXcQ');
  expect(normalizeWallboardVideoUrl('https://vimeo.com/123456789'))
    .toBe('https://player.vimeo.com/video/123456789');
  expect(normalizeWallboardVideoUrl('https://example.com/video/123')).toBeNull();
  expect(normalizeWallboardVideoUrl('javascript:alert(1)')).toBeNull();

  const youtubeEmbed = wallboardVideoEmbedUrl('https://www.youtube.com/embed/dQw4w9WgXcQ');
  expect(youtubeEmbed).toContain('autoplay=1');
  expect(youtubeEmbed).toContain('mute=1');
  expect(youtubeEmbed).toContain('playlist=dQw4w9WgXcQ');
  expect(wallboardVideoEmbedUrl('https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=0')).toBeNull();
});

test('normalizes up to eight safe custom RSS news sources and supports a custom-only page', () => {
  const created = createWallboardCustomNewsSource(1);
  expect(created).toMatchObject({ label: 'Eigen RSS-bron 1', url: '' });
  expect(created.id).toMatch(/^rss-[A-Za-z0-9-]+$/);

  const customSources = normalizeWallboardCustomNewsSources([
    { id: 'luchtvaart', label: ' Luchtvaartnieuws ', url: 'https://voorbeeld.nl/feed.xml' },
    { id: 'luchtvaart', label: 'Dubbel', url: 'https://voorbeeld.nl/anders.xml' },
    { id: 'onveilig', label: 'Onveilig', url: 'http://voorbeeld.nl/feed.xml' },
    { id: 'credentials', label: 'Credentials', url: 'https://naam:wachtwoord@voorbeeld.nl/feed.xml' },
  ]);
  expect(customSources).toEqual([{
    id: 'luchtvaart',
    label: 'Luchtvaartnieuws',
    url: 'https://voorbeeld.nl/feed.xml',
  }]);

  const configuration = wallboardConfigurationCopy({
    ...DEFAULT_WALLBOARD_CONFIGURATION,
    pages: [{
      id: 'news-custom',
      type: 'news',
      name: 'Eigen nieuws',
      duration_seconds: 30,
      options: { sources: [], custom_sources: customSources, max_items: 4, item_duration_seconds: 18 },
    }],
  });
  expect(configuration.pages[0].options).toEqual({
    sources: [],
    custom_sources: customSources,
    max_items: 4,
    item_duration_seconds: 18,
  });
  expect(configuration.pages[0].type).toBe('news');
});

test('never normalizes or renders a drone-news page as the operational map', () => {
  const normalized = wallboardConfigurationCopy({
    ...DEFAULT_WALLBOARD_CONFIGURATION,
    pages: [{
      id: 'drone-news',
      type: 'news',
      name: 'Drone nieuws',
      duration_seconds: 30,
      options: { sources: ['ndt', 'dronewatch'], custom_sources: [], max_items: 6 },
    }],
  });
  expect(normalized.pages[0]).toMatchObject({ id: 'drone-news', type: 'news', name: 'Drone nieuws' });

  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');
  const newsBranch = kiosk.indexOf("if (page.type === 'news')");
  const mapCanvas = kiosk.indexOf('<OperationalMapCanvas', newsBranch);
  expect(newsBranch).toBeGreaterThan(-1);
  expect(mapCanvas).toBeGreaterThan(newsBranch);
  expect(kiosk.slice(newsBranch, mapCanvas)).toContain('<WallboardNewsPage');
  expect(kiosk.slice(newsBranch, mapCanvas)).toContain('state.news.pages[page.id]');
});

test('normalizes page-scoped news content and rejects unsafe article links', () => {
  const normalized = normalizeWallboardNewsState({
    generated_at: '2026-07-19T09:59:00Z',
    pages: {
      'news-main': {
        fallback_used: true,
        lookback_days: 99,
        items: [
          {
            id: 'article-1',
            source: 'dronewatch',
            source_id: 'dronewatch',
            source_label: 'Dronewatch',
            title: ' Nieuwe Europese droneontwikkelingen ',
            excerpt: 'Een inhoudelijke samenvatting voor het wallboard.',
            url: 'https://www.dronewatch.nl/nieuwsbericht/',
            image_url: '/api/wallboard/news-images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            published_at: '2026-07-18T08:00:00Z',
          },
          {
            id: 'unsafe',
            source: 'ndt',
            source_id: 'ndt',
            source_label: 'NDT',
            title: 'Onveilig',
            excerpt: 'Mag niet zichtbaar worden.',
            url: 'javascript:alert(1)',
            published_at: '2026-07-18T08:00:00Z',
          },
          {
            id: 'custom-article',
            source: 'custom',
            source_id: 'luchtvaart',
            source_label: 'Luchtvaartnieuws',
            title: 'Nieuwe Europese luchtruimregels',
            excerpt: 'Samenvatting uit een eigen RSS-bron.',
            url: 'https://voorbeeld.nl/nieuws/europese-regels',
            image_url: 'https://tracking.example.org/raw-image.jpg',
            published_at: '2026-07-17T08:00:00Z',
          },
          {
            id: 'custom-without-id',
            source: 'custom',
            source_label: 'Geen geldige custom bron',
            title: 'Wordt geweigerd',
            excerpt: 'Een custom item moet zijn afzonderlijke bron-ID meesturen.',
            url: 'https://voorbeeld.nl/nieuws/zonder-id',
            published_at: '2026-07-17T08:00:00Z',
          },
        ],
      },
    },
  });

  expect(normalized.generated_at).toBe('2026-07-19T09:59:00Z');
  expect(normalized.pages['news-main']).toMatchObject({ fallback_used: true, lookback_days: 7 });
  expect(normalized.pages['news-main'].items).toHaveLength(2);
  expect(normalized.pages['news-main'].items[0]).toMatchObject({
    source: 'dronewatch',
    source_id: 'dronewatch',
    title: 'Nieuwe Europese droneontwikkelingen',
    url: 'https://www.dronewatch.nl/nieuwsbericht/',
    image_url: '/api/wallboard/news-images/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  });
  expect(normalized.pages['news-main'].items[1]).toMatchObject({
    source: 'custom',
    source_id: 'luchtvaart',
    source_label: 'Luchtvaartnieuws',
    image_url: null,
  });
  expect(normalizeWallboardState({ ...stateFixture(), news: undefined as never }).news).toEqual({ pages: {}, generated_at: '' });
});

test('hard reloads only for a higher server refresh version after the first baseline', () => {
  expect(wallboardRefreshDecision(null, 9)).toEqual({ version: 9, reload: false });
  expect(wallboardRefreshDecision(null, 10, '9')).toEqual({ version: 10, reload: true });
  expect(wallboardRefreshDecision(null, 10, '10')).toEqual({ version: 10, reload: false });
  expect(wallboardRefreshDecision(null, 10, null)).toEqual({ version: 10, reload: false });
  expect(wallboardRefreshDecision(9, 9)).toEqual({ version: 9, reload: false });
  expect(wallboardRefreshDecision(9, 8)).toEqual({ version: 9, reload: false });
  expect(wallboardRefreshDecision(9, 10)).toEqual({ version: 10, reload: true });
  expect(wallboardRefreshDecision(null, 'invalid')).toEqual({ version: 0, reload: false });
});

test('keeps offline status and automatic polling recovery without a reconnect reload path', () => {
  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');
  const statePoll = kiosk.slice(kiosk.indexOf('const poll = async () =>'), kiosk.indexOf('}, [observeRefreshVersion, pollGeneration]'));
  const controlPoll = kiosk.slice(kiosk.indexOf('const pollControl = async () =>'), kiosk.indexOf('}, [hasPairedState, observeRefreshVersion]'));

  expect(kiosk).toContain("const feedStatus = connectionError !== null ? 'offline' : stale ? 'stale' : 'live';");
  expect(kiosk).toContain("feedStatus !== 'offline'");
  expect(kiosk).toContain('!hasLiveFeed && maintenance === null');
  expect(statePoll).toContain("setStateError(errorMessage(error, 'De wallboardfeed is tijdelijk niet bereikbaar.'));");
  expect(statePoll).toContain('setStateError(null);');
  expect(statePoll).toContain('timer = setTimeout(() => void poll(), refreshSecondsRef.current * 1000);');
  expect(controlPoll).toContain("setControlError(errorMessage(error, 'De live schermbesturing is tijdelijk niet bereikbaar.'));");
  expect(controlPoll).toContain('setControlError(null);');
  expect(controlPoll).toContain('timer = setTimeout(() => void pollControl(), CONTROL_POLL_MILLISECONDS);');
  expect(statePoll).not.toContain('window.location.reload()');
  expect(controlPoll).not.toContain('window.location.reload()');
});

test('presents operational pilot availability independently from live location sharing', () => {
  expect(formatWallboardPilotAvailability({ available: 3, total: 8 })).toBe('Operationeel beschikbaar 3 van 8 piloten');
  expect(formatWallboardPilotAvailability({ available: 1, total: 1 })).toBe('Operationeel beschikbaar 1 van 1 piloot');
  expect(formatWallboardPilotAvailability({ available: 9, total: 4 })).toBe('Operationeel beschikbaar 4 van 4 piloten');
  expect(formatWallboardPilotAvailability({ available: 3, total: 8 }, false)).toBe('Operationele beschikbaarheid onbekend');
  expect(formatWallboardPilotAvailability({ available: Number.NaN, total: 8 })).toBe('Operationele beschikbaarheid onbekend');
});

test('selects only coordinate-independent real incidents for the operational overview', () => {
  const incidents = stateFixture().operational_summary.recent_incidents;

  expect(selectRecentWallboardIncidents(incidents).map((incident) => incident.id)).toEqual([
    'recent-latest',
    'recent-older',
  ]);
  expect(selectRecentWallboardIncidents(incidents, 2).map((incident) => incident.id)).toEqual([
    'recent-latest',
    'recent-older',
  ]);
  expect(incidents.find((incident) => incident.id === 'recent-latest')).not.toHaveProperty('latitude');
});

test('counts only real active incidents in wallboard overview metrics', () => {
  expect(countActiveOperationalWallboardIncidents(stateFixture().map.incidents)).toBe(1);
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

  expect(rss).toMatchObject({ type: 'rss', label: 'Nieuws- of weer-RSS', url: '', max_items: 8 });
  expect(internal).toMatchObject({ type: 'internal', label: 'Intern bericht', text: '' });
  expect(rss.id).toMatch(/^ticker-/);
  expect(clampWallboardRssMaxItems(Number.NaN)).toBe(8);
  expect(clampWallboardRssMaxItems(0)).toBe(1);
  expect(clampWallboardRssMaxItems(9)).toBe(8);
  expect(clampWallboardRssMaxItems(4.6)).toBe(5);
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
    refresh_version: 2,
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

test('accepts only bounded active wallboard maintenance notices and expires them locally', () => {
  const notice = {
    active: true,
    kind: 'update',
    title: ' D.I.S. wordt bijgewerkt ',
    message: ' Het wallboard herstelt automatisch. ',
    started_at: '2026-07-19T10:00:00Z',
    expires_at: '2026-07-19T15:59:59Z',
  };

  expect(normalizeWallboardMaintenanceNotice(notice)).toEqual({
    ...notice,
    title: 'D.I.S. wordt bijgewerkt',
    message: 'Het wallboard herstelt automatisch.',
  });
  expect(wallboardMaintenanceNoticeIsActive(notice, Date.parse('2026-07-19T12:00:00Z'))).toBe(true);
  expect(wallboardMaintenanceNoticeIsActive(notice, Date.parse(notice.expires_at))).toBe(false);
  expect(normalizeWallboardMaintenanceNotice({ ...notice, active: false })).toBeNull();
  expect(normalizeWallboardMaintenanceNotice({ ...notice, kind: 'deploy' })).toBeNull();
  expect(normalizeWallboardMaintenanceNotice({ ...notice, expires_at: notice.started_at })).toBeNull();
  expect(normalizeWallboardMaintenanceNotice({ ...notice, expires_at: '2026-07-19T16:00:01Z' })).toBeNull();
  expect(normalizeWallboardMaintenanceNotice({ ...notice, started_at: 'ongeldig' })).toBeNull();
});

test('keeps maintenance state authoritative when state and control responses arrive out of order', () => {
  const current: WallboardControlState = {
    generated_at: '2026-07-19T10:00:05Z',
    maintenance: {
      active: true,
      kind: 'update',
      title: 'D.I.S. wordt bijgewerkt',
      message: 'Het wallboard herstelt automatisch.',
      started_at: '2026-07-19T10:00:00Z',
      expires_at: '2026-07-19T16:00:00Z',
    },
    config_version: 4,
    control_version: 7,
    refresh_version: 2,
    display_profile: 'auto',
    transient_alert: null,
    display: {
      mode: 'static',
      page_id: 'map-overview',
      incident_active: false,
      next_change_at: null,
    },
  };
  const olderWithoutMaintenance: WallboardControlState = {
    ...current,
    generated_at: '2026-07-19T10:00:01Z',
    maintenance: null,
  };

  expect(stabilizeWallboardRotationDeadline(current, olderWithoutMaintenance, now).maintenance)
    .toEqual(current.maintenance);
  expect(stabilizeWallboardRotationDeadline(
    { ...current, maintenance: null },
    { ...olderWithoutMaintenance, maintenance: current.maintenance },
    now,
  ).maintenance).toBeNull();
  expect(stabilizeWallboardRotationDeadline(current, {
    ...olderWithoutMaintenance,
    generated_at: '2026-07-19T10:00:06Z',
  }, now).maintenance).toBeNull();

  const legacyState = stateFixture();
  delete (legacyState as Partial<WallboardState>).maintenance;
  expect(normalizeWallboardState(legacyState).maintenance).toBeNull();
});

test('keeps focus deadlines server-authoritative without postponing one active phase', () => {
  const current: WallboardControlState = {
    generated_at: '2026-07-19T10:00:02Z',
    config_version: 4,
    control_version: 7,
    refresh_version: 2,
    display_profile: 'auto',
    transient_alert: transientAlert(),
    focus: focusState({ next_change_at: '2026-07-19T10:00:05Z' }),
    display: {
      mode: 'rotation',
      page_id: 'map-overview',
      incident_active: true,
      next_change_at: null,
    },
  };
  const postponed: WallboardControlState = {
    ...current,
    generated_at: '2026-07-19T10:00:03Z',
    focus: focusState({ next_change_at: '2026-07-19T10:00:09Z' }),
  };

  expect(stabilizeWallboardRotationDeadline(current, postponed, Date.parse('2026-07-19T10:00:03Z')).focus?.next_change_at)
    .toBe('2026-07-19T10:00:05Z');
  expect(stabilizeWallboardRotationDeadline(current, {
    ...postponed,
    focus: focusState({ next_change_at: '2026-07-19T10:00:04Z' }),
  }, Date.parse('2026-07-19T10:00:03Z')).focus?.next_change_at).toBe('2026-07-19T10:00:04Z');

  const playlistPhase: WallboardControlState = {
    ...postponed,
    focus: focusState({ visible: false, playlist_page_id: 'map-overview', next_change_at: '2026-07-19T10:00:30Z' }),
  };
  expect(stabilizeWallboardRotationDeadline(current, playlistPhase, Date.parse('2026-07-19T10:00:03Z')).focus)
    .toMatchObject({ visible: false, playlist_page_id: 'map-overview', next_change_at: '2026-07-19T10:00:30Z' });
});

test('restores the configured ticker during a real-alarm playlist phase', () => {
  const playlistPhase = focusState({
    kind: 'real_alarm',
    visible: false,
    playlist_page_id: 'map-overview',
  });

  expect(wallboardTickerIsVisible(true, playlistPhase, false, true, 2)).toBe(true);
  expect(wallboardTickerIsVisible(true, { ...playlistPhase, visible: true }, false, true, 2)).toBe(false);
  expect(wallboardTickerIsVisible(true, null, false, true, 2)).toBe(false);
});

test('rejects an older focus transition but accepts a current server focus removal immediately', () => {
  const current: WallboardControlState = {
    generated_at: '2026-07-19T10:00:05Z',
    config_version: 4,
    control_version: 7,
    refresh_version: 2,
    display_profile: 'auto',
    transient_alert: transientAlert(),
    focus: focusState(),
    display: { mode: 'rotation', page_id: 'map-overview', incident_active: true, next_change_at: null },
  };
  const olderRemoval: WallboardControlState = {
    ...current,
    generated_at: '2026-07-19T10:00:01Z',
    focus: null,
  };
  const currentRemoval: WallboardControlState = {
    ...olderRemoval,
    generated_at: '2026-07-19T10:00:06Z',
  };

  expect(stabilizeWallboardRotationDeadline(current, olderRemoval, now).focus?.focus_id).toBe('focus-1');
  expect(stabilizeWallboardRotationDeadline(current, currentRemoval, now).focus).toBeNull();
});

test('normalizes explicit server focus while retaining the absent-focus legacy contract', () => {
  const legacy = normalizeWallboardState(stateFixture());
  expect(legacy.operational_summary.focus).toBeUndefined();

  const current = stateFixture();
  current.operational_summary.focus = focusState({
    responses: {
      counts: { targeted: 2, pending: 1, accepted: 1, declined: 0, no_response: 0 },
      items: [{ name: '  Piloot Een  ', response_status: 'accepted', responded_at: null }],
    },
  });
  const normalized = normalizeWallboardState(current);
  expect(normalized.operational_summary.focus).toMatchObject({
    kind: 'real_alarm',
    visible: true,
    pilot_counts: { available: 3, relevant: 8, contacted: 6 },
    responses: {
      counts: { targeted: 2, accepted: 1 },
      items: [{ name: 'Piloot Een', response_status: 'accepted', responded_at: null }],
    },
  });

  const invalidCounts = stateFixture();
  invalidCounts.operational_summary.focus = focusState({
    pilot_counts: { available: Number.NaN, relevant: 8, contacted: 6 },
  });
  expect(normalizeWallboardState(invalidCounts).operational_summary.focus?.pilot_counts).toBeNull();
});

test('does not lose an unexpired transient alert to an older state response', () => {
  const current: WallboardControlState = {
    config_version: 4,
    control_version: 7,
    refresh_version: 2,
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
  expect(withTests.show_test_incidents).toBe(false);
  expect(buildWallboardMapPresentation(state, true, withTests).models.map((model) => model.incident.id)).toEqual(['incident-1']);

  const summaryPage: WallboardPage = {
    ...page,
    id: 'summary',
    type: 'summary',
    options: { show_test_incidents: true },
  };
  const summaryConfiguration = wallboardPageMapConfiguration(state.wallboard.configuration, summaryPage);
  expect(summaryConfiguration.show_test_incidents).toBe(false);
  expect(buildWallboardMapPresentation(state, true, summaryConfiguration).models.map((model) => model.incident.id)).toEqual(['incident-1']);
});

test('exposes admin and kiosk routes with separate trust boundaries', () => {
  const adminRoute = readFileSync(new URL('../app/wallboards/page.tsx', import.meta.url), 'utf8');
  const createRoute = readFileSync(new URL('../app/wallboards/new/page.tsx', import.meta.url), 'utf8');
  const kioskRoute = readFileSync(new URL('../app/wallboard/page.tsx', import.meta.url), 'utf8');
  const providers = readFileSync(new URL('../app/providers.tsx', import.meta.url), 'utf8');
  const navigation = readFileSync(new URL('../src/app/CommandLayout.tsx', import.meta.url), 'utf8');
  const apiTypes = readFileSync(new URL('../src/types/api.ts', import.meta.url), 'utf8');
  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');
  const admin = readFileSync(new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url), 'utf8');
  const createPage = readFileSync(new URL('../src/features/wallboards/WallboardCreatePage.tsx', import.meta.url), 'utf8');
  const playlistPreview = readFileSync(new URL('../src/features/wallboards/WallboardPlaylistPreview.tsx', import.meta.url), 'utf8');
  const configurationEditor = readFileSync(new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url), 'utf8');
  const newsQr = readFileSync(new URL('../src/features/wallboards/WallboardNewsQrCode.tsx', import.meta.url), 'utf8');
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(adminRoute).toContain("permissions={['wallboards.manage']}");
  expect(createRoute).toContain("permissions={['wallboards.manage']}");
  expect(createRoute).toContain('<WallboardCreatePage />');
  expect(navigation).toContain("to: '/wallboards', label: 'Wallboards'");
  expect(navigation).toContain("permissions: ['wallboards.manage']");
  expect(kioskRoute).not.toContain('ProtectedShell');
  expect(providers).toContain("pathname === '/wallboard'");
  expect(kiosk).not.toContain('useAuth');
  expect(kiosk).not.toContain('localStorage');
  expect(kiosk).toContain('window.sessionStorage');
  expect(kiosk).toContain('window.location.reload()');
  expect(kiosk.match(/window\.location\.reload\(\)/g)).toHaveLength(1);
  expect(kiosk).toContain('persistWallboardRefreshVersion(window.sessionStorage');
  expect(kiosk).toContain('readPersistedWallboardRefreshVersion(window.sessionStorage)');
  expect(kiosk).toContain('clearPersistedWallboardRefreshVersion(window.sessionStorage)');
  expect(kiosk).toContain('if (observeRefreshVersion(nextControl.refresh_version)) return;');
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
  expect(apiTypes).toContain("export type WallboardFocusKind = 'preannouncement' | 'real_alarm' | 'test_alarm';");
  expect(apiTypes).toContain('pilot_counts?: WallboardFocusPilotCounts | null;');
  expect(apiTypes).toContain('response_status: WallboardFocusResponseStatus;');
  expect(apiTypes).toContain('playlist_id: string;');
  expect(apiTypes).toContain("export type WallboardDisplayProfile = 'auto' | '1080p' | '4k';");
  expect(apiTypes).toContain('display_profile: WallboardDisplayProfile;');
  expect(apiTypes).toContain('refresh_version: number;');
  expect(apiTypes).toContain('export interface WallboardMaintenanceNotice');
  expect(apiTypes).toContain("kind: 'update' | 'maintenance';");
  expect(apiTypes).toContain('maintenance?: WallboardMaintenanceNotice | null;');
  expect(apiTypes).toContain("export type WallboardNewsSource = 'ndt' | 'dronewatch';");
  expect(apiTypes).toContain("export type WallboardNewsItemSource = WallboardNewsSource | 'custom';");
  expect(apiTypes).toContain('custom_sources?: WallboardCustomNewsSource[];');
  expect(apiTypes).toContain('item_duration_seconds?: number;');
  expect(apiTypes).toContain('image_url?: string | null;');
  expect(apiTypes).toContain('source_id: string;');
  expect(apiTypes).toContain('playlist: WallboardPlaylistReference;');
  expect(apiTypes).toContain('linked_wallboards_count: number;');
  expect(kiosk).toContain('Koppel deze tv');
  expect(kiosk).toContain('Operationeel beschikbaar');
  expect(kiosk).toContain('Laatste meldingen');
  expect(kiosk).toContain('Proefalarmering');
  expect(kiosk).toContain('<FocusTakeover');
  expect(kiosk).toContain('<MaintenanceTakeover notice={maintenance} />');
  expect(kiosk).toContain('wallboardMaintenanceNoticeIsActive(effectiveControl.maintenance, clock)');
  expect(kiosk).toContain("'Onderhoud actief · het wallboard herstelt automatisch'");
  expect(kiosk).toContain('maintenance === null && wallboardTickerIsVisible');
  expect(kiosk).toContain("effectiveControl.focus === undefined");
  expect(kiosk).toContain("transientAlert?.is_test === true && state.operational_summary.active_alarm !== null");
  expect(kiosk).toContain('focus.playlist_page_id');
  expect(kiosk).toContain('wallboardFocusPilotCounts(focus.pilot_counts, fallbackPilotAvailability, isCurrent)');
  expect(kiosk).toContain("focus.kind !== 'test_alarm'");
  expect(kiosk).toContain('!alert.is_test');
  expect(kiosk).toContain('Beschikbaar gemeld');
  expect(kiosk).toContain('Live stand');
  expect(kiosk).toContain("kind === 'real_alarm' ? (responses?.coming ?? []) : []");
  expect(kiosk).toContain('wallboardFocusEtaLabel(item.eta_minutes)');
  expect(kiosk).toContain("currentPage.type === 'message' ? 'Mededeling' : currentPage.name");
  expect(kiosk).not.toContain('<span className="eyebrow">Mededeling</span>');
  expect(kiosk).toContain('showResponseFeed={configuration.focus[focus.kind].show_response_feed}');
  expect(kiosk).toContain('showTransientAlert');
  expect(kiosk).toContain('formatWallboardClock(clock)');
  expect(kiosk).toContain('wallboard-display__ticker');
  expect(kiosk).toContain('<WallboardNewsPage');
  expect(kiosk).toContain('state.news.pages[page.id]');
  expect(kiosk).toContain('wallboard-display__news-article--${item.source}');
  expect(kiosk).not.toContain('wallboard-display__news-article--${item.source_id}');
  expect(kiosk).toContain('wallboardNewsCarouselIndex');
  expect(kiosk).toContain('<WallboardNewsQrCode');
  expect(kiosk).toContain('wallboard-display__news-progress');
  expect(kiosk).toContain('api\\/wallboard\\/news-images');
  expect(newsQr).toContain("import QRCode from 'qrcode';");
  expect(newsQr).toContain('QRCode.toDataURL(url');
  expect(newsQr).toContain("errorCorrectionLevel: 'M'");
  expect(newsQr).toContain('width: 256');
  expect(newsQr).not.toContain('api.qrserver.com');
  expect(kiosk).not.toContain('autoFocus');
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/pair`");
  expect(admin).toContain('Code op tv');
  expect(admin).toContain('const isPaired = wallboard.active_sessions_count > 0;');
  expect(admin).toContain("const pairingActionLabel = wallboard.paired_at ? 'Tv herkoppelen' : 'Tv koppelen';");
  expect(admin).toContain('{isPaired ? null : (');
  expect(admin).toContain('disabled={busyAction !== null || !isPaired}');
  expect(admin).not.toContain('/pairing-code');
  expect(admin).not.toContain('WallboardPairingCode');
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/display`");
  expect(admin).toContain("`/admin/wallboards/${wallboard.id}/refresh`");
  expect(admin).toContain('Wallboard herstarten');
  expect(admin.match(/Wallboard herstarten/g)).toHaveLength(1);
  expect(admin.slice(admin.indexOf('<section className="wallboard-live-control"'), admin.indexOf('{isPaired ? null : (')))
    .toContain('Wallboard herstarten');
  expect(admin).toContain('De opdracht blijft klaarstaan als het scherm tijdelijk offline is.');
  expect(admin).toContain('expected_control_version');
  expect(admin).toContain('expected_config_version');
  expect(admin).toContain('display_profile: draftDisplayProfile');
  expect(admin).toContain('Auto (aanbevolen)');
  expect(admin).toContain('TV-, HDMI- of OS-uitvoerresolutie');
  expect(admin).toContain("error.status === 409");
  expect(admin).toContain('href="/wallboards/new"');
  expect(admin).toContain('Voorbeeld bekijken');
  expect(admin).not.toContain('async function createScreen');
  expect(createPage).toContain("api.post<Wallboard>('/admin/wallboards'");
  expect(createPage).toContain("useApiResource<WallboardPlaylist[]>('/admin/wallboard-playlists')");
  expect(createPage).toContain('router.replace(`/wallboards?screen=${encodeURIComponent(response.data.id)}`)');
  expect(playlistPreview).toContain('<dialog');
  expect(playlistPreview).toContain('Alleen-lezen voorbeeld');
  expect(playlistPreview).not.toContain('useAuth');
  expect(playlistPreview).not.toContain('api.');
  expect(playlistPreview).not.toContain('dangerouslySetInnerHTML');
  expect(configurationEditor).toContain('Nieuws- of weer-RSS');
  expect(configurationEditor).toContain('https://data.buienradar.nl/1.0/feed/xml/rssbuienradar');
  expect(configurationEditor).toContain('Aantal berichten');
  expect(configurationEditor).toContain('max={MAX_WALLBOARD_RSS_MAX_ITEMS}');
  expect(configurationEditor).toContain("addTickerSource('internal')");
  expect(configurationEditor).toContain('Focusschermen');
  expect(configurationEditor).toContain('focus&nbsp;↔&nbsp;kaart');
  expect(configurationEditor).toContain('Reactiefeed tonen');
  expect(configurationEditor).toContain('Nationaal Droneteam');
  expect(configurationEditor).toContain('Dronewatch');
  expect(configurationEditor).toContain('Eigen RSS-bronnen');
  expect(configurationEditor).toContain('RSS-bron toevoegen');
  expect(configurationEditor).toContain('pattern="https://.+"');
  expect(configurationEditor).toContain('MAX_WALLBOARD_CUSTOM_NEWS_SOURCES');
  expect(configurationEditor).toContain('het maximum aantal berichten geldt gecombineerd');
  expect(configurationEditor).toContain('afgelopen 7 dagen');
  expect(configurationEditor).toContain('MAX_WALLBOARD_NEWS_MAX_ITEMS');
  expect(configurationEditor).toContain('Tijd per nieuwsbericht (seconden)');
  expect(configurationEditor).toContain('MAX_WALLBOARD_NEWS_ITEM_DURATION_SECONDS');
  expect(styles).toContain('.wallboard-display__ticker-track');
  expect(styles).toContain('.wallboard-display__alarm--test');
  expect(styles).toContain('.wallboard-display__alarm--maintenance');
  expect(styles).toContain('.wallboard-display__mode--maintenance');
  expect(styles).toContain('.wallboard-display__alarm--with-feed');
  expect(styles).toContain('.wallboard-display__responses');
  expect(styles).toMatch(/\.wallboard-display__responses\s*\{[\s\S]*?position: absolute;[\s\S]*?bottom:[\s\S]*?left:/);
  expect(styles).toContain('.wallboard-display__focus-availability');
  expect(styles).toContain('.wallboard-display__news-article--custom');
  expect(styles).toContain('.wallboard-display__news-carousel');
  expect(styles).toContain('.wallboard-display__news-article--with-image');
  expect(styles).toContain('.wallboard-display__news-qr');
  expect(styles).toContain('@keyframes wallboard-news-story-enter');
  expect(kiosk).toContain('wallboard-display--profile-${displayProfile}');
  expect(kiosk).toContain('data-display-profile={displayProfile}');
  expect(styles).toContain('.wallboard-display--profile-4k .wallboard-display__header');
  expect(styles).toContain('.wallboard-display--profile-auto .wallboard-display__header');
  expect(styles).not.toContain('devicePixelRatio');
  expect(styles).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*\.wallboard-display__ticker-track/);
});

test('separates screen control from shared playlist content management', () => {
  const admin = readFileSync(new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url), 'utf8');
  const createPage = readFileSync(new URL('../src/features/wallboards/WallboardCreatePage.tsx', import.meta.url), 'utf8');
  const configurationEditor = readFileSync(new URL('../src/features/wallboards/WallboardConfigurationEditor.tsx', import.meta.url), 'utf8');
  const createScreen = createPage.slice(createPage.indexOf('async function createScreen'), createPage.indexOf('return ('));
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
  expect(createPage).toContain("...(values.playlistId === '' ? {} : { playlist_id: values.playlistId })");
  expect(createPage).toContain('display_profile: values.displayProfile');
  expect(createScreen).not.toContain('configuration:');
  expect(saveScreen).toContain('name,');
  expect(saveScreen).toContain('is_enabled: draftEnabled');
  expect(saveScreen).toContain('display_profile: draftDisplayProfile');
  expect(saveScreen).toContain('expected_config_version: wallboard.config_version');
  expect(saveScreen).not.toContain('configuration');
  expect(configurationEditor).toContain('Weergave en ritme');
  expect(configurationEditor).toContain('Gegevens en kaartlagen');
  expect(configurationEditor).toContain('Pagina’s en volgorde');
  expect(configurationEditor).toContain('Focusschermen');
  expect(configurationEditor).toContain('Vaste incidentpagina als fallback');
  expect(configurationEditor).toContain('Onderticker');
});

test('offers three bounded mock focus previews for the selected wallboard', () => {
  const admin = readFileSync(new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url), 'utf8');
  const previewFocus = admin.slice(admin.indexOf('async function previewFocus'), admin.indexOf('async function pairTv'));
  const previewControls = admin.slice(
    admin.indexOf('<section className="wallboard-focus-editor"'),
    admin.indexOf('{isPaired ? null : ('),
  );

  expect(admin).toContain("{ kind: 'preannouncement', label: 'Vooraankondiging' }");
  expect(admin).toContain("{ kind: 'test_alarm', label: 'Testalarm' }");
  expect(admin).toContain("{ kind: 'real_alarm', label: 'Echt alarm' }");
  expect(previewControls).toContain('WALLBOARD_FOCUS_PREVIEW_OPTIONS.map');
  expect(previewControls).toContain('onClick={() => void previewFocus(option.kind)}');
  expect(previewControls).toContain('Toont 30 seconden vaste voorbeelddata op alleen dit scherm.');
  expect(previewControls).toContain('Er wordt geen incident, alarmering of pushbericht aangemaakt.');
  expect(previewControls).toContain('Nog {focusPreviewSecondsRemaining} van {activeFocusPreview.durationSeconds} seconden');
  expect(previewControls).toContain('disabled={busyAction !== null || !wallboard.is_enabled}');

  expect(previewFocus).toContain('`/admin/wallboards/${wallboard.id}/focus-test`');
  expect(previewFocus).toContain('kind,');
  expect(previewFocus).toContain('expected_control_version: wallboard.control_version ?? 1');
  expect(previewFocus).toContain('control_version: response.data.control_version');
  expect(previewFocus).toContain('durationSeconds = response.data.duration_seconds');
  expect(previewFocus).toContain('expiresAtEpoch = Date.parse(response.data.expires_at)');
  expect(previewFocus).toContain('await onReloadWallboards();');
});

test('clears the focus-preview countdown and reports the automatic restore after thirty seconds', () => {
  const admin = readFileSync(new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url), 'utf8');
  const countdown = admin.slice(
    admin.indexOf('const updateCountdown = () =>'),
    admin.indexOf('async function saveScreen'),
  );

  expect(countdown).toContain('Math.ceil((activeFocusPreview.expiresAtEpoch - Date.now()) / 1000)');
  expect(countdown).toContain('window.setInterval(updateCountdown, 1000)');
  expect(countdown).toContain('return () => window.clearInterval(timer);');
  expect(countdown).toContain('setActiveFocusPreview(null);');
  expect(countdown).toContain('De focustest van 30 seconden is afgelopen. Het scherm toont weer de bestaande weergave.');
});

test('selects only the newly created wallboard requested by the return URL', () => {
  const wallboards = [{ id: 'existing' }, { id: 'new-screen' }];

  expect(requestedWallboardScreenSelection('?screen=new-screen', wallboards)).toBe('new-screen');
  expect(requestedWallboardScreenSelection('?screen=missing', wallboards)).toBeNull();
  expect(requestedWallboardScreenSelection('', wallboards)).toBeNull();
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

test('keeps the offline warning and maintenance takeover readable at Full HD and Ultra HD', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
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
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}">
        <header class="wallboard-display__header">
          <div>
            <span class="wallboard-display__live wallboard-display__live--offline"><i></i>Offline</span>
            <span class="wallboard-display__titles"><small>Meldkamer noord</small><h1>D.I.S. wordt bijgewerkt</h1></span>
            <span class="wallboard-display__mode wallboard-display__mode--maintenance">Onderhoud</span>
          </div>
          <time class="wallboard-display__clock"><span>12:34:56</span><small>zondag 19 juli</small></time>
        </header>
        <div class="wallboard-display__connection-warning" role="status">
          <span><strong>Offline — laatst bekende informatie</strong><small>De verbinding wordt automatisch hersteld.</small></span>
        </div>
        <section class="wallboard-display__alarm wallboard-display__alarm--maintenance" role="status">
          <span class="wallboard-display__alarm-icon">↻</span>
          <span class="wallboard-display__alarm-eyebrow">Systeemupdate</span>
          <h2>D.I.S. wordt bijgewerkt</h2>
          <p>Dit wallboard komt automatisch terug zodra de update veilig is afgerond.</p>
          <div class="wallboard-display__alarm-status"><i></i><strong>Automatisch herstel is actief</strong><time>Gestart 12:34</time></div>
        </section>
        <footer class="wallboard-display__footer"><span>Onderhoud actief</span><span>Scherm blijft actief</span></footer>
      </main>
    `);

    const result = await page.locator('.wallboard-display').evaluate((element) => {
      const warning = element.querySelector('.wallboard-display__connection-warning') as HTMLElement;
      const takeover = element.querySelector('.wallboard-display__alarm--maintenance') as HTMLElement;
      const takeoverBox = takeover.getBoundingClientRect();
      return {
        overflow: element.scrollWidth > element.clientWidth || element.scrollHeight > element.clientHeight,
        warningVisible: warning.offsetWidth > 0 && warning.offsetHeight > 0,
        takeoverVisible: takeover.offsetWidth > 0 && takeover.offsetHeight > 0,
        takeoverInsideViewport: takeoverBox.top >= 0 && takeoverBox.bottom <= window.innerHeight,
      };
    });

    expect(result).toEqual({
      overflow: false,
      warningVisible: true,
      takeoverVisible: true,
      takeoverInsideViewport: true,
    });
  }
});

test('shows one readable rotating story with image, QR and twelve progress segments at Full HD and Ultra HD', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
  const progressSegments = Array.from({ length: 12 }, (_, index) => (
    `<li class="wallboard-display__news-position-item${index === 2 ? ' wallboard-display__news-position-item--active' : ''}"></li>`
  )).join('');

  for (const screen of [
    { profile: '1080p', width: 1920, height: 1080, minimumQr: 128, minimumTitle: 32 },
    { profile: '4k', width: 3840, height: 2160, minimumQr: 192, minimumTitle: 48 },
  ] as const) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}">
        <header class="wallboard-display__header"><div><span class="wallboard-display__titles"><small>Meldkamer</small><h1>Dronenieuws</h1></span></div></header>
        <section class="wallboard-display__page">
          <div class="wallboard-display__news">
            <header class="wallboard-display__news-header"><span><strong>Dronenieuws</strong><small>Gepubliceerd in de afgelopen 7 dagen</small></span><b>3 / 12</b></header>
            <div class="wallboard-display__news-carousel" style="--wallboard-news-item-duration: 12s">
              <article class="wallboard-display__news-article wallboard-display__news-article--ndt wallboard-display__news-article--with-image">
                <figure class="wallboard-display__news-image"><img alt="" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='800' height='900'%3E%3Crect width='800' height='900' fill='%231d4ed8'/%3E%3C/svg%3E"></figure>
                <div class="wallboard-display__news-copy">
                  <div class="wallboard-display__news-meta"><strong>Nationaal Droneteam</strong><time>19 juli 2026</time></div>
                  <h2>Nieuwe inzetmogelijkheden voor professionele droneteams</h2>
                  <p>Het actuele nieuws staat per bericht groot en rustig in beeld. De samenvatting blijft vanaf de andere kant van de ruimte leesbaar.</p>
                  <footer class="wallboard-display__news-article-footer"><span>Volledig artikel op Nationaal Droneteam</span><a class="wallboard-display__news-qr"><img alt="QR-code" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='256' height='256'%3E%3Crect width='256' height='256' fill='white'/%3E%3C/svg%3E"><span>Scan voor het hele bericht</span></a></footer>
                </div>
                <i class="wallboard-display__news-progress"></i>
              </article>
              <ol class="wallboard-display__news-position">${progressSegments}</ol>
            </div>
          </div>
        </section>
        <footer class="wallboard-display__footer"><span>Pagina 1 van 1</span></footer>
      </main>
    `);

    const metrics = await page.locator('.wallboard-display__news').evaluate((news) => {
      const cards = [...news.querySelectorAll<HTMLElement>('.wallboard-display__news-article')];
      const newsRect = news.getBoundingClientRect();
      const qr = news.querySelector<HTMLElement>('.wallboard-display__news-qr img');
      const title = news.querySelector<HTMLElement>('.wallboard-display__news-article h2');
      const image = news.querySelector<HTMLElement>('.wallboard-display__news-image');
      return {
        count: cards.length,
        segments: news.querySelectorAll('.wallboard-display__news-position-item').length,
        overflow: news.scrollHeight > news.clientHeight + 1 || news.scrollWidth > news.clientWidth + 1,
        cardsInside: cards.every((card) => {
          const rect = card.getBoundingClientRect();
          return rect.top >= newsRect.top - 1 && rect.bottom <= newsRect.bottom + 1;
        }),
        qrSize: qr?.getBoundingClientRect().width ?? 0,
        titleSize: title === null ? 0 : Number.parseFloat(getComputedStyle(title).fontSize),
        imageVisible: image !== null && image.getBoundingClientRect().width > 0,
      };
    });

    expect(metrics.count).toBe(1);
    expect(metrics.segments).toBe(12);
    expect(metrics.overflow).toBe(false);
    expect(metrics.cardsInside).toBe(true);
    expect(metrics.qrSize).toBeGreaterThanOrEqual(screen.minimumQr);
    expect(metrics.titleSize).toBeGreaterThanOrEqual(screen.minimumTitle);
    expect(metrics.imageVisible).toBe(true);
  }
});

test('keeps the live response feed compact at the upper right in Full HD and Ultra HD', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');
  const responseItems = Array.from({ length: 24 }, (_, index) => `
    <li class="wallboard-display__response wallboard-display__response--${index % 3 === 0 ? 'accepted' : index % 3 === 1 ? 'declined' : 'pending'}">
      <i></i><span><strong>Piloot met lange naam ${index + 1}</strong><small>Reactiestatus</small></span><time>12:34:${String(index).padStart(2, '0')}</time>
    </li>
  `).join('');

  for (const screen of [
    { profile: '1080p', width: 1920, height: 1080 },
    { profile: '4k', width: 3840, height: 2160 },
  ] as const) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}">
        <header class="wallboard-display__header"><div><span class="wallboard-display__titles"><h1>Alarmering</h1></span></div></header>
        <section class="wallboard-display__alarm wallboard-display__alarm--focus wallboard-display__alarm--real-alarm wallboard-display__alarm--with-feed">
          <div class="wallboard-display__alarm-main"><span class="wallboard-display__alarm-eyebrow">Alarmering</span><h2>Zeer lange operationele alarmtitel voor een inzet</h2><p>Locatie met een lange omschrijving</p></div>
          <aside class="wallboard-display__responses">
            <header><span></span><div><small>Live stand</small><h3>Piloten onderweg</h3></div></header>
            <dl class="wallboard-display__response-counts"><div><dt>Gealarmeerd</dt><dd>24</dd></div><div><dt>Komen</dt><dd>8</dd></div></dl>
            <ol class="wallboard-display__response-list">${responseItems}</ol>
          </aside>
        </section>
        <footer class="wallboard-display__footer"><span>Alarmering · Playlist over 30 sec.</span></footer>
      </main>
    `);

    const measurement = await page.locator('.wallboard-display').evaluate((element) => {
      const focus = element.querySelector('.wallboard-display__alarm')!.getBoundingClientRect();
      const responses = element.querySelector('.wallboard-display__responses')!.getBoundingClientRect();
      const list = element.querySelector('.wallboard-display__response-list');
      return {
        horizontalOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth
          || element.scrollWidth > element.clientWidth,
        verticalOverflow: element.scrollHeight > element.clientHeight,
        responseScrollable: list !== null && list.scrollHeight > list.clientHeight,
        responseRightGap: focus.right - responses.right,
        responseTopGap: responses.top - focus.top,
        responseWidthRatio: responses.width / focus.width,
      };
    });
    expect(measurement.horizontalOverflow).toBe(false);
    expect(measurement.verticalOverflow).toBe(false);
    expect(measurement.responseScrollable).toBe(true);
    expect(measurement.responseRightGap).toBeLessThanOrEqual(screen.width * 0.03);
    expect(measurement.responseTopGap).toBeLessThanOrEqual(screen.height * 0.14);
    expect(measurement.responseWidthRatio).toBeLessThan(0.45);
  }
});

test('keeps the preannouncement pilot availability counter readable across display profiles', async ({ page }) => {
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  for (const screen of [
    { profile: '1080p', width: 1920, height: 1080 },
    { profile: '4k', width: 3840, height: 2160 },
  ] as const) {
    await page.setViewportSize({ width: screen.width, height: screen.height });
    await page.setContent(`
      <style>${styles} html, body { width: 100%; min-width: 0; margin: 0; overflow: hidden; }</style>
      <main class="wallboard-display wallboard-display--dark wallboard-display--profile-${screen.profile}">
        <section class="wallboard-display__alarm wallboard-display__alarm--focus wallboard-display__alarm--preannouncement">
          <div class="wallboard-display__alarm-main">
            <span class="wallboard-display__alarm-eyebrow">Vooraankondiging</span>
            <h2>Inzet wordt voorbereid</h2>
            <section class="wallboard-display__focus-availability">
              <span>Beschikbaar gemeld</span>
              <strong><b>7</b><span>van 12 geselecteerde piloten</span></strong>
              <small>9 piloten bereikt</small>
            </section>
          </div>
        </section>
      </main>
    `);

    const measurement = await page.locator('.wallboard-display__focus-availability').evaluate((element) => ({
      horizontalOverflow: element.scrollWidth > element.clientWidth,
      availableFontSize: Number.parseFloat(getComputedStyle(element.querySelector('b')!).fontSize),
      visible: element.getBoundingClientRect().width > 0 && element.getBoundingClientRect().height > 0,
    }));
    expect(measurement.horizontalOverflow).toBe(false);
    expect(measurement.visible).toBe(true);
    expect(measurement.availableFontSize).toBeGreaterThanOrEqual(40);
  }
});

test('reuses the extracted map canvas for the operational map and kiosk', () => {
  const operationalMap = readFileSync(new URL('../src/features/incidents/IncidentMapPage.tsx', import.meta.url), 'utf8');
  const kiosk = readFileSync(new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url), 'utf8');

  expect(operationalMap).toContain('<OperationalMapCanvas');
  expect(kiosk).toContain('<OperationalMapCanvas');
  expect(operationalMap).not.toContain('function OperationsMap');
});
