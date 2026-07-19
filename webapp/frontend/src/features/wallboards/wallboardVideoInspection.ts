'use client';

const YOUTUBE_IFRAME_API_URL = 'https://www.youtube.com/iframe_api';
const VIMEO_PLAYER_API_URL = 'https://player.vimeo.com/api/player.js';
const PROVIDER_API_LOAD_TIMEOUT_MS = 10_000;
const DEFAULT_INSPECTION_TIMEOUT_MS = 15_000;
const MIN_INSPECTION_TIMEOUT_MS = 3_000;
const MAX_INSPECTION_TIMEOUT_MS = 30_000;

/**
 * Houdt rekening met het laden van de externe speler voordat de daadwerkelijke
 * video begint. De exacte videoduur blijft afzonderlijk beschikbaar.
 */
export const WALLBOARD_VIDEO_STARTUP_ALLOWANCE_SECONDS = 5;
export const WALLBOARD_VIDEO_MAX_CONTENT_DURATION_SECONDS = 3595;

export type WallboardVideoProvider = 'youtube' | 'vimeo';

export interface WallboardInspectableVideo {
  provider: WallboardVideoProvider;
  videoId: string;
  canonicalUrl: string;
  embedUrl: string;
}

export interface WallboardVideoInspectionSuccess {
  status: 'ready';
  video: WallboardInspectableVideo;
  durationSeconds: number;
  recommendedDisplayDurationSeconds: number;
}

export type WallboardVideoInspectionFailureCode =
  | 'invalid_url'
  | 'not_embeddable'
  | 'unavailable'
  | 'provider_error'
  | 'too_long'
  | 'timeout'
  | 'cancelled';

export interface WallboardVideoInspectionFailure {
  status: 'failed';
  code: WallboardVideoInspectionFailureCode;
  provider: WallboardVideoProvider | null;
  message: string;
}

export type WallboardVideoInspectionResult =
  | WallboardVideoInspectionSuccess
  | WallboardVideoInspectionFailure;

export interface WallboardVideoInspectionOptions {
  signal?: AbortSignal;
  timeoutMs?: number;
}

interface YouTubePlayerEvent {
  target: YouTubePlayer;
  data?: number;
}

interface YouTubePlayer {
  destroy(): void;
  getDuration(): number;
  getIframe(): HTMLIFrameElement;
}

interface YouTubePlayerConfiguration {
  videoId: string;
  width: string;
  height: string;
  playerVars: {
    autoplay: 0;
    controls: 0;
    enablejsapi: 1;
    origin: string;
    playsinline: 1;
  };
  events: {
    onReady(event: YouTubePlayerEvent): void;
    onError(event: YouTubePlayerEvent): void;
  };
}

interface YouTubePlayerApi {
  Player: new (element: HTMLElement, configuration: YouTubePlayerConfiguration) => YouTubePlayer;
}

interface VimeoPlayer {
  destroy(): Promise<void>;
  getDuration(): Promise<number>;
  ready(): Promise<void>;
}

interface VimeoPlayerApi {
  Player: new (element: HTMLIFrameElement) => VimeoPlayer;
}

interface ExternalPlayerWindow extends Window {
  YT?: YouTubePlayerApi;
  Vimeo?: VimeoPlayerApi;
}

class ProviderInspectionError extends Error {
  public constructor(public readonly code: WallboardVideoInspectionFailureCode) {
    super(code);
    this.name = 'ProviderInspectionError';
  }
}

const providerApiPromises: Partial<Record<WallboardVideoProvider, Promise<unknown>>> = {};

/**
 * Parseert alleen de URL-vormen die DIS server-side accepteert. De provider-ID
 * wordt vervolgens in een vaste URL-template geplaatst; invoer wordt nooit als
 * HTML of scriptbron gebruikt.
 */
