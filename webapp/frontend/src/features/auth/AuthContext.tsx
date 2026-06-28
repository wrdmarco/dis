import { createContext, useContext, useMemo, useState } from 'react';
import { ApiClient, apiBaseUrl } from '../../lib/apiClient';
import type { TwoFactorEnableResult, TwoFactorSetup, User } from '../../types/api';

interface LoginResult {
  requires_2fa: boolean;
  requires_2fa_setup?: boolean;
  two_factor_setup?: TwoFactorSetup;
  token: string;
  user?: User;
}

interface AuthContextValue {
  api: ApiClient;
  token: string | null;
  user: User | null;
  isAuthenticated: boolean;
  setSession: (token: string, user?: User | null, purpose?: SessionPurpose) => void;
  clearSession: () => void;
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
const tokenKey = 'dis.session.token';
const tokenPurposeKey = 'dis.session.purpose';
type SessionPurpose = 'full' | 'mfa';

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [token, setToken] = useState<string | null>(() => storedToken());
  const [sessionPurpose, setSessionPurpose] = useState<SessionPurpose>(() => storedTokenPurpose());
  const [user, setUser] = useState<User | null>(null);

  const clearSession = () => {
    sessionStorage.removeItem(tokenKey);
    sessionStorage.removeItem(tokenPurposeKey);
    localStorage.removeItem(tokenKey);
    localStorage.removeItem(tokenPurposeKey);
    setToken(null);
    setSessionPurpose('full');
    setUser(null);
  };

  const api = useMemo(
    () =>
      new ApiClient({
        baseUrl: apiBaseUrl,
        getToken: storedToken,
        onUnauthenticated: clearSession,
      }),
    [],
  );

  const setSession = (nextToken: string, nextUser?: User | null, purpose: SessionPurpose = 'full') => {
    localStorage.setItem(tokenKey, nextToken);
    localStorage.setItem(tokenPurposeKey, purpose);
    sessionStorage.removeItem(tokenKey);
    sessionStorage.removeItem(tokenPurposeKey);
    setToken(nextToken);
    setSessionPurpose(purpose);
    if (nextUser !== undefined) {
      setUser(purpose === 'full' ? nextUser : null);
    }
  };

  const login = async (email: string, password: string): Promise<LoginResult> => {
    const response = await api.post<LoginResult>('/auth/login', { email, password, device_name: 'DIS Command Center' });
    const isMfaChallenge = response.data.requires_2fa === true || response.data.requires_2fa_setup === true;
    setSession(response.data.token, response.data.user ?? null, isMfaChallenge ? 'mfa' : 'full');
    return response.data;
  };

  const verifyTwoFactor = async (code: string): Promise<User> => {
    const response = await api.post<{ token: string; user: User }>('/auth/2fa/verify', { code, device_name: 'DIS Command Center' });
    setSession(response.data.token, response.data.user, 'full');
    return response.data.user;
  };

  const startTwoFactorSetup = async (): Promise<TwoFactorSetup> => {
    const response = await api.post<TwoFactorSetup>('/auth/2fa/setup');
    return response.data;
  };

  const enableTwoFactor = async (code: string): Promise<TwoFactorEnableResult> => {
    const response = await api.post<TwoFactorEnableResult>('/auth/2fa/enable', { code, device_name: 'DIS Command Center' });
    setSession(response.data.token, response.data.user, 'full');
    return response.data;
  };

  const disableTwoFactor = async (password: string, code: string): Promise<User> => {
    const response = await api.post<User>('/auth/2fa/disable', { password, code });
    setUser(response.data);
    return response.data;
  };

  const refreshMe = async (): Promise<User | null> => {
    if (storedToken() === null) {
      return null;
    }
    if (storedTokenPurpose() !== 'full') {
      setUser(null);
      return null;
    }
    const response = await api.get<User>('/auth/me');
    setUser(response.data);
    return response.data;
  };

  const hasPermission = (permission: string): boolean =>
    user?.roles?.some((role) => role.can_use_admin_app && role.permissions?.some((candidate) => candidate.name === permission)) ?? false;

  const canUseWebConsole = (): boolean =>
    user?.roles?.some((role) => role.can_use_admin_app) ?? false;

  return (
    <AuthContext.Provider
      value={{
        api,
        token,
        user,
        isAuthenticated: token !== null && sessionPurpose === 'full',
        setSession,
        clearSession,
        login,
        verifyTwoFactor,
        startTwoFactorSetup,
        enableTwoFactor,
        disableTwoFactor,
        refreshMe,
        hasPermission,
        canUseWebConsole,
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

function storedToken(): string | null {
  const persistentToken = localStorage.getItem(tokenKey);
  if (persistentToken !== null) {
    return persistentToken;
  }

  const legacySessionToken = sessionStorage.getItem(tokenKey);
  if (legacySessionToken !== null) {
    localStorage.setItem(tokenKey, legacySessionToken);
    localStorage.setItem(tokenPurposeKey, sessionStorage.getItem(tokenPurposeKey) ?? 'full');
    sessionStorage.removeItem(tokenKey);
    sessionStorage.removeItem(tokenPurposeKey);
  }

  return legacySessionToken;
}

function storedTokenPurpose(): SessionPurpose {
  const purpose = localStorage.getItem(tokenPurposeKey) ?? sessionStorage.getItem(tokenPurposeKey);

  return purpose === 'mfa' ? 'mfa' : 'full';
}
