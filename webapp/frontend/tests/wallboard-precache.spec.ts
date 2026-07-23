import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardPage, WallboardState } from '../src/types/api';
import {
  precacheWallboard,
  WALLBOARD_PRECACHE_PREFIX,
  WALLBOARD_PRECACHE_REVISION_HEADER,
  wallboardAssetPathIsCacheable,
  wallboardPrecacheManifest,
} from '../src/features/wallboards/wallboardPrecache';
import {
  activateWallboardPrecacheWorker,
  createWallboardPrecacheClientSessionToken,
  disableWallboardPrecache,
} from '../src/features/wallboards/wallboardPrecacheWorker';

const IMAGE_ONE = '01KXW0QZTP0000000000000000';
const IMAGE_TWO = '01KXW0QZTP0000000000000001';
const VIDEO = '01KXW0QZTP0000000000000002';
const POSTER = '01KXW0QZTP0000000000000003';
const NEWS_HASH = 'a'.repeat(64);
const PRECIPITATION_ATLAS = '/api/wallboard/weather-radar/precipitation/20260723T120000Z-0123456789abcdef.png';
const NEXT_PRECIPITATION_ATLAS = '/api/wallboard/weather-radar/precipitation/20260723T121500Z-fedcba9876543210.png';
const LIGHTNING_ATLAS = '/api/wallboard/weather-radar/lightning/20260723T120000Z-abcdef0123456789.png';

test('builds one stable manifest across all configured cacheable wallboard pages', () => {
  const state = wallboardState();
  const manifest = wallboardPrecacheManifest(state, 'https://dis.example.test/wallboard');

  expect(manifest.wallboardKey).toBe(state.wallboard.id);
  expect(manifest.cacheNamespace).toBe(`${WALLBOARD_PRECACHE_PREFIX}${state.wallboard.id}-`);
  expect(manifest.cacheName).toMatch(new RegExp(`^${WALLBOARD_PRECACHE_PREFIX}`));
  expect(manifest.assets).toEqual([
    {
      url: `https://dis.example.test/api/wallboard/media/${IMAGE_ONE}`,
      kind: 'image',
      revision: `media:${IMAGE_ONE.toLowerCase()}:v1`,
      pageIds: ['news', 'photos'],
    },
    {
      url: `https://dis.example.test/api/wallboard/media/${IMAGE_TWO}`,
      kind: 'image',
      revision: `media:${IMAGE_TWO.toLowerCase()}:v1`,
      pageIds: ['photos'],
    },
    {
      url: `https://dis.example.test/api/wallboard/media/${VIDEO}`,
      kind: 'video',
      revision: `media:${VIDEO.toLowerCase()}:v2`,
      pageIds: ['internal-video'],
    },
    {
      url: `https://dis.example.test/api/wallboard/media/${POSTER}/poster`,
      kind: 'poster',
      revision: `url:https://dis.example.test/api/wallboard/media/${POSTER}/poster`,
      pageIds: ['internal-video'],
    },
    {
      url: `https://dis.example.test/api/wallboard/news-images/${NEWS_HASH}`,
      kind: 'image',
      revision: `url:https://dis.example.test/api/wallboard/news-images/${NEWS_HASH}`,
      pageIds: ['news'],
    },
  ]);
  expect(manifest.externalPreloadHints).toEqual([{
    pageId: 'youtube',
    origin: 'https://www.youtube.com',
    url: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    rel: 'preconnect',
  }]);
  expect(manifest.assets.some((asset) => asset.url.includes('/wallboard/state'))).toBe(false);
  expect(manifest.assets.some((asset) => asset.url.includes(NEWS_HASH))).toBe(true);

  const regenerated = wallboardPrecacheManifest({
    ...state,
    generated_at: '2026-07-19T12:20:00Z',
    news: { ...state.news, generated_at: '2026-07-19T12:20:00Z' },
  }, 'https://dis.example.test');
  expect(regenerated.cacheName).toBe(manifest.cacheName);

  const changed = wallboardState();
  changed.media.photo_pages.photos.media_playlist_version = 9;
  expect(wallboardPrecacheManifest(changed, 'https://dis.example.test').cacheName).not.toBe(manifest.cacheName);

  const runtimePlaylistChanged = wallboardState();
  runtimePlaylistChanged.wallboard.runtime_playlist_id = '01KXW0QZTP0000000000000008';
  expect(wallboardPrecacheManifest(runtimePlaylistChanged, 'https://dis.example.test').contentVersion)
    .not.toBe(manifest.contentVersion);

  const runtimeVersionChanged = wallboardState();
  runtimeVersionChanged.wallboard.runtime_playlist_version = 8;
  expect(wallboardPrecacheManifest(runtimeVersionChanged, 'https://dis.example.test').contentVersion)
    .not.toBe(manifest.contentVersion);

  const runtimeActivationChanged = wallboardState();
  runtimeActivationChanged.wallboard.active_incident_playlist = true;
  expect(wallboardPrecacheManifest(runtimeActivationChanged, 'https://dis.example.test').contentVersion)
    .not.toBe(manifest.contentVersion);

  const runtimePurposeChanged = wallboardState();
  runtimePurposeChanged.wallboard.runtime_playlist_purpose = 'alarm';
  expect(wallboardPrecacheManifest(runtimePurposeChanged, 'https://dis.example.test').contentVersion)
    .not.toBe(manifest.contentVersion);

  const demoMode = wallboardState();
  demoMode.wallboard.data_mode = 'demo';
  demoMode.news.pages.news.items = [];
  const demoManifest = wallboardPrecacheManifest(demoMode, 'https://dis.example.test');
  expect(demoManifest.contentVersion).not.toBe(manifest.contentVersion);
  expect(demoManifest.assets.some((asset) => asset.url.includes(NEWS_HASH))).toBe(false);

  const legacyWithoutMode = wallboardState();
  delete legacyWithoutMode.wallboard.data_mode;
  expect(wallboardPrecacheManifest(legacyWithoutMode, 'https://dis.example.test').contentVersion)
    .toBe(manifest.contentVersion);
});

