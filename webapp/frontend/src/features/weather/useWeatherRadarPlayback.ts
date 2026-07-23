import { useCallback, useEffect, useMemo, useState } from 'react';
import type {
  OperationalWeatherRadarFrame,
  OperationalWeatherRadarLayer,
} from '../../types/api';

const RADAR_FRAME_INTERVAL_MS = 700;
const RADAR_FINAL_FRAME_INTERVAL_MS = 1_650;
const RADAR_ATLAS_LOAD_TIMEOUT_MS = 15_000;
const RADAR_WALLBOARD_RETRY_MS = 30_000;

export interface WeatherRadarPlayback {
  displayLayer: OperationalWeatherRadarLayer | null;
  atlasRenderUrl: string | null;
  frame: OperationalWeatherRadarFrame | null;
  framePosition: number;
  referenceFramePosition: number;
  loadingAtlas: boolean;
  atlasFailed: boolean;
  showingPreviousAtlas: boolean;
  playing: boolean;
  reducedMotion: boolean;
  canPlay: boolean;
  play: () => void;
  pause: () => void;
  previous: () => void;
  next: () => void;
  seek: (position: number) => void;
  goToReference: () => void;
  retryAtlas: () => void;
}

export function useWeatherRadarPlayback(
  layer: OperationalWeatherRadarLayer | null,
  active: boolean,
  autoPlay = false,
): WeatherRadarPlayback {
  const [displayLayer, setDisplayLayer] = useState<OperationalWeatherRadarLayer | null>(null);
  const [atlasRenderUrl, setAtlasRenderUrl] = useState<string | null>(null);
  const [framePosition, setFramePosition] = useState(0);
  const [loadingAtlas, setLoadingAtlas] = useState(false);
  const [atlasFailed, setAtlasFailed] = useState(false);
  const [playbackRequested, setPlaybackRequested] = useState(autoPlay);
  const [retryRequest, setRetryRequest] = useState<{ atlasUrl: string | null; attempt: number }>({
    atlasUrl: null,
    attempt: 0,
  });
  const [pageVisible, setPageVisible] = useState(
    () => typeof document === 'undefined' || document.visibilityState === 'visible',
  );
  const [reducedMotion, setReducedMotion] = useState(
    () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
  );

  const requestedAtlasUrl = layer?.atlas_url ?? null;
  const retryAttempt = retryRequest.atlasUrl === requestedAtlasUrl ? retryRequest.attempt : 0;

  useEffect(() => {
    if (layer === null || layer.status === 'unavailable' || layer.atlas_url === null || layer.frames.length === 0) {
      setDisplayLayer(null);
      setAtlasRenderUrl(null);
      setFramePosition(0);
      setLoadingAtlas(false);
      setAtlasFailed(false);
      return;
    }

    if (displayLayer?.atlas_url === layer.atlas_url && retryAttempt === 0) {
      setDisplayLayer(layer);
      setFramePosition((position) => Math.min(position, layer.frames.length - 1));
      setLoadingAtlas(false);
      setAtlasFailed(false);
      return;
    }

    let cancelled = false;
    let settled = false;
    const image = new Image();
    const renderUrl = radarAtlasAttemptUrl(layer.atlas_url, retryAttempt);
    setLoadingAtlas(true);
    setAtlasFailed(false);

    const commitDecodedAtlas = () => {
      if (cancelled || settled) return;
      settled = true;
      window.clearTimeout(loadTimeout);
      setDisplayLayer(layer);
      setAtlasRenderUrl(renderUrl);
      setFramePosition(initialRadarFramePosition(layer, autoPlay && !reducedMotion));
      setLoadingAtlas(false);
      setAtlasFailed(false);
      setRetryRequest({ atlasUrl: null, attempt: 0 });
    };

    const failAtlas = () => {
      if (cancelled || settled) return;
      settled = true;
      window.clearTimeout(loadTimeout);
      setLoadingAtlas(false);
      setAtlasFailed(true);
    };

    const loadTimeout = window.setTimeout(failAtlas, RADAR_ATLAS_LOAD_TIMEOUT_MS);
    image.decoding = 'async';
    image.onload = () => {
      if (typeof image.decode !== 'function') {
        commitDecodedAtlas();
        return;
      }
      void image.decode().catch(() => undefined).then(commitDecodedAtlas);
    };
    image.onerror = failAtlas;
    image.src = renderUrl;

    return () => {
      cancelled = true;
      window.clearTimeout(loadTimeout);
      image.onload = null;
      image.onerror = null;
    };
  }, [autoPlay, displayLayer?.atlas_url, layer, reducedMotion, retryAttempt]);

  useEffect(() => {
    const media = window.matchMedia('(prefers-reduced-motion: reduce)');
    const updatePreference = () => {
      setReducedMotion(media.matches);
    };
    updatePreference();
    media.addEventListener('change', updatePreference);
    return () => media.removeEventListener('change', updatePreference);
  }, []);

  useEffect(() => {
    const handleVisibility = () => {
      setPageVisible(document.visibilityState === 'visible');
    };
    document.addEventListener('visibilitychange', handleVisibility);
    return () => document.removeEventListener('visibilitychange', handleVisibility);
  }, []);

  useEffect(() => {
    if (!autoPlay || !active || !pageVisible || !atlasFailed || requestedAtlasUrl === null) return;
    const timeout = window.setTimeout(() => {
      setRetryRequest((current) => ({
        atlasUrl: requestedAtlasUrl,
        attempt: current.atlasUrl === requestedAtlasUrl ? current.attempt + 1 : 1,
      }));
    }, RADAR_WALLBOARD_RETRY_MS);
    return () => window.clearTimeout(timeout);
  }, [active, atlasFailed, autoPlay, pageVisible, requestedAtlasUrl]);

  const decodedCurrentAtlas = displayLayer !== null
    && layer !== null
    && displayLayer.atlas_url === layer.atlas_url;
  const showingPreviousAtlas = displayLayer !== null
    && layer !== null
    && displayLayer.atlas_url !== layer.atlas_url;
  const canPlay = active
    && !reducedMotion
    && !loadingAtlas
    && !atlasFailed
    && decodedCurrentAtlas
    && (layer.status === 'available' || (!autoPlay && layer.status === 'stale'))
    && displayLayer.frames.length > 1;
  const playing = playbackRequested && pageVisible && canPlay;

  useEffect(() => {
    if (!autoPlay || displayLayer === null) return;
    const mustHoldReferenceFrame = reducedMotion
      || !active
      || loadingAtlas
      || atlasFailed
      || showingPreviousAtlas
      || layer?.status !== 'available';
    if (!mustHoldReferenceFrame) return;
    setFramePosition(radarReferenceFramePosition(displayLayer));
  }, [
    active,
    atlasFailed,
    autoPlay,
    displayLayer,
    layer?.status,
    loadingAtlas,
    reducedMotion,
    showingPreviousAtlas,
  ]);

  useEffect(() => {
    if (!playing || !canPlay || displayLayer === null) return;
    const frameCount = displayLayer.frames.length;
    const delay = framePosition === frameCount - 1
      ? RADAR_FINAL_FRAME_INTERVAL_MS
      : RADAR_FRAME_INTERVAL_MS;
    const timeout = window.setTimeout(() => {
      setFramePosition((position) => (position + 1) % frameCount);
    }, delay);
    return () => window.clearTimeout(timeout);
  }, [canPlay, displayLayer, framePosition, playing]);

  const pause = useCallback(() => setPlaybackRequested(false), []);
  const play = useCallback(() => {
    if (!canPlay) return;
    setFramePosition((position) => displayLayer !== null && position === displayLayer.frames.length - 1
      ? 0
      : position);
    setPlaybackRequested(true);
  }, [canPlay, displayLayer]);
  const seek = useCallback((position: number) => {
    setPlaybackRequested(false);
    setFramePosition(() => {
      if (displayLayer === null) return 0;
      return Math.max(0, Math.min(Math.round(position), displayLayer.frames.length - 1));
    });
  }, [displayLayer]);
  const previous = useCallback(() => {
    setPlaybackRequested(false);
    setFramePosition((position) => Math.max(0, position - 1));
  }, []);
  const next = useCallback(() => {
    setPlaybackRequested(false);
    setFramePosition((position) => displayLayer === null
      ? 0
      : Math.min(displayLayer.frames.length - 1, position + 1));
  }, [displayLayer]);
  const goToReference = useCallback(() => {
    setPlaybackRequested(false);
    setFramePosition(displayLayer === null ? 0 : radarReferenceFramePosition(displayLayer));
  }, [displayLayer]);
  const retryAtlas = useCallback(() => {
    if (requestedAtlasUrl === null) return;
    setRetryRequest((current) => ({
      atlasUrl: requestedAtlasUrl,
      attempt: current.atlasUrl === requestedAtlasUrl ? current.attempt + 1 : 1,
    }));
  }, [requestedAtlasUrl]);

  const frame = useMemo(
    () => displayLayer?.frames[framePosition] ?? null,
    [displayLayer, framePosition],
  );
  const referenceFramePosition = useMemo(
    () => displayLayer === null ? 0 : radarReferenceFramePosition(displayLayer),
    [displayLayer],
  );

  return {
    displayLayer,
    atlasRenderUrl,
    frame,
    framePosition,
    referenceFramePosition,
    loadingAtlas,
    atlasFailed,
    showingPreviousAtlas,
    playing,
    reducedMotion,
    canPlay,
    play,
    pause,
    previous,
    next,
    seek,
    goToReference,
    retryAtlas,
  };
}

function initialRadarFramePosition(
  layer: OperationalWeatherRadarLayer,
  startAnimation: boolean,
): number {
  return startAnimation && layer.frames.length > 1
    ? 0
    : radarReferenceFramePosition(layer);
}

function radarReferenceFramePosition(layer: OperationalWeatherRadarLayer): number {
  const referenceTime = layer.reference_time === null
    ? Number.NaN
    : Date.parse(layer.reference_time);
  if (!Number.isFinite(referenceTime)) return Math.max(0, layer.frames.length - 1);

  let nearestPosition = 0;
  let nearestDistance = Number.POSITIVE_INFINITY;
  layer.frames.forEach((frame, position) => {
    const validAt = Date.parse(frame.valid_at);
    if (!Number.isFinite(validAt)) return;
    const distance = Math.abs(validAt - referenceTime);
    if (distance <= nearestDistance) {
      nearestDistance = distance;
      nearestPosition = position;
    }
  });
  return nearestPosition;
}

function radarAtlasAttemptUrl(atlasUrl: string, attempt: number): string {
  if (attempt === 0) return atlasUrl;
  const separator = atlasUrl.includes('?') ? '&' : '?';
  return `${atlasUrl}${separator}retry=${attempt}`;
}
