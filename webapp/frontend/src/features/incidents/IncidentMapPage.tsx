import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Link from 'next/link';
import { ChevronDown, Flag, Home, Layers3, MapPin, Maximize2, Minimize2, Navigation, RadioTower, RefreshCw, UsersRound } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import type { Incident, IncidentLiveLocation, OperationalMapLayers } from '../../types/api';
import { isCurrentLiveLocation } from './etaPresentation';

const OPEN_INCIDENTS_PATH = '/incidents?status=draft,active,dispatching,in_progress';
const MAP_LAYERS_PATH = '/operational-map/layers';
const MAP_WIDTH = 1280;
const MAP_HEIGHT = 720;
const INCIDENT_COLORS = ['#7dd3fc', '#fbbf24', '#a7f3d0', '#fca5a5', '#c4b5fd', '#fdba74', '#93c5fd', '#f0abfc'];
const NETHERLANDS_OVERVIEW_CENTER: MapPoint = { latitude: 52.1326, longitude: 5.2913 };
const NETHERLANDS_OVERVIEW_VIEWPORT: MapViewport = { width: MAP_WIDTH, height: MAP_HEIGHT, zoom: 7 };
const DEFAULT_LAYER_VISIBILITY: MapLayerVisibility = {
  commandCenters: true,
  historicalIncidents: false,
  pilotHomes: false,
};

