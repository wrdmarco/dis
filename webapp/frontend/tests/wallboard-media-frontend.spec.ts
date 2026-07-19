import { readFileSync } from 'node:fs';
import { expect, test } from 'playwright/test';
import {
  type WallboardMediaFolder,
  type WallboardMediaPlaylist,
  WALLBOARD_MEDIA_MAX_UPLOAD_BYTES,
  wallboardMediaAssetIds,
  wallboardMediaFileValidationMessage,
  wallboardMediaFolderTree,
  wallboardMediaImageUrl,
  wallboardMediaPageStateFromPlaylist,
  wallboardPhotoPageDurationSeconds,
  wallboardPhotoPageIsWithinDurationLimit,
} from '../src/features/wallboards/wallboardMedia';

function folder(overrides: Partial<WallboardMediaFolder> & Pick<WallboardMediaFolder, 'id' | 'name'>): WallboardMediaFolder {
  return {
    parent_id: null,
    version: 1,
    children_count: 0,
    assets_count: 0,
    created_at: null,
    updated_at: null,
    ...overrides,
  };
}

test('flattens a media folder tree in stable Dutch name order without recursing through cycles', () => {
  const flattened = wallboardMediaFolderTree([
    folder({ id: 'child', name: 'Zomer', parent_id: 'root' }),
    folder({ id: 'root-two', name: 'Briefings' }),
    folder({ id: 'root', name: 'Operaties' }),
    folder({ id: 'cycle-a', name: 'Cyclus A', parent_id: 'cycle-b' }),
    folder({ id: 'cycle-b', name: 'Cyclus B', parent_id: 'cycle-a' }),
  ]);

  expect(flattened.map(({ id, depth }) => ({ id, depth }))).toEqual([
    { id: 'root-two', depth: 0 },
    { id: 'root', depth: 0 },
    { id: 'child', depth: 1 },
    { id: 'cycle-a', depth: 0 },
    { id: 'cycle-b', depth: 0 },
  ]);
});

test('allows only exact same-origin media content paths in image elements', () => {
  const id = '01KXW0QZTP0000000000000000';
  expect(wallboardMediaImageUrl(`/api/admin/wallboard-media/assets/${id}/content`))
    .toBe(`/api/admin/wallboard-media/assets/${id}/content`);
  expect(wallboardMediaImageUrl(`/api/wallboard/media/${id}`)).toBe(`/api/wallboard/media/${id}`);
  expect(wallboardMediaImageUrl(`/api/wallboard/media/${id.toLowerCase()}`)).toBe(`/api/wallboard/media/${id.toLowerCase()}`);
  expect(wallboardMediaImageUrl(`https://evil.example/api/wallboard/media/${id}`)).toBeNull();
  expect(wallboardMediaImageUrl('/api/wallboard/media/not-a-ulid')).toBeNull();
  expect(wallboardMediaImageUrl('javascript:alert(1)')).toBeNull();
});

test('derives the carousel page duration and applies the server maximum', () => {
  expect(wallboardPhotoPageDurationSeconds(8, 12)).toBe(96);
  expect(wallboardPhotoPageDurationSeconds(8, 1)).toBe(40);
  expect(wallboardPhotoPageDurationSeconds(8, 999)).toBe(2400);
  expect(wallboardPhotoPageIsWithinDurationLimit(12, 300)).toBe(true);
  expect(wallboardPhotoPageIsWithinDurationLimit(13, 300)).toBe(false);
  expect(wallboardPhotoPageIsWithinDurationLimit(0, 12)).toBe(false);
});

test('keeps the order returned by a media playlist', () => {
  const playlist = {
    id: 'playlist',
    name: 'Entree',
    version: 1,
    usage_count: 0,
    item_count: 2,
    created_at: null,
    updated_at: null,
    items: [
      { id: 'item-b', position: 1, asset: { id: 'asset-b' } },
      { id: 'item-a', position: 0, asset: { id: 'asset-a' } },
    ],
  } as WallboardMediaPlaylist;

  expect(wallboardMediaAssetIds(playlist)).toEqual(['asset-a', 'asset-b']);
  const withAssets = {
    ...playlist,
    items: [
      {
        id: 'item-b',
        position: 1,
        asset: {
          id: '01KXW0QZTP0000000000000002',
          display_name: 'Tweede',
          content_url: '/api/admin/wallboard-media/assets/01KXW0QZTP0000000000000002/content',
          width: 1920,
          height: 1080,
        },
      },
      {
        id: 'item-a',
        position: 0,
        asset: {
          id: '01KXW0QZTP0000000000000001',
          display_name: 'Eerste',
          content_url: '/api/admin/wallboard-media/assets/01KXW0QZTP0000000000000001/content',
          width: 1920,
          height: 1080,
        },
      },
    ],
  } as WallboardMediaPlaylist;
  const state = wallboardMediaPageStateFromPlaylist(withAssets, 12);
  expect(state.items.map((item) => item.name)).toEqual(['Eerste', 'Tweede']);
  expect(state.total_duration_seconds).toBe(24);
});

test('rejects unsupported, empty and oversized uploads before sending them', () => {
  expect(wallboardMediaFileValidationMessage(new File(['image'], 'image.jpg', { type: 'image/jpeg' }))).toBeNull();
  expect(wallboardMediaFileValidationMessage(new File(['<svg/>'], 'image.svg', { type: 'image/svg+xml' })))
    .toBe('Gebruik een JPEG-, PNG- of WebP-afbeelding.');
  expect(wallboardMediaFileValidationMessage(new File([], 'empty.png', { type: 'image/png' })))
    .toBe('De geselecteerde afbeelding is leeg.');
  expect(wallboardMediaFileValidationMessage({
    type: 'image/webp',
    size: WALLBOARD_MEDIA_MAX_UPLOAD_BYTES + 1,
  } as File)).toBe('De afbeelding mag maximaal 15 MB groot zijn.');
});

test('media management uses CSRF-aware ApiClient writes and no executable markup', () => {
  const source = readFileSync(
    new URL('../src/features/wallboards/WallboardMediaLibrary.tsx', import.meta.url),
    'utf8',
  );

  expect(source).toContain("api.postForm('/admin/wallboard-media/assets', payload)");
  expect(source).toContain('expected_version: asset.version');
  expect(source).toContain('expected_version: selectedPlaylist.version');
  expect(source).toContain('accept="image/jpeg,image/png,image/webp"');
  expect(source).not.toContain('dangerouslySetInnerHTML');
  expect(source).not.toContain('innerHTML');
  expect(source).not.toContain('URL.createObjectURL');
});
