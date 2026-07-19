import type {
  Wallboard,
  WallboardConfiguration,
  WallboardCustomNewsSource,
  WallboardDisplayProfile,
  WallboardFocusConfiguration,
  WallboardFocusKind,
  WallboardMapConfiguration,
  WallboardNewsSource,
  WallboardPage,
  WallboardPageType,
  WallboardPilotAvailability,
  WallboardState,
  WallboardStateIncident,
  WallboardStateRecentIncident,
  WallboardTickerConfiguration,
  WallboardTickerItem,
  WallboardTickerSource,
  WallboardTickerSourceType,
  WallboardTransientAlert,
} from '../../types/api';
import {
  type OperationalMapIncidentModel,
  type OperationalMapLayerModels,
} from '../incidents/OperationalMapCanvas';
import { parseMapPoint, parsePilotRoute, pilotRouteColor } from '../incidents/pilotRoutePresentation';

const INCIDENT_COLORS = ['#7dd3fc', '#fbbf24', '#a7f3d0', '#fca5a5', '#c4b5fd', '#fdba74', '#93c5fd', '#f0abfc'];
export const MIN_WALLBOARD_REFRESH_SECONDS = 5;
export const MAX_WALLBOARD_REFRESH_SECONDS = 60;
export const MIN_WALLBOARD_PAGE_DURATION_SECONDS = 5;
export const MAX_WALLBOARD_PAGE_DURATION_SECONDS = 3600;
export const MIN_WALLBOARD_FOCUS_DURATION_SECONDS = 5;
export const MAX_WALLBOARD_FOCUS_DURATION_SECONDS = 3600;
export const MAX_WALLBOARD_TICKER_SOURCES = 10;
export const MIN_WALLBOARD_RSS_MAX_ITEMS = 1;
export const MAX_WALLBOARD_RSS_MAX_ITEMS = 8;
export const DEFAULT_WALLBOARD_RSS_MAX_ITEMS = 8;
export const MIN_WALLBOARD_NEWS_MAX_ITEMS = 1;
export const MAX_WALLBOARD_NEWS_MAX_ITEMS = 12;
export const DEFAULT_WALLBOARD_NEWS_MAX_ITEMS = 6;
export const MIN_WALLBOARD_NEWS_ITEM_DURATION_SECONDS = 5;
export const MAX_WALLBOARD_NEWS_ITEM_DURATION_SECONDS = 300;
export const DEFAULT_WALLBOARD_NEWS_ITEM_DURATION_SECONDS = 12;
export const DEFAULT_WALLBOARD_NEWS_SOURCES: WallboardNewsSource[] = ['ndt', 'dronewatch'];
export const MAX_WALLBOARD_CUSTOM_NEWS_SOURCES = 8;
export const MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_LABEL_LENGTH = 80;
export const MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_URL_LENGTH = 2048;
const WALLBOARD_HEARTBEAT_GRACE_SECONDS = 90;
const DEFAULT_RECENT_INCIDENT_LIMIT = 4;

export function normalizeWallboardDisplayProfile(value: unknown): WallboardDisplayProfile {
  return value === '1080p' || value === '4k' ? value : 'auto';
}

export function requestedWallboardScreenSelection(
  search: string,
  wallboards: Array<Pick<Wallboard, 'id'>>,
): string | null {
  const requestedScreenId = new URLSearchParams(search).get('screen');
  return requestedScreenId !== null && wallboards.some((wallboard) => wallboard.id === requestedScreenId)
    ? requestedScreenId
    : null;
}

export function wallboardDisplayProfileLabel(profile: WallboardDisplayProfile): string {
  switch (profile) {
    case 'auto': return 'Auto';
    case '1080p': return '1080p';
    case '4k': return '4K';
  }
}

export const DEFAULT_WALLBOARD_MAP_CONFIGURATION: WallboardMapConfiguration = {
  show_active_incidents: true,
  show_test_incidents: false,
  show_live_locations: true,
  show_routes: true,
  show_command_centers: true,
  show_historical_incidents: false,
  show_summary: true,
  show_incident_list: true,
  show_route_legend: true,
  auto_fit: true,
};

