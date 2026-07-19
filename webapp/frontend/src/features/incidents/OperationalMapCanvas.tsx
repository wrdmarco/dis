import type { PilotRoutePresentation } from './pilotRoutePresentation';

const MAP_WIDTH = 1280;
const MAP_HEIGHT = 720;
const NETHERLANDS_OVERVIEW_CENTER: MapPoint = { latitude: 52.1326, longitude: 5.2913 };
const NETHERLANDS_OVERVIEW_VIEWPORT: MapViewport = { width: MAP_WIDTH, height: MAP_HEIGHT, zoom: 7 };

export interface MapPoint {
  latitude: number;
  longitude: number;
}

export interface OperationalMapUserPoint extends MapPoint {
  userId: string;
  name: string;
  color: string;
  route: PilotRoutePresentation | null;
}

export interface OperationalMapIncidentModel {
  incident: { id: string; title: string };
  color: string;
  incidentPoint: MapPoint | null;
  liveLocations: OperationalMapUserPoint[];
}

export interface OperationalMapLayerVisibility {
  commandCenters: boolean;
  historicalIncidents: boolean;
  pilotHomes: boolean;
}

export interface OperationalMapLayerModels {
  commandCenters: Array<MapPoint & { id: string; name: string }>;
  historicalIncidents: Array<MapPoint & { id: string; reference: string; title: string }>;
  pilotHomes: Array<MapPoint & { id: string; name: string; homeCity: string | null }>;
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

interface MapBounds {
  minLat: number;
  maxLat: number;
  minLon: number;
  maxLon: number;
}

export function OperationalMapCanvas({
  models,
  layers,
  layerVisibility,
  showRoutes = true,
  showRouteLegend = true,
  autoFit = true,
}: {
  models: OperationalMapIncidentModel[];
  layers: OperationalMapLayerModels;
  layerVisibility: OperationalMapLayerVisibility;
  showRoutes?: boolean;
  showRouteLegend?: boolean;
  autoFit?: boolean;
}) {
  const points = models.flatMap((model) => [
    ...(model.incidentPoint ? [model.incidentPoint] : []),
    ...model.liveLocations,
    ...(showRoutes ? model.liveLocations.flatMap((location) => location.route?.points ?? []) : []),
  ]);
  const visibleLayerPoints = [
    ...(layerVisibility.commandCenters ? layers.commandCenters : []),
    ...(layerVisibility.historicalIncidents ? layers.historicalIncidents : []),
    ...(layerVisibility.pilotHomes ? layers.pilotHomes : []),
  ];
  const allPoints = [...points, ...visibleLayerPoints];
  const hasOperationalPoints = autoFit && allPoints.length > 0;
  const viewport = hasOperationalPoints ? mapViewport(allPoints) : NETHERLANDS_OVERVIEW_VIEWPORT;
  const center = hasOperationalPoints ? centerFor(allPoints) : NETHERLANDS_OVERVIEW_CENTER;
  const centerWorld = latLonToWorld(center.latitude, center.longitude, viewport.zoom);
  const tiles = visibleTiles(centerWorld, viewport);
  const livePilotCount = models.reduce((total, model) => total + model.liveLocations.length, 0);
  const routeCount = showRoutes
    ? models.reduce((total, model) => total + model.liveLocations.filter((location) => location.route !== null).length, 0)
    : 0;

  return (
    <div className="operational-map__canvas">
      <svg
        className="operational-map__svg"
        viewBox={`0 0 ${viewport.width} ${viewport.height}`}
        role="img"
        aria-label={models.length > 0
          ? `Kaart met incidenten, ${livePilotCount} actuele piloten en ${routeCount} navigatieroutes`
          : 'Kaart van Nederland zonder actieve incidenten'}
      >
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
        {showRoutes ? models.map((model) => model.liveLocations.map((location) => location.route === null ? null : (
          <PilotRoutePath
            key={`${model.incident.id}-${location.userId}-route`}
            location={location}
            centerWorld={centerWorld}
            viewport={viewport}
            incidentTitle={model.incident.title}
          />
        ))) : null}
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
            color={location.color}
            label={location.name}
            type="user"
          />
        )))}
        {layerVisibility.commandCenters ? layers.commandCenters.map((point) => (
          <CommandCenterMarker key={point.id} point={point} centerWorld={centerWorld} viewport={viewport} />
        )) : null}
        {layerVisibility.historicalIncidents ? layers.historicalIncidents.map((point) => (
          <HistoricalIncidentMarker key={point.id} point={point} centerWorld={centerWorld} viewport={viewport} />
        )) : null}
        {layerVisibility.pilotHomes ? layers.pilotHomes.map((point) => (
          <PilotHomeMarker key={point.id} point={point} centerWorld={centerWorld} viewport={viewport} />
        )) : null}
      </svg>
      {models.length === 0 ? (
        <div className="operational-map__empty-state">
          <strong>Geen actieve incidenten</strong>
          <span>Nederland blijft als standaardkaart zichtbaar.</span>
        </div>
      ) : null}
      {showRoutes && showRouteLegend ? <PilotRouteLegend models={models} /> : null}
    </div>
  );
}