export function IncidentMapPage() {
  const { api } = useAuth();
  const incidents = useApiResource<Incident[]>(OPEN_INCIDENTS_PATH);
  const reloadIncidentsSilently = incidents.silentReload;
  const mapLayers = useApiResource<OperationalMapLayers>(MAP_LAYERS_PATH);
  const fullscreenRootRef = useRef<HTMLDivElement | null>(null);
  const [locationsByIncident, setLocationsByIncident] = useState<Record<string, IncidentLiveLocation[]>>({});
  const [locationError, setLocationError] = useState<string | null>(null);
  const [locationsLoading, setLocationsLoading] = useState(false);
  const [fullscreenMode, setFullscreenMode] = useState<'none' | 'browser' | 'web'>('none');
  const [layerVisibility, setLayerVisibility] = useState<MapLayerVisibility>(DEFAULT_LAYER_VISIBILITY);
  const [layerFilterOpen, setLayerFilterOpen] = useState(false);
  const incidentItems = useMemo(() => incidents.data ?? [], [incidents.data]);
  const isFullscreen = fullscreenMode !== 'none';

  const loadLiveLocations = useCallback(async (items: Incident[], options?: { silent?: boolean }) => {
    if (items.length === 0) {
      setLocationsByIncident({});
      setLocationError(null);
      return;
    }

    if (options?.silent !== true) {
      setLocationsLoading(true);
    }
    setLocationError(null);

    try {
      const responses = await Promise.all(items.map(async (incident) => {
        const response = await api.get<IncidentLiveLocation[]>(`/incidents/${incident.id}/live-locations`);
        return [incident.id, response.data] as const;
      }));
      setLocationsByIncident(Object.fromEntries(responses));
    } catch (err) {
      setLocationError(err instanceof ApiClientError ? err.message : 'Live locaties konden niet worden geladen.');
    } finally {
      if (options?.silent !== true) {
        setLocationsLoading(false);
      }
    }
  }, [api]);

  useEffect(() => {
    void loadLiveLocations(incidentItems);
  }, [incidentItems, loadLiveLocations]);

  useEffect(() => {
    const interval = window.setInterval(() => {
      void reloadIncidentsSilently();
      void loadLiveLocations(incidentItems, { silent: true });
    }, 10_000);

    return () => window.clearInterval(interval);
  }, [incidentItems, loadLiveLocations, reloadIncidentsSilently]);

  useEffect(() => {
    if (!isFullscreen) {
      return undefined;
    }

    document.body.classList.add('operational-map-scroll-lock');
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        void exitFullscreen();
      }
    };

    window.addEventListener('keydown', closeOnEscape);

    return () => {
      document.body.classList.remove('operational-map-scroll-lock');
      window.removeEventListener('keydown', closeOnEscape);
    };
  }, [isFullscreen]);

  useEffect(() => {
    const onFullscreenChange = () => {
      if (document.fullscreenElement === fullscreenRootRef.current) {
        setFullscreenMode('browser');
        return;
      }

      setFullscreenMode((current) => current === 'browser' ? 'none' : current);
    };

    document.addEventListener('fullscreenchange', onFullscreenChange);

    return () => document.removeEventListener('fullscreenchange', onFullscreenChange);
  }, []);

  const models = useMemo(() => buildIncidentMapModels(incidentItems, locationsByIncident), [incidentItems, locationsByIncident]);
  const layerModels = useMemo(() => buildOperationalLayerModels(mapLayers.data), [mapLayers.data]);
  const summary = useMemo(() => ({
    incidents: models.length,
    liveUsers: models.reduce((total, model) => total + model.liveLocations.length, 0),
    linkedUsers: models.reduce((total, model) => total + model.locations.length, 0),
  }), [models]);

  async function refresh() {
    await incidents.reload();
    await mapLayers.reload();
    await loadLiveLocations(incidentItems);
  }

  async function enterFullscreen() {
    const root = fullscreenRootRef.current;
    if (root === null) {
      setFullscreenMode('web');
      return;
    }

    try {
      if (root.requestFullscreen !== undefined) {
        await root.requestFullscreen();
        setFullscreenMode('browser');
        return;
      }
    } catch {
      // Browser fullscreen can be blocked by browser policy; fall back to an in-page fullscreen layer.
    }

    setFullscreenMode('web');
  }

  async function exitFullscreen() {
    if (document.fullscreenElement === fullscreenRootRef.current) {
      await document.exitFullscreen().catch(() => undefined);
    }
    setFullscreenMode('none');
  }

  function toggleFullscreen() {
    if (isFullscreen) {
      void exitFullscreen();
      return;
    }

    void enterFullscreen();
  }

  function handleRealtimeEvent() {
    void reloadIncidentsSilently();
    void mapLayers.silentReload();
    void loadLiveLocations(incidentItems, { silent: true });
  }

  function toggleLayer(layer: keyof MapLayerVisibility) {
    setLayerVisibility((current) => ({
      ...current,
      [layer]: !current[layer],
    }));
  }

  const panelAction = (
    <div className="operational-map__actions">
      <div className="operational-map__layer-filter">
        <button
          className="secondary-button"
          type="button"
          onClick={() => setLayerFilterOpen((open) => !open)}
          aria-expanded={layerFilterOpen}
          aria-haspopup="menu"
        >
          <Layers3 size={16} />
          Lagen
          <ChevronDown size={15} />
        </button>
        {layerFilterOpen ? (
          <div className="operational-map__layer-menu" role="menu">
            <LayerToggle
              checked={layerVisibility.commandCenters}
              icon={<Flag size={16} />}
              label="Meldkamers"
              count={layerModels.commandCenters.length}
              onChange={() => toggleLayer('commandCenters')}
            />
            <LayerToggle
              checked={layerVisibility.historicalIncidents}
              icon={<MapPin size={16} />}
              label="Eerdere inzetten"
              count={layerModels.historicalIncidents.length}
              onChange={() => toggleLayer('historicalIncidents')}
            />
            <LayerToggle
              checked={layerVisibility.pilotHomes}
              icon={<Home size={16} />}
              label="Woonplaatsen piloten"
              count={layerModels.pilotHomes.length}
              onChange={() => toggleLayer('pilotHomes')}
            />
          </div>
        ) : null}
      </div>
      <button className="secondary-button" type="button" onClick={refresh} disabled={incidents.loading || mapLayers.loading || locationsLoading}>
        <RefreshCw size={16} />
        Verversen
      </button>
      <button className="secondary-button" type="button" onClick={toggleFullscreen} aria-pressed={isFullscreen}>
        {isFullscreen ? <Minimize2 size={16} /> : <Maximize2 size={16} />}
        {isFullscreen ? 'Normaal' : 'Fullscreen'}
      </button>
    </div>
  );

  return (
    <div ref={fullscreenRootRef} className={`page-stack operational-map-page ${isFullscreen ? 'operational-map-page--fullscreen' : ''} ${fullscreenMode === 'browser' ? 'operational-map-page--browser-fullscreen' : ''}`}>
      <RealtimeBridge onOperationalEvent={handleRealtimeEvent} />
      <Panel title="Operationele kaart" action={panelAction}>
        <ResourceState loading={(incidents.loading && models.length === 0) || (mapLayers.loading && mapLayers.data == null)} error={incidents.error ?? mapLayers.error} empty={false}>
          <div className="operational-map">
            {locationError ? <p className="form-error">{locationError}</p> : null}
            <div className="operational-map__livebar" aria-live="polite">
              <span className="operational-map__live-dot" aria-hidden />
              Live kaart
              <small>Automatisch bijgewerkt elke 10 seconden en bij realtime incidentupdates.</small>
            </div>
            <div className="operational-map__summary" aria-label="Kaart samenvatting">
              <SummaryItem icon={<RadioTower size={18} />} label="Open incidenten" value={String(summary.incidents)} />
              <SummaryItem icon={<Navigation size={18} />} label="Live op kaart" value={String(summary.liveUsers)} />
              <SummaryItem icon={<UsersRound size={18} />} label="Gekoppelde gebruikers" value={String(summary.linkedUsers)} />
            </div>
            <OperationsMap models={models} layers={layerModels} layerVisibility={layerVisibility} />
            <IncidentMapList models={models} />
          </div>
        </ResourceState>
      </Panel>
    </div>
  );
}

