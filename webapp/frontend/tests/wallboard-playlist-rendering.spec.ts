import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import { wallboardPlaylistPageLayers } from '../src/features/wallboards/wallboardPlaylistRendering';

const DISPLAY_SOURCE = readFileSync(
  new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
  'utf8',
);
const PLAYLIST_FRAME_SOURCE = DISPLAY_SOURCE.slice(
  DISPLAY_SOURCE.indexOf('function WallboardPlaylistPageFrame('),
  DISPLAY_SOURCE.indexOf('function MaintenanceTakeover('),
);
const PAGES = [{ id: 'news' }, { id: 'photos' }, { id: 'video' }] as const;

test('keeps every playlist page mounted while only the transition pair is visible', () => {
  expect(wallboardPlaylistPageLayers(PAGES, 'photos', 'news', true, true, true)).toEqual([
    { page: PAGES[0], role: 'outgoing', visible: true, running: false },
    { page: PAGES[1], role: 'current', visible: true, running: true },
    { page: PAGES[2], role: 'preloaded', visible: false, running: false },
  ]);

  expect(wallboardPlaylistPageLayers(PAGES, 'photos', 'news', false, true, true)).toEqual([
    { page: PAGES[0], role: 'preloaded', visible: false, running: false },
    { page: PAGES[1], role: 'current', visible: true, running: true },
    { page: PAGES[2], role: 'preloaded', visible: false, running: false },
  ]);
});

test('pauses every internal page runtime while a takeover is visible or the feed is stale', () => {
  const takeover = wallboardPlaylistPageLayers(PAGES, 'video', null, false, false, true);
  expect(takeover.every((layer) => !layer.visible && !layer.running)).toBe(true);

  const stale = wallboardPlaylistPageLayers(PAGES, 'video', null, false, true, false);
  expect(stale.find((layer) => layer.page.id === 'video')).toMatchObject({
    role: 'current',
    visible: true,
    running: false,
  });
});

test('renders stable keyed page layers and gates news, photo and video runtimes', () => {
  expect(DISPLAY_SOURCE).toContain('pages={configuration.pages}');
  expect(DISPLAY_SOURCE).toContain('const playlistActive = maintenance === null && !showFocus && !showTransientAlert;');
  expect(DISPLAY_SOURCE).toContain('active={playlistActive}');
  expect(DISPLAY_SOURCE).toContain('{precacheReady ? (');
  expect(DISPLAY_SOURCE).toContain('layers.map((layer) =>');
  expect(DISPLAY_SOURCE).toContain('key={layer.page.id}');
  expect(PLAYLIST_FRAME_SOURCE).not.toContain('key={visual.sequence}');
  expect(DISPLAY_SOURCE).toContain('running={layer.running}');
  expect(DISPLAY_SOURCE).toContain('<WallboardVideoPage page={page} running={running} adminPreview={adminPreview} />');
  expect(DISPLAY_SOURCE).toContain('autoPlay={running}');
  expect(DISPLAY_SOURCE).toContain('wallboardVideoEmbedUrl(page.options.url, running)');
  expect(DISPLAY_SOURCE).toContain('wallboardAdminPreviewVideoUrl(page.options.url, page.options.media_asset_version)');
  expect(DISPLAY_SOURCE).toContain('/^\\/api\\/admin\\/wallboard-media\\/assets\\/');
});
