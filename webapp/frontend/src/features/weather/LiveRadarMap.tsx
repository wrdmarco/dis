'use client';

import Feature from 'ol/Feature.js';
import Map from 'ol/Map.js';
import View from 'ol/View.js';
import Point from 'ol/geom/Point.js';
import { defaults as defaultInteractions } from 'ol/interaction/defaults.js';
import ImageLayer from 'ol/layer/Image.js';
import TileLayer from 'ol/layer/Tile.js';
import VectorLayer from 'ol/layer/Vector.js';
import { fromLonLat, transformExtent } from 'ol/proj.js';
import ImageStatic from 'ol/source/ImageStatic.js';
import VectorSource from 'ol/source/Vector.js';
import XYZ from 'ol/source/XYZ.js';
import { Circle as CircleStyle, Fill, Stroke, Style } from 'ol/style.js';
import {
  AlertTriangle,
  LocateFixed,
  Maximize2,
  Minimize2,
  Minus,
  Plus,
  RefreshCw,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { OperationalWeatherRadarBounds, OperationalWeatherRadarKind } from '../../types/api';
import styles from './OperationalForecast.module.css';

const OPENSTREETMAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const OPENSTREETMAP_ATTRIBUTION = 'Kaart: © OpenStreetMap-bijdragers';
const OPENSTREETMAP_ATTRIBUTION_URL = 'https://www.openstreetmap.org/copyright';
const REGIONAL_CENTER: [number, number] = [5.5, 52];
const REGIONAL_VIEW_BOUNDS: [number, number, number, number] = [1, 49, 10, 55];

interface LiveRadarMapProps {
  ariaLabel: string;
  bounds: OperationalWeatherRadarBounds;
  imageUrl: string;
  interactive: boolean;
  kind: OperationalWeatherRadarKind;
  location: {
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
  const markerFeatureRef = useRef<Feature<Point> | null>(null);
  const [mapReady, setMapReady] = useState(false);
  const [imageLoading, setImageLoading] = useState(true);
  const [imageFailed, setImageFailed] = useState(false);
  const [retryAttempt, setRetryAttempt] = useState(0);
  const [fullscreen, setFullscreen] = useState(false);

  useEffect(() => {
    if (targetRef.current === null) return;
    const target = targetRef.current;
    const radarLayer = new ImageLayer<ImageStatic>({ opacity: kind === 'precipitation' ? 0.9 : 0.94 });
    const markerFeature = new Feature<Point>();
    markerFeature.setStyle(new Style({
      image: new CircleStyle({
        radius: 8,
        fill: new Fill({ color: '#0b6fae' }),
        stroke: new Stroke({ color: '#ffffff', width: 3 }),
      }),
    }));

    const markerLayer = new VectorLayer({
      source: new VectorSource({ features: [markerFeature] }),
      zIndex: 20,
    });
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
      layers: [baseLayer, radarLayer, markerLayer],
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
    markerFeatureRef.current = markerFeature;
    setMapReady(true);

    const initialExtent = transformExtent(REGIONAL_VIEW_BOUNDS, 'EPSG:4326', 'EPSG:3857');
    window.requestAnimationFrame(() => {
      map.updateSize();
      map.getView().fit(initialExtent, { padding: [24, 24, 24, 24], maxZoom: 8.2 });
    });

    const observer = new ResizeObserver(() => map.updateSize());
    observer.observe(target);
    return () => {
      observer.disconnect();
      map.setTarget(undefined);
      map.dispose();
      mapRef.current = null;
      radarLayerRef.current = null;
      markerFeatureRef.current = null;
    };
  }, [interactive, kind]);

  useEffect(() => {
    const radarLayer = radarLayerRef.current;
    if (radarLayer === null) return;
    let active = true;
    setImageLoading(true);
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
    const marker = markerFeatureRef.current;
    if (marker === null) return;
    if (location?.latitude === null || location?.longitude === null || location === null) {
      marker.setGeometry(undefined);
      return;
    }
    marker.setGeometry(new Point(fromLonLat([location.longitude, location.latitude])));
  }, [location]);

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

  const centerMap = useCallback(() => {
    const map = mapRef.current;
    if (map === null) return;
    const view = map.getView();
    if (location?.latitude !== null && location?.longitude !== null && location !== null) {
      view.animate({
        center: fromLonLat([location.longitude, location.latitude]),
        zoom: Math.max(view.getZoom() ?? 7, 10),
        duration: motionDuration(),
      });
      return;
    }
    view.fit(
      transformExtent(REGIONAL_VIEW_BOUNDS, 'EPSG:4326', 'EPSG:3857'),
      { padding: [28, 28, 28, 28], duration: motionDuration(), maxZoom: 8.2 },
    );
  }, [location]);

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
            aria-label={location?.latitude !== null && location?.longitude !== null && location !== null
              ? `Centreren op ${location.label}`
              : 'Nederland en omliggende landen tonen'}
            disabled={!mapReady}
            onClick={centerMap}
          >
            <LocateFixed aria-hidden size={19} />
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

function motionDuration(): number {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 220;
}

function appendRetryAttempt(url: string, attempt: number): string {
  return `${url}${url.includes('?') ? '&' : '?'}retry=${attempt}`;
}
