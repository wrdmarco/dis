'use client';

import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { Crosshair, MapPin, RefreshCw, Search } from 'lucide-react';
import { Panel } from '../../components/Panel';
import { ApiClientError } from '../../lib/apiClient';
import {
  fetchLocationSuggestions,
  geocodeAddressLabel,
  lookupLocationSuggestion,
  type LocationSearchResult,
  type LocationSuggestion,
} from '../../lib/locationSearch';
import type { AeretFeatureCollection, AeretGeoJsonFeature, GeoJsonGeometry, GeoJsonPosition } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

const MAP_WIDTH = 1280;
const MAP_HEIGHT = 720;
const NETHERLANDS_CENTER: MapPoint = { latitude: 52.1326, longitude: 5.2913 };
const NETHERLANDS_VIEWPORT: MapViewport = { width: MAP_WIDTH, height: MAP_HEIGHT, zoom: 7 };
const SELECTED_LOCATION_ZOOM = 13;
const AERET_RADIUS_METERS = 5000;

export function TestMapPage() {
  const { api } = useAuth();
  const [query, setQuery] = useState('');
  const [suggestions, setSuggestions] = useState<LocationSuggestion[]>([]);
  const [selectedLocation, setSelectedLocation] = useState<LocationSearchResult | null>(null);
  const [aeretCollection, setAeretCollection] = useState<AeretFeatureCollection | null>(null);
  const [aeretLoading, setAeretLoading] = useState(false);
  const [aeretError, setAeretError] = useState<string | null>(null);
  const [searching, setSearching] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const selectedPoint = useMemo(() => locationPoint(selectedLocation), [selectedLocation]);

  useEffect(() => {
    const trimmed = query.trim();
    if (trimmed.length < 3) {
      setSuggestions([]);
      return;
    }

    const controller = new AbortController();
    const timeoutId = window.setTimeout(() => {
      void fetchLocationSuggestions(trimmed, controller.signal)
        .then(setSuggestions)
        .catch(() => undefined);
    }, 250);

    return () => {
      window.clearTimeout(timeoutId);
      controller.abort();
    };
  }, [query]);

  useEffect(() => {
    if (selectedPoint === null) {
      setAeretCollection(null);
      setAeretError(null);
      setAeretLoading(false);
      return undefined;
    }

    let active = true;
    const params = new URLSearchParams({
      latitude: selectedPoint.latitude.toFixed(7),
      longitude: selectedPoint.longitude.toFixed(7),
      radius_m: String(AERET_RADIUS_METERS),
    });

    setAeretCollection(null);
    setAeretLoading(true);
    setAeretError(null);

    void api.get<AeretFeatureCollection>(`/aeret/preflight/nearby?${params.toString()}`)
      .then((response) => {
        if (active) {
          setAeretCollection(response.data);
        }
      })
      .catch((err) => {
        if (active) {
          setAeretCollection(null);
          setAeretError(err instanceof ApiClientError ? err.message : 'Aeret data kon niet worden geladen.');
        }
      })
      .finally(() => {
        if (active) {
          setAeretLoading(false);
        }
      });

    return () => {
      active = false;
    };
  }, [api, selectedPoint]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    await resolveQuery(query, suggestions);
  }

  async function resolveQuery(value: string, currentSuggestions: LocationSuggestion[]) {
    const trimmed = value.trim();
    if (trimmed.length < 3) {
      setError('Vul minimaal 3 tekens in om een adres te zoeken.');
      return;
    }

    setSearching(true);
    setError(null);

    try {
      const exactSuggestion = currentSuggestions.find((suggestion) => suggestion.label.toLocaleLowerCase('nl-NL') === trimmed.toLocaleLowerCase('nl-NL'));
      const resolved = exactSuggestion
        ? await lookupLocationSuggestion(exactSuggestion)
        : await geocodeAddressLabel(trimmed) ?? await lookupFirstSuggestion(currentSuggestions);

      if (resolved === null) {
        setError('Geen locatie gevonden voor dit adres.');
        return;
      }

      setSelectedLocation(resolved);
      setQuery(resolved.locationLabel || trimmed);
      setSuggestions([]);
    } catch {
      setError('Adres zoeken is tijdelijk niet beschikbaar.');
    } finally {
      setSearching(false);
    }
  }

  async function selectSuggestion(suggestion: LocationSuggestion) {
    setQuery(suggestion.label);
    setSuggestions([]);
    setSearching(true);
    setError(null);

    try {
      const resolved = await lookupLocationSuggestion(suggestion);
      if (resolved === null) {
        setError('Geen coordinaten gevonden voor deze locatie.');
        return;
      }

      setSelectedLocation(resolved);
      setQuery(resolved.locationLabel || suggestion.label);
    } catch {
      setError('Adres zoeken is tijdelijk niet beschikbaar.');
    } finally {
      setSearching(false);
    }
  }

  function resetMap() {
    setSelectedLocation(null);
    setAeretCollection(null);
    setAeretError(null);
    setError(null);
  }

  const panelAction = selectedLocation ? (
    <button className="secondary-button" type="button" onClick={resetMap}>
      <RefreshCw size={16} />
      Nederland
    </button>
  ) : null;

  return (
    <div className="page-stack test-map-page">
      <Panel title="Test kaart" action={panelAction}>
        <div className="test-map">
          <div className="test-map__toolbar">
            <form className="test-map__search" onSubmit={handleSubmit}>
              <label>
                Adres zoeken
                <div className="test-map__search-row">
                  <div className="input-with-icon">
                    <Search size={16} />
                    <input
                      value={query}
                      maxLength={255}
                      placeholder="Adres, plaats, gebouw of gebied"
                      autoComplete="off"
                      onChange={(event) => setQuery(event.target.value)}
                    />
                  </div>
                  <button className="primary-button" type="submit" disabled={searching}>
                    {searching ? 'Zoekt...' : 'Zoeken'}
                  </button>
                </div>
              </label>
              {suggestions.length > 0 ? (
                <div className="location-picker__results test-map__results">
                  {suggestions.map((suggestion) => (
                    <button
                      key={suggestion.id}
                      type="button"
                      onMouseDown={(event) => event.preventDefault()}
                      onClick={() => void selectSuggestion(suggestion)}
                    >
                      <MapPin size={15} />
                      <span>{suggestion.label}</span>
                    </button>
                  ))}
                </div>
              ) : null}
              {error ? <p className="form-error test-map__error">{error}</p> : null}
            </form>

            <div className="test-map__coordinates" aria-live="polite">
              <span className="test-map__coordinate-icon" aria-hidden>
                <Crosshair size={20} />
              </span>
              <div>
                <small>GPS coordinaten</small>
                {selectedPoint ? (
                  <dl>
                    <div>
                      <dt>Latitude</dt>
                      <dd>{selectedPoint.latitude.toFixed(7)}</dd>
                    </div>
                    <div>
                      <dt>Longitude</dt>
                      <dd>{selectedPoint.longitude.toFixed(7)}</dd>
                    </div>
                  </dl>
                ) : (
                  <strong>Zoek een adres om coordinaten te tonen.</strong>
                )}
              </div>
            </div>
          </div>

          <AeretSummary collection={aeretCollection} loading={aeretLoading} error={aeretError} />
          <AddressMap selectedPoint={selectedPoint} selectedLabel={selectedLocation?.locationLabel ?? null} aeretCollection={aeretCollection} />
          <AeretFeatureList collection={aeretCollection} loading={aeretLoading} />
        </div>
      </Panel>
    </div>
  );
}