function OperationsMap({
  models,
  layers,
  layerVisibility,
}: {
  models: IncidentMapModel[];
  layers: OperationalLayerModels;
  layerVisibility: MapLayerVisibility;
}) {
  const points = models.flatMap((model) => [
    ...(model.incidentPoint ? [model.incidentPoint] : []),
    ...model.liveLocations,
  ]);
  const visibleLayerPoints = [
    ...(layerVisibility.commandCenters ? layers.commandCenters : []),
    ...(layerVisibility.historicalIncidents ? layers.historicalIncidents : []),
    ...(layerVisibility.pilotHomes ? layers.pilotHomes : []),
  ];
  const allPoints = [...points, ...visibleLayerPoints];
  const hasOperationalPoints = allPoints.length > 0;
  const viewport = hasOperationalPoints ? mapViewport(allPoints) : NETHERLANDS_OVERVIEW_VIEWPORT;
  const center = hasOperationalPoints ? centerFor(allPoints) : NETHERLANDS_OVERVIEW_CENTER;
  const centerWorld = latLonToWorld(center.latitude, center.longitude, viewport.zoom);
  const tiles = visibleTiles(centerWorld, viewport);

  return (
    <div className="operational-map__canvas">
      <svg className="operational-map__svg" viewBox={`0 0 ${viewport.width} ${viewport.height}`} role="img" aria-label={models.length > 0 ? 'Kaart met incidenten en live gebruikerslocaties' : 'Kaart van Nederland zonder actieve incidenten'}>
        {tiles.map((tile) => (
          <image
            key={`imagery-${tile.x}-${tile.y}-${tile.z}`}
            href={`https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${tile.z}/${tile.y}/${tile.x}`}
            x={tile.left}
            y={tile.top}
            width="256"
            height="256"
            preserveAspectRatio="none"
          />
        ))}
        <rect className="operational-map__shade" x="0" y="0" width={viewport.width} height={viewport.height} />
        {tiles.map((tile) => (
          <image
            key={`labels-${tile.x}-${tile.y}-${tile.z}`}
            href={`https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/${tile.z}/${tile.y}/${tile.x}`}
            x={tile.left}
            y={tile.top}
            width="256"
            height="256"
            preserveAspectRatio="none"
          />
        ))}
        {models.map((model) => model.liveLocations.map((location) => {
          if (model.incidentPoint === null) {
            return null;
          }

          const from = markerPosition(location, centerWorld, viewport);
          const to = markerPosition(model.incidentPoint, centerWorld, viewport);

          return (
            <line
              key={`${model.incident.id}-${location.userId}-line`}
              className="operational-map__route-line"
              x1={from.x}
              y1={from.y}
              x2={to.x}
              y2={to.y}
              stroke={model.color}
            />
          );
        }))}
        {models.map((model) => model.incidentPoint === null ? null : (
          <MapMarker
            key={`${model.incident.id}-incident`}
            point={model.incidentPoint}
            centerWorld={centerWorld}
            viewport={viewport}
            color={model.color}
            label={model.incident.title}
            type="incident"
          />
        ))}
        {models.map((model) => model.liveLocations.map((location) => (
          <MapMarker
            key={`${model.incident.id}-${location.userId}`}
            point={location}
            centerWorld={centerWorld}
            viewport={viewport}
            color={model.color}
            label={location.name}
            type="user"
          />
        )))}
        {layerVisibility.commandCenters ? layers.commandCenters.map((centerPoint) => (
          <CommandCenterMarker key={centerPoint.id} point={centerPoint} centerWorld={centerWorld} viewport={viewport} />
        )) : null}
        {layerVisibility.historicalIncidents ? layers.historicalIncidents.map((incident) => (
          <HistoricalIncidentMarker key={incident.id} point={incident} centerWorld={centerWorld} viewport={viewport} />
        )) : null}
        {layerVisibility.pilotHomes ? layers.pilotHomes.map((homePoint) => (
          <PilotHomeMarker key={homePoint.id} point={homePoint} centerWorld={centerWorld} viewport={viewport} />
        )) : null}
      </svg>
      {models.length === 0 ? (
        <div className="operational-map__empty-state">
          <strong>Geen actieve incidenten</strong>
          <span>Nederland blijft als standaardkaart zichtbaar.</span>
        </div>
      ) : null}
    </div>
  );
}

