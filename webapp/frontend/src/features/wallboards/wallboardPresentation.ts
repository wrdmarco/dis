import type {
  Wallboard,
  WallboardConfiguration,
  WallboardCustomNewsSource,
  WallboardDisplayProfile,
  WallboardFocusConfiguration,
  WallboardFocusKind,
  WallboardFlipDirection,
  WallboardForecastBlockKey,
  WallboardForecastLocationMode,
  WallboardKpiCategory,
  WallboardKpiKey,
  WallboardKpiVisualization,
  WallboardMapConfiguration,
  WallboardNewsSource,
  WallboardNewsItemTransition,
  WallboardPage,
  WallboardPageOptions,
  WallboardPageTransition,
  WallboardPageType,
  WallboardRichTextDocument,
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
  WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS,
  WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS,
  wallboardPhotoItemDurationSeconds,
} from './wallboardMedia';
import {
  type OperationalMapIncidentModel,
  type OperationalMapLayerModels,
} from '../incidents/OperationalMapCanvas';
import { parseMapPoint, parsePilotRoute, pilotRouteColor } from '../incidents/pilotRoutePresentation';
import {
  normalizeWallboardRichText,
  wallboardRichTextFromPlainText,
} from './WallboardRichText';
import {
  WALLBOARD_VIDEO_MAX_CONTENT_DURATION_SECONDS,
  WALLBOARD_VIDEO_STARTUP_ALLOWANCE_SECONDS,
} from './wallboardVideoInspection';

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
export const MIN_WALLBOARD_CALENDAR_MAX_ITEMS = 1;
export const MAX_WALLBOARD_CALENDAR_MAX_ITEMS = 12;
export const DEFAULT_WALLBOARD_CALENDAR_MAX_ITEMS = 6;
export const DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE: WallboardForecastLocationMode = 'netherlands';
export const MAX_WALLBOARD_FORECAST_VISIBLE_BLOCKS = 12;
export const WALLBOARD_FORECAST_BLOCK_KEYS = [
  'weather',
  'daylight',
  'temperature',
  'wind_speed',
  'wind_gust',
  'wind_direction',
  'precipitation_probability',
  'precipitation_outlook',
  'thunderstorm_forecast',
  'cloud_cover',
  'visibility',
  'kp_index',
  'gnss_visible',
  'gnss_usable',
] as const satisfies readonly WallboardForecastBlockKey[];
export const DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS: readonly WallboardForecastBlockKey[] =
  WALLBOARD_FORECAST_BLOCK_KEYS.filter((key) => !['gnss_visible', 'gnss_usable'].includes(key));
