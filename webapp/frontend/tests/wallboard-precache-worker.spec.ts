import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { expect, test } from 'playwright/test';

const ORIGIN = 'https://dis.example.test';
const CLIENT_ID = 'wallboard-client';
const WALLBOARD_KEY = 'wallboard';
const SESSION_TOKEN = 'session_token_00000001';
const ASSET_URL = `${ORIGIN}/api/wallboard/media/01KXW0QZTP0000000000000000`;
const WORKER_SOURCE = readFileSync(new URL('../public/wallboard-media-sw.js', import.meta.url), 'utf8');

test('rehydrates the client cache binding after a service-worker process restart', async () => {
  const storage = new WorkerCacheStorage();
  await putImage(storage, cacheName('one'), 'cached-one');
  let networkFetches = 0;
  const network = async () => {
    networkFetches += 1;
    return imageResponse('network');
  };

  const firstWorker = new WorkerRuntime(storage, network);
  expect(await firstWorker.message(activation(1, cacheName('one')))).toEqual({
    ok: true,
    type: 'DIS_WALLBOARD_PRECACHE_ACTIVATE',
  });
  expect(await (await firstWorker.fetch(ASSET_URL)).text()).toBe('cached-one');

  const restartedWorker = new WorkerRuntime(storage, network);
  expect(await (await restartedWorker.fetch(ASSET_URL)).text()).toBe('cached-one');
  expect(networkFetches).toBe(0);
});

test('latest activation wins when an older cache validation finishes later', async () => {
  const storage = new WorkerCacheStorage();
  const oldCache = await putImage(storage, cacheName('old'), 'cached-old');
  await putImage(storage, cacheName('new'), 'cached-new');
  const gate = deferred<void>();
  const started = deferred<void>();
  oldCache.gateNextMatch(ASSET_URL, started.resolve, gate.promise);
  const worker = new WorkerRuntime(storage, async () => imageResponse('network'));

  const older = worker.message(activation(1, cacheName('old')));
  await started.promise;
  const newer = worker.message(activation(2, cacheName('new')));
  gate.resolve();

  expect((await Promise.all([older, newer])).every((ack) => ack.ok)).toBe(true);
  expect(await (await worker.fetch(ASSET_URL)).text()).toBe('cached-new');
  expect(await storage.keys()).toContain(cacheName('new'));
});

test('prunes the previous live cache after the replacement demo cache is activated', async () => {
  const storage = new WorkerCacheStorage();
  const liveCacheName = cacheName('live-mode');
  const demoCacheName = cacheName('demo-mode');
  await putImage(storage, liveCacheName, 'live-image');
  const worker = new WorkerRuntime(storage, async () => imageResponse('network'));

  expect((await worker.message(activation(1, liveCacheName))).ok).toBe(true);
  expect(await storage.keys()).toContain(liveCacheName);

  await putImage(storage, demoCacheName, 'demo-image');
  expect((await worker.message(activation(2, demoCacheName))).ok).toBe(true);
  expect(await storage.keys()).toContain(demoCacheName);
  expect(await storage.keys()).not.toContain(liveCacheName);
  expect(await (await worker.fetch(ASSET_URL)).text()).toBe('demo-image');
});

test('durable disable tombstone rejects a delayed activation after restart', async () => {
  const storage = new WorkerCacheStorage();
  await putImage(storage, cacheName('active'), 'cached-active');
  const worker = new WorkerRuntime(storage, async () => imageResponse('network'));
  await worker.message(activation(1, cacheName('active')));
  expect(await worker.message(disable(2))).toEqual({
    ok: true,
    type: 'DIS_WALLBOARD_PRECACHE_DISABLE',
  });

  await putImage(storage, cacheName('stale'), 'cached-stale');
  let networkFetches = 0;
  const restartedWorker = new WorkerRuntime(storage, async () => {
    networkFetches += 1;
    return imageResponse('network');
  });
  expect(await restartedWorker.message(activation(1, cacheName('stale')))).toEqual({
    ok: true,
    type: 'DIS_WALLBOARD_PRECACHE_ACTIVATE',
  });
  expect(await (await restartedWorker.fetch(ASSET_URL)).text()).toBe('network');
  expect(networkFetches).toBe(1);
});

