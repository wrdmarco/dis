import type { WallboardPage } from '../../types/api';
import type { WallboardMediaAsset } from './wallboardMedia';
import {
  normalizeWallboardMediaAssetId,
  wallboardLocalVideoUrl,
} from './wallboardPresentation';

export type WallboardVideoSource = 'upload' | 'external';

export const WALLBOARD_VIDEO_PROCESSING_POLL_INTERVAL_MILLISECONDS = 5_000;
export const WALLBOARD_VIDEO_PROCESSING_POLL_MAX_ATTEMPTS = 720;

export function wallboardVideoPageForSource(
  page: WallboardPage,
  source: WallboardVideoSource,
): WallboardPage {
  return {
    ...page,
    options: source === 'external' ? { url: '' } : {},
  };
}

export function wallboardVideoPageForAsset(page: WallboardPage, asset: WallboardMediaAsset): WallboardPage {
  const normalizedId = normalizeWallboardMediaAssetId(asset.id);
  const url = wallboardLocalVideoUrl(normalizedId);
  if (!isSelectableMp4(asset) || asset.duration_seconds === null || url === null) {
    return { ...page, options: {} };
  }

  return {
    ...page,
    duration_seconds: Math.min(3600, asset.duration_seconds + 5),
    options: {
      media_asset_id: normalizedId,
      url,
      video_duration_seconds: asset.duration_seconds,
    },
  };
}

export function isSelectableMp4(asset: WallboardMediaAsset): boolean {
  return wallboardLocalVideoUrl(normalizeWallboardMediaAssetId(asset.id)) !== null
    && asset.status === 'ready'
    && asset.kind === 'video'
    && asset.mime_type === 'video/mp4'
    && typeof asset.duration_seconds === 'number'
    && Number.isInteger(asset.duration_seconds)
    && asset.duration_seconds >= 1
    && asset.duration_seconds <= 3595;
}

export function wallboardVideoProcessingProgress(asset: WallboardMediaAsset): number | null {
  if (
    asset.status !== 'processing'
    || typeof asset.processing_progress !== 'number'
    || !Number.isFinite(asset.processing_progress)
  ) return null;

  return Math.min(99, Math.max(0, Math.round(asset.processing_progress)));
}

export function shouldPollWallboardVideoAssets(
  hasProcessingAsset: boolean,
  completedAttempts: number,
): boolean {
  return Number.isInteger(completedAttempts)
    && completedAttempts >= 0
    && completedAttempts < WALLBOARD_VIDEO_PROCESSING_POLL_MAX_ATTEMPTS
    && hasProcessingAsset;
}

export function shouldRunWallboardVideoProcessingPoll(
  hasProcessingAsset: boolean,
  completedAttempts: number,
  documentVisible: boolean,
  foregroundRequestCount: number,
  pollInFlight: boolean,
): boolean {
  return shouldPollWallboardVideoAssets(hasProcessingAsset, completedAttempts)
    && documentVisible
    && Number.isInteger(foregroundRequestCount)
    && foregroundRequestCount === 0
    && !pollInFlight;
}
