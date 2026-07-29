import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type {
  OperationalWeatherRadarFrame,
  OperationalWeatherRadarLayer,
} from '../../types/api';

const RADAR_FRAME_INTERVAL_MS = 700;
const RADAR_FINAL_FRAME_INTERVAL_MS = 1_650;
const RADAR_ATLAS_LOAD_TIMEOUT_MS = 15_000;
const RADAR_FRAME_START_INTERVAL_MS = 1_050;
const RADAR_WALLBOARD_RETRY_MS = 30_000;

export interface WeatherRadarPlayback {
  displayLayer: OperationalWeatherRadarLayer | null;
  atlasRenderUrl: string | null;
  frame: OperationalWeatherRadarFrame | null;
  framePosition: number;
  referenceFramePosition: number;
  loadingAtlas: boolean;
  atlasFailed: boolean;
  seriesReady: boolean;
  seriesLoading: boolean;
  seriesFailed: boolean;
  loadedFrameCount: number;
  totalFrameCount: number;
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
  const [imageFrameRenderUrls, setImageFrameRenderUrls] = useState<string[] | null>(null);
  const [framePosition, setFramePosition] = useState(0);
  const [loadingAtlas, setLoadingAtlas] = useState(false);
  const [atlasFailed, setAtlasFailed] = useState(false);
  const [seriesReady, setSeriesReady] = useState(false);
  const [seriesLoading, setSeriesLoading] = useState(false);
  const [seriesFailed, setSeriesFailed] = useState(false);
  const [loadedFrameCount, setLoadedFrameCount] = useState(0);
  const [totalFrameCount, setTotalFrameCount] = useState(0);
  const [playbackRequested, setPlaybackRequested] = useState(autoPlay);
  const [retryRequest, setRetryRequest] = useState<{ renderKey: string | null; attempt: number }>({
    renderKey: null,
    attempt: 0,
  });
  const [pageVisible, setPageVisible] = useState(
    () => typeof document === 'undefined' || document.visibilityState === 'visible',
  );
  const [reducedMotion, setReducedMotion] = useState(
    () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
  );
  const requestedLayerRef = useRef(layer);
  const displayLayerRef = useRef(displayLayer);
  requestedLayerRef.current = layer;
  displayLayerRef.current = displayLayer;

  const requestedRenderKey = layer === null
    || layer.status === 'unavailable'
    || !radarLayerHasRenderableFrames(layer)
    ? null
    : radarLayerRenderKey(layer);
  const retryAttempt = retryRequest.renderKey === requestedRenderKey ? retryRequest.attempt : 0;

