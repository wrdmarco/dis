/* DIS wallboard static-media cache worker. No API state is cached or served. */
'use strict';

const CACHE_PREFIX = 'dis-wallboard-static-v1-';
const CLIENT_BINDING_CACHE = 'dis-wallboard-client-bindings-v1';
const CLIENT_BINDING_PATH = '/__dis-wallboard-client-binding-v1__/';
const ACTIVATE = 'DIS_WALLBOARD_PRECACHE_ACTIVATE';
const DISABLE = 'DIS_WALLBOARD_PRECACHE_DISABLE';

/** @typedef {'image'|'poster'|'video'} AssetKind */
/** @typedef {{wallboardKey:string, clientSessionToken:string, commandGeneration:number, cacheName:string, assets:Map<string, AssetKind>}} ClientCacheState */
/** @typedef {{clientId:string, clientSessionToken:string, commandGeneration:number, disabled:boolean, wallboardKey:string|null, cacheName:string|null, assets:Array<{url:string, kind:AssetKind}>}} ClientBindingRecord */
/** @type {Map<string, ClientCacheState>} */
const clientCacheStates = new Map();
/** @type {Map<string, Promise<void>>} */
const clientOperationTails = new Map();
/** @type {Map<string, {type:string, commandGeneration:number, cacheName:string|null}>} */
const latestReceivedCommands = new Map();
/** @type {Map<string, Promise<ClientCacheState|null>>} */
const clientHydrations = new Map();

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('message', (event) => {
  const message = event.data;
  const port = event.ports[0];
  const clientId = typeof event.source?.id === 'string' ? event.source.id : null;
  let command;

  try {
    if (clientId === null) throw new Error('client_missing');
    if (message?.type === ACTIVATE) command = normalizedActivationCommand(message);
    else if (message?.type === DISABLE) command = normalizedDisableCommand(message);
    else return;
  } catch {
    port?.postMessage({ ok: false, type: message?.type });
    return;
  }

  observeReceivedCommand(clientId, command);
  const operation = enqueueClientOperation(clientId, () => command.type === ACTIVATE
    ? activateClientCache(clientId, command)
    : disableClientCache(clientId, command));
  event.waitUntil(operation
    .then(() => port?.postMessage({ ok: true, type: command.type }))
    .catch(() => port?.postMessage({ ok: false, type: command.type })));
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET' || normalizedCacheableUrl(event.request.url) === null) return;
  event.respondWith(serveClientAsset(event.clientId, event.request));
});

/** @param {string} clientId @param {Request} request */
async function serveClientAsset(clientId, request) {
  const state = await clientCacheState(clientId);
  const kind = state?.assets.get(request.url);
  if (state === null || kind === undefined) return fetch(request);

  const cache = await caches.open(state.cacheName);
  const cached = await cache.match(request, { ignoreVary: true });
  if (cached !== undefined && validCachedResponse(cached, kind)) {
    if (kind !== 'video') return cached;

    // Cache API responses cannot be randomly accessed without materializing the
    // complete body. Prefer the origin's bounded byte-range response while the
    // wallboard is online and only slice the cached body as an offline fallback.
    if (request.headers.get('Range') !== null) {
      try {
        return await fetch(request);
      } catch {
        return cachedVideoResponse(cached, request);
      }
    }

    return cached;
  }
  if (cached !== undefined) await cache.delete(request, { ignoreVary: true });

  const response = await fetch(request);
  if (validCachedResponse(response, kind) && commandIsLatest(clientId, state.commandGeneration)) {
    await cache.put(request, response.clone());
  }
  return response;
}

/** @param {Response} response @param {Request} request */
async function cachedVideoResponse(response, request) {
  const rangeHeader = request.headers.get('Range');
  if (rangeHeader === null) return response;
  if (!ifRangeMatches(response.headers, request.headers.get('If-Range'))) return response;

  const body = await response.blob();
  const range = singleByteRange(rangeHeader, body.size);
  if (range === null) return rangeNotSatisfiableResponse(response.headers, body.size);

  const headers = new Headers(response.headers);
  headers.set('Accept-Ranges', 'bytes');
  headers.set('Content-Length', String(range.end - range.start + 1));
  headers.set('Content-Range', `bytes ${range.start}-${range.end}/${body.size}`);
  headers.delete('Content-Encoding');

  return new Response(body.slice(range.start, range.end + 1, response.headers.get('Content-Type') || ''), {
    status: 206,
    statusText: 'Partial Content',
    headers,
  });
}

