import { type ReactNode, useCallback, useEffect, useMemo, useState } from 'react';
import Link from 'next/link';
import { Maximize2, Minimize2, Navigation, RadioTower, RefreshCw, UsersRound } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ResourceState } from '../../components/ResourceState';
import { StatusPill } from '../../components/StatusPill';
import { ApiClientError } from '../../lib/apiClient';
import { formatDateTime } from '../../lib/dateTime';
import { useApiResource } from '../../lib/useApiResource';
import { useAuth } from '../auth/AuthContext';
import { RealtimeBridge } from '../realtime/RealtimeBridge';
import type { Incident, IncidentLiveLocation, Team } from '../../types/api';

const OPEN_INCIDENTS_PATH = '/incidents?status=draft,active,dispatching,in_progress';
const MAP_WIDTH = 1280;
const MAP_HEIGHT = 720;
const INCIDENT_COLORS = ['#7dd3fc', '#fbbf24', '#a7f3d0', '#fca5a5', '#c4b5fd', '#fdba74', '#93c5fd', '#f0abfc'];
const DEFAULT_CENTER = { latitude: 52.1326, longitude: 5.2913 };

export function IncidentMapPage() {
  const { api } = useAuth();
  const incidents = useApiResource<Incident[]>(OPEN_INCIDENTS_PATH);
  const [locationsByIncident, setLocationsByIncident] = useState<Record<string, IncidentLiveLocation[]>>({});
  const [locationError, setLocationError] = useState<string | null>(null);
  const [locationsLoading, setLocationsLoading] = useState(false);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const incidentItems = useMemo(() => incidents.data ?? [], [incidents.data]);

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
      void incidents.silentReload();
      void loadLiveLocations(incidentItems, { silent: true });
    }, 30_000);

    return () => window.clearInterval(interval);
  }, [incidentItems, incidents.silentReload, loadLiveLocations]);

  useEffect(() => {
    if (!isFullscreen) {
      return undefined;
    }

    const originalOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setIsFullscreen(false);
      }
    };

    window.addEventListener('keydown', closeOnEscape);

    return () => {
      document.body.style.overflow = originalOverflow;
      window.removeEventListener('keydown', closeOnEscape);
    };
  }, [isFullscreen]);

  const models = useMemo(() => buildIncidentMapModels(incidentItems, locationsByIncident), [incidentItems, locationsByIncident]);
  const summary = useMemo(() => ({
    incidents: models.length,
    liveUsers: models.reduce((total, model) => total + model.liveLocations.length, 0),
    linkedUsers: models.reduce((total, model) => total + model.locations.length, 0),
  }), [models]);

  async function refresh() {
    await incidents.reload();
    await loadLiveLocations(incidentItems);
  }

  function handleRealtimeEvent() {
    void incidents.silentReload();
    void loadLiveLocations(incidentItems, { silent: true });
  }

  const panelAction = (
    <div className="operational-map__actions">
      <button className="secondary-button" type="button" onClick={refresh} disabled={incidents.loading || locationsLoading}>
        <RefreshCw size={16} />
        Verversen
      </button>
      <button className="secondary-button" type="button" onClick={() => setIsFullscreen((current) => !current)} aria-pressed={isFullscreen}>
        {isFullscreen ? <Minimize2 size={16} /> : <Maximize2 size={16} />}
        {isFullscreen ? 'Normaal' : 'Fullscreen'}
      </button>
    </div>
  );

  return (
    <div className={`page-stack operational-map-page ${isFullscreen ? 'operational-map-page--fullscreen' : ''}`}>
      <RealtimeBridge onOperationalEvent={handleRealtimeEvent} />
      <Panel title="Operationele kaart" action={panelAction}>
        <ResourceState loading={incidents.loading && models.length === 0} error={incidents.error} empty={models.length === 0}>
          <div className="operational-map">
            {locationError ? <p className="form-error">{locationError}</p> : null}
            <div className="operational-map__summary" aria-label="Kaart samenvatting">
              <SummaryItem icon={<RadioTower size={18} />} label="Open incidenten" value={String(summary.incidents)} />
              <SummaryItem icon={<Navigation size={18} />} label="Live op kaart" value={String(summary.liveUsers)} />
              <SummaryItem icon={<UsersRound size={18} />} label="Gekoppelde gebruikers" value={String(summary.linkedUsers)} />
            </div>
            <OperationsMap models={models} />
            <IncidentMapList models={models} />
          </div>
        </ResourceState>
      </Panel>
    </div>
  );
}