  useEffect(() => {
    const requestedLayer = requestedLayerRef.current;
    if (requestedLayer === null || requestedRenderKey === null) {
      displayLayerRef.current = null;
      setDisplayLayer(null);
      setAtlasRenderUrl(null);
      setImageFrameRenderUrls(null);
      setFramePosition(0);
      setLoadingAtlas(false);
      setAtlasFailed(false);
      setSeriesReady(false);
      setSeriesLoading(false);
      setSeriesFailed(false);
      setLoadedFrameCount(0);
      setTotalFrameCount(0);
      return;
    }

    const currentDisplayLayer = displayLayerRef.current;
    if (retryAttempt === 0 && radarLayerRenderKey(currentDisplayLayer) === requestedRenderKey) {
      return;
    }

    const abortController = new AbortController();
    let cancelled = false;
    setLoadingAtlas(true);
    setAtlasFailed(false);
    setSeriesReady(false);
    setSeriesLoading(false);
    setSeriesFailed(false);
    setLoadedFrameCount(0);
    setTotalFrameCount(requestedLayer.frames.length);

    const loadRequestedSeries = async () => {
      if (requestedLayer.render_mode === 'image_frames') {
        const renderUrls = requestedLayer.frames.map((frame) => (
          radarRenderAttemptUrl(frame.image_url ?? '', retryAttempt)
        ));
        const referencePosition = radarReferenceFramePosition(requestedLayer);
        let previousStartTime = performance.now();

        try {
          await preloadRadarFrame(renderUrls[referencePosition], abortController.signal);
        } catch (error) {
          if (cancelled || isAbortError(error)) return;
          setLoadingAtlas(false);
          setAtlasFailed(true);
          return;
        }

        if (cancelled) return;
        displayLayerRef.current = requestedLayer;
        setDisplayLayer(requestedLayer);
        setAtlasRenderUrl(null);
        setImageFrameRenderUrls(renderUrls);
        setFramePosition(referencePosition);
        setLoadingAtlas(false);
        setAtlasFailed(false);
        setLoadedFrameCount(1);

        const backgroundPositions = radarBackgroundFrameOrder(
          requestedLayer.frames.length,
          referencePosition,
        );
        if (backgroundPositions.length === 0) {
          setSeriesReady(true);
          setRetryRequest({ renderKey: null, attempt: 0 });
          return;
        }

        setSeriesLoading(true);
        let backgroundFailed = false;
        for (const position of backgroundPositions) {
          try {
            await waitForRadarFrameStart(previousStartTime, abortController.signal);
            if (cancelled) return;
            previousStartTime = performance.now();
            await preloadRadarFrame(renderUrls[position], abortController.signal);
            if (cancelled) return;
            setLoadedFrameCount((count) => count + 1);
          } catch (error) {
            if (cancelled || isAbortError(error)) return;
            backgroundFailed = true;
          }
        }

        if (cancelled) return;
        setSeriesLoading(false);
        setSeriesFailed(backgroundFailed);
        setSeriesReady(!backgroundFailed);
        if (!backgroundFailed) {
          if (autoPlay && !reducedMotion) setFramePosition(0);
          setRetryRequest({ renderKey: null, attempt: 0 });
        }
        return;
      }

      const atlasUrl = requestedLayer.atlas_url;
      if (atlasUrl === null) return;
      const renderUrl = radarAtlasAttemptUrl(atlasUrl, retryAttempt);
      try {
        await preloadRadarFrame(renderUrl, abortController.signal);
      } catch (error) {
        if (cancelled || isAbortError(error)) return;
        setLoadingAtlas(false);
        setAtlasFailed(true);
        return;
      }
      if (cancelled) return;
      displayLayerRef.current = requestedLayer;
      setDisplayLayer(requestedLayer);
      setAtlasRenderUrl(renderUrl);
      setImageFrameRenderUrls(null);
      setFramePosition(initialRadarFramePosition(requestedLayer, autoPlay && !reducedMotion));
      setLoadingAtlas(false);
      setAtlasFailed(false);
      setSeriesReady(true);
      setLoadedFrameCount(requestedLayer.frames.length);
      setRetryRequest({ renderKey: null, attempt: 0 });
    };

    void loadRequestedSeries();

    return () => {
      cancelled = true;
      abortController.abort();
    };
  }, [autoPlay, reducedMotion, requestedRenderKey, retryAttempt]);

  useEffect(() => {
    if (layer === null || requestedRenderKey === null) return;
    if (radarLayerRenderKey(displayLayer) !== requestedRenderKey) return;
    displayLayerRef.current = layer;
    setDisplayLayer(layer);
    setFramePosition((position) => Math.min(position, layer.frames.length - 1));
  }, [displayLayer, layer, requestedRenderKey]);

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
    if (
      !autoPlay
      || !active
      || !pageVisible
      || (!atlasFailed && !seriesFailed)
      || requestedRenderKey === null
    ) return;
    const timeout = window.setTimeout(() => {
      setRetryRequest((current) => ({
        renderKey: requestedRenderKey,
        attempt: current.renderKey === requestedRenderKey ? current.attempt + 1 : 1,
      }));
    }, RADAR_WALLBOARD_RETRY_MS);
    return () => window.clearTimeout(timeout);
  }, [active, atlasFailed, autoPlay, pageVisible, requestedRenderKey, seriesFailed]);

  const decodedCurrentAtlas = displayLayer !== null
    && layer !== null
    && radarLayerRenderKey(displayLayer) === radarLayerRenderKey(layer);
  const showingPreviousAtlas = displayLayer !== null
    && layer !== null
    && radarLayerRenderKey(displayLayer) !== radarLayerRenderKey(layer);
  const canPlay = active
    && !reducedMotion
    && !loadingAtlas
    && !atlasFailed
    && seriesReady
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
      || !seriesReady
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
    seriesReady,
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
    if (requestedRenderKey === null) return;
    setRetryRequest((current) => ({
      renderKey: requestedRenderKey,
      attempt: current.renderKey === requestedRenderKey ? current.attempt + 1 : 1,
    }));
  }, [requestedRenderKey]);

  const frame = useMemo(() => {
    const selectedFrame = displayLayer?.frames[framePosition] ?? null;
    if (selectedFrame === null || displayLayer?.render_mode !== 'image_frames') return selectedFrame;
    const renderUrl = imageFrameRenderUrls?.[framePosition];
    return renderUrl === undefined ? selectedFrame : { ...selectedFrame, image_url: renderUrl };
  }, [displayLayer, framePosition, imageFrameRenderUrls]);
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
    seriesReady,
    seriesLoading,
    seriesFailed,
    loadedFrameCount,
    totalFrameCount,
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
  return radarRenderAttemptUrl(atlasUrl, attempt);
}

