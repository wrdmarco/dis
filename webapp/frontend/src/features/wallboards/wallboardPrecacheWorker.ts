import type { WallboardPrecacheManifest, WallboardPrecacheResult } from './wallboardPrecache';
import { normalizedWallboardCacheKey } from './wallboardPrecache';

export const WALLBOARD_PRECACHE_WORKER_URL = '/wallboard-media-sw.js';
export const WALLBOARD_PRECACHE_WORKER_SCOPE = '/wallboard';
export const WALLBOARD_PRECACHE_WORKER_LIFECYCLE_TIMEOUT_MS = 10_000;

interface WallboardPrecacheWorkerActivateMessage {
  type: 'DIS_WALLBOARD_PRECACHE_ACTIVATE';
  wallboardKey: string;
  clientSessionToken: string;
  commandGeneration: number;
  cacheName: string;
  assets: Array<{
    url: string;
    kind: WallboardPrecacheManifest['assets'][number]['kind'];
  }>;
}

interface WallboardPrecacheWorkerDisableMessage {
  type: 'DIS_WALLBOARD_PRECACHE_DISABLE';
  clientSessionToken: string;
  commandGeneration: number;
  wallboardKey?: string;
}

type WallboardPrecacheWorkerMessage =
  | WallboardPrecacheWorkerActivateMessage
  | WallboardPrecacheWorkerDisableMessage;

interface WallboardPrecacheWorkerAcknowledgement {
  ok: boolean;
  type: WallboardPrecacheWorkerMessage['type'];
}

export interface WallboardPrecacheWorkerOptions {
  serviceWorkers?: ServiceWorkerContainer;
  acknowledgementTimeoutMs?: number;
  lifecycleTimeoutMs?: number;
  wallboardKey?: string | null;
  clientSessionToken?: string;
  commandGeneration?: number;
}

let fallbackClientSessionSequence = 0;

/**
 * Identifies one pairing lifecycle inside a browser tab. It is not an
 * authentication secret; it prevents a delayed disable acknowledgement from
 * tearing down a newer cache activation for the same service-worker client.
 */
export function createWallboardPrecacheClientSessionToken(): string {
  if (typeof globalThis.crypto?.randomUUID === 'function') {
    return globalThis.crypto.randomUUID().replaceAll('-', '');
  }
  fallbackClientSessionSequence += 1;
  return `session_${Date.now().toString(36)}_${fallbackClientSessionSequence.toString(36)}`;
}

export async function registerWallboardPrecacheWorker(
  serviceWorkers = browserServiceWorkers(),
  lifecycleTimeoutMs = WALLBOARD_PRECACHE_WORKER_LIFECYCLE_TIMEOUT_MS,
): Promise<ServiceWorkerRegistration> {
  const registration = await withTimeout(
    serviceWorkers.register(WALLBOARD_PRECACHE_WORKER_URL, {
      scope: WALLBOARD_PRECACHE_WORKER_SCOPE,
      updateViaCache: 'none',
    }),
    lifecycleTimeoutMs,
    'De wallboardcache-worker kon niet op tijd worden geregistreerd.',
  );
  await withTimeout(
    serviceWorkers.ready,
    lifecycleTimeoutMs,
    'De wallboardcache-worker werd niet op tijd actief.',
  );
  return registration;
}

/**
 * Enables cache-backed rendering only after the matching manifest is complete.
 * This ordering prevents a partially downloaded cache from becoming visible.
 */
export async function activateWallboardPrecacheWorker(
  registration: ServiceWorkerRegistration,
  manifest: WallboardPrecacheManifest,
  result: WallboardPrecacheResult,
  clientSessionToken: string,
  commandGeneration: number,
  acknowledgementTimeoutMs = 5_000,
): Promise<void> {
  if (!result.ready || result.cacheName !== manifest.cacheName || result.contentVersion !== manifest.contentVersion) {
    throw new Error('Een onvolledige of verouderde wallboardcache kan niet worden geactiveerd.');
  }
  assertCommandGeneration(commandGeneration);

  const worker = await activeWorker(registration, acknowledgementTimeoutMs);
  await postWorkerMessage(worker, {
    type: 'DIS_WALLBOARD_PRECACHE_ACTIVATE',
    wallboardKey: manifest.wallboardKey,
    clientSessionToken,
    commandGeneration,
    cacheName: manifest.cacheName,
    assets: manifest.assets.map(({ url, kind }) => ({ url, kind })),
  }, acknowledgementTimeoutMs);
}

/**
 * Stops cache serving for this browser client. The worker removes only caches
 * owned by this wallboard and only after no other live client uses them.
 */
