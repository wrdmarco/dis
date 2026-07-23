import { useCallback, useEffect, useRef, useState } from 'react';
import { ApiClientError } from './apiClient';
import { useAuth } from '../features/auth/AuthContext';

interface ResourceState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  reload: () => Promise<void>;
  silentReload: () => Promise<void>;
  mutate: (next: T | null | ((current: T | null) => T | null)) => void;
}

export function useApiResource<T>(path: string, enabled = true): ResourceState<T> {
  const { api } = useAuth();
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState<boolean>(enabled);
  const [error, setError] = useState<string | null>(null);
  const latestRequestRef = useRef(0);
  const mountedRef = useRef(true);
  const visibleRequestsRef = useRef(new Set<number>());

  const load = useCallback(async (options?: { silent?: boolean }) => {
    if (!enabled) {
      return;
    }
    const silent = options?.silent === true;
    const requestId = latestRequestRef.current + 1;
    latestRequestRef.current = requestId;
    visibleRequestsRef.current.clear();
    if (!silent) {
      visibleRequestsRef.current.add(requestId);
      setLoading(true);
    } else {
      setLoading(false);
    }
    setError(null);
    try {
      const response = await api.get<T>(path);
      if (mountedRef.current && latestRequestRef.current === requestId) {
        setData(response.data);
      }
    } catch (err) {
      if (mountedRef.current && latestRequestRef.current === requestId) {
        setError(err instanceof ApiClientError ? err.message : 'Unable to load data.');
      }
    } finally {
      if (!silent) {
        visibleRequestsRef.current.delete(requestId);
        if (mountedRef.current) {
          setLoading(visibleRequestsRef.current.size > 0);
        }
      }
    }
  }, [api, enabled, path]);

  useEffect(() => {
    mountedRef.current = true;
    const visibleRequests = visibleRequestsRef.current;

    return () => {
      mountedRef.current = false;
      latestRequestRef.current += 1;
      visibleRequests.clear();
    };
  }, []);

  useEffect(() => {
    if (!enabled) {
      latestRequestRef.current += 1;
      visibleRequestsRef.current.clear();
      setLoading(false);
      setError(null);

      return undefined;
    }

    void load();

    return () => {
      latestRequestRef.current += 1;
    };
  }, [enabled, load]);

  const reload = useCallback(() => load(), [load]);
  const silentReload = useCallback(() => load({ silent: true }), [load]);
  const mutate = useCallback((next: T | null | ((current: T | null) => T | null)) => {
    setData((current) => typeof next === 'function' ? (next as (value: T | null) => T | null)(current) : next);
  }, []);

  return { data, loading, error, reload, silentReload, mutate };
}
