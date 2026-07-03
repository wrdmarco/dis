import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

export interface RealtimeOptions {
  token: string;
  onOperationalEvent?: () => void;
  onSystemUpdateStatus?: (payload: unknown) => void;
}

export function createRealtime(options: RealtimeOptions): Echo<'reverb'> | null {
  const appKey = process.env.NEXT_PUBLIC_REVERB_APP_KEY;

  if (appKey === undefined || appKey.trim() === '') {
    return null;
  }

  window.Pusher = Pusher;

  const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: appKey,
    wsHost: process.env.NEXT_PUBLIC_WEBSOCKET_HOST ?? window.location.hostname,
    wsPort: Number(process.env.NEXT_PUBLIC_WEBSOCKET_PORT ?? 80),
    forceTLS: false,
    enabledTransports: ['ws'],
    authEndpoint: `${process.env.NEXT_PUBLIC_API_BASE_URL ?? '/api'}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${options.token}`,
        Accept: 'application/json',
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
