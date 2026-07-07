'use client';

import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { Crosshair, MapPin, RefreshCw, Search } from 'lucide-react';
import { Panel } from '../../components/Panel';
import {
  fetchLocationSuggestions,
  geocodeAddressLabel,
  lookupLocationSuggestion,
  type LocationSearchResult,
  type LocationSuggestion,
} from '../../lib/locationSearch';

const MAP_WIDTH = 1280;
const MAP_HEIGHT = 720;
const NETHERLANDS_CENTER: MapPoint = { latitude: 52.1326, longitude: 5.2913 };
const NETHERLANDS_VIEWPORT: MapViewport = { width: MAP_WIDTH, height: MAP_HEIGHT, zoom: 7 };
const SELECTED_LOCATION_ZOOM = 15;

export function TestMapPage() {
  const [query, setQuery] = useState('');
  const [suggestions, setSuggestions] = useState<LocationSuggestion[]>([]);
  const [selectedLocation, setSelectedLocation] = useState<LocationSearchResult | null>(null);
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
        setError('Geen coördinaten gevonden voor deze locatie.');
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
                <small>GPS coördinaten</small>
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
                  <strong>Zoek een adres om coördinaten te tonen.</strong>
                )}
              </div>
            </div>
          </div>

          <AddressMap selectedPoint={selectedPoint} selectedLabel={selectedLocation?.locationLabel ?? null} />
        </div>
      </Panel>
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

function AddressMap({ selectedPoint, selectedLabel }: { selectedPoint: MapPoint | null; selectedLabel: string | null }) {
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
