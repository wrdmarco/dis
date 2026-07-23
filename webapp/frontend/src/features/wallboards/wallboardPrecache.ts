import type { WallboardPage, WallboardState } from '../../types/api';
import { normalizeWallboardPlaylistDataMode } from './wallboardPlaylistDataMode';
import { normalizeWallboardPlaylistPurpose } from './wallboardPlaylistPurpose';
import { normalizeWallboardWeatherRadarKind } from './wallboardPresentation';

export const WALLBOARD_PRECACHE_PREFIX = 'dis-wallboard-static-v1-';
export const WALLBOARD_PRECACHE_CONCURRENCY = 4;
export const WALLBOARD_PRECACHE_REVISION_HEADER = 'X-DIS-Wallboard-Asset-Revision';

export type WallboardPrecacheAssetKind = 'image' | 'poster' | 'video';
export type WallboardPrecachePhase = 'preparing' | 'caching' | 'ready' | 'failed' | 'cancelled';

export interface WallboardPrecacheAsset {
  url: string;
  kind: WallboardPrecacheAssetKind;
  revision: string;
  pageIds: string[];
}

export interface WallboardExternalPreloadHint {
  pageId: string;
  origin: string;
  url: string;
  rel: 'preconnect';
}

export interface WallboardPrecacheManifest {
  wallboardKey: string;
  cacheNamespace: string;
  cacheName: string;
  contentVersion: string;
  /** Stable across immutable radar snapshot refreshes; used only for the rotation readiness gate. */
  blockingContentVersion: string;
  assets: WallboardPrecacheAsset[];
  externalPreloadHints: WallboardExternalPreloadHint[];
}

export interface WallboardPrecacheFailure {
  url: string | null;
  reason: 'cache_unavailable' | 'http_error' | 'invalid_content_type' | 'network_error' | 'cache_write_error';
  message: string;
}

export interface WallboardPrecacheProgress {
  phase: WallboardPrecachePhase;
  total: number;
  completed: number;
  completedUrls: string[];
  failed: number;
  currentUrl: string | null;
  failures: WallboardPrecacheFailure[];
}

export interface WallboardPrecacheResult extends WallboardPrecacheProgress {
  ready: boolean;
  cacheName: string;
  contentVersion: string;
}

export interface WallboardPrecacheOptions {
  baseUrl?: string;
  cacheStorage?: CacheStorage;
  fetcher?: typeof fetch;
  signal?: AbortSignal;
  concurrency?: number;
  onProgress?: (progress: WallboardPrecacheProgress) => void;
}

type ExtendedWallboardPageOptions = WallboardPage['options'] & {
  poster_url?: unknown;
};

const CACHE_NAME_SAFE_CHARACTERS = /[^a-zA-Z0-9_-]/g;
const CACHEABLE_IMAGE_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif']);
const EXTERNAL_VIDEO_HOSTS = new Set(['www.youtube.com', 'player.vimeo.com']);
const WALLBOARD_RADAR_ATLAS_PATH = /^\/api\/wallboard\/weather-radar\/(precipitation|lightning)\/\d{8}T\d{6}Z-[a-f0-9]{16}\.png$/;

/**
 * Builds one deterministic manifest for every configured wallboard page. The
 * version changes only when the runtime playlist, configuration or referenced
 * static content changes; volatile state timestamps deliberately do not
 * invalidate it. A fresh immutable radar atlas gets a new cache version, while
 * the blocking version stays stable so the valid playlist keeps rotating as
 * the replacement snapshot is staged atomically.
 */
