import { useCallback, useEffect, useMemo, useState } from 'react';
import type {
  OperationalWeatherRadarFrame,
  OperationalWeatherRadarLayer,
} from '../../types/api';

const RADAR_FRAME_INTERVAL_MS = 650;

export interface WeatherRadarPlayback {
  displayLayer: OperationalWeatherRadarLayer | null;
  frame: OperationalWeatherRadarFrame | null;
  framePosition: number;
  loadingAtlas: boolean;
  atlasFailed: boolean;
  playing: boolean;
  reducedMotion: boolean;
  canPlay: boolean;
  play: () => void;
  pause: () => void;
  previous: () => void;
  next: () => void;
  seek: (position: number) => void;
  goToNewest: () => void;
}

export function useWeatherRadarPlayback(
  layer: OperationalWeatherRadarLayer | null,
  active: boolean,
  autoPlay = false,
): WeatherRadarPlayback {
  const [displayLayer, setDisplayLayer] = useState<OperationalWeatherRadarLayer | null>(null);
  const [framePosition, setFramePosition] = useState(0);
  const [loadingAtlas, setLoadingAtlas] = useState(false);
  const [atlasFailed, setAtlasFailed] = useState(false);
  const [playbackRequested, setPlaybackRequested] = useState(autoPlay);
  const [pageVisible, setPageVisible] = useState(
    () => typeof document === 'undefined' || document.visibilityState === 'visible',
  );
  const [reducedMotion, setReducedMotion] = useState(
    () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
  );

  useEffect(() => {
    if (layer === null || layer.status === 'unavailable' || layer.atlas_url === null || layer.frames.length === 0) {
      setDisplayLayer(null);
      setFramePosition(0);
      setLoadingAtlas(false);
      setAtlasFailed(false);
      return;
    }

    if (displayLayer?.atlas_url === layer.atlas_url) {
      setDisplayLayer(layer);
      setFramePosition((position) => Math.min(position, layer.frames.length - 1));
      setLoadingAtlas(false);
      setAtlasFailed(false);
      return;
    }

    let cancelled = false;
    const image = new Image();
    setLoadingAtlas(true);
    setAtlasFailed(false);

    const commitDecodedAtlas = () => {
      if (cancelled) return;
      setDisplayLayer(layer);
      setFramePosition(layer.frames.length - 1);
      setLoadingAtlas(false);
      setAtlasFailed(false);
    };

    image.decoding = 'async';
    image.onload = () => {
      if (typeof image.decode !== 'function') {
        commitDecodedAtlas();
        return;
      }
      void image.decode().catch(() => undefined).then(commitDecodedAtlas);
    };
    image.onerror = () => {
      if (cancelled) return;
      setLoadingAtlas(false);
      setAtlasFailed(true);
    };
    image.src = layer.atlas_url;

    return () => {
      cancelled = true;
      image.onload = null;
      image.onerror = null;
    };
  }, [displayLayer?.atlas_url, layer]);

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

  const decodedCurrentAtlas = displayLayer !== null
    && layer !== null
    && displayLayer.atlas_url === layer.atlas_url;
  const canPlay = active
    && !reducedMotion
    && !loadingAtlas
    && !atlasFailed
    && decodedCurrentAtlas
    && layer.status === 'available'
    && displayLayer.frames.length > 1;
  const playing = playbackRequested && pageVisible && canPlay;

  useEffect(() => {
    if (!playing || !canPlay || displayLayer === null) return;
    const frameCount = displayLayer.frames.length;
    const interval = window.setInterval(() => {
      setFramePosition((position) => (position + 1) % frameCount);
    }, RADAR_FRAME_INTERVAL_MS);
    return () => window.clearInterval(interval);
  }, [canPlay, displayLayer, playing]);

  const pause = useCallback(() => setPlaybackRequested(false), []);
  const play = useCallback(() => {
    if (canPlay) setPlaybackRequested(true);
  }, [canPlay]);
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
  const goToNewest = useCallback(() => {
    setPlaybackRequested(false);
    setFramePosition(displayLayer === null ? 0 : displayLayer.frames.length - 1);
  }, [displayLayer]);

  const frame = useMemo(
    () => displayLayer?.frames[framePosition] ?? null,
    [displayLayer, framePosition],
  );

  return {
    displayLayer,
    frame,
    framePosition,
    loadingAtlas,
    atlasFailed,
    playing,
    reducedMotion,
    canPlay,
    play,
    pause,
    previous,
    next,
    seek,
    goToNewest,
  };
}