export const DEFAULT_WALLBOARD_CONFIGURATION: WallboardConfiguration = {
  theme: 'dark',
  refresh_seconds: 10,
  map: DEFAULT_WALLBOARD_MAP_CONFIGURATION,
  ticker: {
    enabled: false,
    sources: [],
  },
  focus: {
    preannouncement: {
      enabled: true,
      duration_seconds: 120,
      show_response_feed: true,
    },
    real_alarm: {
      enabled: true,
      duration_seconds: 30,
      show_response_feed: true,
    },
    test_alarm: {
      enabled: true,
      duration_seconds: 300,
      show_response_feed: true,
    },
  },
  pages: [{
    id: 'map-overview',
    type: 'map',
    name: 'Operationele kaart',
    duration_seconds: 30,
    options: {},
  }],
  rotation_enabled: true,
  incident_override: {
    enabled: false,
    page_id: 'map-overview',
  },
};

export interface WallboardMapPresentation {
  models: OperationalMapIncidentModel[];
  layers: OperationalMapLayerModels;
  linkedUsers: number;
}

export function wallboardConfigurationCopy(configuration: WallboardConfiguration = DEFAULT_WALLBOARD_CONFIGURATION): WallboardConfiguration {
  const legacyConfiguration = configuration as WallboardConfiguration & {
    pages?: WallboardPage[];
    rotation_enabled?: boolean;
    incident_override?: WallboardConfiguration['incident_override'];
    focus?: Partial<WallboardFocusConfiguration>;
  };
  const fallbackMap = { ...DEFAULT_WALLBOARD_MAP_CONFIGURATION, ...legacyConfiguration.map };
  fallbackMap.show_test_incidents = false;
  const ticker = wallboardTickerConfigurationCopy(legacyConfiguration.ticker);
  const sourcePages = Array.isArray(legacyConfiguration.pages) && legacyConfiguration.pages.length > 0
    ? legacyConfiguration.pages
    : [{ ...DEFAULT_WALLBOARD_CONFIGURATION.pages[0], options: {} }];
  const pages = sourcePages.map((page, index) => normalizeWallboardPage(page, index));
  const requestedOverridePageId = legacyConfiguration.incident_override?.page_id ?? pages[0].id;
  const overridePageId = pages.some((page) => page.id === requestedOverridePageId)
    ? requestedOverridePageId
    : pages[0].id;

  return {
    ...legacyConfiguration,
    refresh_seconds: clampRefreshSeconds(legacyConfiguration.refresh_seconds),
    map: fallbackMap,
    ticker,
    focus: wallboardFocusConfigurationCopy(legacyConfiguration.focus),
    pages,
    rotation_enabled: legacyConfiguration.rotation_enabled ?? true,
    incident_override: {
      enabled: legacyConfiguration.incident_override?.enabled ?? false,
      page_id: overridePageId,
    },
  };
}

export function wallboardConfigurationForSave(configuration: WallboardConfiguration): WallboardConfiguration {
  return wallboardConfigurationCopy(configuration);
}

export function clampWallboardPageDuration(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_CONFIGURATION.pages[0].duration_seconds;
  return Math.min(
    MAX_WALLBOARD_PAGE_DURATION_SECONDS,
    Math.max(MIN_WALLBOARD_PAGE_DURATION_SECONDS, Math.round(value)),
  );
}

export function clampWallboardFocusDuration(value: number, fallbackSeconds: number): number {
  if (!Number.isFinite(value)) return fallbackSeconds;
  return Math.min(
    MAX_WALLBOARD_FOCUS_DURATION_SECONDS,
    Math.max(MIN_WALLBOARD_FOCUS_DURATION_SECONDS, Math.round(value)),
  );
}

export function clampWallboardRssMaxItems(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_RSS_MAX_ITEMS;
  return Math.min(
    MAX_WALLBOARD_RSS_MAX_ITEMS,
    Math.max(MIN_WALLBOARD_RSS_MAX_ITEMS, Math.round(value)),
  );
}

export function clampWallboardNewsMaxItems(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_NEWS_MAX_ITEMS;
  return Math.min(
    MAX_WALLBOARD_NEWS_MAX_ITEMS,
    Math.max(MIN_WALLBOARD_NEWS_MAX_ITEMS, Math.round(value)),
  );
}

export function clampWallboardNewsItemDuration(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_NEWS_ITEM_DURATION_SECONDS;
  return Math.min(
    MAX_WALLBOARD_NEWS_ITEM_DURATION_SECONDS,
    Math.max(MIN_WALLBOARD_NEWS_ITEM_DURATION_SECONDS, Math.round(value)),
  );
}

