import type {
  Wallboard,
  WallboardConfiguration,
  WallboardMapConfiguration,
  WallboardPage,
  WallboardPageType,
  WallboardState,
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
  };
  const fallbackMap = { ...DEFAULT_WALLBOARD_MAP_CONFIGURATION, ...legacyConfiguration.map };
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
      : ['incident_list', 'summary'].includes(type)
        ? { show_test_incidents: false }
        : {},
  };
}

export function wallboardPageTypeLabel(type: WallboardPageType): string {
  switch (type) {
    case 'map': return 'Operationele kaart';
    case 'incident_list': return 'Incidentenoverzicht';
    case 'summary': return 'Operationele samenvatting';
    case 'message': return 'Mededeling';
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
    show_test_incidents: page.options.show_test_incidents ?? configuration.map.show_test_incidents,
  };
}

export function clampRefreshSeconds(value: number): number {
  if (!Number.isFinite(value)) return DEFAULT_WALLBOARD_CONFIGURATION.refresh_seconds;
  return Math.min(MAX_WALLBOARD_REFRESH_SECONDS, Math.max(MIN_WALLBOARD_REFRESH_SECONDS, Math.round(value)));
}

export function wallboardIsOnline(wallboard: Wallboard, now = Date.now()): boolean {
  if (
    !wallboard.is_enabled
    || wallboard.active_sessions_count <= 0
    || wallboard.last_seen_at === null
    || wallboard.last_seen_at === undefined
  ) return false;
  const lastSeen = Date.parse(wallboard.last_seen_at);
  if (!Number.isFinite(lastSeen)) return false;
  const allowedAge = Math.max(60, clampRefreshSeconds(wallboard.configuration.refresh_seconds) * 3) * 1000;
  return now - lastSeen <= allowedAge;
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
    && (configuration.show_test_incidents || !incident.is_test)
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
  const type: WallboardPageType = ['map', 'incident_list', 'summary', 'message'].includes(page.type)
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
      : ['incident_list', 'summary'].includes(type)
        ? { show_test_incidents: page.options?.show_test_incidents === true }
        : {},
  };
}