/** @param {Headers} cachedHeaders @param {string|null} ifRange */
function ifRangeMatches(cachedHeaders, ifRange) {
  if (ifRange === null) return true;
  const validator = ifRange.trim();
  if (validator === '' || validator.startsWith('W/')) return false;

  const etag = cachedHeaders.get('ETag');
  if (validator.startsWith('"')) {
    return etag !== null && !etag.startsWith('W/') && validator === etag;
  }

  const requestedDate = Date.parse(validator);
  const lastModified = Date.parse(cachedHeaders.get('Last-Modified') || '');
  return Number.isFinite(requestedDate)
    && Number.isFinite(lastModified)
    && requestedDate === lastModified;
}

/** @param {string} value @param {number} size @returns {{start:number,end:number}|null} */
function singleByteRange(value, size) {
  if (!Number.isSafeInteger(size) || size <= 0) return null;
  const match = /^bytes\s*=\s*(\d*)-(\d*)$/i.exec(value.trim());
  if (match === null || (match[1] === '' && match[2] === '')) return null;

  if (match[1] === '') {
    const suffixLength = Number(match[2]);
    if (!Number.isSafeInteger(suffixLength) || suffixLength <= 0) return null;
    return {
      start: Math.max(0, size - suffixLength),
      end: size - 1,
    };
  }

  const start = Number(match[1]);
  const requestedEnd = match[2] === '' ? size - 1 : Number(match[2]);
  if (!Number.isSafeInteger(start)
    || !Number.isSafeInteger(requestedEnd)
    || start < 0
    || start >= size
    || requestedEnd < start) return null;

  return { start, end: Math.min(requestedEnd, size - 1) };
}

/** @param {Headers} sourceHeaders @param {number} size */
function rangeNotSatisfiableResponse(sourceHeaders, size) {
  const headers = new Headers(sourceHeaders);
  headers.set('Accept-Ranges', 'bytes');
  headers.set('Content-Length', '0');
  headers.set('Content-Range', `bytes */${size}`);
  headers.delete('Content-Encoding');
  return new Response(null, {
    status: 416,
    statusText: 'Range Not Satisfiable',
    headers,
  });
}

/** @param {string} clientId @param {ReturnType<typeof normalizedActivationCommand>} command */
async function activateClientCache(clientId, command) {
  if (!commandIsLatest(clientId, command.commandGeneration)) return;

  const cacheNames = await caches.keys();
  if (!cacheNames.includes(command.cacheName)) throw new Error('cache_missing');
  const cache = await caches.open(command.cacheName);
  for (const asset of command.assets) {
    const response = await cache.match(asset.url, { ignoreVary: true });
    if (response === undefined || !validCachedResponse(response, asset.kind)) {
      throw new Error('cache_incomplete');
    }
  }
  if (!commandIsLatest(clientId, command.commandGeneration)) return;

  const previous = await readClientBinding(clientId);
  if (previous !== null && previous.commandGeneration >= command.commandGeneration) return;
  const record = {
    clientId,
    clientSessionToken: command.clientSessionToken,
    commandGeneration: command.commandGeneration,
    disabled: false,
    wallboardKey: command.wallboardKey,
    cacheName: command.cacheName,
    assets: command.assets,
  };
  await writeClientBinding(record);
  if (!commandIsLatest(clientId, command.commandGeneration)) return;

  clientCacheStates.set(clientId, stateFromBinding(record));
  await pruneClosedClientStates();
  await removeObsoleteWallboardCaches(command.wallboardKey, clientId, command.commandGeneration);
  if (previous !== null && previous.wallboardKey !== null && previous.wallboardKey !== command.wallboardKey) {
    await removeObsoleteWallboardCaches(previous.wallboardKey, clientId, command.commandGeneration);
  }
}