test('allows only immutable wallboard media paths and never state feeds', () => {
  expect(wallboardAssetPathIsCacheable(`/api/wallboard/media/${VIDEO}`)).toBe(true);
  expect(wallboardAssetPathIsCacheable(`/api/wallboard/media/${POSTER}/poster`)).toBe(true);
  expect(wallboardAssetPathIsCacheable(`/api/wallboard/news-images/${NEWS_HASH}`)).toBe(true);
  expect(wallboardAssetPathIsCacheable(PRECIPITATION_ATLAS)).toBe(true);
  expect(wallboardAssetPathIsCacheable(LIGHTNING_ATLAS)).toBe(true);
  expect(wallboardAssetPathIsCacheable(
    '/api/operational-weather/radar/lightning/20260723T120000Z-abcdef0123456789.png',
  )).toBe(false);
  expect(wallboardAssetPathIsCacheable(
    '/api/wallboard/weather-radar/precipitation/latest.png',
  )).toBe(false);
  expect(wallboardAssetPathIsCacheable('/api/wallboard/state')).toBe(false);
  expect(wallboardAssetPathIsCacheable('/api/wallboard/control')).toBe(false);
  expect(wallboardAssetPathIsCacheable('/api/wallboard/live')).toBe(false);
  expect(wallboardAssetPathIsCacheable('/api/auth/session')).toBe(false);
});