function OperationsMap({ models }: { models: IncidentMapModel[] }) {
  const points = models.flatMap((model) => [
    ...(model.incidentPoint ? [model.incidentPoint] : []),
    ...model.liveLocations,
  ]);
  const mapPoints = points.length > 0 ? points : [DEFAULT_CENTER];
  const viewport = mapViewport(mapPoints);
  const center = centerFor(mapPoints);
  const centerWorld = latLonToWorld(center.latitude, center.longitude, viewport.zoom);
  const tiles = visibleTiles(centerWorld, viewport);

  return (
    <div className="operational-map__canvas">
      <svg className="operational-map__svg" viewBox={`0 0 ${viewport.width} ${viewport.height}`} role="img" aria-label="Kaart met incidenten en live gebruikerslocaties">
        {tiles.map((tile) => (
          <image
            key={`${tile.x}-${tile.y}-${tile.z}`}
            href={`https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/${tile.z}/${tile.y}/${tile.x}`}
            x={tile.left}
            y={tile.top}
            width="256"
            height="256"
            preserveAspectRatio="none"
          />
        ))}
        <rect className="operational-map__shade" x="0" y="0" width={viewport.width} height={viewport.height} />
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
      </svg>
    </div>
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

function IncidentMapList({ models }: { models: IncidentMapModel[] }) {
  return (
    <div className="operational-map__list">
      {models.map((model) => (
        <article className="operational-map-card" key={model.incident.id} style={{ borderColor: model.color }}>
          <header>
            <span className="operational-map-card__color" style={{ background: model.color }} aria-hidden />
            <div>
              <Link href={`/incidents/${model.incident.id}`}>{model.incident.title}</Link>
              <small>{model.incident.location_label ?? 'Geen locatie bekend'}</small>
            </div>
            <StatusPill value={incidentStatusLabel(model.incident.status)} tone={incidentTone(model.incident.status)} />
          </header>
          <dl>
            <div>
              <dt>Teams</dt>
              <dd>{incidentTeamsLabel(model.incident)}</dd>
            </div>
            <div>
              <dt>Live</dt>
              <dd>{model.liveLocations.length}/{model.locations.length}</dd>
            </div>
          </dl>
          <div className="operational-map-card__users">
            {model.locations.length > 0 ? model.locations.map((location) => (
              <span key={location.user_id} className={isLiveLocation(location) ? 'operational-map-card__user operational-map-card__user--live' : 'operational-map-card__user'}>
                {location.user?.name ?? location.user_id}
                <small>{locationMeta(location)}</small>
              </span>
            )) : <span className="form-note">Nog geen gekoppelde gebruikers.</span>}
          </div>
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
  incidentPoint: MapPoint | null;
  locations: IncidentLiveLocation[];
  liveLocations: UserMapPoint[];
}

interface MapPoint {
  latitude: number;
  longitude: number;
}

interface UserMapPoint extends MapPoint {
  userId: string;
  name: string;
}

function buildIncidentMapModels(incidents: Incident[], locationsByIncident: Record<string, IncidentLiveLocation[]>): IncidentMapModel[] {
  return incidents
    .filter((incident) => !['resolved', 'cancelled'].includes(incident.status))
    .map((incident, index) => {
      const color = INCIDENT_COLORS[index % INCIDENT_COLORS.length];
      const locations = locationsByIncident[incident.id] ?? [];

      return {
        incident,
        color,
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
  return point !== null && (location.location_is_current === true || location.sharing_status === 'shared' || location.sharing_status === 'stale');
}

function locationMeta(location: IncidentLiveLocation): string {
  const parts = [
    locationStatusLabel(location),
    location.eta_minutes ? `ETA ${location.eta_minutes} min` : null,
    location.recorded_at ? formatDateTime(location.recorded_at) : null,
  ].filter((value): value is string => typeof value === 'string' && value !== '');

  return parts.join(' - ') || '-';
}

function locationStatusLabel(location: IncidentLiveLocation): string {
  switch (location.sharing_status) {
    case 'shared':
      return 'Live gedeeld';
    case 'stale':
      return 'Locatie verlopen';
    case 'consented':
      return 'Toestemming gegeven';
    case 'requested':
      return 'Locatie gevraagd';
    case 'declined':
      return 'Geweigerd';
    default:
      return 'Geen live locatie';
  }
}

function shortMapLabel(value: string, maxLength: number): string {
  const trimmed = value.trim();
  if (trimmed.length <= maxLength) {
    return trimmed;
  }

  return `${trimmed.slice(0, Math.max(0, maxLength - 1)).trim()}...`;
}

function incidentTeamsLabel(incident: Incident): string {
  const teams = incident.teams?.length ? incident.teams : incident.team ? [incident.team] : [];
  return teams.map((team: Team) => `${team.code} - ${team.name}`).join(', ') || 'Geen team';
}

function incidentStatusLabel(status: Incident['status']): string {
  switch (status) {
    case 'draft':
      return 'Concept';
    case 'active':
      return 'Actief';
    case 'dispatching':
      return 'Alarmeren';
    case 'in_progress':
      return 'In uitvoering';
    case 'resolved':
      return 'Afgerond';
    case 'cancelled':
      return 'Geannuleerd';
    default:
      return status;
  }
}

function incidentTone(status: Incident['status']): 'neutral' | 'good' | 'warn' | 'bad' {
  switch (status) {
    case 'active':
    case 'dispatching':
    case 'in_progress':
      return 'warn';
    case 'resolved':
      return 'good';
    case 'cancelled':
      return 'bad';
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
