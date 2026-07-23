import { expect, test } from 'playwright/test';
import type { WallboardPage } from '../src/types/api';
import type {
  WallboardPrecacheManifest,
  WallboardPrecacheProgress,
} from '../src/features/wallboards/wallboardPrecache';
import {
  wallboardPrecacheBlocksPlaylist,
  wallboardPreloadRuntimeState,
} from '../src/features/wallboards/wallboardPrecacheRuntime';

const pages = [
  page('message', 'Mededeling'),
  page('news', 'Nieuws'),
  page('photos', 'Foto\'s'),
];
const manifest: WallboardPrecacheManifest = {
  wallboardKey: 'test',
  cacheNamespace: 'dis-wallboard-static-v1-test-',
  cacheName: 'dis-wallboard-static-v1-test',
  contentVersion: '7-test',
  blockingContentVersion: '7-test',
  assets: [
    { url: 'https://dis.test/news.webp', kind: 'image', pageIds: ['news'] },
    { url: 'https://dis.test/shared.webp', kind: 'image', pageIds: ['news', 'photos'] },
  ],
  externalPreloadHints: [],
};

test('counts pages only after all of their local assets are cached', () => {
  const state = wallboardPreloadRuntimeState(progress({
    completed: 1,
    completedUrls: ['https://dis.test/shared.webp'],
    currentUrl: 'https://dis.test/news.webp',
  }), manifest, pages);

  expect(state).toMatchObject({
    status: 'loading',
    pagesReady: 2,
    pagesTotal: 3,
    onlineOnlyPages: 0,
    currentLabel: 'Nieuws',
    completed: 1,
    total: 2,
  });
});

test('reports external video pages separately instead of claiming they are cached', () => {
  const externalPage = {
    ...page('youtube', 'YouTube'),
    type: 'video' as const,
  } as WallboardPage;
  const externalManifest: WallboardPrecacheManifest = {
    ...manifest,
    assets: [],
    externalPreloadHints: [{
      pageId: 'youtube',
      origin: 'https://www.youtube.com',
      url: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
      rel: 'preconnect',
    }],
  };

  const state = wallboardPreloadRuntimeState(progress({ total: 0 }), externalManifest, [externalPage]);

  expect(state).toMatchObject({
    pagesReady: 0,
    pagesTotal: 0,
    onlineOnlyPages: 1,
  });
});

test('never exposes a technical asset URL as loading label or error detail', () => {
  const state = wallboardPreloadRuntimeState(progress({
    phase: 'failed',
    currentUrl: 'https://dis.test/news.webp',
    failures: [{
      url: 'https://dis.test/news.webp',
      reason: 'network_error',
      message: 'Bestand kon niet volledig worden gedownload.',
    }],
  }), manifest, pages);

  expect(state.status).toBe('error');
  expect(state.currentLabel).toBe('Nieuws');
  expect(state.errorText).toBe('Bestand kon niet volledig worden gedownload.');
  expect(JSON.stringify(state)).not.toContain('news.webp');
});

test('blocks only normal playlist playback while maintenance and alarms keep priority', () => {
  expect(wallboardPrecacheBlocksPlaylist({
    maintenanceActive: false,
    focusVisible: false,
    transientAlertVisible: false,
    precacheReady: false,
  })).toBe(true);
  expect(wallboardPrecacheBlocksPlaylist({
    maintenanceActive: true,
    focusVisible: false,
    transientAlertVisible: false,
    precacheReady: false,
  })).toBe(false);
  expect(wallboardPrecacheBlocksPlaylist({
    maintenanceActive: false,
    focusVisible: true,
    transientAlertVisible: false,
    precacheReady: false,
  })).toBe(false);
  expect(wallboardPrecacheBlocksPlaylist({
    maintenanceActive: false,
    focusVisible: false,
    transientAlertVisible: true,
    precacheReady: false,
  })).toBe(false);
  expect(wallboardPrecacheBlocksPlaylist({
    maintenanceActive: false,
    focusVisible: false,
    transientAlertVisible: false,
    precacheReady: true,
  })).toBe(false);
});

function page(id: string, name: string): WallboardPage {
  return {
    id,
    name,
    type: id === 'news' ? 'news' : id === 'photos' ? 'photo_carousel' : 'message',
    enabled: true,
    duration_seconds: 10,
    options: {},
  } as WallboardPage;
}

function progress(overrides: Partial<WallboardPrecacheProgress> = {}): WallboardPrecacheProgress {
  return {
    phase: 'caching',
    total: 2,
    completed: 0,
    completedUrls: [],
    failed: 0,
    currentUrl: null,
    failures: [],
    ...overrides,
  };
}