function PilotRoutePath({
  location,
  centerWorld,
  viewport,
  incidentTitle,
}: {
  location: OperationalMapUserPoint;
  centerWorld: WorldPoint;
  viewport: MapViewport;
  incidentTitle: string;
}) {
  if (location.route === null) {
    return null;
  }

  const path = location.route.points
    .map((point, index) => {
      const position = markerPosition(point, centerWorld, viewport);
      return `${index === 0 ? 'M' : 'L'} ${position.x} ${position.y}`;
    })
    .join(' ');

  return (
    <path className="operational-map__route-line" d={path} fill="none" stroke={location.color} vectorEffect="non-scaling-stroke">
      <title>{`${location.name} naar ${incidentTitle} - ${pilotRouteStatus(location.route)}`}</title>
    </path>
  );
}

function PilotRouteLegend({ models }: { models: OperationalMapIncidentModel[] }) {
  const entries = models.flatMap((model) => model.liveLocations.map((location) => ({
    incidentId: model.incident.id,
    incidentTitle: model.incident.title,
    location,
  })));
  if (entries.length === 0) {
    return null;
  }

  return (
    <section className="operational-map__route-legend" aria-label="Pilootroutes">
      <strong>Pilootroutes</strong>
      <ul>
        {entries.map(({ incidentId, incidentTitle, location }) => (
          <li key={`${incidentId}-${location.userId}`}>
            <svg viewBox="0 0 34 12" aria-hidden="true">
              {location.route === null
                ? <circle cx="17" cy="6" r="4" fill={location.color} />
                : <line x1="2" y1="6" x2="32" y2="6" stroke={location.color} />}
            </svg>
            <span>
              <b>{location.name}</b>
              <small>{`${incidentTitle} · ${location.route === null ? 'Route niet beschikbaar' : pilotRouteStatus(location.route)}`}</small>
            </span>
          </li>
        ))}
      </ul>
    </section>
  );
}

function MapMarker({ point, centerWorld, viewport, color, label, type }: {
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

function CommandCenterMarker({ point, centerWorld, viewport }: {
  point: MapPoint & { name: string };
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

function HistoricalIncidentMarker({ point, centerWorld, viewport }: {
  point: MapPoint & { reference: string; title: string };
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

function PilotHomeMarker({ point, centerWorld, viewport }: {
  point: MapPoint & { name: string; homeCity: string | null };
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

function pilotRouteStatus(route: PilotRoutePresentation): string {
  const details: string[] = [];
  if (route.durationSeconds !== null) details.push(`${Math.max(1, Math.ceil(route.durationSeconds / 60))} min`);
  if (route.distanceMeters !== null) {
    details.push(route.distanceMeters >= 1000 ? `${(route.distanceMeters / 1000).toFixed(1)} km` : `${Math.round(route.distanceMeters)} m`);
  }
  const label = route.source === 'navigation' ? 'Navigatieroute' : 'Route';
  return details.length > 0 ? `${label} · ${details.join(' · ')}` : label;
}

function shortMapLabel(value: string, maxLength: number): string {
  const trimmed = value.trim();
  return trimmed.length <= maxLength ? trimmed : `${trimmed.slice(0, Math.max(0, maxLength - 1)).trim()}...`;
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
  return { minLat: minLat - latPadding, maxLat: maxLat + latPadding, minLon: minLon - lonPadding, maxLon: maxLon + lonPadding };
}

function centerFor(points: MapPoint[]): MapPoint {
  const bounds = boundsFor(points);
  return { latitude: (bounds.minLat + bounds.maxLat) / 2, longitude: (bounds.minLon + bounds.maxLon) / 2 };
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
      if (y < 0 || y >= tileCount) continue;
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
  return { x: Math.round(world.x - center.x + viewport.width / 2), y: Math.round(world.y - center.y + viewport.height / 2) };
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}
