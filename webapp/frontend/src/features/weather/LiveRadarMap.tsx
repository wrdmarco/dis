'use client';

import Map from 'ol/Map.js';
import View from 'ol/View.js';
import { defaults as defaultInteractions } from 'ol/interaction/defaults.js';
import ImageLayer from 'ol/layer/Image.js';
import TileLayer from 'ol/layer/Tile.js';
import { fromLonLat, transformExtent } from 'ol/proj.js';
import ImageStatic from 'ol/source/ImageStatic.js';
import XYZ from 'ol/source/XYZ.js';
import {
  AlertTriangle,
  Maximize2,
  Minimize2,
  Minus,
  Plus,
  RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type {
  OperationalWeatherRadarBounds,
  OperationalWeatherRadarKind,
  WallboardForecastLocationMode,
} from '../../types/api';
import styles from './OperationalForecast.module.css';
import {
  radarMapViewKey,
  radarMapViewTarget,
  type RadarMapViewTarget,
} from './radarMapView';

const OPENSTREETMAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OPENSTREETMAP_ATTRIBUTION = 'Kaart: © OpenStreetMap-bijdragers';
const OPENSTREETMAP_ATTRIBUTION_URL = 'https://www.openstreetmap.org/copyright';
const REGIONAL_CENTER: [number, number] = [5.5, 52];

interface LiveRadarMapProps {
  ariaLabel: string;
  bounds: OperationalWeatherRadarBounds;
  imageUrl: string;
  interactive: boolean;
  kind: OperationalWeatherRadarKind;
  location: {
    mode: WallboardForecastLocationMode;
    label: string;
    latitude: number | null;
    longitude: number | null;
  } | null;
}

export default function LiveRadarMap({
  ariaLabel,
  bounds,
  imageUrl,
  interactive,
  kind,
  location,
}: LiveRadarMapProps) {
  const wrapperRef = useRef<HTMLDivElement | null>(null);
  const targetRef = useRef<HTMLDivElement | null>(null);
  const mapRef = useRef<Map | null>(null);
  const radarLayerRef = useRef<ImageLayer<ImageStatic> | null>(null);
  const hasRenderedImageRef = useRef(false);
  const appliedLocationViewRef = useRef<string | null>(null);
  const [mapReady, setMapReady] = useState(false);
  const [imageLoading, setImageLoading] = useState(true);
  const [imageFailed, setImageFailed] = useState(false);
  const [retryAttempt, setRetryAttempt] = useState(0);
  const [fullscreen, setFullscreen] = useState(false);
  const locationMode = location?.mode ?? null;
  const locationLatitude = location?.latitude ?? null;
  const locationLongitude = location?.longitude ?? null;
  const locationViewKey = radarMapViewKey(radarMapViewTarget(
    locationMode,
    locationLatitude,
    locationLongitude,
  ));

  useEffect(() => {
    if (targetRef.current === null) return;
    const target = targetRef.current;
    const radarLayer = new ImageLayer<ImageStatic>({ opacity: kind === 'precipitation' ? 0.9 : 0.94 });
    const baseLayer = new TileLayer({
      source: new XYZ({
        attributions: OPENSTREETMAP_ATTRIBUTION,
        crossOrigin: 'anonymous',
        maxZoom: 19,
        transition: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180,
        url: OPENSTREETMAP_TILE_URL,
      }),
    });
    const map = new Map({
      controls: [],
      interactions: interactive ? defaultInteractions() : [],
      layers: [baseLayer, radarLayer],
      target,
      view: new View({
        center: fromLonLat(REGIONAL_CENTER),
        zoom: 6.4,
        minZoom: 5,
        maxZoom: 11,
      }),
    });

    mapRef.current = map;
    radarLayerRef.current = radarLayer;
    hasRenderedImageRef.current = false;
    appliedLocationViewRef.current = null;
    setImageLoading(true);
    setImageFailed(false);
    setMapReady(true);

    const initialViewFrame = window.requestAnimationFrame(() => {
      map.updateSize();
    });

    const observer = new ResizeObserver(() => map.updateSize());
    observer.observe(target);
    return () => {
      window.cancelAnimationFrame(initialViewFrame);
      observer.disconnect();
      map.setTarget(undefined);
      map.dispose();
      mapRef.current = null;
      radarLayerRef.current = null;
      hasRenderedImageRef.current = false;
      appliedLocationViewRef.current = null;
    };
  }, [interactive, kind]);

  useEffect(() => {
    const map = mapRef.current;
    if (map === null || appliedLocationViewRef.current === locationViewKey) return;
    const animated = appliedLocationViewRef.current !== null;
    appliedLocationViewRef.current = locationViewKey;
    const viewFrame = window.requestAnimationFrame(() => {
      if (mapRef.current !== map) return;
      map.updateSize();
      applyLocationView(
        map.getView(),
        radarMapViewTarget(locationMode, locationLatitude, locationLongitude),
        animated,
      );
    });

    return () => window.cancelAnimationFrame(viewFrame);
  }, [kind, locationLatitude, locationLongitude, locationMode, locationViewKey]);

  useEffect(() => {
    const radarLayer = radarLayerRef.current;
    if (radarLayer === null) return;
    let active = true;
    if (!hasRenderedImageRef.current) setImageLoading(true);
    setImageFailed(false);

    const imageExtent: [number, number, number, number] = [
      bounds.west,
      bounds.south,
      bounds.east,
      bounds.north,
    ];
    const source = new ImageStatic({
      imageExtent,
      // The WMS pixels are sampled on a geographic grid. Declaring that source
      // projection lets OpenLayers reproject the raster instead of stretching
      // only its transformed corner coordinates across Web Mercator.
      projection: bounds.crs,
      url: retryAttempt === 0 ? imageUrl : appendRetryAttempt(imageUrl, retryAttempt),
    });
    source.on('imageloadend', () => {
      if (!active) return;
      hasRenderedImageRef.current = true;
      setImageLoading(false);
      setImageFailed(false);
    });
    source.on('imageloaderror', () => {
      if (!active) return;
      setImageLoading(false);
      setImageFailed(true);
    });
    radarLayer.setSource(source);
    return () => {
      active = false;
    };
  }, [bounds, imageUrl, retryAttempt]);

  useEffect(() => {
    const handleFullscreenChange = () => setFullscreen(document.fullscreenElement === wrapperRef.current);
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
  }, []);

  const zoomBy = useCallback((delta: number) => {
    const view = mapRef.current?.getView();
    const zoom = view?.getZoom();
    if (view === undefined || zoom === undefined) return;
    view.animate({ zoom: zoom + delta, duration: motionDuration() });
  }, []);

  const toggleFullscreen = useCallback(async () => {
    const wrapper = wrapperRef.current;
    if (wrapper === null) return;
    if (document.fullscreenElement === wrapper) {
      await document.exitFullscreen();
    } else {
      await wrapper.requestFullscreen();
    }
    window.requestAnimationFrame(() => mapRef.current?.updateSize());
  }, []);

  return (
    <div
      ref={wrapperRef}
      className={styles.radarLiveMap}
      data-map-ready={mapReady ? 'true' : 'false'}
    >
      <div
        ref={targetRef}
        className={styles.radarMapCanvas}
        role="application"
        tabIndex={interactive ? 0 : -1}
        aria-label={interactive
          ? `${ariaLabel}. Sleep om te verplaatsen en gebruik de zoomknoppen of het muiswiel.`
          : ariaLabel}
      />

      {interactive ? (
        <div className={styles.radarMapControls} aria-label="Kaartbediening">
          <button type="button" aria-label="Inzoomen" disabled={!mapReady} onClick={() => zoomBy(1)}>
            <Plus aria-hidden size={19} />
          </button>
          <button type="button" aria-label="Uitzoomen" disabled={!mapReady} onClick={() => zoomBy(-1)}>
            <Minus aria-hidden size={19} />
          </button>
          <button
            type="button"
            aria-label={fullscreen ? 'Volledig scherm sluiten' : 'Kaart op volledig scherm tonen'}
            disabled={!mapReady}
            onClick={() => void toggleFullscreen()}
          >
            {fullscreen ? <Minimize2 aria-hidden size={19} /> : <Maximize2 aria-hidden size={19} />}
          </button>
        </div>
      ) : null}

      {imageLoading ? (
        <div className={styles.radarMapLoadState} role="status">
          <span className={styles.stateSpinner} aria-hidden />
          <span>Radarbeeld laden</span>
        </div>
      ) : imageFailed ? (
        <div className={`${styles.radarMapLoadState} ${styles.radarMapLoadError}`} role="alert">
          <AlertTriangle aria-hidden size={18} />
          <span>Radarbeeld tijdelijk niet beschikbaar</span>
          {interactive ? (
            <button type="button" onClick={() => setRetryAttempt((attempt) => attempt + 1)}>
              <RefreshCw aria-hidden size={16} /> Opnieuw
            </button>
          ) : null}
        </div>
      ) : null}

      <a
        className={styles.radarMapAttribution}
        href={OPENSTREETMAP_ATTRIBUTION_URL}
        rel="noreferrer"
        target="_blank"
      >
        {OPENSTREETMAP_ATTRIBUTION}
      </a>
    </div>
  );
}

function applyLocationView(
  view: View,
  target: RadarMapViewTarget,
  animated: boolean,
): void {
  if (target.kind === 'address') {
    const center = fromLonLat([...target.center]);
    if (animated) {
      view.animate({ center, zoom: target.zoom, duration: motionDuration() });
    } else {
      view.setCenter(center);
      view.setZoom(target.zoom);
    }
    return;
  }

  view.fit(
    transformExtent([...target.bounds], 'EPSG:4326', 'EPSG:3857'),
    {
      padding: animated ? [28, 28, 28, 28] : [24, 24, 24, 24],
      duration: animated ? motionDuration() : undefined,
      maxZoom: 8.2,
    },
  );
}

function motionDuration(): number {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220;
}

function appendRetryAttempt(url: string, attempt: number): string {
  return `${url}${url.includes('?') ? '&' : '?'}retry=${attempt}`;
}