export function normalizeWallboardNewsSources(value: unknown, allowEmpty = false): WallboardNewsSource[] {
  if (!Array.isArray(value)) return [...DEFAULT_WALLBOARD_NEWS_SOURCES];

  const sources = [...new Set(value.filter((source): source is WallboardNewsSource => (
    source === 'ndt' || source === 'dronewatch'
  )))];
  return sources.length > 0 || allowEmpty ? sources : [...DEFAULT_WALLBOARD_NEWS_SOURCES];
}

export function normalizeWallboardCustomNewsSources(value: unknown): WallboardCustomNewsSource[] {
  if (!Array.isArray(value)) return [];

  const seenIds = new Set<string>();
  return value.flatMap((source): WallboardCustomNewsSource[] => {
    if (typeof source !== 'object' || source === null || Array.isArray(source)) return [];
    const candidate = source as Partial<WallboardCustomNewsSource>;
    const id = typeof candidate.id === 'string' ? candidate.id.trim() : '';
    const label = typeof candidate.label === 'string' ? candidate.label.trim() : '';
    const url = typeof candidate.url === 'string' ? safeWallboardCustomNewsUrl(candidate.url) : null;
    if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/.test(id) || seenIds.has(id) || label === '' || url === null) return [];
    seenIds.add(id);
    return [{
      id,
      label: label.slice(0, MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_LABEL_LENGTH),
      url,
    }];
  }).slice(0, MAX_WALLBOARD_CUSTOM_NEWS_SOURCES);
}

export function createWallboardCustomNewsSource(sequence: number): WallboardCustomNewsSource {
  const suffix = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${sequence}`;
  return {
    id: `rss-${suffix}`.slice(0, 64),
    label: `Eigen RSS-bron ${sequence}`,
    url: '',
  };
}

export function wallboardFocusKindLabel(kind: WallboardFocusKind): string {
  switch (kind) {
    case 'preannouncement': return 'Vooraankondiging';
    case 'real_alarm': return 'Alarmering';
    case 'test_alarm': return 'Proefalarmering';
  }
}

export function createWallboardPage(type: WallboardPageType, sequence: number): WallboardPage {
  const suffix = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${sequence}`;

  return {
    id: `page-${suffix}`,
    type,
    name: wallboardPageTypeLabel(type),
    duration_seconds: 30,
    options: type === 'message'
      ? { body: '' }
      : type === 'news'
        ? {
          sources: [...DEFAULT_WALLBOARD_NEWS_SOURCES],
          custom_sources: [],
          max_items: DEFAULT_WALLBOARD_NEWS_MAX_ITEMS,
          item_duration_seconds: DEFAULT_WALLBOARD_NEWS_ITEM_DURATION_SECONDS,
        }
        : type === 'video'
          ? { url: '' }
        : {},
  };
}

