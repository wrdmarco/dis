import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

export interface RealtimeOptions {
  token: string;
  onOperationalEvent: () => void;
}

export function createRealtime(options: RealtimeOptions): Echo<'reverb'> {
  window.Pusher = Pusher;

  const echo = new Echo<'reverb'>({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_WEBSOCKET_HOST ?? window.location.hostname,
    wsPort: Number(import.meta.env.VITE_WEBSOCKET_PORT ?? 80),
    forceTLS: false,
    enabledTransports: ['ws'],
    authEndpoint: `${import.meta.env.VITE_API_BASE_URL ?? '/api'}/broadcasting/auth`,
    auth: {
      headers: {
        Authorization: `Bearer ${options.token}`,
        Accept: 'application/json',
      },
    },
  });

  echo.private('operations')
    .listen('.incident.changed', options.onOperationalEvent)
    .listen('.dispatch.changed', options.onOperationalEvent)
    .listen('.location.updated', options.onOperationalEvent)
    .listen('.availability.changed', options.onOperationalEvent)
    .listen('.asset.changed', options.onOperationalEvent);

  return echo;
}