export async function disableWallboardPrecache(
  options: WallboardPrecacheWorkerOptions = {},
): Promise<void> {
  const serviceWorkers = options.serviceWorkers ?? browserServiceWorkers();
  const registration = await withTimeout(
    serviceWorkers.getRegistration(WALLBOARD_PRECACHE_WORKER_SCOPE),
    options.lifecycleTimeoutMs ?? WALLBOARD_PRECACHE_WORKER_LIFECYCLE_TIMEOUT_MS,
    'De wallboardcache-worker kon niet op tijd worden gevonden.',
  );
  const worker = registration?.active ?? serviceWorkers.controller;
  if (worker === null || worker === undefined) return;

  const wallboardKey = options.wallboardKey === null || options.wallboardKey === undefined
    ? undefined
    : normalizedWallboardCacheKey(options.wallboardKey);
  if (options.clientSessionToken === undefined) {
    throw new Error('De wallboardcache kan niet zonder clientsessie worden uitgeschakeld.');
  }
  if (options.commandGeneration === undefined) {
    throw new Error('De wallboardcache kan niet zonder commandogeneratie worden uitgeschakeld.');
  }
  assertCommandGeneration(options.commandGeneration);
  await postWorkerMessage(worker, {
    type: 'DIS_WALLBOARD_PRECACHE_DISABLE',
    clientSessionToken: options.clientSessionToken,
    commandGeneration: options.commandGeneration,
    ...(wallboardKey === undefined ? {} : { wallboardKey }),
  }, options.acknowledgementTimeoutMs ?? 5_000);
}

async function activeWorker(
  registration: ServiceWorkerRegistration,
  timeoutMs: number,
): Promise<ServiceWorker> {
  if (registration.active !== null) return registration.active;
  const candidate = registration.installing ?? registration.waiting;
  if (candidate === null) throw new Error('De wallboardcache-worker is niet actief.');
  if (candidate.state === 'activated') return candidate;

  return new Promise<ServiceWorker>((resolve, reject) => {
    const timer = globalThis.setTimeout(() => {
      candidate.removeEventListener('statechange', handleStateChange);
      reject(new Error('De wallboardcache-worker werd niet op tijd geactiveerd.'));
    }, boundedTimeout(timeoutMs));
    const handleStateChange = () => {
      if (candidate.state === 'activated') {
        globalThis.clearTimeout(timer);
        candidate.removeEventListener('statechange', handleStateChange);
        resolve(candidate);
      } else if (candidate.state === 'redundant') {
        globalThis.clearTimeout(timer);
        candidate.removeEventListener('statechange', handleStateChange);
        reject(new Error('De wallboardcache-worker kon niet worden geactiveerd.'));
      }
    };
    candidate.addEventListener('statechange', handleStateChange);
    handleStateChange();
  });
}

async function postWorkerMessage(
  worker: ServiceWorker,
  message: WallboardPrecacheWorkerMessage,
  timeoutMs: number,
): Promise<void> {
  const channel = new MessageChannel();
  await new Promise<void>((resolve, reject) => {
    const timer = globalThis.setTimeout(() => {
      channel.port1.close();
      reject(new Error('De wallboardcache-worker bevestigde de opdracht niet op tijd.'));
    }, boundedTimeout(timeoutMs));

    channel.port1.onmessage = (event: MessageEvent<WallboardPrecacheWorkerAcknowledgement>) => {
      globalThis.clearTimeout(timer);
      channel.port1.close();
      if (event.data?.ok === true && event.data.type === message.type) resolve();
      else reject(new Error('De wallboardcache-worker heeft de opdracht geweigerd.'));
    };

    worker.postMessage(message, [channel.port2]);
  });
}

async function withTimeout<T>(
  operation: Promise<T>,
  timeoutMs: number,
  message: string,
): Promise<T> {
  let timer: ReturnType<typeof globalThis.setTimeout> | null = null;
  try {
    return await Promise.race([
      operation,
      new Promise<T>((_, reject) => {
        timer = globalThis.setTimeout(() => reject(new Error(message)), boundedTimeout(timeoutMs));
      }),
    ]);
  } finally {
    if (timer !== null) globalThis.clearTimeout(timer);
  }
}

function boundedTimeout(value: number): number {
  if (!Number.isFinite(value)) return WALLBOARD_PRECACHE_WORKER_LIFECYCLE_TIMEOUT_MS;
  return Math.max(1_000, Math.min(30_000, Math.trunc(value)));
}

function assertCommandGeneration(value: number): void {
  if (!Number.isSafeInteger(value) || value <= 0) {
    throw new Error('De wallboardcache-opdracht heeft geen geldige generatie.');
  }
}

function browserServiceWorkers(): ServiceWorkerContainer {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
    throw new Error('Service workers zijn niet beschikbaar in deze browser.');
  }
  return navigator.serviceWorker;
}