export function createWallboardTickerSource(
  type: WallboardTickerSourceType,
  sequence: number,
): WallboardTickerSource {
  const suffix = typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${sequence}`;

  return type === 'rss'
    ? {
      id: `ticker-${suffix}`,
      type,
      label: 'Nieuws- of weer-RSS',
      url: '',
      max_items: DEFAULT_WALLBOARD_RSS_MAX_ITEMS,
    }
    : { id: `ticker-${suffix}`, type, label: 'Intern bericht', text: '' };
}

export function wallboardPageTypeLabel(type: WallboardPageType): string {
  switch (type) {
    case 'map': return 'Operationele kaart';
    case 'incident_list': return 'Incidentenoverzicht';
    case 'summary': return 'Operationele samenvatting';
    case 'message': return 'Mededeling';
    case 'news': return 'Dronenieuws';
    case 'video': return 'Promovideo';
  }
}

export function wallboardPageMapConfiguration(
  configuration: WallboardConfiguration,
  page?: WallboardPage,
): WallboardMapConfiguration {
  if (page === undefined || !['incident_list', 'summary'].includes(page.type)) {
    return { ...configuration.map };
  }

  return {
    ...configuration.map,
    show_active_incidents: true,
    show_test_incidents: false,
  };
}

export function clampRefreshSeconds(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_CONFIGURATION.refresh_seconds;
  return Math.min(MAX_WALLBOARD_REFRESH_SECONDS, Math.max(MIN_WALLBOARD_REFRESH_SECONDS, Math.round(value)));
}

export function wallboardIsOnline(wallboard: Wallboard, now = Date.now()): boolean {
  if (!wallboard.is_enabled) return false;
  if (typeof wallboard.is_online === 'boolean') return wallboard.is_online;
  if (wallboard.active_sessions_count <= 0 || !wallboard.last_seen_at) return false;

  const lastSeen = Date.parse(wallboard.last_seen_at);
  if (!Number.isFinite(lastSeen)) return false;

  // The kiosk persists its heartbeat at most once per minute. A short grace
  // window prevents the management status from flapping between two touches,
  // while the timestamp still provides a real liveness boundary.
  const allowedAge = Math.max(
    WALLBOARD_HEARTBEAT_GRACE_SECONDS,
    clampRefreshSeconds(wallboard.configuration.refresh_seconds) * 3,
  ) * 1000;
  return now - lastSeen <= allowedAge;
}

export function wallboardPlaylistUsageCount(playlist: {
  linked_wallboards_count: number;
}): number {
  const value = playlist.linked_wallboards_count;
  return Number.isFinite(value) ? Math.max(0, Math.trunc(value)) : 0;
}

export function formatWallboardPilotAvailability(
  availability: WallboardPilotAvailability,
  isCurrent = true,
): string {
  if (
    !isCurrent
    || !Number.isFinite(availability.available)
    || !Number.isFinite(availability.total)
    || availability.available < 0
    || availability.total < 0
  ) return 'Operationele beschikbaarheid onbekend';

  const total = nonNegativeInteger(availability.total);
  const available = Math.min(total, nonNegativeInteger(availability.available));
  return `Operationeel beschikbaar ${available} van ${total} ${total === 1 ? 'piloot' : 'piloten'}`;
}

export function wallboardTransientAlertIsActive(alert: WallboardTransientAlert | null, now = Date.now()): boolean {
  if (alert === null) return false;
  const expiresAt = Date.parse(alert.expires_at);
  return Number.isFinite(expiresAt) && expiresAt > now;
}

export function selectRecentWallboardIncidents(
  incidents: WallboardStateRecentIncident[],
  limit = DEFAULT_RECENT_INCIDENT_LIMIT,
): WallboardStateRecentIncident[] {
  const boundedLimit = Math.max(0, Math.trunc(limit));
  return incidents
    .filter((incident) => !incident.is_test)
    .slice()
    .sort((left, right) => {
      const leftTimestamp = incidentTimestamp(left.closed_at);
      const rightTimestamp = incidentTimestamp(right.closed_at);
      if (leftTimestamp === rightTimestamp) return 0;
      return rightTimestamp > leftTimestamp ? 1 : -1;
    })
    .slice(0, boundedLimit);
}

export function countActiveOperationalWallboardIncidents(incidents: WallboardStateIncident[]): number {
  return incidents.reduce((count, incident) => (
    !incident.is_test && !['resolved', 'cancelled'].includes(incident.status) ? count + 1 : count
  ), 0);
}

export function wallboardTickerDurationSeconds(items: WallboardTickerItem[]): number {
  const characters = items.reduce(
    (total, item) => total + item.source_label.length + item.text.length + 6,
    0,
  );
  return Math.min(120, Math.max(24, Math.ceil(characters / 8)));
}

export function wallboardStateIsStale(state: WallboardState, now = Date.now()): boolean {
  const generatedAt = Date.parse(state.generated_at);
  if (!Number.isFinite(generatedAt)) return true;
  const allowedAge = Math.max(60, clampRefreshSeconds(state.wallboard.configuration.refresh_seconds) * 3) * 1000;
  return now - generatedAt > allowedAge;
}

export function buildWallboardMapPresentation(
  state: WallboardState,
  includeLiveData = true,
  configuration: WallboardMapConfiguration = state.wallboard.configuration.map,
): WallboardMapPresentation {
  const incidents = state.map.incidents.filter((incident) => (
    configuration.show_active_incidents
    && !['resolved', 'cancelled'].includes(incident.status)
    && !incident.is_test
  ));
  const visibleIncidentIds = new Set(incidents.map((incident) => incident.id));
  const liveLocations = configuration.show_live_locations && includeLiveData
    ? state.map.live_locations.filter((location) => location.location_is_current && visibleIncidentIds.has(location.incident_id))
    : [];

  const models: OperationalMapIncidentModel[] = incidents.map((incident, index) => {
    const color = INCIDENT_COLORS[index % INCIDENT_COLORS.length];
    return {
      incident: { id: incident.id, title: incident.title },
      color,
      incidentPoint: parseMapPoint(incident.latitude, incident.longitude),
      liveLocations: liveLocations
        .filter((location) => location.incident_id === incident.id)
        .flatMap((location) => {
          const point = parseMapPoint(location.latitude, location.longitude);
          if (point === null) return [];
          return [{
            ...point,
            userId: location.user_id,
            name: location.user?.name ?? location.user_id,
            color: pilotRouteColor(location.user_id),
            route: configuration.show_routes ? parsePilotRoute(location.route) : null,
          }];
        }),
    };
  });

  return {
    models,
    linkedUsers: liveLocations.length,
    layers: {
      commandCenters: configuration.show_command_centers
        ? state.map.command_centers.flatMap((center) => {
          const point = parseMapPoint(center.latitude, center.longitude);
          return point === null ? [] : [{ ...point, id: center.id, name: center.name }];
        })
        : [],
      historicalIncidents: configuration.show_historical_incidents
        ? state.map.historical_incidents.flatMap((incident) => {
          const point = parseMapPoint(incident.latitude, incident.longitude);
          return point === null ? [] : [{ ...point, id: incident.id, reference: incident.reference, title: incident.title }];
        })
        : [],
      pilotHomes: [],
    },
  };
}

function normalizeWallboardPage(
  page: WallboardPage,
  index: number,
): WallboardPage {
  const type: WallboardPageType = ['map', 'incident_list', 'summary', 'message', 'news', 'video'].includes(page.type)
    ? page.type
    : 'map';
  const id = typeof page.id === 'string' && page.id.trim() !== '' ? page.id : `page-${index + 1}`;
  const legacyPage = page as WallboardPage & { title?: string };
  const name = typeof legacyPage.name === 'string' && legacyPage.name.trim() !== ''
    ? legacyPage.name.trim()
    : typeof legacyPage.title === 'string' && legacyPage.title.trim() !== ''
      ? legacyPage.title.trim()
    : wallboardPageTypeLabel(type);

  return {
    id,
    type,
    name,
    duration_seconds: clampWallboardPageDuration(page.duration_seconds),
    options: type === 'message'
      ? { body: typeof page.options?.body === 'string' ? page.options.body : '' }
      : type === 'news'
        ? normalizeWallboardNewsPageOptions(page)
        : type === 'video'
          ? { url: typeof page.options?.url === 'string' ? (normalizeWallboardVideoUrl(page.options.url) ?? page.options.url.trim()) : '' }
        : {},
  };
}

/**
 * Canonicaliseert uitsluitend de door de backend toegestane YouTube- en
 * Vimeo-vormen. Deze clientcontrole is gebruiksgemak; de backend valideert de
 * URL opnieuw voordat een playlist wordt opgeslagen.
 */
export function normalizeWallboardVideoUrl(value: string): string | null {
  const trimmed = value.trim();
  if (trimmed === '' || trimmed.length > 2048 || /[\u0000-\u0020\u007f]/.test(trimmed)) return null;

  try {
    const url = new URL(trimmed);
    if (url.protocol !== 'https:' || url.username !== '' || url.password !== '' || (url.port !== '' && url.port !== '443')) {
      return null;
    }

    const host = url.hostname.toLowerCase();
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].includes(host)) {
      const watchId = url.pathname === '/watch' ? url.searchParams.get('v') : null;
      const embedMatch = /^\/embed\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname);
      const videoId = watchId ?? embedMatch?.[1] ?? null;
      return videoId !== null && /^[A-Za-z0-9_-]{11}$/.test(videoId)
        ? `https://www.youtube.com/embed/${videoId}`
        : null;
    }

    if (host === 'youtu.be') {
      const match = /^\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname);
      return match ? `https://www.youtube.com/embed/${match[1]}` : null;
    }

    const vimeoPattern = ['vimeo.com', 'www.vimeo.com'].includes(host)
      ? /^\/([1-9][0-9]{0,11})\/?$/
      : host === 'player.vimeo.com'
        ? /^\/video\/([1-9][0-9]{0,11})\/?$/
        : null;
    const vimeoMatch = vimeoPattern?.exec(url.pathname) ?? null;
    return vimeoMatch ? `https://player.vimeo.com/video/${vimeoMatch[1]}` : null;
  } catch {
    return null;
  }
}