export function parseWallboardInspectableVideo(value: string): WallboardInspectableVideo | null {
  const trimmed = value.trim();
  if (trimmed === '' || trimmed.length > 2048 || /[\u0000-\u0020\u007f]/.test(trimmed)) return null;

  try {
    const url = new URL(trimmed);
    if (
      url.protocol !== 'https:'
      || url.username !== ''
      || url.password !== ''
      || (url.port !== '' && url.port !== '443')
    ) {
      return null;
    }

    const host = url.hostname.toLowerCase();
    if (['youtube.com', 'www.youtube.com', 'm.youtube.com'].includes(host)) {
      const watchId = url.pathname === '/watch' ? url.searchParams.get('v') : null;
      const embedId = /^\/embed\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname)?.[1] ?? null;
      const shortsId = /^\/shorts\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname)?.[1] ?? null;
      return youtubeVideo(watchId ?? embedId ?? shortsId);
    }

    if (host === 'youtu.be') {
      return youtubeVideo(/^\/([A-Za-z0-9_-]{11})\/?$/.exec(url.pathname)?.[1] ?? null);
    }

    const vimeoPattern = ['vimeo.com', 'www.vimeo.com'].includes(host)
      ? /^\/([1-9][0-9]{0,11})\/?$/
      : host === 'player.vimeo.com'
        ? /^\/video\/([1-9][0-9]{0,11})\/?$/
        : null;
    const videoId = vimeoPattern?.exec(url.pathname)?.[1] ?? null;
    if (videoId === null) return null;

    return {
      provider: 'vimeo',
      videoId,
      canonicalUrl: `https://player.vimeo.com/video/${videoId}`,
      embedUrl: `https://player.vimeo.com/video/${videoId}?autoplay=0&dnt=1&title=0`,
    };
  } catch {
    return null;
  }
}

export function wallboardVideoDurationSeconds(value: number): number | null {
  if (!Number.isFinite(value) || value <= 0) return null;
  return Math.ceil(value);
}

export function wallboardVideoRecommendedDisplayDurationSeconds(value: number): number | null {
  const durationSeconds = wallboardVideoDurationSeconds(value);
  return durationSeconds === null
    ? null
    : durationSeconds + WALLBOARD_VIDEO_STARTUP_ALLOWANCE_SECONDS;
}

export function formatWallboardVideoDuration(seconds: number): string {
  const total = Math.max(0, Math.trunc(seconds));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);
  const remainder = total % 60;
  return hours > 0
    ? `${hours}:${minutes.toString().padStart(2, '0')}:${remainder.toString().padStart(2, '0')}`
    : `${minutes}:${remainder.toString().padStart(2, '0')}`;
}

export function youtubeInspectionFailureCode(errorCode: number): WallboardVideoInspectionFailureCode {
  if (errorCode === 101 || errorCode === 150) return 'not_embeddable';
  if (errorCode === 100) return 'unavailable';
  return 'provider_error';
}

export function vimeoInspectionFailureCode(error: unknown): WallboardVideoInspectionFailureCode {
  const name = errorName(error).toLowerCase();
  if (name.includes('privacy') || name.includes('embed')) return 'not_embeddable';
  if (name.includes('password') || name.includes('notfound') || name.includes('unavailable')) return 'unavailable';
  return 'provider_error';
}

/**
 * Controleert via de officiële YouTube IFrame API of Vimeo Player API of een
 * video werkelijk in een wallboard kan worden geladen en leest de providerduur.
 */
