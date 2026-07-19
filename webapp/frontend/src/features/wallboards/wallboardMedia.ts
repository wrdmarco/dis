import type {
  PaginationMeta,
  WallboardMediaPageState,
  WallboardMediaPageStateItem,
} from '../../types/api';

export type {
  WallboardMediaPageState,
  WallboardMediaPageStateItem,
} from '../../types/api';

export const WALLBOARD_MEDIA_ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'] as const;
export const WALLBOARD_MEDIA_MAX_UPLOAD_BYTES = 15 * 1024 * 1024;
export const WALLBOARD_MEDIA_MAX_PLAYLIST_ITEMS = 100;
export const WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS = 5;
export const WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS = 300;
export const WALLBOARD_PHOTO_MAX_PAGE_DURATION_SECONDS = 3600;

export interface WallboardMediaFolder {
  id: string;
  parent_id: string | null;
  name: string;
  version: number;
  children_count: number;
  assets_count: number;
  created_at: string | null;
  updated_at: string | null;
}

export interface WallboardMediaAsset {
  id: string;
  folder_id: string | null;
  folder_name: string | null;
  display_name: string;
  original_name: string;
  mime_type: 'image/jpeg' | 'image/png' | 'image/webp';
  byte_size: number;
  width: number;
  height: number;
  status: 'processing' | 'ready' | 'failed';
  version: number;
  playlist_references_count: number;
  content_url: string | null;
  created_at: string | null;
  updated_at: string | null;
}

export interface WallboardMediaPlaylistItem {
  id: string;
  position: number;
  asset: WallboardMediaAsset;
}

export interface WallboardMediaPlaylist {
  id: string;
  name: string;
  version: number;
  usage_count: number;
  item_count: number;
  items: WallboardMediaPlaylistItem[];
  created_at: string | null;
  updated_at: string | null;
}

export interface WallboardPhotoPageOptions {
  media_playlist_id?: string;
  item_duration_seconds?: number;
}

export interface WallboardMediaAssetPage {
  items: WallboardMediaAsset[];
  pagination: PaginationMeta;
}

export interface WallboardMediaFolderTreeItem extends WallboardMediaFolder {
  depth: number;
}

const ADMIN_ASSET_CONTENT_PATH = /^\/api\/admin\/wallboard-media\/assets\/[0-9A-HJKMNP-TV-Z]{26}\/content$/i;
const WALLBOARD_ASSET_CONTENT_PATH = /^\/api\/wallboard\/media\/[0-9A-HJKMNP-TV-Z]{26}$/i;

export function wallboardMediaImageUrl(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const path = value.trim();
  return ADMIN_ASSET_CONTENT_PATH.test(path) || WALLBOARD_ASSET_CONTENT_PATH.test(path) ? path : null;
}

export function wallboardMediaFolderTree(folders: readonly WallboardMediaFolder[]): WallboardMediaFolderTreeItem[] {
  const byParent = new Map<string | null, WallboardMediaFolder[]>();
  const knownIds = new Set(folders.map((folder) => folder.id));

  for (const folder of folders) {
    const parentId = folder.parent_id !== null && knownIds.has(folder.parent_id) ? folder.parent_id : null;
    const siblings = byParent.get(parentId) ?? [];
    siblings.push(folder);
    byParent.set(parentId, siblings);
  }

  for (const siblings of byParent.values()) {
    siblings.sort((left, right) => left.name.localeCompare(right.name, 'nl', { sensitivity: 'base' }));
  }

  const result: WallboardMediaFolderTreeItem[] = [];
  const visited = new Set<string>();
  const append = (parentId: string | null, depth: number) => {
    for (const folder of byParent.get(parentId) ?? []) {
      if (visited.has(folder.id)) continue;
      visited.add(folder.id);
      result.push({ ...folder, depth });
      append(folder.id, depth + 1);
    }
  };

  append(null, 0);
  for (const folder of folders) {
    if (!visited.has(folder.id)) result.push({ ...folder, depth: 0 });
  }

  return result;
}

export function wallboardMediaAssetIds(playlist: WallboardMediaPlaylist | null): string[] {
  return playlist === null
    ? []
    : [...playlist.items]
      .sort((left, right) => left.position - right.position)
      .map((item) => item.asset.id);
}

export function wallboardMediaPageStateFromPlaylist(
  playlist: WallboardMediaPlaylist,
  itemDurationSeconds: unknown,
): WallboardMediaPageState {
  const duration = wallboardPhotoItemDurationSeconds(itemDurationSeconds);
  const items = [...playlist.items]
    .sort((left, right) => left.position - right.position)
    .flatMap((item) => {
      const imageUrl = wallboardMediaImageUrl(item.asset.content_url);
      return imageUrl === null ? [] : [{
        id: item.asset.id,
        name: item.asset.display_name,
        image_url: imageUrl,
        width: item.asset.width,
        height: item.asset.height,
      }];
    });

  return {
    media_playlist_id: playlist.id,
    media_playlist_version: playlist.version,
    item_duration_seconds: duration,
    total_duration_seconds: items.length * duration,
    items,
  };
}