function AeretSummary({ collection, loading, error }: { collection: AeretFeatureCollection | null; loading: boolean; error: string | null }) {
  if (loading) {
    return (
      <div className="test-map__aeret-summary" aria-live="polite">
        <span>NOTAM en no-fly zones worden opgehaald...</span>
      </div>
    );
  }

  if (error !== null) {
    return (
      <div className="test-map__aeret-summary test-map__aeret-summary--error" aria-live="polite">
        <span>{error}</span>
      </div>
    );
  }

  if (collection === null) {
    return null;
  }

  const counts = collection.meta?.counts ?? {};
  const total = collection.meta?.feature_count ?? collection.features.length;

  return (
    <div className="test-map__aeret-summary" aria-live="polite">
      <strong>{total}</strong>
      <span>objecten binnen 5 km</span>
      <small>NOTAM {counts.notam ?? 0}</small>
      <small>No-fly {counts.no_fly ?? 0}</small>
      <small>Laagvlieg {counts.low_flying ?? 0}</small>
      <small>Natura 2000 {counts.natura2000 ?? 0}</small>
      <small>Vitale infra {counts.vital_infra ?? 0}</small>
    </div>
  );
}

function AeretFeatureList({ collection, loading }: { collection: AeretFeatureCollection | null; loading: boolean }) {
  if (loading || collection === null || collection.features.length === 0) {
    return null;
  }

  return (
    <div className="test-map__aeret-list">
      {collection.features.slice(0, 12).map((feature) => {
        const info = feature.properties._aeret;
        const category = info?.category ?? 'zone';
        const title = info?.title ?? fallbackFeatureTitle(feature);
        const summary = info?.summary ?? featurePropertyString(feature, ['Beschrijving', 'Luchtruim', 'Uitleg']);

        return (
          <article className={`test-map__aeret-card test-map__aeret-card--${category}`} key={feature.id ?? `${title}-${info?.distance_m ?? ''}`}>
            <header>
              <strong>{title}</strong>
              <span>{categoryLabel(category)}</span>
            </header>
            {summary ? <p>{summary}</p> : null}
            <small>{formatDistance(info?.distance_m)} vanaf gekozen locatie</small>
          </article>
        );
      })}
    </div>
  );
}