/** @param {string} clientId @param {ReturnType<typeof normalizedDisableCommand>} command */
async function disableClientCache(clientId, command) {
  if (!commandIsLatest(clientId, command.commandGeneration)) return;
  const current = await readClientBinding(clientId);
  if (current !== null && current.commandGeneration >= command.commandGeneration) return;
  if (
    current !== null
    && !current.disabled
    && command.clientSessionToken !== current.clientSessionToken
  ) return;
  if (
    current !== null
    && !current.disabled
    && command.wallboardKey !== null
    && command.wallboardKey !== current.wallboardKey
  ) throw new Error('disable_contract');

  const wallboardKey = command.wallboardKey ?? current?.wallboardKey ?? null;
  await writeClientBinding({
    clientId,
    clientSessionToken: command.clientSessionToken,
    commandGeneration: command.commandGeneration,
    disabled: true,
    wallboardKey,
    cacheName: null,
    assets: [],
  });
  if (!commandIsLatest(clientId, command.commandGeneration)) return;

  clientCacheStates.delete(clientId);
  await pruneClosedClientStates();
  if (wallboardKey !== null) {
    await removeObsoleteWallboardCaches(wallboardKey, clientId, command.commandGeneration);
  }
}

/** @param {string} clientId */
async function clientCacheState(clientId) {
  if (clientId === '') return null;
  const inMemory = clientCacheStates.get(clientId);
  if (inMemory !== undefined && commandIsLatest(clientId, inMemory.commandGeneration)) return inMemory;
  const existingHydration = clientHydrations.get(clientId);
  if (existingHydration !== undefined) return existingHydration;

  const hydration = readClientBinding(clientId).then((record) => {
    if (
      record === null
      || record.disabled
      || !commandIsLatest(clientId, record.commandGeneration)
    ) return null;
    const state = stateFromBinding(record);
    clientCacheStates.set(clientId, state);
    return state;
  }).finally(() => {
    if (clientHydrations.get(clientId) === hydration) clientHydrations.delete(clientId);
  });
  clientHydrations.set(clientId, hydration);
  return hydration;
}

/** @param {string} clientId @param {() => Promise<void>} operation */
function enqueueClientOperation(clientId, operation) {
  const previous = clientOperationTails.get(clientId) ?? Promise.resolve();
  const current = previous.catch(() => undefined).then(operation);
  clientOperationTails.set(clientId, current);
  void current.finally(() => {
    if (clientOperationTails.get(clientId) === current) clientOperationTails.delete(clientId);
  }).catch(() => undefined);
  return current;
}

/** @param {string} clientId @param {{type:string, commandGeneration:number, cacheName:string|null}} command */
function observeReceivedCommand(clientId, command) {
  const current = latestReceivedCommands.get(clientId);
  if (current === undefined || command.commandGeneration > current.commandGeneration) {
    latestReceivedCommands.set(clientId, {
      type: command.type,
      commandGeneration: command.commandGeneration,
      cacheName: command.cacheName,
    });
  }
}

/** @param {string} clientId @param {number} commandGeneration */
function commandIsLatest(clientId, commandGeneration) {
  const latest = latestReceivedCommands.get(clientId);
  return latest === undefined || latest.commandGeneration <= commandGeneration;
}

async function pruneClosedClientStates() {
  const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
  const liveClientIds = new Set(clients.map((client) => client.id));
  for (const clientId of clientCacheStates.keys()) {
    if (!liveClientIds.has(clientId)) clientCacheStates.delete(clientId);
  }
  for (const clientId of latestReceivedCommands.keys()) {
    if (!liveClientIds.has(clientId)) latestReceivedCommands.delete(clientId);
  }

  const cacheNames = await caches.keys();
  if (!cacheNames.includes(CLIENT_BINDING_CACHE)) return;
  const bindings = await caches.open(CLIENT_BINDING_CACHE);
  const requests = await bindings.keys();
  for (const request of requests) {
    const clientId = bindingClientId(request.url);
    if (clientId === null || !liveClientIds.has(clientId)) {
      await bindings.delete(request);
      continue;
    }
    const response = await bindings.match(request);
    const record = await bindingFromResponse(response, clientId);
    if (record === null) {
      await bindings.delete(request);
    } else if (!record.disabled && commandIsLatest(clientId, record.commandGeneration)) {
      clientCacheStates.set(clientId, stateFromBinding(record));
    }
  }
}