export function wallboardPrecacheManifest(
  state: WallboardState,
  baseUrl = browserOrigin(),
): WallboardPrecacheManifest {
  const origin = normalizedOrigin(baseUrl);
  const assets = new Map<string, WallboardPrecacheAsset>();
  const externalHints = new Map<string, WallboardExternalPreloadHint>();
  const configuredPages = state.wallboard.configuration.pages;
  const runtimePlaylistId = typeof state.wallboard.runtime_playlist_id === 'string'
    ? state.wallboard.runtime_playlist_id.trim()
    : '';
  const runtimePlaylistVersion = Number.isSafeInteger(state.wallboard.runtime_playlist_version)
    ? Math.max(0, state.wallboard.runtime_playlist_version ?? 0)
    : 0;
  const contentParts: string[] = [
    `config:${state.wallboard.config_version}`,
    `data-mode:${normalizeWallboardPlaylistDataMode(state.wallboard.data_mode)}`,
    `runtime-playlist:${runtimePlaylistId}:${runtimePlaylistVersion}:${normalizeWallboardPlaylistPurpose(state.wallboard.runtime_playlist_purpose)}:${state.wallboard.active_incident_playlist === true ? 1 : 0}`,
  ];

  for (const page of configuredPages) {
    contentParts.push(`page:${page.id}:${page.type}`);

    if (page.type === 'news') {
      const items = state.news.pages[page.id]?.items ?? [];
      for (const item of items) {
        contentParts.push(`news:${page.id}:${item.id}:${item.image_url ?? ''}`);
        addAsset(assets, item.image_url, 'image', page.id, origin);
      }
      continue;
    }

    if (page.type === 'photo_carousel') {
      const media = state.media.photo_pages[page.id];
      contentParts.push(`media:${page.id}:${media?.media_playlist_version ?? 0}`);
      for (const item of media?.items ?? []) {
        const revision = mediaAssetRevision(item.id, item.media_asset_version, item.image_url);
        contentParts.push(`photo:${page.id}:${item.id}:${item.image_url}:${revision}`);
        addAsset(assets, item.image_url, 'image', page.id, origin, revision);
      }
      continue;
    }

    if (page.type === 'weather_radar') {
      const kind = normalizeWallboardWeatherRadarKind(page.options.radar_kind);
      const atlasUrl = wallboardRadarAtlasUrl(state.weather_radar?.[kind]?.atlas_url, origin, kind);
      contentParts.push(`weather-radar:${page.id}:${kind}`);
      if (atlasUrl !== null) {
        addResolvedAsset(assets, atlasUrl, 'image', page.id, `radar:${atlasUrl}`);
      }
      continue;
    }

    if (page.type === 'video') {
      const options = page.options as ExtendedWallboardPageOptions;
      const rawUrl = typeof options.url === 'string' ? options.url : null;
      const internalVideoUrl = sameOriginUrl(rawUrl, origin);
      if (internalVideoUrl !== null) {
        const revision = mediaAssetRevision(options.media_asset_id, options.media_asset_version, internalVideoUrl);
        addResolvedAsset(assets, internalVideoUrl, 'video', page.id, revision);
        contentParts.push(`video:${page.id}:${internalVideoUrl}:${revision}`);
      } else {
        const hint = externalVideoPreloadHint(rawUrl, page.id);
        if (hint !== null) externalHints.set(`${hint.pageId}:${hint.origin}`, hint);
        contentParts.push(`external-video:${page.id}:${hint?.url ?? ''}`);
      }

      const posterUrl = sameOriginUrl(options.poster_url, origin);
      if (posterUrl !== null) {
        addResolvedAsset(assets, posterUrl, 'poster', page.id);
        contentParts.push(`poster:${page.id}:${posterUrl}`);
      }
    }
  }

  const sortedAssets = [...assets.values()]
    .map((asset) => ({ ...asset, pageIds: [...asset.pageIds].sort() }))
    .sort((left, right) => left.url.localeCompare(right.url));
  const sortedHints = [...externalHints.values()]
    .sort((left, right) => `${left.pageId}:${left.origin}`.localeCompare(`${right.pageId}:${right.origin}`));
  const contentHash = stableHash([
    ...contentParts.sort(),
    ...sortedAssets.map((asset) => `${asset.url}:${asset.revision}`),
  ].join('\n'));
  const blockingContentHash = stableHash([
    ...contentParts,
    ...sortedAssets
      .filter((asset) => !asset.revision.startsWith('radar:'))
      .map((asset) => `${asset.url}:${asset.revision}`),
  ].join('\n'));
  const wallboardKey = normalizedWallboardCacheKey(state.wallboard.id);
  const cacheNamespace = wallboardPrecacheNamespace(wallboardKey);
  const contentVersion = `${state.wallboard.config_version}-${runtimePlaylistVersion}-${contentHash}`;
  const blockingContentVersion = `${state.wallboard.config_version}-${runtimePlaylistVersion}-${blockingContentHash}`;

  return {
    wallboardKey,
    cacheNamespace,
    cacheName: `${cacheNamespace}${contentVersion}`,
    contentVersion,
    blockingContentVersion,
    assets: sortedAssets,
    externalPreloadHints: sortedHints,
  };
}

/**
 * Downloads every required asset before returning ready=true. Partial caches
 * are retained after a failure so a retry can resume without downloading a
 * large MP4 again. Obsolete versions remain available as rollback until the
 * service worker has validated and activated this complete manifest.
 */