const LEGACY_DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS: readonly WallboardForecastBlockKey[] = [
  'weather',
  'daylight',
  'temperature',
  'wind_speed',
  'wind_gust',
  'wind_direction',
  'precipitation_probability',
  'cloud_cover',
  'visibility',
  'gnss_visible',
  'kp_index',
  'gnss_usable',
];
export const WALLBOARD_KPI_DEFINITIONS = [
  { key: 'pilots_available', category: 'pilots', label: 'Beschikbare piloten', help: 'Piloten die nu operationeel beschikbaar zijn.' },
  { key: 'pilots_unavailable', category: 'pilots', label: 'Niet-beschikbare piloten', help: 'Piloten die nu niet inzetbaar zijn.' },
  { key: 'pilots_total', category: 'pilots', label: 'Totaal piloten', help: 'Alle piloten binnen het operationele bereik.' },
  { key: 'pilot_availability_rate', category: 'pilots', label: 'Beschikbaarheid', help: 'Het actuele percentage beschikbare piloten.' },
  { key: 'pilots_en_route', category: 'pilots', label: 'Piloten onderweg', help: 'Piloten die momenteel onderweg zijn naar een inzet.' },
  { key: 'pilots_on_scene', category: 'pilots', label: 'Piloten op locatie', help: 'Piloten die zich momenteel op een inzetlocatie bevinden.' },
  { key: 'pilots_push_disabled', category: 'pilots', label: 'Push uitgeschakeld', help: 'Piloten die door uitgeschakelde push niet operationeel inzetbaar zijn.' },
  { key: 'incidents_total', category: 'incidents', label: 'Open incidenten totaal', help: 'Alle echte open incidenten in de fasen actief, alarmering en in uitvoering.' },
  { key: 'incidents_registered_total', category: 'incidents', label: 'Incidenten sinds registratie', help: 'Alle incidenten die sinds de ingebruikname van D.I.S. zijn geregistreerd.' },
  { key: 'incidents_active', category: 'incidents', label: 'Status actief', help: 'Open incidenten met de status actief.' },
  { key: 'incidents_dispatching', category: 'incidents', label: 'Wordt gealarmeerd', help: 'Incidenten waarvoor de alarmering loopt.' },
  { key: 'incidents_in_progress', category: 'incidents', label: 'In uitvoering', help: 'Incidenten met een lopende inzet.' },
  { key: 'incidents_low', category: 'incidents', label: 'Prioriteit laag', help: 'Incidenten met lage prioriteit.' },
  { key: 'incidents_normal', category: 'incidents', label: 'Prioriteit normaal', help: 'Incidenten met normale prioriteit.' },
  { key: 'incidents_high', category: 'incidents', label: 'Prioriteit hoog', help: 'Incidenten met hoge prioriteit.' },
  { key: 'incidents_critical', category: 'incidents', label: 'Prioriteit kritiek', help: 'Incidenten met kritieke prioriteit.' },
  { key: 'incidents_opened_today', category: 'incidents', label: 'Incidenten geopend vandaag', help: 'Incidenten die vandaag zijn geopend.' },
  { key: 'incidents_resolved_today', category: 'incidents', label: 'Incidenten afgerond vandaag', help: 'Incidenten die vandaag zijn afgerond.' },
  { key: 'incidents_cancelled_today', category: 'incidents', label: 'Incidenten geannuleerd vandaag', help: 'Incidenten die vandaag zijn geannuleerd.' },
  { key: 'incidents_resolved_total', category: 'incidents', label: 'Afgerond sinds registratie', help: 'Alle afgeronde incidenten sinds de ingebruikname van D.I.S.' },
  { key: 'incidents_cancelled_total', category: 'incidents', label: 'Geannuleerd sinds registratie', help: 'Alle geannuleerde incidenten sinds de ingebruikname van D.I.S.' },
  { key: 'assets_total', category: 'assets', label: 'Totaal middelen', help: 'Alle geregistreerde operationele middelen.' },
  { key: 'assets_ready', category: 'assets', label: 'Middelen gereed', help: 'Middelen die direct inzetbaar zijn.' },
  { key: 'assets_maintenance', category: 'assets', label: 'Middelen in onderhoud', help: 'Middelen die vanwege onderhoud niet gereed zijn.' },
  { key: 'assets_unavailable', category: 'assets', label: 'Middelen niet beschikbaar', help: 'Middelen die niet inzetbaar of buiten gebruik zijn.' },
  { key: 'assets_issues', category: 'assets', label: 'Middelproblemen', help: 'Middelen die operationele aandacht nodig hebben.' },
  { key: 'drones_total', category: 'assets', label: 'Drones totaal', help: 'Alle als drone geregistreerde operationele middelen.' },
  { key: 'drones_ready', category: 'assets', label: 'Drones gereed', help: 'Drones die direct inzetbaar zijn.' },
  { key: 'responses_targeted', category: 'responses', label: 'Doelgroep', help: 'Piloten aan wie een actuele uitvraag is gericht.' },
  { key: 'responses_contacted', category: 'responses', label: 'Gecontacteerd', help: 'Piloten die voor de actuele uitvraag zijn bereikt.' },
  { key: 'responses_pending', category: 'responses', label: 'Wacht op reactie', help: 'Piloten van wie nog een reactie wordt verwacht.' },
  { key: 'responses_accepted', category: 'responses', label: 'Komt / beschikbaar', help: 'Piloten die positief hebben gereageerd.' },
  { key: 'responses_declined', category: 'responses', label: 'Komt niet', help: 'Piloten die de actuele uitvraag hebben afgewezen.' },
  { key: 'responses_no_response', category: 'responses', label: 'Geen reactie', help: 'Piloten van wie geen reactie is ontvangen.' },
  { key: 'dispatches_active', category: 'responses', label: 'Actieve dispatches', help: 'Dispatches waarvoor de uitvraag of reactieperiode nog loopt.' },
  { key: 'dispatch_acceptance_rate', category: 'responses', label: 'Acceptatiegraad', help: 'Het percentage positieve reacties op dispatches.' },
  { key: 'flight_reports_this_month', category: 'flight', label: 'Vluchten deze maand', help: 'Inzetrapporten met een vastgelegde vlucht in de huidige maand.' },
  { key: 'flight_minutes_this_month', category: 'flight', label: 'Vluchttijd deze maand', help: 'Totaal vastgelegde vliegminuten in de huidige maand.' },
  { key: 'average_flight_minutes_this_month', category: 'flight', label: 'Gem. vluchttijd deze maand', help: 'Gemiddelde vastgelegde vluchttijd per inzetrapport in de huidige maand.' },
  { key: 'drones_flown_distribution', category: 'flight', label: 'Dronetypen in inzetrapporten', help: 'Verdeling op fabrikant en model van de drones waarmee volgens inzetrapporten is gevlogen.' },
  { key: 'incidents_by_province', category: 'incidents', label: 'Incidenten per provincie', help: 'Verdeling van geregistreerde incidenten over de provincies.' },
  { key: 'incidents_by_country', category: 'incidents', label: 'Incidenten per land', help: 'Verdeling van geregistreerde incidenten over de herkende landen.' },
] as const satisfies ReadonlyArray<{
  key: WallboardKpiKey;
  category: WallboardKpiCategory;
  label: string;
  help: string;
}>;
export const WALLBOARD_KPI_KEYS = WALLBOARD_KPI_DEFINITIONS.map((definition) => definition.key);
export const DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS = [
  'pilots_available',
  'pilots_unavailable',
  'pilots_total',
  'pilot_availability_rate',
  'incidents_total',
  'incidents_active',
  'incidents_dispatching',
  'incidents_in_progress',
  'incidents_low',
  'incidents_normal',
  'incidents_high',
  'incidents_critical',
  'assets_total',
  'assets_ready',
  'assets_maintenance',
  'assets_unavailable',
  'assets_issues',
  'responses_targeted',
  'responses_contacted',
  'responses_pending',
  'responses_accepted',
  'responses_declined',
  'responses_no_response',
] as const satisfies readonly WallboardKpiKey[];
export const MAX_WALLBOARD_KPI_CHARTS = 6;
export const WALLBOARD_KPI_VISUALIZATIONS = ['counter', 'bar', 'pie', 'ring'] as const satisfies readonly WallboardKpiVisualization[];
const WALLBOARD_KPI_DISTRIBUTION_KEYS = new Set<WallboardKpiKey>([
  'drones_flown_distribution',
  'incidents_by_province',
  'incidents_by_country',
]);
const WALLBOARD_KPI_RATIO_KEYS = new Set<WallboardKpiKey>([
  'pilot_availability_rate',
  'dispatch_acceptance_rate',
  'pilots_available',
  'pilots_unavailable',
  'pilots_en_route',
  'pilots_on_scene',
  'pilots_push_disabled',
  'incidents_active',
  'incidents_dispatching',
  'incidents_in_progress',
  'incidents_low',
  'incidents_normal',
  'incidents_high',
  'incidents_critical',
  'incidents_resolved_total',
  'incidents_cancelled_total',
  'assets_ready',
  'assets_maintenance',
  'assets_unavailable',
  'assets_issues',
  'drones_ready',
  'responses_contacted',
  'responses_pending',
  'responses_accepted',
  'responses_declined',
  'responses_no_response',
]);
export const DEFAULT_WALLBOARD_KPI_VISUALIZATIONS = Object.fromEntries(
  WALLBOARD_KPI_KEYS.map((key) => [key, wallboardKpiDefaultVisualization(key)]),
) as Record<WallboardKpiKey, WallboardKpiVisualization>;
export const MIN_WALLBOARD_NEWS_MAX_ITEMS = 1;
export const MAX_WALLBOARD_NEWS_MAX_ITEMS = 12;
export const DEFAULT_WALLBOARD_NEWS_MAX_ITEMS = 6;
export const MIN_WALLBOARD_NEWS_ITEM_DURATION_SECONDS = 5;
export const MAX_WALLBOARD_NEWS_ITEM_DURATION_SECONDS = 300;
export const DEFAULT_WALLBOARD_NEWS_ITEM_DURATION_SECONDS = 12;
export const DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION: WallboardNewsItemTransition = 'fade';
export const MIN_WALLBOARD_TRANSITION_DURATION_MS = 100;
export const MAX_WALLBOARD_TRANSITION_DURATION_MS = 5000;
export const DEFAULT_WALLBOARD_PAGE_TRANSITION: WallboardPageTransition = 'fade';
export const DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS = 320;
export const DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS = 720;
export const DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION = DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION;
export const DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS = DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS;
export const DEFAULT_WALLBOARD_FLIP_DIRECTION: WallboardFlipDirection = 'left_to_right';
export const WALLBOARD_FLIP_DIRECTIONS: ReadonlyArray<{
  value: WallboardFlipDirection;
  label: string;
}> = [
  { value: 'left_to_right', label: 'Links → rechts' },
  { value: 'top_to_bottom', label: 'Boven → onder' },
  { value: 'bottom_to_top', label: 'Onder → boven' },
  { value: 'random', label: 'Willekeurig' },
];
export const WALLBOARD_PAGE_TRANSITIONS: ReadonlyArray<{
  value: WallboardPageTransition;
  label: string;
}> = [
  { value: 'fade', label: 'Vervagen' },
  { value: 'dissolve', label: 'Dissolve' },
  { value: 'slide', label: 'Schuiven' },
  { value: 'flip', label: 'Flip' },
  { value: 'zoom', label: 'Zachte zoom' },
  { value: 'wipe', label: 'Wipe' },
  { value: 'none', label: 'Direct wisselen' },
];
export const WALLBOARD_NEWS_ITEM_TRANSITIONS = WALLBOARD_PAGE_TRANSITIONS;
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
  page_transition: DEFAULT_WALLBOARD_PAGE_TRANSITION,
  page_transition_duration_ms: DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
  page_flip_direction: DEFAULT_WALLBOARD_FLIP_DIRECTION,
  page_fade_enabled: true,
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
    page_transition?: WallboardPageTransition;
    page_transition_duration_ms?: number;
    page_flip_direction?: WallboardFlipDirection;
    page_fade_enabled?: boolean;
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
  const pageTransition = normalizeWallboardPageTransition(
    legacyConfiguration.page_transition
      ?? (legacyConfiguration.page_fade_enabled === false ? 'none' : DEFAULT_WALLBOARD_PAGE_TRANSITION),
  );

  return {
    ...legacyConfiguration,
    refresh_seconds: clampRefreshSeconds(legacyConfiguration.refresh_seconds),
    map: fallbackMap,
    ticker,
    focus: wallboardFocusConfigurationCopy(legacyConfiguration.focus),
    pages,
    rotation_enabled: legacyConfiguration.rotation_enabled ?? true,
    page_transition: pageTransition,
    page_transition_duration_ms: clampWallboardTransitionDurationMs(
      legacyConfiguration.page_transition_duration_ms,
      DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
    ),
    page_flip_direction: normalizeWallboardFlipDirection(legacyConfiguration.page_flip_direction),
    page_fade_enabled: pageTransition !== 'none',
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

export function normalizeWallboardNewsItemTransition(value: unknown): WallboardNewsItemTransition {
  return WALLBOARD_PAGE_TRANSITIONS.some((transition) => transition.value === value)
    ? value as WallboardNewsItemTransition
    : DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION;
}

export function normalizeWallboardPageTransition(value: unknown): WallboardPageTransition {
  return WALLBOARD_PAGE_TRANSITIONS.some((transition) => transition.value === value)
    ? value as WallboardPageTransition
    : DEFAULT_WALLBOARD_PAGE_TRANSITION;
}

export function normalizeWallboardFlipDirection(value: unknown): WallboardFlipDirection {
  return WALLBOARD_FLIP_DIRECTIONS.some((direction) => direction.value === value)
    ? value as WallboardFlipDirection
    : DEFAULT_WALLBOARD_FLIP_DIRECTION;
}

export type WallboardResolvedFlipDirection = Exclude<WallboardFlipDirection, 'random'>;

export function resolveWallboardFlipDirection(
  value: unknown,
  transitionKey: string | number,
): WallboardResolvedFlipDirection {
  const direction = normalizeWallboardFlipDirection(value);
  if (direction !== 'random') return direction;

  const choices: WallboardResolvedFlipDirection[] = ['left_to_right', 'top_to_bottom', 'bottom_to_top'];
  const hash = String(transitionKey).split('').reduce(
    (current, character) => Math.imul(current ^ character.charCodeAt(0), 16777619) >>> 0,
    2166136261,
  );
  return choices[hash % choices.length];
}

export function clampWallboardTransitionDurationMs(
  value: unknown,
  fallback = DEFAULT_WALLBOARD_PAGE_TRANSITION_DURATION_MS,
): number {
  const duration = typeof value === 'number' ? value : Number.NaN;
  if (!Number.isFinite(duration)) return fallback;
  return Math.min(
    MAX_WALLBOARD_TRANSITION_DURATION_MS,
    Math.max(MIN_WALLBOARD_TRANSITION_DURATION_MS, Math.round(duration)),
  );
}

export function wallboardEffectivePageTransition(
  configuration: Pick<WallboardConfiguration, 'page_transition' | 'page_transition_duration_ms' | 'page_flip_direction'>,
  page: Pick<WallboardPage, 'transition' | 'transition_duration_ms' | 'flip_direction'>,
): { transition: WallboardPageTransition; durationMs: number; flipDirection: WallboardFlipDirection } {
  return {
    transition: page.transition === undefined
      ? normalizeWallboardPageTransition(configuration.page_transition)
      : normalizeWallboardPageTransition(page.transition),
    durationMs: page.transition_duration_ms === undefined
      ? clampWallboardTransitionDurationMs(configuration.page_transition_duration_ms)
      : clampWallboardTransitionDurationMs(page.transition_duration_ms),
    flipDirection: page.flip_direction === undefined
      ? normalizeWallboardFlipDirection(configuration.page_flip_direction)
      : normalizeWallboardFlipDirection(page.flip_direction),
  };
}

export function wallboardEffectivePageDuration(page: Pick<WallboardPage, 'type' | 'duration_seconds' | 'options'>): number {
  if (page.type === 'video') {
    const videoDurationSeconds = wallboardVideoDurationFromOptions(page.options);
    return videoDurationSeconds === null
      ? clampWallboardPageDuration(page.duration_seconds)
      : clampWallboardPageDuration(videoDurationSeconds + WALLBOARD_VIDEO_STARTUP_ALLOWANCE_SECONDS);
  }
  if (page.type !== 'news') return clampWallboardPageDuration(page.duration_seconds);

  return clampWallboardPageDuration(
    clampWallboardNewsMaxItems(Number(page.options.max_items))
      * clampWallboardNewsItemDuration(Number(page.options.item_duration_seconds)),
  );
}

export function wallboardVideoDurationFromOptions(options: WallboardPageOptions): number | null {
  const value = options.video_duration_seconds;
  return typeof value === 'number'
    && Number.isInteger(value)
    && value >= 1
    && value <= WALLBOARD_VIDEO_MAX_CONTENT_DURATION_SECONDS
    ? value
    : null;
}

export function wallboardConfigurationHasUnverifiedVideos(configuration: Pick<WallboardConfiguration, 'pages'>): boolean {
  return configuration.pages.some((page) => {
    if (page.type !== 'video') return false;
    const localUrl = wallboardLocalVideoUrl(page.options.media_asset_id);
    if (localUrl !== null) {
      return page.options.url !== localUrl || wallboardVideoDurationFromOptions(page.options) === null;
    }
    return typeof page.options.url !== 'string'
      || normalizeWallboardVideoUrl(page.options.url) === null
      || wallboardVideoDurationFromOptions(page.options) === null;
  });
}

export function wallboardConfigurationHasInvalidPhotoCarousels(
  configuration: Pick<WallboardConfiguration, 'pages'>,
  availableMediaPlaylistIds: ReadonlySet<string> | null = null,
): boolean {
  return configuration.pages.some((page) => {
    if (page.type !== 'photo_carousel') return false;
    const mediaPlaylistId = page.options.media_playlist_id;
    const itemDurationSeconds = page.options.item_duration_seconds;
    const normalizedMediaPlaylistId = typeof mediaPlaylistId === 'string' ? mediaPlaylistId.trim() : '';
    return !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(normalizedMediaPlaylistId)
      || (availableMediaPlaylistIds !== null && !availableMediaPlaylistIds.has(normalizedMediaPlaylistId))
      || typeof itemDurationSeconds !== 'number'
      || !Number.isInteger(itemDurationSeconds)
      || itemDurationSeconds < WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS
      || itemDurationSeconds > WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS;
  });
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
  const page: WallboardPage = {
    id: `page-${suffix}`,
    type,
    name: wallboardPageTypeLabel(type),
    duration_seconds: 30,
    options: type === 'message' || type === 'safety_notice'
      ? { content: wallboardRichTextFromPlainText('') }
      : type === 'quote'
        ? { quotes: [{ text: '' }] }
      : type === 'uav_forecast'
        ? {
          location_mode: DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
          visible_blocks: [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS],
        }
      : type === 'calendar'
        ? { max_items: DEFAULT_WALLBOARD_CALENDAR_MAX_ITEMS }
      : type === 'kpi'
        ? {
          visible_metrics: [...DEFAULT_WALLBOARD_KPI_VISIBLE_METRICS],
          metric_visualizations: { ...DEFAULT_WALLBOARD_KPI_VISUALIZATIONS },
        }
      : type === 'news'
        ? {
          sources: [...DEFAULT_WALLBOARD_NEWS_SOURCES],
          custom_sources: [],
          max_items: DEFAULT_WALLBOARD_NEWS_MAX_ITEMS,
          item_duration_seconds: DEFAULT_WALLBOARD_NEWS_ITEM_DURATION_SECONDS,
          item_transition: DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION,
          item_transition_duration_ms: DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS,
          item_flip_direction: DEFAULT_WALLBOARD_FLIP_DIRECTION,
        }
        : type === 'video'
          ? { url: '' }
          : type === 'photo_carousel'
            ? {
              media_playlist_id: '',
              item_duration_seconds: 12,
              item_transition: DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION,
              item_transition_duration_ms: DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
              item_flip_direction: DEFAULT_WALLBOARD_FLIP_DIRECTION,
            }
        : {},
  };

  return { ...page, duration_seconds: wallboardEffectivePageDuration(page) };
}

export function wallboardMessageContent(options: WallboardPageOptions): WallboardRichTextDocument {
  return normalizeWallboardRichText(
    options.content,
    typeof options.body === 'string' ? options.body : '',
  );
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
    case 'kpi': return 'KPI-overzicht';
    case 'calendar': return 'Agenda';
    case 'message': return 'Mededeling';
    case 'safety_notice': return 'Veiligheidsbericht';
    case 'quote': return 'Quote van de dag';
    case 'uav_forecast': return 'UAV Forecast';
    case 'news': return 'Dronenieuws';
    case 'video': return 'Video';
    case 'photo_carousel': return 'Fotocarrousel';
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

export function clampWallboardCalendarMaxItems(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_CALENDAR_MAX_ITEMS;
  return Math.min(
    MAX_WALLBOARD_CALENDAR_MAX_ITEMS,
    Math.max(MIN_WALLBOARD_CALENDAR_MAX_ITEMS, Math.round(value)),
  );
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
            operationalStatus: location.operational_status ?? location.dispatch_response_status,
            etaMinutes: typeof location.eta_minutes === 'number' && Number.isFinite(location.eta_minutes)
              ? Math.max(1, Math.ceil(location.eta_minutes))
              : null,
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
  const type: WallboardPageType = ['map', 'incident_list', 'summary', 'kpi', 'calendar', 'message', 'safety_notice', 'quote', 'uav_forecast', 'news', 'video', 'photo_carousel'].includes(page.type)
    ? page.type
    : 'map';
  const id = typeof page.id === 'string' && page.id.trim() !== '' ? page.id : `page-${index + 1}`;
  const legacyPage = page as WallboardPage & { title?: string };
  const name = typeof legacyPage.name === 'string' && legacyPage.name.trim() !== ''
    ? legacyPage.name.trim()
    : typeof legacyPage.title === 'string' && legacyPage.title.trim() !== ''
      ? legacyPage.title.trim()
    : wallboardPageTypeLabel(type);

  const normalizedPage: WallboardPage = {
    id,
    type,
    name,
    duration_seconds: page.duration_seconds,
    ...(WALLBOARD_PAGE_TRANSITIONS.some((transition) => transition.value === page.transition)
      ? { transition: page.transition }
      : {}),
    ...(typeof page.transition_duration_ms === 'number' && Number.isFinite(page.transition_duration_ms)
      ? { transition_duration_ms: clampWallboardTransitionDurationMs(page.transition_duration_ms) }
      : {}),
    ...(page.flip_direction === undefined
      ? {}
      : { flip_direction: normalizeWallboardFlipDirection(page.flip_direction) }),
    options: type === 'message' || type === 'safety_notice'
      ? { content: wallboardMessageContent(page.options ?? {}) }
      : type === 'quote'
        ? { quotes: normalizeWallboardQuotes(page.options?.quotes) }
      : type === 'uav_forecast'
        ? normalizeWallboardForecastPageOptions(page)
      : type === 'calendar'
        ? { max_items: clampWallboardCalendarMaxItems(Number(page.options?.max_items)) }
      : type === 'kpi'
        ? normalizeWallboardKpiPageOptions(page)
      : type === 'news'
        ? normalizeWallboardNewsPageOptions(page)
        : type === 'video'
          ? normalizeWallboardVideoPageOptions(page)
          : type === 'photo_carousel'
            ? normalizeWallboardPhotoPageOptions(page)
        : {},
  };

  return {
    ...normalizedPage,
    duration_seconds: wallboardEffectivePageDuration(normalizedPage),
  };
}

export const MAX_WALLBOARD_QUOTES = 50;
export const MAX_WALLBOARD_QUOTE_TEXT_LENGTH = 500;
export const MAX_WALLBOARD_QUOTE_AUTHOR_LENGTH = 120;

export function normalizeWallboardQuotes(value: unknown): NonNullable<WallboardPage['options']['quotes']> {
  if (!Array.isArray(value)) return [];
  return value.slice(0, MAX_WALLBOARD_QUOTES).flatMap((candidate) => {
    if (typeof candidate !== 'object' || candidate === null || Array.isArray(candidate)) return [];
    const quote = candidate as { text?: unknown; author?: unknown };
    const text = typeof quote.text === 'string'
      ? quote.text.trim().slice(0, MAX_WALLBOARD_QUOTE_TEXT_LENGTH)
      : '';
    if (text === '') return [];
    const author = typeof quote.author === 'string'
      ? quote.author.trim().slice(0, MAX_WALLBOARD_QUOTE_AUTHOR_LENGTH)
      : '';
    return [{ text, ...(author === '' ? {} : { author }) }];
  });
}

export function wallboardAmsterdamDateKey(date: Date): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Europe/Amsterdam',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);
  const part = (type: 'year' | 'month' | 'day') => parts.find((candidate) => candidate.type === type)?.value ?? '00';
  return `${part('year')}-${part('month')}-${part('day')}`;
}

export function selectWallboardDailyQuote(
  value: unknown,
  pageId: string,
  date = new Date(),
): NonNullable<WallboardPage['options']['quotes']>[number] | null {
  const quotes = normalizeWallboardQuotes(value);
  if (quotes.length === 0) return null;
  const dateKey = wallboardAmsterdamDateKey(date);
  const seed = `${pageId}|${dateKey}`;
  let hash = 2166136261;
  for (let index = 0; index < seed.length; index += 1) {
    hash ^= seed.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return quotes[(hash >>> 0) % quotes.length] ?? quotes[0];
}

export function normalizeWallboardForecastPageOptions(page: WallboardPage): WallboardPage['options'] {
  const label = typeof page.options.location_label === 'string'
    ? page.options.location_label.trim().slice(0, 120)
    : '';
  const explicitMode = page.options.location_mode === 'address'
    ? 'address'
    : page.options.location_mode === 'netherlands'
      ? 'netherlands'
      : null;
  const locationMode: WallboardForecastLocationMode = explicitMode
    ?? (label !== '' && label !== 'UAV Nederland' ? 'address' : DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE);
  const suppliedBlocks = Array.isArray(page.options.visible_blocks)
    ? page.options.visible_blocks
    : null;
  const selectedBlocks = suppliedBlocks === null
    ? null
    : new Set(arraysEqual(suppliedBlocks, LEGACY_DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS)
      ? DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS
      : suppliedBlocks);
  const visibleBlocks = selectedBlocks === null
    ? [...DEFAULT_WALLBOARD_FORECAST_VISIBLE_BLOCKS]
    : WALLBOARD_FORECAST_BLOCK_KEYS.filter((key) => selectedBlocks.has(key));

  return locationMode === 'address'
    ? { location_mode: 'address', location_label: label, visible_blocks: visibleBlocks }
    : {
      location_mode: DEFAULT_WALLBOARD_FORECAST_LOCATION_MODE,
      visible_blocks: visibleBlocks,
    };
}

function arraysEqual<T>(first: readonly T[], second: readonly T[]): boolean {
  return first.length === second.length && first.every((value, index) => value === second[index]);
}

export function normalizeWallboardKpiPageOptions(page: WallboardPage): WallboardPage['options'] {
  const selectedMetrics = Array.isArray(page.options.visible_metrics)
    ? new Set(page.options.visible_metrics)
    : null;
  const rawVisualizations = typeof page.options.metric_visualizations === 'object'
    && page.options.metric_visualizations !== null
    && !Array.isArray(page.options.metric_visualizations)
    ? page.options.metric_visualizations
    : {};
  const metricVisualizations = Object.fromEntries(WALLBOARD_KPI_KEYS.map((key) => {
    const candidate = rawVisualizations[key];
    return [
      key,
      typeof candidate === 'string'
        && wallboardKpiSupportedVisualizations(key).includes(candidate as WallboardKpiVisualization)
        ? candidate
        : wallboardKpiDefaultVisualization(key),
    ];
  })) as Record<WallboardKpiKey, WallboardKpiVisualization>;

  return {
    visible_metrics: selectedMetrics === null
      ? [...WALLBOARD_KPI_KEYS]
      : WALLBOARD_KPI_KEYS.filter((key) => selectedMetrics.has(key)),
    metric_visualizations: metricVisualizations,
  };
}

export function wallboardVisibleKpiKeys(page: WallboardPage): WallboardKpiKey[] {
  return normalizeWallboardKpiPageOptions(page).visible_metrics ?? [];
}

export function wallboardKpiSupportedVisualizations(key: WallboardKpiKey): readonly WallboardKpiVisualization[] {
  return WALLBOARD_KPI_DISTRIBUTION_KEYS.has(key) || WALLBOARD_KPI_RATIO_KEYS.has(key)
    ? WALLBOARD_KPI_VISUALIZATIONS
    : ['counter'];
}

export function wallboardKpiDefaultVisualization(key: WallboardKpiKey): WallboardKpiVisualization {
  if (key === 'drones_flown_distribution') return 'pie';
  if (key === 'incidents_by_province' || key === 'incidents_by_country') return 'bar';
  return 'counter';
}

export function wallboardKpiVisualization(
  page: WallboardPage,
  key: WallboardKpiKey,
): WallboardKpiVisualization {
  return normalizeWallboardKpiPageOptions(page).metric_visualizations?.[key]
    ?? wallboardKpiDefaultVisualization(key);
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
      const shortsMatch = /^\/shorts\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname);
      const videoId = watchId ?? embedMatch?.[1] ?? shortsMatch?.[1] ?? null;
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

/** Bouwt een vast, gedempt embed-adres uit een server-gecanonicaliseerde URL. */
export function wallboardVideoEmbedUrl(value: unknown, autoplay = true): string | null {
  if (typeof value !== 'string') return null;
  const canonical = normalizeWallboardVideoUrl(value);
  if (canonical === null || canonical !== value.trim().replace(/\/$/, '')) return null;

  const url = new URL(canonical);
  if (url.hostname === 'www.youtube.com') {
    const videoId = url.pathname.split('/').at(-1);
    if (videoId === undefined) return null;
    url.search = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      mute: '1',
      controls: '0',
      rel: '0',
      playsinline: '1',
      disablekb: '1',
      fs: '0',
      iv_load_policy: '3',
    }).toString();
  } else {
    url.search = new URLSearchParams({
      autoplay: autoplay ? '1' : '0',
      muted: '1',
      controls: '0',
      autopause: '0',
      dnt: '1',
      title: '0',
      byline: '0',
      portrait: '0',
      badge: '0',
    }).toString();
  }

  return url.toString();
}