async function lookupFirstSuggestion(suggestions: LocationSuggestion[]): Promise<LocationSearchResult | null> {
  const first = suggestions[0];
  return first ? lookupLocationSuggestion(first) : null;
}

function locationPoint(location: LocationSearchResult | null): MapPoint | null {
  if (location === null) {
    return null;
  }

  const latitude = Number(location.latitude);
  const longitude = Number(location.longitude);
  if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) {
    return null;
  }

  return { latitude, longitude };
}

function AddressMap({
  selectedPoint,
  selectedLabel,
  aeretCollection,
}: {
  selectedPoint: MapPoint | null;
  selectedLabel: string | null;
  aeretCollection: AeretFeatureCollection | null;
}) {
  const center = selectedPoint ?? NETHERLANDS_CENTER;
  const viewport: MapViewport = selectedPoint === null
    ? NETHERLANDS_VIEWPORT
    : { width: MAP_WIDTH, height: MAP_HEIGHT, zoom: SELECTED_LOCATION_ZOOM };
  const centerWorld = latLonToWorld(center.latitude, center.longitude, viewport.zoom);
  const tiles = visibleTiles(centerWorld, viewport);

  return (
    <div className="operational-map__canvas test-map__canvas">
      <svg className="operational-map__svg test-map__svg" viewBox={`0 0 ${viewport.width} ${viewport.height}`} role="img" aria-label={selectedPoint ? 'Kaart ingezoomd op de gevonden locatie' : 'Kaart van Nederland'}>
        {tiles.map((tile) => (
          <image
            key={`osm-${tile.x}-${tile.y}-${tile.z}`}
            href={`https://tile.openstreetmap.org/${tile.z}/${tile.x}/${tile.y}.png`}
            x={tile.left}
            y={tile.top}
            width="256"
            height="256"
            preserveAspectRatio="none"
          />
        ))}
        <rect className="operational-map__shade test-map__osm-shade" x="0" y="0" width={viewport.width} height={viewport.height} />
        {selectedPoint ? <RadiusCircle point={selectedPoint} radiusMeters={AERET_RADIUS_METERS} centerWorld={centerWorld} viewport={viewport} /> : null}
        {aeretCollection ? (
          <AeretFeatureLayer features={aeretCollection.features} centerWorld={centerWorld} viewport={viewport} />
        ) : null}
        {selectedPoint ? <LocationPin point={selectedPoint} label={selectedLabel ?? 'Geselecteerde locatie'} centerWorld={centerWorld} viewport={viewport} /> : null}
      </svg>
      {selectedPoint === null ? (
        <div className="operational-map__empty-state">
          <strong>Nederland kaart</strong>
          <span>Zoek een adres om in te zoomen en een pin te plaatsen.</span>
        </div>
      ) : null}
    </div>
  );
}