export async function inspectWallboardVideo(
  value: string,
  options: WallboardVideoInspectionOptions = {},
): Promise<WallboardVideoInspectionResult> {
  const video = parseWallboardInspectableVideo(value);
  if (video === null) return failure('invalid_url', null);
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return failure('provider_error', video.provider);
  }

  const timeoutMs = boundedTimeout(options.timeoutMs);
  const controller = new AbortController();
  const relayAbort = () => controller.abort('cancelled');
  options.signal?.addEventListener('abort', relayAbort, { once: true });
  const timeout = window.setTimeout(() => controller.abort('timeout'), timeoutMs);

  try {
    if (options.signal?.aborted === true) controller.abort('cancelled');
    const duration = video.provider === 'youtube'
      ? await inspectYouTubeVideo(video, controller.signal)
      : await inspectVimeoVideo(video, controller.signal);
    const durationSeconds = wallboardVideoDurationSeconds(duration);
    const recommendedDisplayDurationSeconds = wallboardVideoRecommendedDisplayDurationSeconds(duration);
    if (durationSeconds === null || recommendedDisplayDurationSeconds === null) {
      return failure('provider_error', video.provider);
    }
    if (durationSeconds > WALLBOARD_VIDEO_MAX_CONTENT_DURATION_SECONDS) {
      return failure('too_long', video.provider);
    }

    return {
      status: 'ready',
      video,
      durationSeconds,
      recommendedDisplayDurationSeconds,
    };
  } catch (error) {
    if (controller.signal.aborted) {
      return failure(controller.signal.reason === 'timeout' ? 'timeout' : 'cancelled', video.provider);
    }
    if (error instanceof ProviderInspectionError) return failure(error.code, video.provider);
    return failure('provider_error', video.provider);
  } finally {
    window.clearTimeout(timeout);
    options.signal?.removeEventListener('abort', relayAbort);
  }
}

async function inspectYouTubeVideo(video: WallboardInspectableVideo, signal: AbortSignal): Promise<number> {
  const api = await loadYouTubeApi(signal);
  const probe = createProbeContainer('youtube');
  const playerHolder: { current: YouTubePlayer | null } = { current: null };
  const iframeObserver = new MutationObserver(() => applyProbeIframeReferrerPolicy(probe.wrapper));
  iframeObserver.observe(probe.wrapper, { childList: true, subtree: true });

  try {
    return await new Promise<number>((resolve, reject) => {
      let settled = false;
      const finish = (callback: () => void) => {
        if (settled) return;
        settled = true;
        signal.removeEventListener('abort', abort);
        callback();
      };
      const abort = () => finish(() => reject(new ProviderInspectionError('cancelled')));
      signal.addEventListener('abort', abort, { once: true });

      try {
        playerHolder.current = new api.Player(probe.element, {
          videoId: video.videoId,
          width: '1',
          height: '1',
          playerVars: {
            autoplay: 0,
            controls: 0,
            enablejsapi: 1,
            origin: window.location.origin,
            playsinline: 1,
          },
          events: {
            onReady: (event) => {
              event.target.getIframe().referrerPolicy = 'strict-origin-when-cross-origin';
              void waitForPositiveDuration(() => event.target.getDuration(), signal)
                .then((duration) => finish(() => resolve(duration)))
                .catch((error: unknown) => finish(() => reject(error)));
            },
            onError: (event) => {
              const code = typeof event.data === 'number'
                ? youtubeInspectionFailureCode(event.data)
                : 'provider_error';
              finish(() => reject(new ProviderInspectionError(code)));
            },
          },
        });
      } catch (error) {
        finish(() => reject(error));
      }
    });
  } finally {
    iframeObserver.disconnect();
    try {
      playerHolder.current?.destroy();
    } catch {
      // De probe wordt hieronder altijd verwijderd; destroy is best effort.
    }
    probe.wrapper.remove();
  }
}

function applyProbeIframeReferrerPolicy(wrapper: HTMLElement): void {
  wrapper.querySelectorAll('iframe').forEach((iframe) => {
    iframe.referrerPolicy = 'strict-origin-when-cross-origin';
  });
}