function normalizeWallboardVideoPageOptions(page: WallboardPage): WallboardPage['options'] {
  const mediaAssetId = normalizeWallboardMediaAssetId(page.options?.media_asset_id);
  const localUrl = wallboardLocalVideoUrl(mediaAssetId);
  const videoDurationSeconds = wallboardVideoDurationFromOptions(page.options ?? {});
  if (localUrl !== null) {
    return {
      media_asset_id: mediaAssetId,
      url: localUrl,
      ...(videoDurationSeconds === null ? {} : { video_duration_seconds: videoDurationSeconds }),
    };
  }
  const url = typeof page.options?.url === 'string'
    ? (normalizeWallboardVideoUrl(page.options.url) ?? page.options.url.trim())
    : '';
  return {
    url,
    ...(videoDurationSeconds === null ? {} : { video_duration_seconds: videoDurationSeconds }),
  };
}

function normalizeWallboardPhotoPageOptions(page: WallboardPage): WallboardPage['options'] {
  const mediaPlaylistId = normalizeWallboardMediaPlaylistId(page.options?.media_playlist_id);

  return {
    media_playlist_id: mediaPlaylistId,
    item_duration_seconds: wallboardPhotoItemDurationSeconds(page.options?.item_duration_seconds),
    item_transition: normalizeWallboardNewsItemTransition(page.options?.item_transition),
    item_transition_duration_ms: clampWallboardTransitionDurationMs(
      page.options?.item_transition_duration_ms,
      DEFAULT_WALLBOARD_PHOTO_ITEM_TRANSITION_DURATION_MS,
    ),
    item_flip_direction: normalizeWallboardFlipDirection(page.options?.item_flip_direction),
  };
}

