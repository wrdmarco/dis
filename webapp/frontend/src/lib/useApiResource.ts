import { useCallback, useEffect, useState } from 'react';
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

  const load = useCallback(async (options?: { silent?: boolean }) => {
    if (!enabled) {
      return;
    }
    if (options?.silent !== true) {
      setLoading(true);
    }
    setError(null);
    try {
      const response = await api.get<T>(path);
      setData(response.data);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to load data.');
    } finally {
      if (options?.silent !== true) {
        setLoading(false);
      }
    }
  }, [api, enabled, path]);

  useEffect(() => {
    void load();
  }, [load]);

  const reload = useCallback(() => load(), [load]);
  const silentReload = useCallback(() => load({ silent: true }), [load]);
  const mutate = useCallback((next: T | null | ((current: T | null) => T | null)) => {
    setData((current) => typeof next === 'function' ? (next as (value: T | null) => T | null)(current) : next);
  }, []);

  return { data, loading, error, reload, silentReload, mutate };
}
