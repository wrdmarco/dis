import { expect, test } from 'playwright/test';
import type { WallboardPage } from '../src/types/api';
import type { WallboardMediaAsset } from '../src/features/wallboards/wallboardMedia';
import {
  isSelectableMp4,
  wallboardVideoPageForAsset,
  wallboardVideoPageForSource,
} from '../src/features/wallboards/wallboardVideoPageEditorState';

const ASSET_ID = '01KXW0QZTP0000000000000002';

test('projects a ready MP4 selection into canonical local-video page options', () => {
  const selected = wallboardVideoPageForAsset(videoPage(), videoAsset());

  expect(selected).toMatchObject({
    id: 'video',
    duration_seconds: 47,
    options: {
      media_asset_id: ASSET_ID,
      url: `/api/wallboard/media/${ASSET_ID}`,
      video_duration_seconds: 42,
    },
  });
  expect(wallboardVideoPageForAsset(videoPage(), videoAsset({ id: ASSET_ID.toLowerCase() })).options)
    .toMatchObject({ media_asset_id: ASSET_ID, url: `/api/wallboard/media/${ASSET_ID}` });
  expect(isSelectableMp4(videoAsset({ duration_seconds: 3_595 }))).toBe(true);
  expect(wallboardVideoPageForAsset(videoPage(), videoAsset({ duration_seconds: 3_595 })).duration_seconds)
    .toBe(3_600);
});

test('clears incompatible options when switching video source', () => {
  const localPage = wallboardVideoPageForAsset(videoPage(), videoAsset());

  expect(wallboardVideoPageForSource(localPage, 'external').options).toEqual({ url: '' });
  expect(wallboardVideoPageForSource(videoPage({
    url: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
    video_duration_seconds: 30,
  }), 'upload').options).toEqual({});
});

test('rejects unavailable, non-MP4 and out-of-range media assets', () => {
  const invalidAssets = [
    videoAsset({ status: 'processing' }),
    videoAsset({ kind: 'image', mime_type: 'image/webp' }),
    videoAsset({ duration_seconds: null }),
    videoAsset({ duration_seconds: 0 }),
    videoAsset({ duration_seconds: 3_596 }),
    videoAsset({ id: 'not-a-ulid' }),
  ];

  for (const asset of invalidAssets) {
    expect(isSelectableMp4(asset)).toBe(false);
    expect(wallboardVideoPageForAsset(videoPage(), asset).options).toEqual({});
  }
});

function videoPage(options: WallboardPage['options'] = {}): WallboardPage {
  return {
    id: 'video',
    name: 'Lokale video',
    type: 'video',
    duration_seconds: 30,
    options,
  };
}

function videoAsset(overrides: Partial<WallboardMediaAsset> = {}): WallboardMediaAsset {
  return {
    id: ASSET_ID,
    folder_id: null,
    folder_name: null,
    display_name: 'Lokale video',
    original_name: 'video.mp4',
    kind: 'video',
    mime_type: 'video/mp4',
    byte_size: 1_024,
    width: 1_920,
    height: 1_080,
    duration_seconds: 42,
    status: 'ready',
    version: 1,
    playlist_references_count: 0,
    thumbnail_url: null,
    content_url: null,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}
