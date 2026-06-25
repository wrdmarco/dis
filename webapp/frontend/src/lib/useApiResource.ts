import { useCallback, useEffect, useState } from 'react';
import { ApiClientError } from './apiClient';
import { useAuth } from '../features/auth/AuthContext';

interface ResourceState<T> {
  data: T | null;
  loading: boolean;
  error: string | null;
  reload: () => Promise<void>;
}

export function useApiResource<T>(path: string, enabled = true): ResourceState<T> {
  const { api } = useAuth();
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState<boolean>(enabled);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!enabled) {
      return;
    }
    setLoading(true);
    setError(null);
    try {
      const response = await api.get<T>(path);
      setData(response.data);
    } catch (err) {
      setError(err instanceof ApiClientError ? err.message : 'Unable to load data.');
    } finally {
      setLoading(false);
    }
  }, [api, enabled, path]);

  useEffect(() => {
    void load();
  }, [load]);

  return { data, loading, error, reload: load };
}

