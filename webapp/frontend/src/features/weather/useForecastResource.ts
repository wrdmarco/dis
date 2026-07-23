import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiClientError } from '../../lib/apiClient';
import type { WallboardForecastLocationMode } from '../../types/api';
import { useAuth } from '../auth/AuthContext';

export const FORECAST_REFRESH_INTERVAL_MS = 15 * 60 * 1000;
export const WEATHER_REFRESH_INTERVAL_MS = 5 * 60 * 1000;
export const FORECAST_RETRY_INTERVAL_MS = 60 * 1000;

export interface ForecastLocationQuery {
  mode: WallboardForecastLocationMode;
  label: string;
}

export interface ForecastResourceState<T> {
  data: T | null;
  loading: boolean;
  refreshing: boolean;
  busy: boolean;
  stale: boolean;
  error: string | null;
  refresh: () => Promise<void>;
}

export type ForecastResourceNormalizer<T> = (value: unknown) => T | null;

export const DEFAULT_FORECAST_LOCATION: ForecastLocationQuery = {
  mode: 'netherlands',
  label: '',
};

export function buildForecastResourcePath(endpoint: string, location: ForecastLocationQuery): string {
  const parameters = new URLSearchParams({ location_mode: location.mode });
  const label = normalizeForecastAddress(location.label);
  if (location.mode === 'address' && label !== '') {
    parameters.set('location_label', label);
  }

  return `${endpoint}?${parameters.toString()}`;
}

export function normalizeForecastAddress(value: string): string {
  return value.trim().replace(/\s+/g, ' ');
}

export function forecastRefreshDeadline(
  lastSuccessfulAt: number,
  lastAttemptAt: number,
  lastAttemptFailed: boolean,
  refreshIntervalMs = FORECAST_REFRESH_INTERVAL_MS,
): number {
  if (lastAttemptFailed || lastSuccessfulAt === 0) {
    return lastAttemptAt === 0 ? 0 : lastAttemptAt + FORECAST_RETRY_INTERVAL_MS;
  }

  return lastSuccessfulAt + refreshIntervalMs;
}

export function useForecastResource<T>(
  endpoint: '/operational-weather' | '/uav-forecast',
  location: ForecastLocationQuery,
  normalize?: ForecastResourceNormalizer<T>,
  refreshIntervalMs = FORECAST_REFRESH_INTERVAL_MS,
): ForecastResourceState<T> {
  const { api } = useAuth();
  const path = buildForecastResourcePath(endpoint, location);
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [stale, setStale] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [schedulerRevision, setSchedulerRevision] = useState(0);
  const requestSequence = useRef(0);
  const activeRequest = useRef<{ path: string; sequence: number } | null>(null);
  const initializedPath = useRef<string | null>(null);
  const currentPath = useRef(path);
  const lastAttemptAt = useRef(0);
  const lastSuccessfulAt = useRef(0);
  const lastAttemptFailed = useRef(false);
  currentPath.current = path;

  const load = useCallback(async (initial: boolean) => {
    if (activeRequest.current?.path === path) return;

    const sequence = requestSequence.current + 1;
    requestSequence.current = sequence;
    activeRequest.current = { path, sequence };
    lastAttemptAt.current = Date.now();
    lastAttemptFailed.current = false;
    if (initial) setLoading(true);
    else {
      setRefreshing(true);
      if (endpoint !== '/operational-weather') setStale(true);
    }

    try {
      const response = await api.get<unknown>(path);
      const normalized = normalize === undefined
        ? response.data as T | null
        : normalize(response.data);
      if (normalized === null || normalized === undefined) {
        throw new Error('De server leverde een onbetrouwbare weersrespons.');
      }

      if (requestSequence.current === sequence && currentPath.current === path) {
        lastSuccessfulAt.current = Date.now();
        setData(normalized);
        setStale(false);
        setError(null);
      }
    } catch (reason) {
      if (requestSequence.current === sequence && currentPath.current === path) {
        lastAttemptFailed.current = true;
        const successfulDataExpired = lastSuccessfulAt.current === 0
          || Date.now() >= lastSuccessfulAt.current + refreshIntervalMs;
        setStale(endpoint !== '/operational-weather' || successfulDataExpired);
        setError(reason instanceof ApiClientError
          ? reason.message
          : reason instanceof Error
            ? reason.message
          : 'De weersinformatie kon niet worden opgehaald.');
      }
    } finally {
      if (activeRequest.current?.sequence === sequence) activeRequest.current = null;
      if (requestSequence.current === sequence && currentPath.current === path) {
        setLoading(false);
        setRefreshing(false);
        setSchedulerRevision((revision) => revision + 1);
      }
    }
  }, [api, endpoint, normalize, path, refreshIntervalMs]);

  useEffect(() => {
    if (initializedPath.current !== path) {
      initializedPath.current = path;
      lastAttemptAt.current = 0;
      lastSuccessfulAt.current = 0;
      lastAttemptFailed.current = false;
      setData(null);
      setError(null);
      setStale(false);
    }
    void load(true);
  }, [load, path]);

  useEffect(() => {
    let timeout: number | null = null;

    const scheduleRefresh = () => {
      if (timeout !== null) {
        window.clearTimeout(timeout);
        timeout = null;
      }
      if (document.visibilityState !== 'visible' || activeRequest.current !== null) return;

      const deadline = forecastRefreshDeadline(
        lastSuccessfulAt.current,
        lastAttemptAt.current,
        lastAttemptFailed.current,
        refreshIntervalMs,
      );
      const remaining = deadline - Date.now();
      if (remaining <= 0) {
        void load(false);
        return;
      }

      timeout = window.setTimeout(scheduleRefresh, remaining);
    };

    scheduleRefresh();
    document.addEventListener('visibilitychange', scheduleRefresh);

    return () => {
      if (timeout !== null) window.clearTimeout(timeout);
      document.removeEventListener('visibilitychange', scheduleRefresh);
    };
  }, [load, refreshIntervalMs, schedulerRevision]);

  const refresh = useCallback(() => load(data === null), [data, load]);
  const busy = loading || refreshing;

  return { data, loading, refreshing, busy, stale, error, refresh };
}