export function normalizeWallboardMediaPlaylistId(value: unknown): string {
  if (typeof value !== 'string') return '';
  const trimmed = value.trim();

  return /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(trimmed) ? trimmed.toLowerCase() : '';
}

export function normalizeWallboardMediaAssetId(value: unknown): string {
  if (typeof value !== 'string') return '';
  const trimmed = value.trim();

  return /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(trimmed) ? trimmed.toUpperCase() : '';
}

export function wallboardLocalVideoUrl(value: unknown): string | null {
  const mediaAssetId = normalizeWallboardMediaAssetId(value);
  return mediaAssetId === '' ? null : `/api/wallboard/media/${mediaAssetId}`;
}

function normalizeWallboardNewsPageOptions(page: WallboardPage): WallboardPage['options'] {
  const customSources = normalizeWallboardCustomNewsSources(page.options?.custom_sources);
  return {
    sources: normalizeWallboardNewsSources(page.options?.sources, customSources.length > 0),
    custom_sources: customSources,
    max_items: clampWallboardNewsMaxItems(Number(page.options?.max_items)),
    item_duration_seconds: clampWallboardNewsItemDuration(Number(page.options?.item_duration_seconds)),
    item_transition: normalizeWallboardNewsItemTransition(page.options?.item_transition),
    item_transition_duration_ms: clampWallboardTransitionDurationMs(
      page.options?.item_transition_duration_ms,
      DEFAULT_WALLBOARD_NEWS_ITEM_TRANSITION_DURATION_MS,
    ),
    item_flip_direction: normalizeWallboardFlipDirection(page.options?.item_flip_direction),
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