/** @param {string} wallboardKey @param {string} clientId @param {number} commandGeneration */
async function removeObsoleteWallboardCaches(wallboardKey, clientId, commandGeneration) {
  if (!commandIsLatest(clientId, commandGeneration)) return;
  const namespace = `${CACHE_PREFIX}${wallboardKey}-`;
  const protectedCacheNames = new Set(
    [...clientCacheStates.values()]
      .filter((state) => state.wallboardKey === wallboardKey)
      .map((state) => state.cacheName),
  );
  for (const command of latestReceivedCommands.values()) {
    if (command.type === ACTIVATE && command.cacheName?.startsWith(namespace)) {
      protectedCacheNames.add(command.cacheName);
    }
  }
  const cacheNames = await caches.keys();
  if (!commandIsLatest(clientId, commandGeneration)) return;
  for (const cacheName of cacheNames) {
    if (!commandIsLatest(clientId, commandGeneration)) return;
    if (cacheName.startsWith(namespace) && !protectedCacheNames.has(cacheName)) {
      await caches.delete(cacheName);
    }
  }
}

/** @param {ClientBindingRecord} record */
async function writeClientBinding(record) {
  const cache = await caches.open(CLIENT_BINDING_CACHE);
  await cache.put(bindingRequest(record.clientId), new Response(JSON.stringify(record), {
    status: 200,
    headers: { 'content-type': 'application/json' },
  }));
}

/** @param {string} clientId */
async function readClientBinding(clientId) {
  const cacheNames = await caches.keys();
  if (!cacheNames.includes(CLIENT_BINDING_CACHE)) return null;
  const cache = await caches.open(CLIENT_BINDING_CACHE);
  const response = await cache.match(bindingRequest(clientId));
  return bindingFromResponse(response, clientId);
}

/** @param {Response|undefined} response @param {string} clientId */
async function bindingFromResponse(response, clientId) {
  if (response === undefined || !response.ok) return null;
  try {
    return normalizedBindingRecord(await response.json(), clientId);
  } catch {
    return null;
  }
}

/** @param {string} clientId */
function bindingRequest(clientId) {
  return new Request(`${self.location.origin}${CLIENT_BINDING_PATH}${encodeURIComponent(clientId)}`);
}

/** @param {string} value */
function bindingClientId(value) {
  try {
    const url = new URL(value);
    if (url.origin !== self.location.origin || !url.pathname.startsWith(CLIENT_BINDING_PATH)) return null;
    const encoded = url.pathname.slice(CLIENT_BINDING_PATH.length);
    if (encoded === '' || encoded.includes('/')) return null;
    return decodeURIComponent(encoded);
  } catch {
    return null;
  }
}

/** @param {ClientBindingRecord} record */
function stateFromBinding(record) {
  return {
    wallboardKey: record.wallboardKey,
    clientSessionToken: record.clientSessionToken,
    commandGeneration: record.commandGeneration,
    cacheName: record.cacheName,
    assets: new Map(record.assets.map((asset) => [asset.url, asset.kind])),
  };
}

/** @param {any} message */
function normalizedActivationCommand(message) {
  const wallboardKey = normalizedWallboardKey(message?.wallboardKey);
  const clientSessionToken = normalizedClientSessionToken(message?.clientSessionToken);
  const commandGeneration = normalizedCommandGeneration(message?.commandGeneration);
  const cacheName = typeof message?.cacheName === 'string' ? message.cacheName : '';
  const rawAssets = Array.isArray(message?.assets) ? message.assets : [];
  const normalizedAssets = rawAssets.map(normalizedCacheableAsset);
  const cacheNamespace = wallboardKey === null ? '' : `${CACHE_PREFIX}${wallboardKey}-`;
  if (
    wallboardKey === null
    || clientSessionToken === null
    || commandGeneration === null
    || !cacheName.startsWith(cacheNamespace)
    || cacheName.length > 160
    || normalizedAssets.some((asset) => asset === null)
  ) throw new Error('activation_contract');

  const assets = new Map();
  for (const asset of normalizedAssets) {
    if (asset === null) throw new Error('activation_contract');
    const existingKind = assets.get(asset.url);
    if (existingKind !== undefined && existingKind !== asset.kind) throw new Error('activation_contract');
    assets.set(asset.url, asset.kind);
  }
  return {
    type: ACTIVATE,
    wallboardKey,
    clientSessionToken,
    commandGeneration,
    cacheName,
    assets: [...assets].map(([url, kind]) => ({ url, kind })),
  };
}