test('precaches only radar layers selected by configured pages and refreshes snapshots incrementally', async () => {
  const initial = wallboardState();
  initial.wallboard.configuration.pages.push(
    page('rain-radar', 'weather_radar', { radar_kind: 'precipitation' }),
    page('rain-radar-copy', 'weather_radar', { radar_kind: 'precipitation' }),
  );
  initial.weather_radar = {
    precipitation: radarLayer('precipitation', PRECIPITATION_ATLAS),
    lightning: radarLayer('lightning', LIGHTNING_ATLAS),
  };

  const initialManifest = wallboardPrecacheManifest(initial, 'https://dis.example.test');
  const precipitationAsset = initialManifest.assets.find((asset) => asset.url.endsWith(PRECIPITATION_ATLAS));
  expect(precipitationAsset).toMatchObject({
    kind: 'image',
    pageIds: ['rain-radar', 'rain-radar-copy'],
  });
  expect(initialManifest.assets.some((asset) => asset.url.endsWith(LIGHTNING_ATLAS))).toBe(false);

  const next = structuredClone(initial);
  next.weather_radar = {
    ...next.weather_radar,
    precipitation: radarLayer('precipitation', NEXT_PRECIPITATION_ATLAS),
  };
  const nextManifest = wallboardPrecacheManifest(next, 'https://dis.example.test');
  expect(nextManifest.contentVersion).not.toBe(initialManifest.contentVersion);
  expect(nextManifest.blockingContentVersion).toBe(initialManifest.blockingContentVersion);

  const storage = new MemoryCacheStorage();
  await precacheWallboard(initialManifest, {
    cacheStorage: storage.asCacheStorage(),
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      return request.url.endsWith(VIDEO) ? videoResponse() : imageResponse();
    }) as typeof fetch,
  });
  const requested: string[] = [];
  const result = await precacheWallboard(nextManifest, {
    cacheStorage: storage.asCacheStorage(),
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      requested.push(request.url);
      return imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(true);
  expect(requested).toEqual([`https://dis.example.test${NEXT_PRECIPITATION_ATLAS}`]);
});

test('downloads every asset before ready but leaves obsolete cleanup to validated worker activation', async () => {
  const storage = new MemoryCacheStorage();
  const manifest = wallboardPrecacheManifest(wallboardState(), 'https://dis.example.test');
  const oldCacheName = `${manifest.cacheNamespace}old-version`;
  const otherWallboardCache = `${WALLBOARD_PRECACHE_PREFIX}other-display-old-version`;
  await storage.open(oldCacheName);
  await storage.open(otherWallboardCache);
  const current = await storage.open(manifest.cacheName);
  await current.put(
    new Request(`https://dis.example.test/api/wallboard/media/${'0'.repeat(26)}`),
    imageResponse(),
  );
  const requested: Request[] = [];
  const progress: string[] = [];

  const result = await precacheWallboard(manifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 2,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      requested.push(request);
      return request.url.endsWith(VIDEO) ? videoResponse() : imageResponse();
    }) as typeof fetch,
    onProgress: (update) => progress.push(`${update.phase}:${update.completed}:${update.failed}`),
  });

  expect(result).toMatchObject({
    ready: true,
    phase: 'ready',
    total: 5,
    completed: 5,
    completedUrls: manifest.assets.map((asset) => asset.url),
    failed: 0,
    currentUrl: null,
  });
  expect(requested).toHaveLength(5);
  expect(requested.every((request) => request.credentials === 'include')).toBe(true);
  expect(requested.every((request) => request.mode === 'same-origin')).toBe(true);
  expect(requested.every((request) => request.headers.get('X-Requested-With') === 'XMLHttpRequest')).toBe(true);
  expect(requested.every((request) => request.redirect === 'error')).toBe(true);
  expect((await storage.keys()).sort()).toEqual([
    manifest.cacheName,
    oldCacheName,
    otherWallboardCache,
  ].sort());
  expect((await current.keys()).map((request) => request.url).sort())
    .toEqual(manifest.assets.map((asset) => asset.url).sort());
  expect(progress.at(-1)).toBe('ready:5:0');
});