test('uses bounded origin byte ranges while online instead of buffering the cached MP4', async () => {
  const storage = new WorkerCacheStorage();
  await putVideo(storage, cacheName('video'), '0123456789');
  let networkFetches = 0;
  const worker = new WorkerRuntime(storage, async (request) => {
    networkFetches += 1;
    return networkVideoRangeResponse(request, '0123456789');
  });
  await worker.message(activation(1, cacheName('video'), 'video'));

  const cases = [
    { range: 'bytes=2-5', body: '2345', contentRange: 'bytes 2-5/10' },
    { range: 'bytes=6-', body: '6789', contentRange: 'bytes 6-9/10' },
    { range: 'bytes=-3', body: '789', contentRange: 'bytes 7-9/10' },
  ];
  for (const candidate of cases) {
    const response = await worker.fetch(new Request(ASSET_URL, {
      headers: { Range: candidate.range },
    }));
    expect(response.status).toBe(206);
    expect(response.headers.get('accept-ranges')).toBe('bytes');
    expect(response.headers.get('content-range')).toBe(candidate.contentRange);
    expect(response.headers.get('content-length')).toBe(String(candidate.body.length));
    expect(await response.text()).toBe(candidate.body);
  }
  expect(networkFetches).toBe(3);
});

test('serves cached MP4 byte ranges after a network failure', async () => {
  const storage = new WorkerCacheStorage();
  await putVideo(storage, cacheName('video'), '0123456789');
  let networkFetches = 0;
  const worker = new WorkerRuntime(storage, async () => {
    networkFetches += 1;
    throw new TypeError('offline');
  });
  await worker.message(activation(1, cacheName('video'), 'video'));

  const response = await worker.fetch(new Request(ASSET_URL, {
    headers: { Range: 'bytes=2-5' },
  }));
  expect(response.status).toBe(206);
  expect(response.headers.get('content-range')).toBe('bytes 2-5/10');
  expect(await response.text()).toBe('2345');
  expect(networkFetches).toBe(1);
});

test('rejects an unsatisfiable cached MP4 byte range after a network failure', async () => {
  const storage = new WorkerCacheStorage();
  await putVideo(storage, cacheName('video'), '0123456789');
  let networkFetches = 0;
  const worker = new WorkerRuntime(storage, async () => {
    networkFetches += 1;
    throw new TypeError('offline');
  });
  await worker.message(activation(1, cacheName('video'), 'video'));

  const response = await worker.fetch(new Request(ASSET_URL, {
    headers: { Range: 'bytes=10-' },
  }));
  expect(response.status).toBe(416);
  expect(response.headers.get('content-range')).toBe('bytes */10');
  expect(response.headers.get('content-length')).toBe('0');
  expect(await response.text()).toBe('');
  expect(networkFetches).toBe(1);
});

test('honors If-Range validators before serving an offline cached partial response', async () => {
  const storage = new WorkerCacheStorage();
  await putVideo(storage, cacheName('video'), '0123456789');
  const worker = new WorkerRuntime(storage, async () => {
    throw new TypeError('offline');
  });
  await worker.message(activation(1, cacheName('video'), 'video'));

  const matched = await worker.fetch(new Request(ASSET_URL, {
    headers: { Range: 'bytes=2-5', 'If-Range': '"video-etag"' },
  }));
  expect(matched.status).toBe(206);
  expect(await matched.text()).toBe('2345');

  const stale = await worker.fetch(new Request(ASSET_URL, {
    headers: { Range: 'bytes=2-5', 'If-Range': '"stale-etag"' },
  }));
  expect(stale.status).toBe(200);
  expect(stale.headers.get('content-range')).toBeNull();
  expect(await stale.text()).toBe('0123456789');
});

function activation(
  commandGeneration: number,
  requestedCacheName: string,
  kind: 'image' | 'video' = 'image',
) {
  return {
    type: 'DIS_WALLBOARD_PRECACHE_ACTIVATE',
    wallboardKey: WALLBOARD_KEY,
    clientSessionToken: SESSION_TOKEN,
    commandGeneration,
    cacheName: requestedCacheName,
    assets: [{ url: ASSET_URL, kind }],
  };
}

function disable(commandGeneration: number) {
  return {
    type: 'DIS_WALLBOARD_PRECACHE_DISABLE',
    wallboardKey: WALLBOARD_KEY,
    clientSessionToken: SESSION_TOKEN,
    commandGeneration,
  };
}

function cacheName(version: string): string {
  return `dis-wallboard-static-v1-${WALLBOARD_KEY}-${version}`;
}

async function putImage(storage: WorkerCacheStorage, name: string, body: string): Promise<WorkerCache> {
  const cache = await storage.open(name);
  await cache.put(new Request(ASSET_URL), imageResponse(body));
  return cache;
}

async function putVideo(storage: WorkerCacheStorage, name: string, body: string): Promise<WorkerCache> {
  const cache = await storage.open(name);
  await cache.put(new Request(ASSET_URL), videoResponse(body));
  return cache;
}