function LayerToggle({
  checked,
  icon,
  label,
  count,
  onChange,
}: {
  checked: boolean;
  icon: ReactNode;
  label: string;
  count: number;
  onChange: () => void;
}) {
  return (
    <label className="operational-map__layer-option" role="menuitemcheckbox" aria-checked={checked}>
      <input type="checkbox" checked={checked} onChange={onChange} />
      <span className="operational-map__layer-check" aria-hidden />
      <span className="operational-map__layer-icon" aria-hidden>{icon}</span>
      <span>{label}</span>
      <small>{count}</small>
    </label>
  );
}

function MapMarker({
  point,
  centerWorld,
  viewport,
  color,
  label,
  type,
}: {
  point: MapPoint;
  centerWorld: WorldPoint;
  viewport: MapViewport;
  color: string;
  label: string;
  type: 'incident' | 'user';
}) {
  const position = markerPosition(point, centerWorld, viewport);
  const labelAnchor = position.x > viewport.width - 220 ? 'end' : 'start';
  const labelX = labelAnchor === 'end' ? position.x - 16 : position.x + 16;
  const labelY = type === 'incident' ? position.y - 14 : position.y + 5;

  return (
    <g className={`operational-map__marker operational-map__marker--${type}`}>
      <circle cx={position.x} cy={position.y} r={type === 'incident' ? 11 : 7} fill={color} />
      <circle cx={position.x} cy={position.y} r={type === 'incident' ? 17 : 12} stroke={color} />
      <title>{label}</title>
      <text x={labelX} y={labelY} textAnchor={labelAnchor}>{shortMapLabel(label, type === 'incident' ? 38 : 26)}</text>
    </g>
  );
}