function radarRenderAttemptUrl(renderUrl: string, attempt: number): string {
  if (attempt === 0) return renderUrl;
  const separator = renderUrl.includes('?') ? '&' : '?';
  return `${renderUrl}${separator}retry=${attempt}`;
}

function preloadRadarFrame(renderUrl: string, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const image = new Image();
    let settled = false;
    const loadTimeout = window.setTimeout(() => {
      settle(() => reject(new Error('Radarframe laden duurde te lang.')));
    }, RADAR_ATLAS_LOAD_TIMEOUT_MS);

    const releaseImage = () => {
      window.clearTimeout(loadTimeout);
      signal.removeEventListener('abort', handleAbort);
      image.onload = null;
      image.onerror = null;
      image.src = '';
    };
    const settle = (finish: () => void) => {
      if (settled) return;
      settled = true;
      releaseImage();
      finish();
    };
    const handleAbort = () => settle(() => reject(createAbortError()));

    signal.addEventListener('abort', handleAbort, { once: true });
    image.decoding = 'async';
    image.onload = () => {
      if (typeof image.decode !== 'function') {
        settle(resolve);
        return;
      }
      void image.decode().then(
        () => settle(resolve),
        () => settle(() => reject(new Error('Radarframe kon niet worden gedecodeerd.'))),
      );
    };
    image.onerror = () => settle(() => reject(new Error('Radarframe kon niet worden geladen.')));
    if (signal.aborted) {
      handleAbort();
      return;
    }
    image.src = renderUrl;
  });
}

function radarBackgroundFrameOrder(frameCount: number, referencePosition: number): number[] {
  return Array.from({ length: frameCount }, (_, position) => position)
    .filter((position) => position !== referencePosition)
    .sort((left, right) => (
      Math.abs(left - referencePosition) - Math.abs(right - referencePosition)
      || left - right
    ));
}

function waitForRadarFrameStart(previousStartTime: number, signal: AbortSignal): Promise<void> {
  const waitTime = Math.max(
    0,
    RADAR_FRAME_START_INTERVAL_MS - (performance.now() - previousStartTime),
  );
  if (waitTime === 0) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const timeout = window.setTimeout(() => {
      signal.removeEventListener('abort', handleAbort);
      resolve();
    }, waitTime);
    const handleAbort = () => {
      window.clearTimeout(timeout);
      signal.removeEventListener('abort', handleAbort);
      reject(createAbortError());
    };
    signal.addEventListener('abort', handleAbort, { once: true });
    if (signal.aborted) handleAbort();
  });
}

function createAbortError(): Error {
  return new DOMException('Radarframe laden geannuleerd.', 'AbortError');
}

function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError';
}

function radarLayerHasRenderableFrames(layer: OperationalWeatherRadarLayer): boolean {
  if (layer.frames.length === 0) return false;
  if (layer.render_mode === 'image_frames') {
    return layer.bounds !== null
      && layer.bounds !== undefined
      && layer.frames.every((frame) => typeof frame.image_url === 'string' && frame.image_url !== '');
  }
  return layer.atlas_url !== null;
}

function radarLayerRenderKey(layer: OperationalWeatherRadarLayer | null): string | null {
  if (layer === null) return null;
  if (layer.render_mode === 'image_frames') {
    return `image_frames:${layer.reference_time ?? ''}:${layer.frames.map((frame) => frame.image_url ?? '').join('|')}`;
  }
  return layer.atlas_url;
}
