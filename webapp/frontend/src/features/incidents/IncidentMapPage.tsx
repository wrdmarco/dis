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
import { loadIncidentLocationResults, replaceIncidentLocationsAfterPoll } from './incidentMapRequests';
import { parseMapPoint, parsePilotRoute, pilotRouteColor } from './pilotRoutePresentation';
import {
  OperationalMapCanvas,
  type MapPoint,
  type OperationalMapLayerModels,
  type OperationalMapLayerVisibility,
  type OperationalMapUserPoint,
} from './OperationalMapCanvas';

const OPEN_INCIDENTS_PATH = '/incidents?status=draft,active,dispatching,in_progress';
const MAP_LAYERS_PATH = '/operational-map/layers';
const INCIDENT_COLORS = ['#7dd3fc', '#fbbf24', '#a7f3d0', '#fca5a5', '#c4b5fd', '#fdba74', '#93c5fd', '#f0abfc'];
const DEFAULT_LAYER_VISIBILITY: OperationalMapLayerVisibility = {
  commandCenters: true,
  historicalIncidents: false,
  pilotHomes: false,
};
const LIVE_LOCATION_REQUEST_CONCURRENCY = 2;

export function IncidentMapPage() {
  const { api } = useAuth();
  const incidents = useApiResource<Incident[]>(OPEN_INCIDENTS_PATH);
  const reloadIncidentsSilently = incidents.silentReload;
  const mapLayers = useApiResource<OperationalMapLayers>(MAP_LAYERS_PATH);
  const fullscreenRootRef = useRef<HTMLDivElement | null>(null);
  const liveLocationRequestIdRef = useRef(0);
  const liveLocationRequestRef = useRef<Promise<void> | null>(null);
  const [locationsByIncident, setLocationsByIncident] = useState<Record<string, IncidentLiveLocation[]>>({});
  const [locationError, setLocationError] = useState<string | null>(null);
  const [locationsLoading, setLocationsLoading] = useState(false);
  const [fullscreenMode, setFullscreenMode] = useState<'none' | 'browser' | 'web'>('none');
  const [layerVisibility, setLayerVisibility] = useState<OperationalMapLayerVisibility>(DEFAULT_LAYER_VISIBILITY);
  const [layerFilterOpen, setLayerFilterOpen] = useState(false);
  const incidentItems = useMemo(() => incidents.data ?? [], [incidents.data]);
  const isFullscreen = fullscreenMode !== 'none';

  const loadLiveLocations = useCallback(async (items: Incident[], options?: { silent?: boolean }) => {
    if (liveLocationRequestRef.current !== null) {
      if (options?.silent === true) {
        return;
      }
      await liveLocationRequestRef.current.catch(() => undefined);
    }

    const requestId = liveLocationRequestIdRef.current + 1;
    liveLocationRequestIdRef.current = requestId;

    if (items.length === 0) {
      setLocationsByIncident({});
      setLocationError(null);
      setLocationsLoading(false);
      return;
    }

    if (options?.silent !== true) {
      setLocationsLoading(true);
    }
    setLocationError(null);

    const request = (async () => {
      const responses = await loadIncidentLocationResults(
        items.map((incident) => incident.id),
        LIVE_LOCATION_REQUEST_CONCURRENCY,
        async (incidentId) => {
          const response = await api.get<IncidentLiveLocation[]>(`/incidents/${incidentId}/live-locations?include_routes=1`);
          return response.data;
        },
      );
      if (liveLocationRequestIdRef.current === requestId) {
        // Replace every incident and pilot route atomically. A failed
        // incident refresh immediately loses its old route, so a stale line
        // can never become a browser-side trail.
        setLocationsByIncident((current) => replaceIncidentLocationsAfterPoll(current, responses));

        const failedResponse = responses.find((response) => response.error !== null);
        setLocationError(failedResponse === undefined
          ? null
          : liveLocationErrorMessage(failedResponse.error));
      }
    })();
    liveLocationRequestRef.current = request;

    try {
      await request;
    } catch (err) {
      if (liveLocationRequestIdRef.current === requestId) {
        setLocationsByIncident((current) => replaceIncidentLocationsAfterPoll(
          current,
          items.map((incident) => ({ incidentId: incident.id, locations: null, error: err })),
        ));
        setLocationError(liveLocationErrorMessage(err));
      }
    } finally {
      if (liveLocationRequestIdRef.current === requestId) {
        setLocationsLoading(false);
      }
      if (liveLocationRequestRef.current === request) {
        liveLocationRequestRef.current = null;
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

  function toggleLayer(layer: keyof OperationalMapLayerVisibility) {
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
            <OperationalMapCanvas models={models} layers={layerModels} layerVisibility={layerVisibility} />
            <IncidentMapList models={models} />
          </div>
        </ResourceState>
      </Panel>
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
  liveLocations: OperationalMapUserPoint[];
}

function buildOperationalLayerModels(layers: OperationalMapLayers | null): OperationalMapLayerModels {
  return {
    commandCenters: (layers?.command_centers ?? []).flatMap((center) => {
      const point = parseMapPoint(center.latitude, center.longitude);
      return point === null ? [] : [{
        id: center.id,
        name: center.name,
        latitude: point.latitude,
        longitude: point.longitude,
      }];
    }),
    historicalIncidents: (layers?.historical_incidents ?? []).flatMap((incident) => {
      const point = parseMapPoint(incident.latitude, incident.longitude);
      return point === null ? [] : [{
        id: incident.id,
        reference: incident.reference,
        title: incident.title,
        latitude: point.latitude,
        longitude: point.longitude,
      }];
    }),
    pilotHomes: (layers?.pilot_homes ?? []).flatMap((home) => {
      const point = parseMapPoint(home.latitude, home.longitude);
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
        incidentPoint: parseMapPoint(incident.latitude, incident.longitude),
        locations,
        liveLocations: locations.flatMap((location) => {
          const point = parseMapPoint(location.latitude, location.longitude);
          if (point === null || !isCurrentLiveLocation(location)) {
            return [];
          }

          return [{
            ...point,
            userId: location.user_id,
            name: location.user?.name ?? location.user_id,
            color: pilotRouteColor(location.user_id),
            route: parsePilotRoute(location.route),
          }];
        }),
      };
    });
}

function liveLocationErrorMessage(error: unknown): string {
  return error instanceof ApiClientError ? error.message : 'Live locaties konden niet worden geladen.';
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