async function inspectVimeoVideo(video: WallboardInspectableVideo, signal: AbortSignal): Promise<number> {
  const api = await loadVimeoApi(signal);
  const iframe = document.createElement('iframe');
  iframe.src = video.embedUrl;
  iframe.width = '1';
  iframe.height = '1';
  iframe.title = 'Tijdelijke controle van wallboardvideo';
  iframe.referrerPolicy = 'strict-origin-when-cross-origin';
  iframe.allow = 'autoplay; fullscreen; picture-in-picture';
  iframe.setAttribute('aria-hidden', 'true');
  iframe.tabIndex = -1;
  const wrapper = createProbeWrapper('vimeo');
  wrapper.append(iframe);

  let player: VimeoPlayer | null = null;
  try {
    player = new api.Player(iframe);
    await abortable(player.ready(), signal);
    return await abortable(player.getDuration(), signal);
  } catch (error) {
    if (signal.aborted) throw new ProviderInspectionError('cancelled');
    throw new ProviderInspectionError(vimeoInspectionFailureCode(error));
  } finally {
    if (player !== null) {
      try {
        await player.destroy();
      } catch {
        // De iframe wordt hieronder altijd verwijderd; destroy is best effort.
      }
    }
    wrapper.remove();
  }
}

function loadYouTubeApi(signal: AbortSignal): Promise<YouTubePlayerApi> {
  return abortable(loadProviderApi('youtube', YOUTUBE_IFRAME_API_URL, () => {
    const api = externalWindow().YT;
    return api !== undefined && typeof api.Player === 'function' ? api : undefined;
  }), signal);
}

function loadVimeoApi(signal: AbortSignal): Promise<VimeoPlayerApi> {
  return abortable(loadProviderApi('vimeo', VIMEO_PLAYER_API_URL, () => {
    const api = externalWindow().Vimeo;
    return api !== undefined && typeof api.Player === 'function' ? api : undefined;
  }), signal);
}

function loadProviderApi<T>(
  provider: WallboardVideoProvider,
  source: string,
  readApi: () => T | undefined,
): Promise<T> {
  const readyApi = readApi();
  if (readyApi !== undefined) return Promise.resolve(readyApi);

  const existingPromise = providerApiPromises[provider] as Promise<T> | undefined;
  if (existingPromise !== undefined) return existingPromise;

  const promise = new Promise<T>((resolve, reject) => {
    const scriptId = `dis-wallboard-${provider}-player-api`;
    const existing = document.getElementById(scriptId);
    let script: HTMLScriptElement;
    if (existing === null) {
      script = document.createElement('script');
      script.id = scriptId;
      script.src = source;
      script.async = true;
      script.referrerPolicy = 'strict-origin-when-cross-origin';
      document.head.append(script);
    } else if (existing instanceof HTMLScriptElement && existing.src === source) {
      script = existing;
    } else {
      reject(new ProviderInspectionError('provider_error'));
      return;
    }

    let elapsed = 0;
    const intervalMs = 50;
    const interval = window.setInterval(() => {
      elapsed += intervalMs;
      const api = readApi();
      if (api !== undefined) {
        cleanup();
        resolve(api);
      } else if (elapsed >= PROVIDER_API_LOAD_TIMEOUT_MS) {
        cleanup();
        reject(new ProviderInspectionError('timeout'));
      }
    }, intervalMs);
    const onError = () => {
      cleanup();
      reject(new ProviderInspectionError('provider_error'));
    };
    const cleanup = () => {
      window.clearInterval(interval);
      script.removeEventListener('error', onError);
    };
    script.addEventListener('error', onError, { once: true });
  }).catch((error: unknown) => {
    delete providerApiPromises[provider];
    throw error;
  });

  providerApiPromises[provider] = promise;
  return promise;
}

async function waitForPositiveDuration(readDuration: () => number, signal: AbortSignal): Promise<number> {
  while (true) {
    if (signal.aborted) throw new ProviderInspectionError('cancelled');
    const duration = readDuration();
    if (wallboardVideoDurationSeconds(duration) !== null) return duration;
    await abortableDelay(100, signal);
  }
}

function createProbeContainer(provider: WallboardVideoProvider): {
  wrapper: HTMLDivElement;
  element: HTMLDivElement;
} {
  const wrapper = createProbeWrapper(provider);
  const probe = document.createElement('div');
  probe.setAttribute('aria-hidden', 'true');
  wrapper.append(probe);
  return { wrapper, element: probe };
}

