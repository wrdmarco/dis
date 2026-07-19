export type WallboardPreloadStatus = 'idle' | 'loading' | 'retrying' | 'ready' | 'error';

export interface WallboardPreloadProgressInput {
  status: WallboardPreloadStatus;
  completed: number;
  total: number;
  pagesReady: number;
  pagesTotal: number;
}

const TECHNICAL_LOCATION_PATTERN = /(?:https?|blob|data|file):(?:\/\/)?\S+|\bwww\.\S+/i;

function boundedCount(value: number): number {
  if (!Number.isFinite(value)) return 0;
  return Math.max(0, Math.floor(value));
}

export function boundedWallboardPreloadCount(value: number): number {
  return boundedCount(value);
}

export function wallboardPreloadPercentage({
  status,
  completed,
  total,
  pagesReady,
  pagesTotal,
}: WallboardPreloadProgressInput): number {
  if (status === 'ready') return 100;
  if (status === 'idle') return 0;

  const safeCompleted = boundedCount(completed);
  const safeTotal = boundedCount(total);
  const safePagesReady = boundedCount(pagesReady);
  const safePagesTotal = boundedCount(pagesTotal);
  const ratio = safeTotal > 0
    ? safeCompleted / safeTotal
    : safePagesTotal > 0
      ? safePagesReady / safePagesTotal
      : 0;

  return Math.min(100, Math.max(0, Math.round(ratio * 100)));
}

export function safeWallboardPreloadText(
  value: string | null | undefined,
  fallback: string,
): string {
  const normalized = value?.trim() ?? '';
  if (normalized === '' || TECHNICAL_LOCATION_PATTERN.test(normalized)) return fallback;
  return normalized.slice(0, 160);
}