/** @param {any} message */
function normalizedDisableCommand(message) {
  const clientSessionToken = normalizedClientSessionToken(message?.clientSessionToken);
  const commandGeneration = normalizedCommandGeneration(message?.commandGeneration);
  const wallboardKey = message?.wallboardKey === undefined
    ? null
    : normalizedWallboardKey(message.wallboardKey);
  if (clientSessionToken === null || commandGeneration === null || (message?.wallboardKey !== undefined && wallboardKey === null)) {
    throw new Error('disable_contract');
  }
  return {
    type: DISABLE,
    clientSessionToken,
    commandGeneration,
    wallboardKey,
    cacheName: null,
  };
}

/** @param {unknown} value @param {string} clientId */
function normalizedBindingRecord(value, clientId) {
  if (value === null || typeof value !== 'object' || value.clientId !== clientId) return null;
  const clientSessionToken = normalizedClientSessionToken(value.clientSessionToken);
  const commandGeneration = normalizedCommandGeneration(value.commandGeneration);
  const wallboardKey = value.wallboardKey === null ? null : normalizedWallboardKey(value.wallboardKey);
  if (clientSessionToken === null || commandGeneration === null || (value.wallboardKey !== null && wallboardKey === null)) return null;
  if (value.disabled === true) {
    return { clientId, clientSessionToken, commandGeneration, disabled: true, wallboardKey, cacheName: null, assets: [] };
  }
  if (value.disabled !== false || wallboardKey === null || typeof value.cacheName !== 'string') return null;
  const namespace = `${CACHE_PREFIX}${wallboardKey}-`;
  const rawAssets = Array.isArray(value.assets) ? value.assets : [];
  const assets = rawAssets.map(normalizedCacheableAsset);
  if (!value.cacheName.startsWith(namespace) || value.cacheName.length > 160 || assets.some((asset) => asset === null)) return null;
  return { clientId, clientSessionToken, commandGeneration, disabled: false, wallboardKey, cacheName: value.cacheName, assets };
}

/** @param {unknown} value */
function normalizedWallboardKey(value) {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{1,48}$/.test(value) ? value : null;
}

/** @param {unknown} value */
function normalizedClientSessionToken(value) {
  return typeof value === 'string' && /^[A-Za-z0-9_-]{16,128}$/.test(value) ? value : null;
}

/** @param {unknown} value */
function normalizedCommandGeneration(value) {
  return Number.isSafeInteger(value) && value > 0 ? value : null;
}

/** @param {unknown} value */
function normalizedCacheableAsset(value) {
  if (value === null || typeof value !== 'object') return null;
  const url = normalizedCacheableUrl(value.url);
  const kind = value.kind;
  if (url === null || !['image', 'poster', 'video'].includes(kind)) return null;
  return { url, kind };
}

/** @param {unknown} value */
function normalizedCacheableUrl(value) {
  if (typeof value !== 'string') return null;
  try {
    const url = new URL(value);
    if (url.origin !== self.location.origin || url.search !== '' || !cacheablePath(url.pathname)) return null;
    url.hash = '';
    return url.toString();
  } catch {
    return null;
  }
}

/** @param {string} pathname */
function cacheablePath(pathname) {
  return /^\/api\/wallboard\/media\/[0-9A-HJKMNP-TV-Z]{26}(?:\/(?:poster|thumbnail))?$/i.test(pathname)
    || /^\/api\/wallboard\/news-images\/[a-f0-9]{64}$/.test(pathname);
}

/** @param {Response} response @param {AssetKind} kind */
function validCachedResponse(response, kind) {
  if (response.status !== 200 || response.redirected) return false;
  const mimeType = (response.headers.get('content-type') || '').split(';', 1)[0].trim().toLowerCase();
  return kind === 'video'
    ? mimeType === 'video/mp4'
    : ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'].includes(mimeType);
}