export async function precacheWallboard(
  manifest: WallboardPrecacheManifest,
  options: WallboardPrecacheOptions = {},
): Promise<WallboardPrecacheResult> {
  const total = manifest.assets.length;
  const failures: WallboardPrecacheFailure[] = [];
  const onProgress = options.onProgress;
  const signal = options.signal;
  let completed = 0;
  const completedUrls = new Set<string>();
  let failed = 0;
  let currentUrl: string | null = null;
  let phase: WallboardPrecachePhase = 'preparing';

  const snapshot = (): WallboardPrecacheProgress => ({
    phase,
    total,
    completed,
    completedUrls: [...completedUrls],
    failed,
    currentUrl,
    failures: [...failures],
  });
  const report = () => onProgress?.(snapshot());
  const result = (ready: boolean): WallboardPrecacheResult => ({
    ...snapshot(),
    ready,
    cacheName: manifest.cacheName,
    contentVersion: manifest.contentVersion,
  });

  report();
  if (signalIsAborted(signal)) {
    phase = 'cancelled';
    report();
    return result(false);
  }

  const cacheStorage = options.cacheStorage ?? globalThis.caches;
  if (cacheStorage === undefined) {
    failures.push({
      url: null,
      reason: 'cache_unavailable',
      message: 'CacheStorage is niet beschikbaar in deze browser.',
    });
    failed = total;
    phase = 'failed';
    report();
    return result(false);
  }

  let cache: Cache;
  try {
    cache = await cacheStorage.open(manifest.cacheName);
  } catch {
    failures.push({
      url: null,
      reason: 'cache_unavailable',
      message: 'De lokale wallboardcache kon niet worden geopend.',
    });
    failed = total;
    phase = signalIsAborted(signal) ? 'cancelled' : 'failed';
    report();
    return result(false);
  }

  phase = 'caching';
  report();
  const fetcher = options.fetcher ?? globalThis.fetch;
  const concurrency = clampConcurrency(options.concurrency ?? WALLBOARD_PRECACHE_CONCURRENCY, total);
  const reusableCaches = await reusableWallboardCaches(
    cacheStorage,
    manifest.cacheName,
    manifest.cacheNamespace,
  );
  let nextIndex = 0;

  const cacheAsset = async (asset: WallboardPrecacheAsset): Promise<void> => {
    if (signalIsAborted(signal)) return;
    currentUrl = asset.url;
    report();

    const request = new Request(asset.url, {
      method: 'GET',
      // Wallboard media is protected by the HttpOnly wallboard-session cookie.
      // Keep the request same-origin only and explicitly include that cookie
      // through both the window fetch and service-worker network fallback.
      credentials: 'include',
      mode: 'same-origin',
      redirect: 'error',
      cache: 'no-cache',
      headers: {
        // The backend encrypts the HttpOnly wallboard credential. Some TV
        // browsers omit Referer when a service-worker-controlled page builds a
        // media Request, so explicitly mark this same-origin fetch as the
        // first-party XHR path that decrypts that cookie before wallboard.auth.
        'X-Requested-With': 'XMLHttpRequest',
      },
      signal,
    });

    try {
      const existing = await cache.match(request);
      if (existing !== undefined) {
        if (cachedAssetMatches(existing, asset)) {
          completed += 1;
          completedUrls.add(asset.url);
          report();
          return;
        }
        await cache.delete(request);
      }

      const copied = await copyReusableAsset(request, asset, cache, reusableCaches);
      if (copied) {
        completed += 1;
        completedUrls.add(asset.url);
        report();
        return;
      }

      const response = await fetcher(request);
      if (!response.ok || response.redirected) {
        failed += 1;
        failures.push({
          url: asset.url,
          reason: 'http_error',
          message: `Bestand reageerde met HTTP ${response.status}.`,
        });
        report();
        return;
      }

      if (!contentTypeMatches(asset.kind, response.headers.get('content-type'))) {
        failed += 1;
        failures.push({
          url: asset.url,
          reason: 'invalid_content_type',
          message: `Bestand heeft geen toegestaan ${asset.kind === 'video' ? 'MP4-video' : 'afbeeldingsformaat'}.`,
        });
        report();
        return;
      }

      try {
        await cache.put(request, responseWithRevision(response, asset.revision));
        completed += 1;
        completedUrls.add(asset.url);
      } catch {
        failed += 1;
        failures.push({
          url: asset.url,
          reason: 'cache_write_error',
          message: 'Bestand kon niet naar de lokale wallboardcache worden geschreven.',
        });
      }
      report();
    } catch (error) {
      if (signalIsAborted(signal) || isAbortError(error)) return;
      failed += 1;
      failures.push({
        url: asset.url,
        reason: 'network_error',
        message: 'Bestand kon niet volledig worden gedownload.',
      });
      report();
    }
  };

  const worker = async () => {
    while (!signalIsAborted(signal)) {
      const index = nextIndex;
      nextIndex += 1;
      const asset = manifest.assets[index];
      if (asset === undefined) return;
      await cacheAsset(asset);
    }
  };

  await Promise.all(Array.from({ length: concurrency }, () => worker()));
  currentUrl = null;

  if (signalIsAborted(signal)) {
    phase = 'cancelled';
    report();
    return result(false);
  }

  if (failed > 0 || completed !== total) {
    phase = 'failed';
    report();
    return result(false);
  }

  try {
    await removeUnusedEntries(cache, new Set(manifest.assets.map((asset) => asset.url)));
  } catch {
    failures.push({
      url: null,
      reason: 'cache_write_error',
      message: 'De lokale wallboardcache kon niet veilig worden afgerond.',
    });
    failed += 1;
    phase = 'failed';
    report();
    return result(false);
  }

  phase = 'ready';
  report();
  return result(true);
}

