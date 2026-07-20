import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import type { WallboardConfiguration } from '../src/types/api';
import { preserveWallboardPlaylistPreviewAdminMedia } from '../src/features/wallboards/WallboardPlaylistPreview';
import { DEFAULT_WALLBOARD_CONFIGURATION, wallboardConfigurationCopy } from '../src/features/wallboards/wallboardPresentation';
import {
  advanceWallboardPlaylistPreviewRotation,
  createWallboardPlaylistPreviewRotation,
  pauseWallboardPlaylistPreviewRotation,
  resumeWallboardPlaylistPreviewRotation,
  selectWallboardPlaylistPreviewPage,
  wallboardPlaylistPreviewRemainingMilliseconds,
} from '../src/features/wallboards/wallboardPlaylistPreviewRotation';

test('rotates through variable page durations and wraps to the first page', () => {
  const durations = [5_000, 12_000, 30_000];
  let runtime = createWallboardPlaylistPreviewRotation(durations, true, 1_000);

  expect(runtime).toMatchObject({ pageIndex: 0, deadlineEpochMilliseconds: 6_000 });
  runtime = advanceWallboardPlaylistPreviewRotation(runtime, durations, true, 6_000);
  expect(runtime).toMatchObject({ pageIndex: 1, remainingMilliseconds: 12_000, deadlineEpochMilliseconds: 18_000 });
  runtime = advanceWallboardPlaylistPreviewRotation(runtime, durations, true, 18_000);
  runtime = advanceWallboardPlaylistPreviewRotation(runtime, durations, true, 48_000);
  expect(runtime).toMatchObject({ pageIndex: 0, remainingMilliseconds: 5_000, deadlineEpochMilliseconds: 53_000 });
});

test('pauses at the exact remaining time and resumes without counting hidden time', () => {
  let runtime = createWallboardPlaylistPreviewRotation([10_000], true, 1_000);
  const pageStartedAt = runtime.pageStartedAtEpochMilliseconds;
  runtime = pauseWallboardPlaylistPreviewRotation(runtime, 4_500);

  expect(runtime.deadlineEpochMilliseconds).toBeNull();
  expect(wallboardPlaylistPreviewRemainingMilliseconds(runtime, 40_000)).toBe(6_500);

  runtime = resumeWallboardPlaylistPreviewRotation(runtime, 40_000);
  expect(runtime.deadlineEpochMilliseconds).toBe(46_500);
  expect(runtime.pageStartedAtEpochMilliseconds).toBe(pageStartedAt);
  expect(wallboardPlaylistPreviewRemainingMilliseconds(runtime, 42_500)).toBe(4_000);
});

test('manual page selection restarts that page while preserving playback state', () => {
  const durations = [5_000, 12_000, 30_000];
  const initial = createWallboardPlaylistPreviewRotation(durations, false, 1_000);
  const paused = selectWallboardPlaylistPreviewPage(initial, 2, durations, false, 2_000);
  const running = selectWallboardPlaylistPreviewPage(paused, 1, durations, true, 3_000);

  expect(paused).toMatchObject({ pageIndex: 2, remainingMilliseconds: 30_000, deadlineEpochMilliseconds: null });
  expect(running).toMatchObject({ pageIndex: 1, remainingMilliseconds: 12_000, deadlineEpochMilliseconds: 15_000 });
});

test('keeps the narrowly scoped admin MP4 URL and asset revision after kiosk normalization', () => {
  const assetId = '01JZZZZZZZZZZZZZZZZZZZZZZZ';
  const source: WallboardConfiguration = {
    ...DEFAULT_WALLBOARD_CONFIGURATION,
    pages: [{
      id: 'preview-video',
      name: 'Previewvideo',
      type: 'video',
      duration_seconds: 25,
      options: {
        media_asset_id: assetId.toLowerCase(),
        media_asset_version: 7,
        video_duration_seconds: 20,
        url: `/api/admin/wallboard-media/assets/${assetId}/content`,
      },
    }],
  };
  const normalized = wallboardConfigurationCopy(source);
  expect(normalized.pages[0].options.url).toBe(`/api/wallboard/media/${assetId}`);

  const preview = preserveWallboardPlaylistPreviewAdminMedia(source, normalized);
  expect(preview.pages[0].options).toMatchObject({
    media_asset_id: assetId,
    media_asset_version: 7,
    url: `/api/admin/wallboard-media/assets/${assetId}/content`,
  });
});

test('uses the real wallboard renderer while keeping admin and kiosk sessions separate', () => {
  const preview = readFileSync(
    new URL('../src/features/wallboards/WallboardPlaylistPreview.tsx', import.meta.url),
    'utf8',
  );
  const display = readFileSync(
    new URL('../src/features/wallboards/WallboardDisplayPage.tsx', import.meta.url),
    'utf8',
  );
  const admin = readFileSync(
    new URL('../src/features/wallboards/WallboardsAdminPage.tsx', import.meta.url),
    'utf8',
  );
  const styles = readFileSync(new URL('../src/styles/global.css', import.meta.url), 'utf8');

  expect(preview).toContain('<WallboardPlaylistPageFrame');
  expect(preview).toContain('adminPreview');
  expect(preview).toContain('className="wallboard-playlist-preview__viewport" inert aria-hidden="true"');
  expect(preview).toContain('<WallboardTicker key={`preview-ticker-${restartGeneration}`} items={state.ticker.items} running={running} />');
  expect(preview).toContain('loadState(snapshot)');
  expect(preview).toContain("document.addEventListener('visibilitychange', updateVisibility)");
  expect(preview).toContain("document.removeEventListener('visibilitychange', updateVisibility)");
  expect(preview).toContain('window.clearTimeout(timer)');
  expect(preview).toContain('window.clearInterval(interval)');
  expect(preview).not.toContain('useAuth');
  expect(preview).not.toContain('/wallboard/state');
  expect(admin).toContain('/preview-state');
  expect(display).toContain('running = active');
  expect(display).toContain('hasLiveFeed && running');
  expect(display).toContain('admin\\/wallboard-news-images');
  expect(styles).toContain('width: 1920px;');
  expect(styles).toContain('height: 1080px;');
  expect(styles).toMatch(/@media \(max-width: 480px\)[\s\S]*?wallboard-playlist-preview__playback-controls[\s\S]*?grid-template-columns: repeat\(4, minmax\(0, 1fr\)\);/);
  expect(styles).toContain('.wallboard-display__ticker--paused .wallboard-display__ticker-track');
  expect(styles).toMatch(/@media \(prefers-reduced-motion: reduce\)[\s\S]*wallboard-playlist-preview__progress/);
});