/** Bouwt een vast, gedempt autoplay-adres uit een server-gecanonicaliseerde URL. */
export function wallboardVideoEmbedUrl(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const canonical = normalizeWallboardVideoUrl(value);
  if (canonical === null || canonical !== value.trim().replace(/\/$/, '')) return null;

  const url = new URL(canonical);
  if (url.hostname === 'www.youtube.com') {
    const videoId = url.pathname.split('/').at(-1);
    if (videoId === undefined) return null;
    url.search = new URLSearchParams({
      autoplay: '1',
      mute: '1',
      controls: '1',
      rel: '0',
      playsinline: '1',
      loop: '1',
      playlist: videoId,
    }).toString();
  } else {
    url.search = new URLSearchParams({
      autoplay: '1',
      muted: '1',
      loop: '1',
      autopause: '0',
      title: '0',
      byline: '0',
      portrait: '0',
    }).toString();
  }

  return url.toString();
}

function normalizeWallboardNewsPageOptions(page: WallboardPage): WallboardPage['options'] {
  const customSources = normalizeWallboardCustomNewsSources(page.options?.custom_sources);
  return {
    sources: normalizeWallboardNewsSources(page.options?.sources, customSources.length > 0),
    custom_sources: customSources,
    max_items: clampWallboardNewsMaxItems(Number(page.options?.max_items)),
    item_duration_seconds: clampWallboardNewsItemDuration(Number(page.options?.item_duration_seconds)),
  };
}