test('reports a failed asset explicitly and keeps resumable and previous caches', async () => {
  const storage = new MemoryCacheStorage();
  const manifest = wallboardPrecacheManifest(wallboardState(), 'https://dis.example.test');
  const oldCacheName = `${WALLBOARD_PRECACHE_PREFIX}previous-working`;
  await storage.open(oldCacheName);

  const result = await precacheWallboard(manifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 1,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.url.includes(IMAGE_TWO)) return new Response('not found', { status: 404 });
      return request.url.endsWith(VIDEO) ? videoResponse() : imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(false);
  expect(result.phase).toBe('failed');
  expect(result.completed).toBe(4);
  expect(result.failed).toBe(1);
  expect(result.failures).toEqual([{
    url: `https://dis.example.test/api/wallboard/media/${IMAGE_TWO}`,
    reason: 'http_error',
    message: 'Bestand reageerde met HTTP 404.',
  }]);
  expect((await storage.keys()).sort()).toEqual([manifest.cacheName, oldCacheName].sort());
});

test('copies an unchanged large MP4 atomically from the previous valid cache version', async () => {
  const storage = new MemoryCacheStorage();
  const previousState = wallboardState();
  const previousManifest = wallboardPrecacheManifest(previousState, 'https://dis.example.test');
  const previousCache = await storage.open(previousManifest.cacheName);
  const videoUrl = `https://dis.example.test/api/wallboard/media/${VIDEO}`;
  const videoAsset = previousManifest.assets.find((asset) => asset.url === videoUrl);
  expect(videoAsset).toBeDefined();
  await previousCache.put(new Request(videoUrl), revisionResponse(new Response('cached-100mb-video', {
    status: 200,
    headers: { 'content-type': 'video/mp4' },
  }), videoAsset!.revision));

  const nextState = wallboardState();
  nextState.wallboard.config_version += 1;
  const nextManifest = wallboardPrecacheManifest(nextState, 'https://dis.example.test');
  const fetchedUrls: string[] = [];
  const result = await precacheWallboard(nextManifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 1,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      fetchedUrls.push(request.url);
      if (request.url === videoUrl) throw new Error('De ongewijzigde MP4 mag niet opnieuw worden gedownload.');
      return imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(true);
  expect(result.completedUrls).toContain(videoUrl);
  expect(fetchedUrls).not.toContain(videoUrl);
  expect((await storage.keys()).sort()).toEqual([
    previousManifest.cacheName,
    nextManifest.cacheName,
  ].sort());
  const nextCache = await storage.open(nextManifest.cacheName);
  expect(await (await nextCache.match(new Request(videoUrl)))?.text()).toBe('cached-100mb-video');
});

test('downloads fresh bytes when a media asset revision changes at the same URL', async () => {
  const storage = new MemoryCacheStorage();
  const previousState = wallboardState();
  const previousManifest = wallboardPrecacheManifest(previousState, 'https://dis.example.test');
  const imageUrl = `https://dis.example.test/api/wallboard/media/${IMAGE_ONE}`;
  const previousAsset = previousManifest.assets.find((asset) => asset.url === imageUrl);
  expect(previousAsset).toBeDefined();
  const previousCache = await storage.open(previousManifest.cacheName);
  await previousCache.put(
    new Request(imageUrl),
    revisionResponse(new Response('old-image', {
      status: 200,
      headers: { 'content-type': 'image/webp' },
    }), previousAsset!.revision),
  );

  const nextState = wallboardState();
  nextState.media.photo_pages.photos.media_playlist_version += 1;
  nextState.media.photo_pages.photos.items[0].media_asset_version += 1;
  const nextManifest = wallboardPrecacheManifest(nextState, 'https://dis.example.test');
  let imageFetches = 0;
  const result = await precacheWallboard(nextManifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 1,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.url === imageUrl) {
        imageFetches += 1;
        return new Response('fresh-image', {
          status: 200,
          headers: { 'content-type': 'image/webp' },
        });
      }
      return request.url.endsWith(VIDEO) ? videoResponse() : imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(true);
  expect(imageFetches).toBe(1);
  const nextCache = await storage.open(nextManifest.cacheName);
  const cached = await nextCache.match(new Request(imageUrl));
  expect(await cached?.text()).toBe('fresh-image');
  expect(cached?.headers.get(WALLBOARD_PRECACHE_REVISION_HEADER))
    .toBe(nextManifest.assets.find((asset) => asset.url === imageUrl)?.revision);
});

test('rejects an old cache entry with the wrong content type and downloads the valid asset', async () => {
  const storage = new MemoryCacheStorage();
  const state = wallboardState();
  const previousManifest = wallboardPrecacheManifest(state, 'https://dis.example.test');
  const videoUrl = `https://dis.example.test/api/wallboard/media/${VIDEO}`;
  const previousCache = await storage.open(previousManifest.cacheName);
  await previousCache.put(new Request(videoUrl), imageResponse());

  state.wallboard.config_version += 1;
  const nextManifest = wallboardPrecacheManifest(state, 'https://dis.example.test');
  let videoFetches = 0;
  const result = await precacheWallboard(nextManifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 1,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.url === videoUrl) {
        videoFetches += 1;
        return videoResponse();
      }
      return imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(true);
  expect(videoFetches).toBe(1);
  const nextCache = await storage.open(nextManifest.cacheName);
  expect((await nextCache.match(new Request(videoUrl)))?.headers.get('content-type')).toBe('video/mp4');
});

test('removes a poisoned image response and never marks the page cache ready', async () => {
  const storage = new MemoryCacheStorage();
  const state = wallboardState();
  const manifest = wallboardPrecacheManifest(state, 'https://dis.example.test');
  const imageUrl = `https://dis.example.test/api/wallboard/media/${IMAGE_ONE}`;
  const cache = await storage.open(manifest.cacheName);
  await cache.put(new Request(imageUrl), new Response('<html>login</html>', {
    status: 200,
    headers: { 'content-type': 'text/html; charset=utf-8' },
  }));

  const result = await precacheWallboard(manifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 1,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.url === imageUrl) {
        return new Response('{"message":"not an image"}', {
          status: 200,
          headers: { 'content-type': 'application/json' },
        });
      }
      return request.url.endsWith(VIDEO) ? videoResponse() : imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(false);
  expect(result.failures).toContainEqual({
    url: imageUrl,
    reason: 'invalid_content_type',
    message: 'Bestand heeft geen toegestaan afbeeldingsformaat.',
  });
  expect(await cache.match(new Request(imageUrl))).toBeUndefined();
});

test('cancels before opening the cache and never reports ready', async () => {
  const storage = new MemoryCacheStorage();
  const controller = new AbortController();
  controller.abort();
  const manifest = wallboardPrecacheManifest(wallboardState(), 'https://dis.example.test');

  const result = await precacheWallboard(manifest, {
    cacheStorage: storage.asCacheStorage(),
    signal: controller.signal,
  });

  expect(result).toMatchObject({
    ready: false,
    phase: 'cancelled',
    completed: 0,
    completedUrls: [],
    failed: 0,
  });
  expect(await storage.keys()).toEqual([]);
});

test('does not perform an unsafe origin-wide purge when worker lookup fails', async () => {
  const unavailableServiceWorkers = {
    getRegistration: async () => { throw new Error('service worker unavailable'); },
  } as unknown as ServiceWorkerContainer;

  await expect(disableWallboardPrecache({
    serviceWorkers: unavailableServiceWorkers,
    wallboardKey: '01KXW0QZTP0000000000000009',
    clientSessionToken: createWallboardPrecacheClientSessionToken(),
    lifecycleTimeoutMs: 1_000,
  })).rejects.toThrow('service worker unavailable');
});

test('targets disable and activation messages to one wallboard client namespace', async () => {
  const messages: Array<Record<string, unknown>> = [];
  const active = acknowledgementWorker(messages);
  const serviceWorkers = {
    controller: null,
    getRegistration: async () => ({ active }),
  } as unknown as ServiceWorkerContainer;
  const manifest = wallboardPrecacheManifest(wallboardState(), 'https://dis.example.test');
  const result = {
    ready: true,
    phase: 'ready' as const,
    total: manifest.assets.length,
    completed: manifest.assets.length,
    completedUrls: manifest.assets.map((asset) => asset.url),
    failed: 0,
    currentUrl: null,
    failures: [],
    cacheName: manifest.cacheName,
    contentVersion: manifest.contentVersion,
  };
  const clientSessionToken = createWallboardPrecacheClientSessionToken();

  await activateWallboardPrecacheWorker(
    { active } as ServiceWorkerRegistration,
    manifest,
    result,
    clientSessionToken,
    1,
    1_000,
  );
  await disableWallboardPrecache({
    serviceWorkers,
    wallboardKey: manifest.wallboardKey,
    clientSessionToken,
    commandGeneration: 2,
    acknowledgementTimeoutMs: 1_000,
  });

  expect(messages).toEqual([
    {
      type: 'DIS_WALLBOARD_PRECACHE_ACTIVATE',
      wallboardKey: manifest.wallboardKey,
      clientSessionToken,
      commandGeneration: 1,
      cacheName: manifest.cacheName,
      assets: manifest.assets.map(({ url, kind }) => ({ url, kind })),
    },
    {
      type: 'DIS_WALLBOARD_PRECACHE_DISABLE',
      wallboardKey: manifest.wallboardKey,
      clientSessionToken,
      commandGeneration: 2,
    },
  ]);
});

test('the service worker serves only the activated client manifest and supports an immediate purge contract', () => {
  const worker = readFileSync(new URL('../public/wallboard-media-sw.js', import.meta.url), 'utf8');
  const registration = readFileSync(
    new URL('../src/features/wallboards/wallboardPrecacheWorker.ts', import.meta.url),
    'utf8',
  );

  expect(worker).toContain('const clientCacheStates = new Map()');
  expect(worker).toContain('const CLIENT_BINDING_CACHE');
  expect(worker).toContain('await readClientBinding(clientId)');
  expect(worker).toContain('enqueueClientOperation(clientId');
  expect(worker).toContain('commandIsLatest(clientId');
  expect(worker).toContain('state?.assets.get(request.url)');
  expect(worker).toContain("event.request.method !== 'GET'");
  expect(worker).toContain('cacheNames.includes(command.cacheName)');
  expect(worker).toContain("throw new Error('cache_incomplete')");
  expect(worker).toContain('validCachedResponse(response, asset.kind)');
  expect(worker).toContain("mimeType === 'video/mp4'");
  expect(worker).toContain("['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif']");
  expect(worker).toContain('await cache.delete(request');
  expect(worker).toContain('disabled: true');
  expect(worker).toContain('clientCacheStates.delete(clientId)');
  expect(worker).toContain('!protectedCacheNames.has(cacheName)');
  expect(worker).toContain('/api\\/wallboard\\/media');
  expect(worker).toContain('/api\\/wallboard\\/news-images');
  expect(worker).toContain('/api\\/wallboard\\/weather-radar');
  expect(worker).not.toContain('operational-weather');
  expect(worker).not.toContain('/api/wallboard/state');
  expect(worker).not.toContain('/api/wallboard/control');
  expect(registration).toContain('if (!result.ready');
  expect(registration).toContain("WALLBOARD_PRECACHE_WORKER_SCOPE = '/wallboard'");
  expect(registration).toContain("type: 'DIS_WALLBOARD_PRECACHE_DISABLE'");
  expect(registration).toContain('createWallboardPrecacheClientSessionToken');
  expect(registration).toContain('withTimeout(');
  expect(registration).not.toContain('purgeWallboardPrecacheCaches');
});

test('never reuses cache entries owned by another wallboard', async () => {
  const storage = new MemoryCacheStorage();
  const state = wallboardState();
  const manifest = wallboardPrecacheManifest(state, 'https://dis.example.test');
  const otherCache = await storage.open(`${WALLBOARD_PRECACHE_PREFIX}other-display-7-deadbeef`);
  const videoUrl = `https://dis.example.test/api/wallboard/media/${VIDEO}`;
  await otherCache.put(new Request(videoUrl), videoResponse());
  let videoFetches = 0;

  const result = await precacheWallboard(manifest, {
    cacheStorage: storage.asCacheStorage(),
    concurrency: 1,
    fetcher: (async (input) => {
      const request = input instanceof Request ? input : new Request(input);
      if (request.url === videoUrl) videoFetches += 1;
      return request.url.endsWith(VIDEO) ? videoResponse() : imageResponse();
    }) as typeof fetch,
  });

  expect(result.ready).toBe(true);
  expect(videoFetches).toBe(1);
  expect(await storage.keys()).toContain(`${WALLBOARD_PRECACHE_PREFIX}other-display-7-deadbeef`);
});

function wallboardState(): WallboardState {
  const pages: WallboardPage[] = [
    page('news', 'news'),
    page('photos', 'photo_carousel', { media_playlist_id: 'playlist' }),
    page('internal-video', 'video', {
      url: `/api/wallboard/media/${VIDEO}`,
      media_asset_id: VIDEO,
      media_asset_version: 2,
      poster_url: `/api/wallboard/media/${POSTER}/poster`,
    }),
    page('youtube', 'video', { url: 'https://www.youtube.com/embed/dQw4w9WgXcQ' }),
    page('invalid-video', 'video', { url: '/api/wallboard/state' }),
  ];

  return {
    generated_at: '2026-07-19T12:00:00Z',
    wallboard: {
      id: '01KXW0QZTP0000000000000009',
      name: 'Entree',
      data_mode: 'live',
      layout: 'fullscreen_map',
      display_profile: '1080p',
      configuration: {
        theme: 'dark',
        refresh_seconds: 15,
        map: {} as WallboardState['wallboard']['configuration']['map'],
        ticker: {} as WallboardState['wallboard']['configuration']['ticker'],
        focus: {} as WallboardState['wallboard']['configuration']['focus'],
        pages,
        rotation_enabled: true,
        page_transition: 'fade',
        page_transition_duration_ms: 300,
        page_flip_direction: 'left_to_right',
        page_fade_enabled: true,
        incident_override: {} as WallboardState['wallboard']['configuration']['incident_override'],
      },
      config_version: 7,
      control_version: 1,
      refresh_version: 1,
      runtime_playlist_id: '01KXW0QZTP0000000000000007',
      runtime_playlist_version: 7,
      active_incident_playlist: false,
      display: {} as WallboardState['wallboard']['display'],
      updated_at: '2026-07-19T12:00:00Z',
    },
    news: {
      generated_at: '2026-07-19T12:00:00Z',
      pages: {
        news: {
          fallback_used: false,
          lookback_days: 7,
          items: [
            newsItem('story-1', `/api/wallboard/media/${IMAGE_ONE}`),
            newsItem('story-2', `/api/wallboard/news-images/${NEWS_HASH}`),
            newsItem('story-3', `/api/wallboard/media/${IMAGE_ONE}`),
          ],
        },
        unconfigured: {
          fallback_used: false,
          lookback_days: 7,
          items: [newsItem('not-configured', `/api/wallboard/news-images/${NEWS_HASH}`)],
        },
      },
    },
    media: {
      photo_pages: {
        photos: {
          media_playlist_id: 'playlist',
          media_playlist_version: 3,
          item_duration_seconds: 10,
          total_duration_seconds: 20,
          items: [
            mediaItem(IMAGE_ONE),
            mediaItem(IMAGE_TWO),
          ],
        },
      },
    },
    calendar: { generated_at: '2026-07-19T12:00:00Z', pages: {} },
    map: { incidents: [], command_centers: [], historical_incidents: [], live_locations: [] },
    operational_summary: {} as WallboardState['operational_summary'],
    ticker: { items: [] },
    forecast: { pages: {} },
  };
}

function page(id: string, type: WallboardPage['type'], options: Record<string, unknown> = {}): WallboardPage {
  return { id, name: id, type, enabled: true, duration_seconds: 20, options } as WallboardPage;
}

function radarLayer(
  kind: 'precipitation' | 'lightning',
  atlasUrl: string,
): NonNullable<NonNullable<WallboardState['weather_radar']>['precipitation']> {
  return {
    status: 'available',
    reference_time: '2026-07-23T12:00:00Z',
    atlas_url: atlasUrl,
    atlas_columns: kind === 'precipitation' ? 5 : 4,
    atlas_rows: kind === 'precipitation' ? 5 : 2,
    frame_width: kind === 'precipitation' ? 140 : 640,
    frame_height: kind === 'precipitation' ? 153 : 384,
    frames: [{ index: 0, valid_at: '2026-07-23T12:00:00Z', lead_minutes: 0 }],
    source: { name: kind === 'precipitation' ? 'KNMI' : 'EUMETSAT', url: null, license: 'Open data' },
    availability_note: null,
  };
}

function newsItem(id: string, imageUrl: string) {
  return {
    id,
    source: 'ndt' as const,
    source_id: 'ndt',
    source_label: 'Nationaal Drone Team',
    title: id,
    excerpt: 'Samenvatting',
    url: 'https://example.test/article',
    image_url: imageUrl,
    published_at: '2026-07-19T10:00:00Z',
  };
}

function mediaItem(id: string) {
  return {
    id,
    name: id,
    image_url: `/api/wallboard/media/${id}`,
    media_asset_version: 1,
    width: 1920,
    height: 1080,
  };
}

function revisionResponse(response: Response, revision: string): Response {
  const headers = new Headers(response.headers);
  headers.set(WALLBOARD_PRECACHE_REVISION_HEADER, revision);
  return new Response(response.body, { status: response.status, statusText: response.statusText, headers });
}

function imageResponse() {
  return new Response('image', { status: 200, headers: { 'content-type': 'image/webp' } });
}

function videoResponse() {
  return new Response('video', { status: 200, headers: { 'content-type': 'video/mp4' } });
}

class MemoryCacheStorage {
  private readonly stores = new Map<string, MemoryCache>();

  async open(name: string): Promise<MemoryCache> {
    const existing = this.stores.get(name);
    if (existing !== undefined) return existing;
    const cache = new MemoryCache();
    this.stores.set(name, cache);
    return cache;
  }

  async keys(): Promise<string[]> {
    return [...this.stores.keys()];
  }

  async delete(name: string): Promise<boolean> {
    return this.stores.delete(name);
  }

  asCacheStorage(): CacheStorage {
    return this as unknown as CacheStorage;
  }
}

class MemoryCache {
  private readonly responses = new Map<string, Response>();

  async match(request: RequestInfo | URL): Promise<Response | undefined> {
    return this.responses.get(requestUrl(request))?.clone();
  }

  async put(request: RequestInfo | URL, response: Response): Promise<void> {
    this.responses.set(requestUrl(request), response.clone());
  }

  async keys(): Promise<Request[]> {
    return [...this.responses.keys()].map((url) => new Request(url));
  }

  async delete(request: RequestInfo | URL): Promise<boolean> {
    return this.responses.delete(requestUrl(request));
  }
}

function requestUrl(request: RequestInfo | URL): string {
  if (request instanceof Request) return request.url;
  if (request instanceof URL) return request.toString();
  return new Request(request).url;
}

function acknowledgementWorker(messages: Array<Record<string, unknown>>): ServiceWorker {
  return {
    postMessage(message: Record<string, unknown>, transfer: Transferable[]) {
      messages.push(message);
      const port = transfer[0] as MessagePort;
      port.postMessage({ ok: true, type: message.type });
    },
  } as unknown as ServiceWorker;
}