async function reusableWallboardCaches(
  cacheStorage: CacheStorage,
  activeCacheName: string,
  cacheNamespace: string,
): Promise<Cache[]> {
  try {
    const names = await cacheStorage.keys();
    return await Promise.all(names
      .filter((name) => name.startsWith(cacheNamespace) && name !== activeCacheName)
      .map((name) => cacheStorage.open(name)));
  } catch {
    // Reuse is an optimisation. A valid network response remains authoritative
    // when an older browser cache cannot be enumerated or opened.
    return [];
  }
}

export function normalizedWallboardCacheKey(value: unknown): string {
  if (typeof value !== 'string') return 'display';
  return value.replace(CACHE_NAME_SAFE_CHARACTERS, '').slice(0, 48) || 'display';
}

export function wallboardPrecacheNamespace(wallboardKey: string): string {
  return `${WALLBOARD_PRECACHE_PREFIX}${normalizedWallboardCacheKey(wallboardKey)}-`;
}

async function copyReusableAsset(
  request: Request,
  asset: WallboardPrecacheAsset,
  destination: Cache,
  sources: Cache[],
): Promise<boolean> {
  for (const source of sources) {
    let candidate: Response | undefined;
    try {
      candidate = await source.match(request);
    } catch {
      continue;
    }
    if (
      candidate === undefined
      || !cachedAssetMatches(candidate, asset)
      || candidate.redirected
    ) continue;

    try {
      // Cache.put resolves only after the cloned body is completely stored, so
      // the new version never exposes a partially copied large media object.
      await destination.put(request, candidate.clone());
      return true;
    } catch {
      continue;
    }
  }
  return false;
}

function addAsset(
  assets: Map<string, WallboardPrecacheAsset>,
  value: unknown,
  kind: WallboardPrecacheAssetKind,
  pageId: string,
  origin: string,
  revision?: string,
) {
  const url = sameOriginUrl(value, origin);
  if (url !== null) addResolvedAsset(assets, url, kind, pageId, revision);
}

function addResolvedAsset(
  assets: Map<string, WallboardPrecacheAsset>,
  url: string,
  kind: WallboardPrecacheAssetKind,
  pageId: string,
  revision = `url:${url}`,
) {
  const existing = assets.get(url);
  if (existing === undefined) {
    assets.set(url, { url, kind, revision, pageIds: [pageId] });
    return;
  }
  existing.revision = mergedAssetRevision(existing.revision, revision, url);
  if (!existing.pageIds.includes(pageId)) existing.pageIds.push(pageId);
}

function mediaAssetRevision(id: unknown, version: unknown, fallbackUrl: string): string {
  if (typeof id === 'string'
    && /^[0-9A-HJKMNP-TV-Z]{26}$/i.test(id)
    && Number.isSafeInteger(version)
    && Number(version) > 0) {
    return `media:${id.toLowerCase()}:v${String(version)}`;
  }
  return `url:${fallbackUrl}`;
}

function mergedAssetRevision(current: string, candidate: string, url: string): string {
  if (current === candidate) return current;
  const fallback = `url:${url}`;
  if (current === fallback) return candidate;
  if (candidate === fallback) return current;
  return `conflict:${stableHash([current, candidate].sort().join('|'))}`;
}