function RadiusCircle({
  point,
  radiusMeters,
  centerWorld,
  viewport,
}: {
  point: MapPoint;
  radiusMeters: number;
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  const position = markerPosition(point, centerWorld, viewport);
  const radiusPixels = metersToPixels(radiusMeters, point.latitude, viewport.zoom);

  return (
    <g className="test-map__radius">
      <circle cx={position.x} cy={position.y} r={radiusPixels} />
      <text x={position.x + radiusPixels + 8} y={position.y - 8}>5 km</text>
    </g>
  );
}

function AeretFeatureLayer({
  features,
  centerWorld,
  viewport,
}: {
  features: AeretGeoJsonFeature[];
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  return (
    <g className="test-map__aeret-layer">
      {features.map((feature) => (
        <AeretFeatureShape key={feature.id ?? feature.properties._aeret?.source_url ?? JSON.stringify(feature.geometry).slice(0, 64)} feature={feature} centerWorld={centerWorld} viewport={viewport} />
      ))}
    </g>
  );
}

function AeretFeatureShape({
  feature,
  centerWorld,
  viewport,
}: {
  feature: AeretGeoJsonFeature;
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  const category = feature.properties._aeret?.category ?? 'zone';
  const title = feature.properties._aeret?.title ?? fallbackFeatureTitle(feature);
  const path = geometryPath(feature.geometry, centerWorld, viewport);
  const point = geometryLabelPoint(feature.geometry);
  const labelPosition = point ? markerPosition({ latitude: point[1], longitude: point[0] }, centerWorld, viewport) : null;

  if (path !== '') {
    return (
      <g className={`test-map__aeret-feature test-map__aeret-feature--${category}`}>
        <title>{title}</title>
        <path d={path} />
        {labelPosition ? <text x={labelPosition.x + 8} y={labelPosition.y - 8}>{shortMapLabel(title, 28)}</text> : null}
      </g>
    );
  }

  if (point !== null) {
    const position = markerPosition({ latitude: point[1], longitude: point[0] }, centerWorld, viewport);

    return (
      <g className={`test-map__aeret-point test-map__aeret-feature--${category}`}>
        <title>{title}</title>
        <circle cx={position.x} cy={position.y} r="9" />
        <text x={position.x + 13} y={position.y - 8}>{shortMapLabel(title, 28)}</text>
      </g>
    );
  }

  return null;
}

function LocationPin({
  point,
  label,
  centerWorld,
  viewport,
}: {
  point: MapPoint;
  label: string;
  centerWorld: WorldPoint;
  viewport: MapViewport;
}) {
  const position = markerPosition(point, centerWorld, viewport);

  return (
    <g className="test-map__pin" transform={`translate(${position.x} ${position.y})`}>
      <title>{label}</title>
      <path d="M0 -47 C-19 -47 -33 -32 -33 -14 C-33 12 0 45 0 45 C0 45 33 12 33 -14 C33 -32 19 -47 0 -47 Z" />
      <circle cx="0" cy="-15" r="11" />
      <line x1="0" y1="36" x2="0" y2="57" />
      <text x="42" y="-18">{shortMapLabel(label, 42)}</text>
    </g>
  );
}

interface MapPoint {
  latitude: number;
  longitude: number;
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

function metersToPixels(meters: number, latitude: number, zoom: number): number {
  const metersPerPixel = (156543.03392 * Math.cos((latitude * Math.PI) / 180)) / (2 ** zoom);

  return meters / metersPerPixel;
}

function geometryPath(geometry: GeoJsonGeometry, centerWorld: WorldPoint, viewport: MapViewport): string {
  switch (geometry.type) {
    case 'Polygon':
      return polygonPath(geometry.coordinates, centerWorld, viewport);
    case 'MultiPolygon':
      return geometry.coordinates.map((polygon) => polygonPath(polygon, centerWorld, viewport)).join(' ');
    case 'LineString':
      return linePath(geometry.coordinates, centerWorld, viewport, false);
    case 'MultiLineString':
      return geometry.coordinates.map((line) => linePath(line, centerWorld, viewport, false)).join(' ');
    default:
      return '';
  }
}

function polygonPath(polygon: GeoJsonPosition[][], centerWorld: WorldPoint, viewport: MapViewport): string {
  return polygon.map((ring) => linePath(ring, centerWorld, viewport, true)).join(' ');
}

function linePath(points: GeoJsonPosition[], centerWorld: WorldPoint, viewport: MapViewport, close: boolean): string {
  return points
    .map((coordinate, index) => {
      const position = markerPosition({ latitude: coordinate[1], longitude: coordinate[0] }, centerWorld, viewport);
      return `${index === 0 ? 'M' : 'L'} ${position.x} ${position.y}`;
    })
    .join(' ') + (close ? ' Z' : '');
}

function geometryLabelPoint(geometry: GeoJsonGeometry): GeoJsonPosition | null {
  switch (geometry.type) {
    case 'Point':
      return geometry.coordinates;
    case 'MultiPoint':
    case 'LineString':
      return geometry.coordinates[0] ?? null;
    case 'MultiLineString':
    case 'Polygon':
      return geometry.coordinates[0]?.[0] ?? null;
    case 'MultiPolygon':
      return geometry.coordinates[0]?.[0]?.[0] ?? null;
    default:
      return null;
  }
}

function fallbackFeatureTitle(feature: AeretGeoJsonFeature): string {
  return featurePropertyString(feature, ['NOTAM nummer', 'Naam', 'Afkorting', 'Luchtruim']) ?? 'Aeret object';
}

function featurePropertyString(feature: AeretGeoJsonFeature, keys: string[]): string | null {
  for (const key of keys) {
    const value = feature.properties[key];
    if (typeof value === 'string' && value.trim() !== '') {
      return value.trim();
    }
    if (typeof value === 'number') {
      return String(value);
    }
  }

  return null;
}

function categoryLabel(category: string): string {
  switch (category) {
    case 'notam':
      return 'NOTAM';
    case 'no_fly':
      return 'No-fly';
    case 'low_flying':
      return 'Laagvlieg';
    case 'natura2000':
      return 'Natura 2000';
    case 'vital_infra':
      return 'Vitale infra';
    default:
      return 'Zone';
  }
}

function formatDistance(distance?: number): string {
  if (distance === undefined || !Number.isFinite(distance)) {
    return '-';
  }

  return distance >= 1000 ? `${(distance / 1000).toFixed(1)} km` : `${Math.round(distance)} m`;
}

function shortMapLabel(value: string, maxLength: number): string {
  const trimmed = value.trim();
  if (trimmed.length <= maxLength) {
    return trimmed;
  }

  return `${trimmed.slice(0, Math.max(0, maxLength - 1)).trim()}...`;
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}
