'use client';

import { useRouter } from 'next/navigation';
import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
} from 'react';
import { ApiClientError } from '../../lib/apiClient';
import { createRealtime } from '../../lib/realtime';
import { useApiResource } from '../../lib/useApiResource';
import type {
  UserNotification,
  UserNotificationFeed,
  UserNotificationMarkAllResult,
} from '../../types/api';
import { useAuth } from '../auth/AuthContext';
import {
  containsNotificationAfterCursor,
  newestNotificationCursor,
  safeNotificationActionUrl,
  type NotificationCursor,
} from './notificationPresentation';

const notificationPollIntervalMs = 60_000;
const notificationRingDurationMs = 720;
const realtimeRingDebounceMs = 1_200;

interface NotificationsContextValue {
  notifications: UserNotification[];
  unreadCount: number;
  loading: boolean;
  loadError: string | null;
  actionError: string | null;
  openingId: string | null;
  markingAllRead: boolean;
  isRinging: boolean;
  ringSequence: number;
  refresh: () => Promise<void>;
  openNotification: (notification: UserNotification) => Promise<boolean>;
  markAllRead: () => Promise<boolean>;
}

const NotificationsContext = createContext<NotificationsContextValue | null>(null);

export function NotificationsProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const userId = user?.id ?? null;

  return (
    <NotificationSessionProvider key={userId ?? 'signed-out'} userId={userId}>
      {children}
    </NotificationSessionProvider>
  );
}