export function normalizeWallboardMediaPageStates(value: unknown): Record<string, WallboardMediaPageState> {
  if (!isRecord(value)) return {};

  return Object.entries(value).reduce<Record<string, WallboardMediaPageState>>((pages, [pageId, candidate]) => {
    if (!/^[A-Za-z0-9][A-Za-z0-9_-]{0,127}$/.test(pageId) || !isRecord(candidate)) return pages;
    const mediaPlaylistId = typeof candidate.media_playlist_id === 'string'
      ? candidate.media_playlist_id.trim().toUpperCase()
      : '';
    const mediaPlaylistVersion = candidate.media_playlist_version;
    const itemDurationSeconds = candidate.item_duration_seconds;
    if (
      !/^[0-9A-HJKMNP-TV-Z]{26}$/.test(mediaPlaylistId)
      || typeof mediaPlaylistVersion !== 'number'
      || !Number.isInteger(mediaPlaylistVersion)
      || mediaPlaylistVersion < 1
      || typeof itemDurationSeconds !== 'number'
      || !Number.isInteger(itemDurationSeconds)
      || itemDurationSeconds < WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS
      || itemDurationSeconds > WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS
      || !Array.isArray(candidate.items)
    ) return pages;

    const items = candidate.items.slice(0, WALLBOARD_MEDIA_MAX_PLAYLIST_ITEMS).flatMap((item): WallboardMediaPageStateItem[] => {
      if (!isRecord(item)) return [];
      const imageUrl = wallboardMediaImageUrl(item.image_url);
      const id = typeof item.id === 'string' ? item.id.trim().toUpperCase() : '';
      const name = typeof item.name === 'string' ? item.name.trim().slice(0, 120) : '';
      const width = item.width;
      const height = item.height;
      if (
        imageUrl === null
        || !/^[0-9A-HJKMNP-TV-Z]{26}$/.test(id)
        || name === ''
        || typeof width !== 'number'
        || !Number.isInteger(width)
        || width < 1
        || width > 32768
        || typeof height !== 'number'
        || !Number.isInteger(height)
        || height < 1
        || height > 32768
      ) return [];
      return [{ id, name, image_url: imageUrl, width, height }];
    });
    if (items.length === 0) return pages;

    pages[pageId] = {
      media_playlist_id: mediaPlaylistId,
      media_playlist_version: mediaPlaylistVersion,
      item_duration_seconds: itemDurationSeconds,
      total_duration_seconds: items.length * itemDurationSeconds,
      items,
    };
    return pages;
  }, {});
}

export function wallboardPhotoItemDurationSeconds(value: unknown): number {
  const parsed = typeof value === 'number' ? value : Number(value);
  if (!Number.isFinite(parsed)) return 12;
  return Math.min(
    WALLBOARD_PHOTO_MAX_ITEM_DURATION_SECONDS,
    Math.max(WALLBOARD_PHOTO_MIN_ITEM_DURATION_SECONDS, Math.round(parsed)),
  );
}

export function wallboardPhotoPageDurationSeconds(itemCount: number, itemDurationSeconds: unknown): number {
  const safeCount = Math.max(0, Math.min(WALLBOARD_MEDIA_MAX_PLAYLIST_ITEMS, Math.trunc(itemCount)));
  return safeCount * wallboardPhotoItemDurationSeconds(itemDurationSeconds);
}

export function wallboardPhotoPageIsWithinDurationLimit(itemCount: number, itemDurationSeconds: unknown): boolean {
  const duration = wallboardPhotoPageDurationSeconds(itemCount, itemDurationSeconds);
  return itemCount > 0 && duration <= WALLBOARD_PHOTO_MAX_PAGE_DURATION_SECONDS;
}

export function wallboardMediaFormatBytes(bytes: number): string {
  const safeBytes = Number.isFinite(bytes) ? Math.max(0, bytes) : 0;
  if (safeBytes < 1024) return `${Math.round(safeBytes)} B`;
  if (safeBytes < 1024 * 1024) return `${(safeBytes / 1024).toFixed(1)} kB`;
  return `${(safeBytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function wallboardMediaFileValidationMessage(file: File): string | null {
  if (!(WALLBOARD_MEDIA_ACCEPTED_TYPES as readonly string[]).includes(file.type)) {
    return 'Gebruik een JPEG-, PNG- of WebP-afbeelding.';
  }
  if (file.size <= 0) return 'De geselecteerde afbeelding is leeg.';
  if (file.size > WALLBOARD_MEDIA_MAX_UPLOAD_BYTES) return 'De afbeelding mag maximaal 15 MB groot zijn.';
  return null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