function imageResponse(body: string): Response {
  return new Response(body, { status: 200, headers: { 'content-type': 'image/webp' } });
}

function videoResponse(body: string): Response {
  return new Response(body, {
    status: 200,
    headers: {
      'accept-ranges': 'bytes',
      'content-length': String(body.length),
      'content-type': 'video/mp4',
      etag: '"video-etag"',
      'last-modified': 'Mon, 20 Jul 2026 10:00:00 GMT',
    },
  });
}

function networkVideoRangeResponse(request: Request, body: string): Response {
  const match = /^bytes=(\d*)-(\d*)$/.exec(request.headers.get('Range') ?? '');
  if (match === null || (match[1] === '' && match[2] === '')) return videoResponse(body);
  const suffixLength = match[1] === '' ? Number(match[2]) : null;
  const start = suffixLength === null ? Number(match[1]) : Math.max(0, body.length - suffixLength);
  const end = suffixLength !== null || match[2] === ''
    ? body.length - 1
    : Math.min(Number(match[2]), body.length - 1);
  const partial = body.slice(start, end + 1);
  return new Response(partial, {
    status: 206,
    headers: {
      'accept-ranges': 'bytes',
      'content-length': String(partial.length),
      'content-range': `bytes ${start}-${end}/${body.length}`,
      'content-type': 'video/mp4',
    },
  });
}

class WorkerRuntime {
  private readonly listeners = new Map<string, (event: Record<string, unknown>) => void>();

  constructor(storage: WorkerCacheStorage, network: (request: Request) => Promise<Response>) {
    const listeners = this.listeners;
    const workerGlobal = {
      location: new URL(ORIGIN),
      clients: {
        claim: async () => undefined,
        matchAll: async () => [{ id: CLIENT_ID }],
      },
      skipWaiting: async () => undefined,
      addEventListener(type: string, listener: (event: Record<string, unknown>) => void) {
        listeners.set(type, listener);
      },
    };
    runInNewContext(WORKER_SOURCE, {
      self: workerGlobal,
      caches: storage,
      fetch: network,
      Headers,
      Request,
      Response,
      URL,
    });
  }

  async message(data: Record<string, unknown>): Promise<{ ok: boolean; type: string }> {
    const waits: Promise<unknown>[] = [];
    let acknowledgement: { ok: boolean; type: string } | null = null;
    this.listeners.get('message')?.({
      data,
      source: { id: CLIENT_ID },
      ports: [{ postMessage: (value: { ok: boolean; type: string }) => { acknowledgement = value; } }],
      waitUntil: (value: Promise<unknown>) => waits.push(value),
    });
    await Promise.all(waits);
    if (acknowledgement === null) throw new Error('Worker acknowledgement ontbreekt.');
    return acknowledgement;
  }

  async fetch(input: RequestInfo | URL): Promise<Response> {
    let response: Promise<Response> | null = null;
    const request = input instanceof Request ? input : new Request(input);
    this.listeners.get('fetch')?.({
      clientId: CLIENT_ID,
      request,
      respondWith: (value: Promise<Response>) => { response = value; },
    });
    if (response === null) throw new Error('Worker heeft het cachebare verzoek niet afgehandeld.');
    return response;
  }
}

class WorkerCacheStorage {
  private readonly stores = new Map<string, WorkerCache>();

  async open(name: string): Promise<WorkerCache> {
    const existing = this.stores.get(name);
    if (existing !== undefined) return existing;
    const cache = new WorkerCache();
    this.stores.set(name, cache);
    return cache;
  }

  async keys(): Promise<string[]> {
    return [...this.stores.keys()];
  }

  async delete(name: string): Promise<boolean> {
    return this.stores.delete(name);
  }
}

class WorkerCache {
  private readonly responses = new Map<string, Response>();
  private readonly matchGates = new Map<string, { started: () => void; wait: Promise<void> }>();

  gateNextMatch(url: string, started: () => void, wait: Promise<void>): void {
    this.matchGates.set(url, { started, wait });
  }

  async match(request: RequestInfo | URL): Promise<Response | undefined> {
    const url = requestUrl(request);
    const gate = this.matchGates.get(url);
    if (gate !== undefined) {
      this.matchGates.delete(url);
      gate.started();
      await gate.wait;
    }
    return this.responses.get(url)?.clone();
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

function deferred<T>(): { promise: Promise<T>; resolve: (value?: T) => void } {
  let resolvePromise!: (value: T) => void;
  const promise = new Promise<T>((resolve) => { resolvePromise = resolve; });
  return { promise, resolve: (value?: T) => resolvePromise(value as T) };
}