function externalVideoPreloadHint(value: unknown, pageId: string): WallboardExternalPreloadHint | null {
  if (typeof value !== 'string') return null;
  try {
    const url = new URL(value);
    if (
      url.protocol !== 'https:'
      || url.username !== ''
      || url.password !== ''
      || (url.port !== '' && url.port !== '443')
      || !EXTERNAL_VIDEO_HOSTS.has(url.hostname.toLowerCase())
    ) return null;
    url.hash = '';
    return { pageId, origin: url.origin, url: url.toString(), rel: 'preconnect' };
  } catch {
    return null;
  }
}

function sameOriginUrl(value: unknown, origin: string): string | null {
  if (typeof value !== 'string' || value.trim() === '') return null;
  try {
    const url = new URL(value, `${origin}/`);
    if (
      url.origin !== origin
      || !['http:', 'https:'].includes(url.protocol)
      || url.username !== ''
      || url.password !== ''
      || url.search !== ''
    ) return null;
    url.hash = '';
    return wallboardAssetPathIsCacheable(url.pathname) ? url.toString() : null;
  } catch {
    return null;
  }
}

/** Keep state, control, authentication and HTML routes outside CacheStorage. */
export function wallboardAssetPathIsCacheable(pathname: string): boolean {
  return /^\/api\/wallboard\/media\/[0-9A-HJKMNP-TV-Z]{26}(?:\/(?:poster|thumbnail))?$/i.test(pathname)
    || /^\/api\/wallboard\/news-images\/[a-f0-9]{64}$/.test(pathname)
    || WALLBOARD_RADAR_ATLAS_PATH.test(pathname);
}

export function wallboardCacheableAssetUrl(
  value: unknown,
  baseUrl = browserOrigin(),
): string | null {
  return sameOriginUrl(value, normalizedOrigin(baseUrl));
}

function wallboardRadarAtlasUrl(
  value: unknown,
  origin: string,
  kind: 'precipitation' | 'lightning',
): string | null {
  const url = sameOriginUrl(value, origin);
  if (url === null) return null;
  const match = WALLBOARD_RADAR_ATLAS_PATH.exec(new URL(url).pathname);
  return match?.[1] === kind ? url : null;
}

function normalizedOrigin(baseUrl: string): string {
  const url = new URL(baseUrl);
  if (!['http:', 'https:'].includes(url.protocol) || url.username !== '' || url.password !== '') {
    throw new TypeError('Wallboard precache vereist een geldige HTTP(S)-origin.');
  }
  return url.origin;
}

function browserOrigin(): string {
  if (typeof window === 'undefined') {
    throw new TypeError('Geef baseUrl expliciet door buiten een browsercontext.');
  }
  return window.location.origin;
}

function stableHash(value: string): string {
  let hash = 0x811c9dc5;
  for (let index = 0; index < value.length; index += 1) {
    hash ^= value.charCodeAt(index);
    hash = Math.imul(hash, 0x01000193);
  }
  return (hash >>> 0).toString(16).padStart(8, '0');
}

function clampConcurrency(value: number, total: number): number {
  if (total === 0) return 0;
  if (!Number.isFinite(value)) return 1;
  return Math.max(1, Math.min(8, Math.trunc(value)));
}

function contentTypeMatches(kind: WallboardPrecacheAssetKind, value: string | null): boolean {
  const mimeType = value?.split(';', 1)[0]?.trim().toLowerCase() ?? '';
  return kind === 'video' ? mimeType === 'video/mp4' : CACHEABLE_IMAGE_TYPES.has(mimeType);
}

function cachedAssetMatches(response: Response, asset: WallboardPrecacheAsset): boolean {
  return response.status === 200
    && !response.redirected
    && contentTypeMatches(asset.kind, response.headers.get('content-type'))
    && response.headers.get(WALLBOARD_PRECACHE_REVISION_HEADER) === asset.revision;
}

function responseWithRevision(response: Response, revision: string): Response {
  const clone = response.clone();
  const headers = new Headers(clone.headers);
  headers.set(WALLBOARD_PRECACHE_REVISION_HEADER, revision);
  return new Response(clone.body, {
    status: clone.status,
    statusText: clone.statusText,
    headers,
  });
}

function isAbortError(error: unknown): boolean {
  return error instanceof DOMException && error.name === 'AbortError';
}

function signalIsAborted(signal: AbortSignal | undefined): boolean {
  return signal?.aborted ?? false;
}

async function removeUnusedEntries(cache: Cache, manifestUrls: Set<string>): Promise<void> {
  const requests = await cache.keys();
  await Promise.all(requests
    .filter((request) => !manifestUrls.has(request.url))
    .map((request) => cache.delete(request)));
}