function CommandCenterMarker({
  point,
  centerWorld,
  viewport,
}: {
  point: CommandCenterMapPoint;
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  const position = markerPosition(point, centerWorld, viewport);

  return (
    <g className="operational-map__marker operational-map__marker--command-center">
      <title>{point.name}</title>
      <line x1={position.x} y1={position.y - 24} x2={position.x} y2={position.y + 14} />
      <path d={`M ${position.x} ${position.y - 24} L ${position.x + 27} ${position.y - 18} L ${position.x} ${position.y - 10} Z`} />
      <circle cx={position.x} cy={position.y + 14} r="5" />
    </g>
  );
}

function HistoricalIncidentMarker({
  point,
  centerWorld,
  viewport,
}: {
  point: HistoricalIncidentMapPoint;
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  const position = markerPosition(point, centerWorld, viewport);

  return (
    <g className="operational-map__marker operational-map__marker--historical">
      <title>{`${point.reference} - ${point.title}`}</title>
      <path d={`M ${position.x} ${position.y - 22} C ${position.x - 13} ${position.y - 22}, ${position.x - 18} ${position.y - 7}, ${position.x} ${position.y + 18} C ${position.x + 18} ${position.y - 7}, ${position.x + 13} ${position.y - 22}, ${position.x} ${position.y - 22} Z`} />
      <circle cx={position.x} cy={position.y - 8} r="6" />
    </g>
  );
}

function PilotHomeMarker({
  point,
  centerWorld,
  viewport,
}: {
  point: PilotHomeMapPoint;
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  const position = markerPosition(point, centerWorld, viewport);
  const label = point.homeCity ? `${point.name} - ${point.homeCity}` : point.name;

  return (
    <g className="operational-map__marker operational-map__marker--pilot-home">
      <title>{label}</title>
      <path d={`M ${position.x - 17} ${position.y - 4} L ${position.x} ${position.y - 19} L ${position.x + 17} ${position.y - 4}`} />
      <path d={`M ${position.x - 12} ${position.y - 3} L ${position.x - 12} ${position.y + 15} L ${position.x + 12} ${position.y + 15} L ${position.x + 12} ${position.y - 3} Z`} />
    </g>
  );
}

function IncidentMapList({ models }: { models: IncidentMapModel[] }) {
  if (models.length === 0) {
    return null;
  }

  return (
    <div className="operational-map__list">
      {models.map((model) => (
        <article className={`operational-map-card operational-map-card--color-${model.colorIndex}`} key={model.incident.id}>
          <header>
            <span className="operational-map-card__color" aria-hidden />
            <div>
              <Link href={`/incidents/${model.incident.id}`}>{model.incident.title}</Link>
              <small>{model.incident.location_label ?? 'Geen locatie bekend'}</small>
            </div>
            <StatusPill value={priorityLabel(model.incident.priority)} tone={priorityTone(model.incident.priority)} />
          </header>
        </article>
      ))}
    </div>
  );
}

function SummaryItem({ icon, label, value }: { icon: ReactNode; label: string; value: string }) {
  return (
    <div>
      <span>{icon}</span>
      <small>{label}</small>
      <strong>{value}</strong>
    </div>
  );
}

interface IncidentMapModel {
  incident: Incident;
  color: string;
  colorIndex: number;
  incidentPoint: MapPoint | null;
  locations: IncidentLiveLocation[];
  liveLocations: UserMapPoint[];
}

interface MapLayerVisibility {
  commandCenters: boolean;
  historicalIncidents: boolean;
  pilotHomes: boolean;
}

interface OperationalLayerModels {
  commandCenters: CommandCenterMapPoint[];
  historicalIncidents: HistoricalIncidentMapPoint[];
  pilotHomes: PilotHomeMapPoint[];
}

interface MapPoint {
  latitude: number;
  longitude: number;
}

interface CommandCenterMapPoint extends MapPoint {
  id: string;
  name: string;
}

interface HistoricalIncidentMapPoint extends MapPoint {
  id: string;
  reference: string;
  title: string;
}

interface PilotHomeMapPoint extends MapPoint {
  id: string;
  name: string;
  homeCity: string | null;
}

interface UserMapPoint extends MapPoint {
  userId: string;
  name: string;
}

function buildOperationalLayerModels(layers: OperationalMapLayers | null): OperationalLayerModels {
  return {
    commandCenters: (layers?.command_centers ?? []).flatMap((center) => {
      const point = coordinatePoint(center.latitude, center.longitude);
      return point === null ? [] : [{
        id: center.id,
        name: center.name,
        latitude: point.latitude,
        longitude: point.longitude,
      }];
    }),
    historicalIncidents: (layers?.historical_incidents ?? []).flatMap((incident) => {
      const point = coordinatePoint(incident.latitude, incident.longitude);
      return point === null ? [] : [{
        id: incident.id,
        reference: incident.reference,
        title: incident.title,
        latitude: point.latitude,
        longitude: point.longitude,
      }];
    }),
    pilotHomes: (layers?.pilot_homes ?? []).flatMap((home) => {
      const point = coordinatePoint(home.latitude, home.longitude);
      return point === null ? [] : [{
        id: home.id,
        name: home.name,
        homeCity: home.home_city ?? null,
        latitude: point.latitude,
        longitude: point.longitude,
      }];
    }),
  };
}

function buildIncidentMapModels(incidents: Incident[], locationsByIncident: Record<string, IncidentLiveLocation[]>): IncidentMapModel[] {
  return incidents
    .filter((incident) => !['resolved', 'cancelled'].includes(incident.status))
    .map((incident, index) => {
      const colorIndex = index % INCIDENT_COLORS.length;
      const color = INCIDENT_COLORS[colorIndex];
      const locations = locationsByIncident[incident.id] ?? [];

      return {
        incident,
        color,
        colorIndex,
        incidentPoint: coordinatePoint(incident.latitude, incident.longitude),
        locations,
        liveLocations: locations
          .filter(isLiveLocation)
          .map((location) => ({
            latitude: Number(location.latitude),
            longitude: Number(location.longitude),
            userId: location.user_id,
            name: location.user?.name ?? location.user_id,
          })),
      };
    });
}

function coordinatePoint(latitude: string | number | null | undefined, longitude: string | number | null | undefined): MapPoint | null {
  const lat = Number(latitude);
  const lon = Number(longitude);

  if (!Number.isFinite(lat) || !Number.isFinite(lon)) {
    return null;
  }

  return { latitude: lat, longitude: lon };
}

function isLiveLocation(location: IncidentLiveLocation): boolean {
  const point = coordinatePoint(location.latitude, location.longitude);
  return point !== null && isCurrentLiveLocation(location);
}

function shortMapLabel(value: string, maxLength: number): string {
  const trimmed = value.trim();
  if (trimmed.length <= maxLength) {
    return trimmed;
  }

  return `${trimmed.slice(0, Math.max(0, maxLength - 1)).trim()}...`;
}

function priorityLabel(priority: Incident['priority']): string {
  switch (priority) {
    case 'critical':
      return 'Kritiek';
    case 'high':
      return 'Hoog';
    case 'normal':
      return 'Normaal';
    case 'low':
      return 'Laag';
    default:
      return priority;
  }
}

function priorityTone(priority: Incident['priority']): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (priority) {
    case 'critical':
      return 'bad';
    case 'high':
      return 'warn';
    case 'low':
      return 'good';
    default:
      return 'neutral';
  }
}

interface MapBounds {
  minLat: number;
  maxLat: number;
  minLon: number;
  maxLon: number;
}

interface MapViewport {
  width: number;
  height: number;
  zoom: number;
}

interface WorldPoint {
  x: number;
  y: number;
}

interface TilePosition {
  x: number;
  y: number;
  z: number;
  left: number;
  top: number;
}

function boundsFor(points: MapPoint[]): MapBounds {
  const latitudes = points.map((point) => point.latitude);
  const longitudes = points.map((point) => point.longitude);
  const minLat = Math.min(...latitudes);
  const maxLat = Math.max(...latitudes);
  const minLon = Math.min(...longitudes);
  const maxLon = Math.max(...longitudes);
  const latPadding = Math.max((maxLat - minLat) * 0.22, 0.012);
  const lonPadding = Math.max((maxLon - minLon) * 0.22, 0.018);

  return {
    minLat: minLat - latPadding,
    maxLat: maxLat + latPadding,
    minLon: minLon - lonPadding,
    maxLon: maxLon + lonPadding,
  };
}

function centerFor(points: MapPoint[]): MapPoint {
  const bounds = boundsFor(points);

  return {
    latitude: (bounds.minLat + bounds.maxLat) / 2,
    longitude: (bounds.minLon + bounds.maxLon) / 2,
  };
}

function mapViewport(points: MapPoint[]): MapViewport {
  const bounds = boundsFor(points);

  for (let zoom = 16; zoom >= 5; zoom -= 1) {
    const northWest = latLonToWorld(bounds.maxLat, bounds.minLon, zoom);
    const southEast = latLonToWorld(bounds.minLat, bounds.maxLon, zoom);
    if (Math.abs(southEast.x - northWest.x) <= MAP_WIDTH - 150 && Math.abs(southEast.y - northWest.y) <= MAP_HEIGHT - 150) {
      return { width: MAP_WIDTH, height: MAP_HEIGHT, zoom };
    }
  }

  return { width: MAP_WIDTH, height: MAP_HEIGHT, zoom: 5 };
}

function latLonToWorld(latitude: number, longitude: number, zoom: number): WorldPoint {
  const sinLatitude = Math.sin((clamp(latitude, -85.05112878, 85.05112878) * Math.PI) / 180);
  const scale = 256 * 2 ** zoom;

  return {
    x: ((longitude + 180) / 360) * scale,
    y: (0.5 - Math.log((1 + sinLatitude) / (1 - sinLatitude)) / (4 * Math.PI)) * scale,
  };
}

function visibleTiles(center: WorldPoint, viewport: MapViewport): TilePosition[] {
  const tileSize = 256;
  const minTileX = Math.floor((center.x - viewport.width / 2) / tileSize);
  const maxTileX = Math.floor((center.x + viewport.width / 2) / tileSize);
  const minTileY = Math.floor((center.y - viewport.height / 2) / tileSize);
  const maxTileY = Math.floor((center.y + viewport.height / 2) / tileSize);
  const tileCount = 2 ** viewport.zoom;
  const tiles: TilePosition[] = [];

  for (let x = minTileX; x <= maxTileX; x += 1) {
    for (let y = minTileY; y <= maxTileY; y += 1) {
      if (y < 0 || y >= tileCount) {
        continue;
      }

      const wrappedX = ((x % tileCount) + tileCount) % tileCount;
      tiles.push({
        x: wrappedX,
        y,
        z: viewport.zoom,
        left: Math.round(x * tileSize - (center.x - viewport.width / 2)),
        top: Math.round(y * tileSize - (center.y - viewport.height / 2)),
      });
    }
  }

  return tiles;
}

function markerPosition(point: MapPoint, center: WorldPoint, viewport: MapViewport): { x: number; y: number } {
  const world = latLonToWorld(point.latitude, point.longitude, viewport.zoom);

  return {
    x: Math.round(world.x - center.x + viewport.width / 2),
    y: Math.round(world.y - center.y + viewport.height / 2),
  };
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}
