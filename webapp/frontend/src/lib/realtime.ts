import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { apiBaseUrl, csrfTokenFromCookie } from './apiClient';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

export interface RealtimeOptions {
  onOperationalEvent?: () => void;
  onSystemUpdateStatus?: (payload: unknown) => void;
}

export function createRealtime(options: RealtimeOptions): Echo<'reverb'> | null {
  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY;

  if (appKey === undefined || appKey.trim() === '') {
    return null;
  }

  window.Pusher = Pusher;
  const authorizationEndpoint = `${apiBaseUrl}/broadcasting/auth`;
  const forceTls = window.location.protocol === 'https:';

  const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: appKey,
    wsHost: process.env.NEXT_PUBLIC_WEBSOCKET_HOST ?? window.location.hostname,
    wsPort: Number(process.env.NEXT_PUBLIC_WEBSOCKET_PORT ?? (forceTls ? 443 : 80)),
    wssPort: Number(process.env.NEXT_PUBLIC_WEBSOCKET_PORT ?? 443),
    forceTLS: forceTls,
    enabledTransports: ['ws'],
    channelAuthorization: {
      customHandler: (params, callback) => {
        void authorizeChannel(authorizationEndpoint, params.socketId, params.channelName)
          .then((authorization) => callback(null, authorization))
          .catch((error: unknown) => {
            callback(error instanceof Error ? error : new Error('Realtime authorization failed.'), null);
          });
      },
    },
  });

  if (options.onOperationalEvent !== undefined) {
    echo.private('operations')
      .listen('.incident.changed', options.onOperationalEvent)
      .listen('.dispatch.changed', options.onOperationalEvent)
      .listen('.location.updated', options.onOperationalEvent)
      .listen('.availability.changed', options.onOperationalEvent)
      .listen('.asset.changed', options.onOperationalEvent);
  }

  if (options.onSystemUpdateStatus !== undefined) {
    echo.private('admin.system')
      .listen('.system.update.status', options.onSystemUpdateStatus);
  }

  return echo;
}

interface ChannelAuthorization {
  auth: string;
  channel_data?: string;
  shared_secret?: string;
}

async function authorizeChannel(endpoint: string, socketId: string, channelName: string): Promise<ChannelAuthorization> {
  let csrfToken = csrfTokenFromCookie();
  if (csrfToken === null) {
    const csrfResponse = await fetch(`${apiBaseUrl}/auth/csrf-cookie`, {
      method: 'GET',
      credentials: 'include',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });
    if (!csrfResponse.ok) {
      throw new Error('Unable to initialize realtime authorization.');
    }
    csrfToken = csrfTokenFromCookie();
  }

  if (csrfToken === null) {
    throw new Error('The CSRF token is unavailable.');
  }

  const response = await fetch(endpoint, {
    method: 'POST',
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
  });

  if (!response.ok) {
    throw new Error('Realtime authorization failed.');
  }

  return await response.json() as ChannelAuthorization;
}