function safeWallboardCustomNewsUrl(value: string): string | null {
  if (value.length > MAX_WALLBOARD_CUSTOM_NEWS_SOURCE_URL_LENGTH) return null;
  try {
    const url = new URL(value);
    if (url.protocol !== 'https:' || url.port !== '' || url.username !== '' || url.password !== '') return null;
    return url.toString();
  } catch {
    return null;
  }
}

function wallboardTickerConfigurationCopy(
  ticker: WallboardTickerConfiguration | undefined,
): WallboardTickerConfiguration {
  if (ticker === undefined || !Array.isArray(ticker.sources)) {
    return { ...DEFAULT_WALLBOARD_CONFIGURATION.ticker, sources: [] };
  }

  return {
    enabled: ticker.enabled === true,
    sources: ticker.sources
      .filter((source) => source.type === 'rss' || source.type === 'internal')
      .slice(0, MAX_WALLBOARD_TICKER_SOURCES)
      .map((source): WallboardTickerSource => source.type === 'rss'
        ? {
          id: source.id,
          type: 'rss',
          label: source.label,
          url: source.url ?? '',
          max_items: clampWallboardRssMaxItems(source.max_items),
        }
        : {
          id: source.id,
          type: 'internal',
          label: source.label,
          text: source.text ?? '',
        }),
  };
}

function wallboardFocusConfigurationCopy(
  focus: Partial<WallboardFocusConfiguration> | undefined,
): WallboardFocusConfiguration {
  const defaults = DEFAULT_WALLBOARD_CONFIGURATION.focus;

  return {
    preannouncement: wallboardFocusTypeConfigurationCopy(focus?.preannouncement, defaults.preannouncement),
    real_alarm: wallboardFocusTypeConfigurationCopy(focus?.real_alarm, defaults.real_alarm),
    test_alarm: wallboardFocusTypeConfigurationCopy(focus?.test_alarm, defaults.test_alarm),
  };
}

function wallboardFocusTypeConfigurationCopy(
  configuration: Partial<WallboardFocusConfiguration['preannouncement']> | undefined,
  defaults: WallboardFocusConfiguration['preannouncement'],
): WallboardFocusConfiguration['preannouncement'] {
  return {
    enabled: configuration?.enabled ?? defaults.enabled,
    duration_seconds: clampWallboardFocusDuration(
      configuration?.duration_seconds ?? defaults.duration_seconds,
      defaults.duration_seconds,
    ),
    show_response_feed: configuration?.show_response_feed ?? defaults.show_response_feed,
  };
}

function incidentTimestamp(value: string | null | undefined): number {
  if (!value) return Number.NEGATIVE_INFINITY;
  const timestamp = Date.parse(value);
  return Number.isFinite(timestamp) ? timestamp : Number.NEGATIVE_INFINITY;
}

function nonNegativeInteger(value: number): number {
  return Number.isFinite(value) ? Math.max(0, Math.trunc(value)) : 0;
}
