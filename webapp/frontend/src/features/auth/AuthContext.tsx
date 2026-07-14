import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { ApiClient, apiBaseUrl } from '../../lib/apiClient';
import type { TwoFactorEnableResult, TwoFactorSetup, User } from '../../types/api';

interface LoginResult {
  requires_2fa: boolean;
  requires_2fa_setup?: boolean;
  authenticated: boolean;
  two_factor_setup?: TwoFactorSetup;
  user?: User;
}

interface AuthContextValue {
  api: ApiClient;
  user: User | null;
  theme: ThemePreference;
  isAuthenticated: boolean;
  clearSession: () => void;
  setThemePreference: (theme: ThemePreference) => Promise<void>;
  login: (email: string, password: string) => Promise<LoginResult>;
  verifyTwoFactor: (code: string) => Promise<User>;
  startTwoFactorSetup: () => Promise<TwoFactorSetup>;
  enableTwoFactor: (code: string) => Promise<TwoFactorEnableResult>;
  disableTwoFactor: (password: string, code: string) => Promise<User>;
  refreshMe: () => Promise<User | null>;
  hasPermission: (permission: string) => boolean;
  canUseWebConsole: () => boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);
const legacyTokenKey = 'dis.session.token';
const legacyTokenPurposeKey = 'dis.session.purpose';
const themeKey = 'dis.theme';
type ThemePreference = 'dark' | 'light';

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(() => {
    removeLegacyBearerState();
    return null;
  });
  const [theme, setTheme] = useState<ThemePreference>(() => storedTheme());
  const [initializing, setInitializing] = useState(true);

  const clearSession = useCallback(() => {
    removeLegacyBearerState();
    setUser(null);
  }, []);

  const api = useMemo(
    () => new ApiClient({ baseUrl: apiBaseUrl, onUnauthenticated: clearSession }),
    [clearSession],
  );

  const applyAuthenticatedUser = useCallback((nextUser: User | null) => {
    setUser(nextUser);
    if (nextUser !== null) {
      setTheme(themeFromUser(nextUser));
    }
  }, []);

  const refreshMe = useCallback(async (): Promise<User | null> => {
    const response = await api.get<User>('/auth/me');
    applyAuthenticatedUser(response.data);
    return response.data;
  }, [api, applyAuthenticatedUser]);

  useEffect(() => {
    let cancelled = false;

    void api.get<User>('/auth/me')
      .then((response) => {
        if (!cancelled) {
          applyAuthenticatedUser(response.data);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setUser(null);
        }
      })
      .finally(() => {
        if (!cancelled) {
          setInitializing(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [api, applyAuthenticatedUser]);

  const login = useCallback(async (email: string, password: string): Promise<LoginResult> => {
    const response = await api.post<LoginResult>('/auth/login', {
      email,
      password,
      device_name: 'DIS Command Center',
      client_type: 'web',
    });
    applyAuthenticatedUser(response.data.authenticated ? response.data.user ?? null : null);
    return response.data;
  }, [api, applyAuthenticatedUser]);

  const verifyTwoFactor = useCallback(async (code: string): Promise<User> => {
    const response = await api.post<{ authenticated: boolean; user: User }>('/auth/2fa/verify', {
      code,
      device_name: 'DIS Command Center',
      client_type: 'web',
    });
    applyAuthenticatedUser(response.data.authenticated ? response.data.user : null);
    return response.data.user;
  }, [api, applyAuthenticatedUser]);

  const startTwoFactorSetup = useCallback(async (): Promise<TwoFactorSetup> => {
    const response = await api.post<TwoFactorSetup>('/auth/2fa/setup');
    return response.data;
  }, [api]);

  const enableTwoFactor = useCallback(async (code: string): Promise<TwoFactorEnableResult> => {
    const response = await api.post<TwoFactorEnableResult>('/auth/2fa/enable', {
      code,
      device_name: 'DIS Command Center',
      client_type: 'web',
    });
    applyAuthenticatedUser(response.data.authenticated ? response.data.user : null);
    return response.data;
  }, [api, applyAuthenticatedUser]);

  const disableTwoFactor = useCallback(async (password: string, code: string): Promise<User> => {
    const response = await api.post<User>('/auth/2fa/disable', { password, code });
    applyAuthenticatedUser(response.data);
    return response.data;
  }, [api, applyAuthenticatedUser]);

  const setThemePreference = useCallback(async (nextTheme: ThemePreference): Promise<void> => {
    const currentUser = user;
    setTheme(nextTheme);
    localStorage.setItem(themeKey, nextTheme);
    if (currentUser === null) {
      return;
    }

    const response = await api.patch<User>('/auth/me', { theme: nextTheme });
    applyAuthenticatedUser(response.data);
  }, [api, applyAuthenticatedUser, user]);

  const hasPermission = useCallback((permission: string): boolean =>
    user?.roles?.some((role) => role.permissions?.some((candidate) => candidate.name === permission)) ?? false,
  [user]);

  const canUseWebConsole = useCallback((): boolean =>
    user?.roles?.some((role) => role.can_use_admin_app) ?? false,
  [user]);

  const contextValue = useMemo<AuthContextValue>(() => ({
    api,
    user,
    theme,
    isAuthenticated: user !== null,
    clearSession,
    setThemePreference,
    login,
    verifyTwoFactor,
    startTwoFactorSetup,
    enableTwoFactor,
    disableTwoFactor,
    refreshMe,
    hasPermission,
    canUseWebConsole,
  }), [
    api,
    user,
    theme,
    clearSession,
    setThemePreference,
    login,
    verifyTwoFactor,
    startTwoFactorSetup,
    enableTwoFactor,
    disableTwoFactor,
    refreshMe,
    hasPermission,
    canUseWebConsole,
  ]);

  if (initializing) {
    return <div role="status" aria-live="polite">D.I.S laden…</div>;
  }

  return (
    <AuthContext.Provider value={contextValue}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (context === null) {
    throw new Error('useAuth must be used inside AuthProvider');
  }
  return context;
}

function removeLegacyBearerState(): void {
  if (!isBrowser()) {
    return;
  }

  localStorage.removeItem(legacyTokenKey);
  localStorage.removeItem(legacyTokenPurposeKey);
  sessionStorage.removeItem(legacyTokenKey);
  sessionStorage.removeItem(legacyTokenPurposeKey);
}

function storedTheme(): ThemePreference {
  if (!isBrowser()) {
    return 'dark';
  }

  return localStorage.getItem(themeKey) === 'light' ? 'light' : 'dark';
}

function themeFromUser(user?: User | null): ThemePreference {
  const theme = user?.mail_preferences?.ui?.theme === 'light' ? 'light' : 'dark';
  if (isBrowser()) {
    localStorage.setItem(themeKey, theme);
  }

  return theme;
}

function isBrowser(): boolean {
  return typeof window !== 'undefined';
}