function createProbeWrapper(provider: WallboardVideoProvider): HTMLDivElement {
  const wrapper = document.createElement('div');
  wrapper.dataset.wallboardVideoProbe = provider;
  wrapper.setAttribute('aria-hidden', 'true');
  Object.assign(wrapper.style, {
    position: 'fixed',
    top: '0',
    left: '-10000px',
    width: '1px',
    height: '1px',
    overflow: 'hidden',
    opacity: '0',
    pointerEvents: 'none',
  });
  document.body.append(wrapper);
  return wrapper;
}

function youtubeVideo(videoId: string | null): WallboardInspectableVideo | null {
  if (videoId === null || !/^[A-Za-z0-9_-]{11}$/.test(videoId)) return null;
  return {
    provider: 'youtube',
    videoId,
    canonicalUrl: `https://www.youtube.com/embed/${videoId}`,
    embedUrl: `https://www.youtube.com/embed/${videoId}?enablejsapi=1&playsinline=1&rel=0`,
  };
}

function externalWindow(): ExternalPlayerWindow {
  return window as ExternalPlayerWindow;
}

function boundedTimeout(value: number | undefined): number {
  if (!Number.isFinite(value)) return DEFAULT_INSPECTION_TIMEOUT_MS;
  return Math.min(MAX_INSPECTION_TIMEOUT_MS, Math.max(MIN_INSPECTION_TIMEOUT_MS, Math.trunc(value as number)));
}

function failure(
  code: WallboardVideoInspectionFailureCode,
  provider: WallboardVideoProvider | null,
): WallboardVideoInspectionFailure {
  return { status: 'failed', code, provider, message: failureMessage(code) };
}

function failureMessage(code: WallboardVideoInspectionFailureCode): string {
  switch (code) {
    case 'invalid_url':
      return 'Gebruik een geldige openbare YouTube-, YouTube Shorts- of Vimeo-link.';
    case 'not_embeddable':
      return 'De eigenaar van deze video staat afspelen buiten YouTube of Vimeo niet toe.';
    case 'unavailable':
      return 'De video is verwijderd, privé, met een wachtwoord beveiligd of niet beschikbaar.';
    case 'timeout':
      return 'De videodienst reageerde niet op tijd. Controleer de verbinding en probeer opnieuw.';
    case 'cancelled':
      return 'De videcontrole is geannuleerd.';
    case 'provider_error':
      return 'De videodienst kon de insluitbaarheid en speelduur niet bevestigen.';
    case 'too_long':
      return 'Deze video duurt langer dan 59 minuten en 55 seconden en past niet veilig in een wallboardplaylist.';
  }
}

function errorName(error: unknown): string {
  if (error instanceof Error) return `${error.name} ${error.message}`;
  if (typeof error === 'object' && error !== null && 'name' in error && typeof error.name === 'string') {
    return error.name;
  }
  return '';
}

function abortableDelay(milliseconds: number, signal: AbortSignal): Promise<void> {
  if (signal.aborted) return Promise.reject(new ProviderInspectionError('cancelled'));

  return new Promise<void>((resolve, reject) => {
    const timeout = window.setTimeout(() => {
      signal.removeEventListener('abort', abort);
      resolve();
    }, milliseconds);
    const abort = () => {
      window.clearTimeout(timeout);
      reject(new ProviderInspectionError('cancelled'));
    };
    signal.addEventListener('abort', abort, { once: true });
  });
}

function abortable<T>(promise: Promise<T>, signal: AbortSignal): Promise<T> {
  if (signal.aborted) return Promise.reject(new ProviderInspectionError('cancelled'));

  return new Promise<T>((resolve, reject) => {
    const abort = () => reject(new ProviderInspectionError('cancelled'));
    signal.addEventListener('abort', abort, { once: true });
    void promise.then(
      (value) => {
        signal.removeEventListener('abort', abort);
        resolve(value);
      },
      (error: unknown) => {
        signal.removeEventListener('abort', abort);
        reject(error);
      },
    );
  });
}
