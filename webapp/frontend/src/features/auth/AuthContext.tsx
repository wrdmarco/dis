import { createContext, useContext, useMemo, useState } from 'react';
import { ApiClient, apiBaseUrl } from '../../lib/apiClient';
import type { TwoFactorEnableResult, TwoFactorSetup, User } from '../../types/api';

interface LoginResult {
  requires_2fa: boolean;
  requires_2fa_setup?: boolean;
  token: string;
  user?: User;
}

interface AuthContextValue {
  api: ApiClient;
  token: string | null;
  user: User | null;
  isAuthenticated: boolean;
  setSession: (token: string, user?: User | null) => void;
  clearSession: () => void;
  login: (email: string, password: string) => Promise<LoginResult>;
  verifyTwoFactor: (code: string) => Promise<User>;
  startTwoFactorSetup: () => Promise<TwoFactorSetup>;
  enableTwoFactor: (code: string) => Promise<TwoFactorEnableResult>;
  disableTwoFactor: (password: string, code: string) => Promise<User>;
  refreshMe: () => Promise<User | null>;
  hasPermission: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextValue | null>(null);
const tokenKey = 'dis.session.token';

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(() => sessionStorage.getItem(tokenKey));
  const [user, setUser] = useState<User | null>(null);

  const clearSession = () => {
    sessionStorage.removeItem(tokenKey);
    setToken(null);
    setUser(null);
  };

  const api = useMemo(
    () =>
      new ApiClient({
        baseUrl: apiBaseUrl,
        getToken: () => sessionStorage.getItem(tokenKey),
        onUnauthenticated: clearSession,
      }),
    [],
  );

  const setSession = (nextToken: string, nextUser?: User | null) => {
    sessionStorage.setItem(tokenKey, nextToken);
    setToken(nextToken);
    if (nextUser !== undefined) {
      setUser(nextUser);
    }
  };

  const login = async (email: string, password: string): Promise<LoginResult> => {
    const response = await api.post<LoginResult>('/auth/login', { email, password, device_name: 'DIS Command Center' });
    setSession(response.data.token, response.data.user ?? null);
    return response.data;
  };

  const verifyTwoFactor = async (code: string): Promise<User> => {
    const response = await api.post<{ token: string; user: User }>('/auth/2fa/verify', { code, device_name: 'DIS Command Center' });
    setSession(response.data.token, response.data.user);
    return response.data.user;
  };

  const startTwoFactorSetup = async (): Promise<TwoFactorSetup> => {
    const response = await api.post<TwoFactorSetup>('/auth/2fa/setup');
    return response.data;
  };

  const enableTwoFactor = async (code: string): Promise<TwoFactorEnableResult> => {
    const response = await api.post<TwoFactorEnableResult>('/auth/2fa/enable', { code, device_name: 'DIS Command Center' });
    setSession(response.data.token, response.data.user);
    return response.data;
  };

  const disableTwoFactor = async (password: string, code: string): Promise<User> => {
    const response = await api.post<User>('/auth/2fa/disable', { password, code });
    setUser(response.data);
    return response.data;
  };

  const refreshMe = async (): Promise<User | null> => {
    if (!sessionStorage.getItem(tokenKey)) {
      return null;
    }
    const response = await api.get<User>('/auth/me');
    setUser(response.data);
    return response.data;
  };

  const hasPermission = (permission: string): boolean =>
    user?.roles?.some((role) => role.permissions?.some((candidate) => candidate.name === permission)) ?? false;

  return (
    <AuthContext.Provider
      value={{
        api,
        token,
        user,
        isAuthenticated: token !== null,
        setSession,
        clearSession,
        login,
        verifyTwoFactor,
        startTwoFactorSetup,
        enableTwoFactor,
        disableTwoFactor,
        refreshMe,
        hasPermission,
      }}
    >
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