function NotificationSessionProvider({
  children,
  userId,
}: {
  children: ReactNode;
  userId: string | null;
}) {
  const { api } = useAuth();
  const router = useRouter();
  const {
    data: inboxData,
    loading: inboxLoading,
    error: inboxError,
    silentReload,
    mutate: mutateInbox,
  } = useApiResource<UserNotificationFeed>('/notifications', userId !== null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [openingId, setOpeningId] = useState<string | null>(null);
  const [markingAllRead, setMarkingAllRead] = useState(false);
  const [isRinging, setIsRinging] = useState(false);
  const [ringSequence, setRingSequence] = useState(0);
  const ringTimerRef = useRef<number | null>(null);
  const cursorInitializedRef = useRef(false);
  const newestCursorRef = useRef<NotificationCursor | null>(null);
  const suppressNextFeedPulseRef = useRef(false);
  const lastRealtimeRingAtRef = useRef(0);

  const notifications = useMemo(
    () => (inboxData?.notifications ?? []).filter((notification) => notification.read_at === null),
    [inboxData?.notifications],
  );
  const unreadCount = Math.max(inboxData?.unread_count ?? notifications.length, notifications.length);

  const ring = useCallback(() => {
    if (ringTimerRef.current !== null) {
      window.clearTimeout(ringTimerRef.current);
    }
    setRingSequence((current) => current + 1);
    setIsRinging(true);
    ringTimerRef.current = window.setTimeout(() => {
      ringTimerRef.current = null;
      setIsRinging(false);
    }, notificationRingDurationMs);
  }, []);

  useEffect(() => () => {
    if (ringTimerRef.current !== null) {
      window.clearTimeout(ringTimerRef.current);
    }
  }, []);

  useEffect(() => {
    if (inboxData === null) {
      return;
    }

    const nextCursor = newestNotificationCursor(notifications);
    if (!cursorInitializedRef.current) {
      cursorInitializedRef.current = true;
      newestCursorRef.current = nextCursor;
      suppressNextFeedPulseRef.current = false;
      return;
    }

    const previousCursor = newestCursorRef.current;
    const containsNewArrival = previousCursor === null
      ? nextCursor !== null
      : containsNotificationAfterCursor(notifications, previousCursor);

    if (suppressNextFeedPulseRef.current) {
      suppressNextFeedPulseRef.current = false;
    } else if (containsNewArrival) {
      ring();
    }

    if (containsNewArrival) {
      newestCursorRef.current = nextCursor;
    }
  }, [inboxData, notifications, ring]);

  useEffect(() => {
    if (userId === null) {
      return undefined;
    }

    const refreshWhenVisible = () => {
      if (document.visibilityState === 'visible') {
        void silentReload();
      }
    };
    const interval = window.setInterval(refreshWhenVisible, notificationPollIntervalMs);
    window.addEventListener('focus', refreshWhenVisible);
    document.addEventListener('visibilitychange', refreshWhenVisible);

    return () => {
      window.clearInterval(interval);
      window.removeEventListener('focus', refreshWhenVisible);
      document.removeEventListener('visibilitychange', refreshWhenVisible);
    };
  }, [silentReload, userId]);

  useEffect(() => {
    if (userId === null) {
      return undefined;
    }

    const echo = createRealtime({
      notificationUserId: userId,
      onNotificationCreated: () => {
        const now = Date.now();
        if (now - lastRealtimeRingAtRef.current >= realtimeRingDebounceMs) {
          lastRealtimeRingAtRef.current = now;
          ring();
        }
        suppressNextFeedPulseRef.current = true;
        void silentReload();
      },
      onNotificationChanged: () => {
        void silentReload();
      },
    });

    return () => {
      echo?.leave(`user-notifications.${userId}`);
      echo?.disconnect();
    };
  }, [ring, silentReload, userId]);

  const openNotification = useCallback(async (notification: UserNotification): Promise<boolean> => {
    const actionUrl = safeNotificationActionUrl(notification.action_url);
    if (actionUrl === null) {
      setActionError('Deze melding heeft geen geldige bestemming.');
      return false;
    }

    setActionError(null);
    setOpeningId(notification.id);
    try {
      await api.patch<UserNotification>(`/notifications/${encodeURIComponent(notification.id)}/read`);
      mutateInbox((current) => current === null ? current : {
        notifications: current.notifications.filter((candidate) => candidate.id !== notification.id),
        unread_count: Math.max(0, current.unread_count - (
          current.notifications.some((candidate) => candidate.id === notification.id) ? 1 : 0
        )),
      });
      router.push(actionUrl);
      void silentReload();
      return true;
    } catch (error) {
      setActionError(error instanceof ApiClientError
        ? error.message
        : 'De melding kon niet als gelezen worden gemarkeerd.');
      return false;
    } finally {
      setOpeningId(null);
    }
  }, [api, mutateInbox, router, silentReload]);

  const markAllRead = useCallback(async (): Promise<boolean> => {
    setActionError(null);
    setMarkingAllRead(true);
    try {
      await api.patch<UserNotificationMarkAllResult>('/notifications/read-all');
      mutateInbox({ notifications: [], unread_count: 0 });
      return true;
    } catch (error) {
      setActionError(error instanceof ApiClientError
        ? error.message
        : 'De meldingen konden niet als gelezen worden gemarkeerd.');
      return false;
    } finally {
      setMarkingAllRead(false);
    }
  }, [api, mutateInbox]);

  const value = useMemo<NotificationsContextValue>(() => ({
    notifications,
    unreadCount,
    loading: inboxLoading,
    loadError: inboxError,
    actionError,
    openingId,
    markingAllRead,
    isRinging,
    ringSequence,
    refresh: silentReload,
    openNotification,
    markAllRead,
  }), [
    actionError,
    inboxError,
    inboxLoading,
    isRinging,
    markAllRead,
    markingAllRead,
    notifications,
    openNotification,
    openingId,
    ringSequence,
    silentReload,
    unreadCount,
  ]);

  return (
    <NotificationsContext.Provider value={value}>
      {children}
    </NotificationsContext.Provider>
  );
}

export function useNotifications(): NotificationsContextValue {
  const context = useContext(NotificationsContext);
  if (context === null) {
    throw new Error('useNotifications must be used inside NotificationsProvider');
  }

  return context;
}
