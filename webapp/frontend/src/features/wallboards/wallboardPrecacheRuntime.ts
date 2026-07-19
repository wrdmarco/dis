import type { WallboardPage } from '../../types/api';
import type {
  WallboardPrecacheManifest,
  WallboardPrecacheProgress,
} from './wallboardPrecache';
import type { WallboardPreloadStatus } from './wallboardPreloadProgress';

export interface WallboardPreloadRuntimeState {
  status: WallboardPreloadStatus;
  contentVersion: string | null;
  completed: number;
  total: number;
  pagesReady: number;
  pagesTotal: number;
  onlineOnlyPages: number;
  currentLabel: string | null;
  errorText: string | null;
}

export interface WallboardPrecacheGateInput {
  maintenanceActive: boolean;
  focusVisible: boolean;
  transientAlertVisible: boolean;
  precacheReady: boolean;
}

export function initialWallboardPreloadRuntimeState(): WallboardPreloadRuntimeState {
  return {
    status: 'idle',
    contentVersion: null,
    completed: 0,
    total: 0,
    pagesReady: 0,
    pagesTotal: 0,
    onlineOnlyPages: 0,
    currentLabel: null,
    errorText: null,
  };
}

export function wallboardPrecacheBlocksPlaylist({
  maintenanceActive,
  focusVisible,
  transientAlertVisible,
  precacheReady,
}: WallboardPrecacheGateInput): boolean {
  return !maintenanceActive && !focusVisible && !transientAlertVisible && !precacheReady;
}

export function wallboardPreloadRuntimeState(
  progress: WallboardPrecacheProgress,
  manifest: WallboardPrecacheManifest,
  pages: WallboardPage[],
  status: WallboardPreloadStatus = progress.phase === 'ready'
    ? 'ready'
    : progress.phase === 'failed'
      ? 'error'
      : 'loading',
): WallboardPreloadRuntimeState {
  const completedUrls = new Set(progress.completedUrls);
  const configuredPages = pages;
  const onlineOnlyPageIds = new Set(manifest.externalPreloadHints.map((hint) => hint.pageId));
  const locallyPreparedPages = configuredPages.filter((page) => !onlineOnlyPageIds.has(page.id));
  const currentAsset = progress.currentUrl === null
    ? null
    : manifest.assets.find((asset) => asset.url === progress.currentUrl) ?? null;
  const currentPage = currentAsset === null
    ? null
    : configuredPages.find((page) => currentAsset.pageIds.includes(page.id)) ?? null;

  return {
    status,
    contentVersion: manifest.contentVersion,
    completed: progress.completed,
    total: progress.total,
    pagesReady: locallyPreparedPages.filter((page) => {
      const urls = manifest.assets
        .filter((asset) => asset.pageIds.includes(page.id))
        .map((asset) => asset.url);
      return urls.every((url) => completedUrls.has(url));
    }).length,
    pagesTotal: locallyPreparedPages.length,
    onlineOnlyPages: onlineOnlyPageIds.size,
    currentLabel: currentPage?.name ?? null,
    errorText: progress.failures.at(-1)?.message ?? null,
  };
}
