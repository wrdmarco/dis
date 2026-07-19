export interface WallboardPhotoCarouselClock {
  anchorEpochMs: number;
  nowEpochMs: number;
  accumulatedPauseMs: number;
  pausedAtEpochMs: number | null;
}

export function wallboardPhotoCarouselClock(
  anchor: string | number | Date | null | undefined,
  running: boolean,
  nowEpochMs = Date.now(),
): WallboardPhotoCarouselClock {
  const parsedAnchor = anchor instanceof Date
    ? anchor.getTime()
    : typeof anchor === 'string'
      ? Date.parse(anchor)
      : anchor;
  const anchorEpochMs = typeof parsedAnchor === 'number' && Number.isFinite(parsedAnchor)
    ? parsedAnchor
    : nowEpochMs;

  return {
    anchorEpochMs: Math.min(anchorEpochMs, nowEpochMs),
    nowEpochMs,
    accumulatedPauseMs: 0,
    pausedAtEpochMs: running ? null : nowEpochMs,
  };
}

export function wallboardPhotoCarouselAnchorFromDeadline(
  nextChangeAt: string | null | undefined,
  totalDurationSeconds: number,
): number | null {
  if (typeof nextChangeAt !== 'string') return null;
  const deadlineEpochMs = Date.parse(nextChangeAt);
  const durationMs = Math.round(totalDurationSeconds * 1000);
  if (!Number.isFinite(deadlineEpochMs) || !Number.isFinite(durationMs) || durationMs <= 0) return null;
  return deadlineEpochMs - durationMs;
}

export function wallboardPhotoCarouselTransition(
  clock: WallboardPhotoCarouselClock,
  running: boolean,
  nowEpochMs: number,
): WallboardPhotoCarouselClock {
  const safeNow = Number.isFinite(nowEpochMs) ? Math.max(clock.nowEpochMs, nowEpochMs) : clock.nowEpochMs;
  if (!running && clock.pausedAtEpochMs === null) {
    return { ...clock, nowEpochMs: safeNow, pausedAtEpochMs: safeNow };
  }
  if (running && clock.pausedAtEpochMs !== null) {
    return {
      ...clock,
      nowEpochMs: safeNow,
      accumulatedPauseMs: clock.accumulatedPauseMs + Math.max(0, safeNow - clock.pausedAtEpochMs),
      pausedAtEpochMs: null,
    };
  }
  return { ...clock, nowEpochMs: safeNow };
}

export function wallboardPhotoCarouselElapsedMs(clock: WallboardPhotoCarouselClock): number {
  const effectiveNow = clock.pausedAtEpochMs ?? clock.nowEpochMs;
  return Math.max(0, effectiveNow - clock.anchorEpochMs - clock.accumulatedPauseMs);
}

export function wallboardPhotoCarouselIndex(
  clock: WallboardPhotoCarouselClock,
  itemCount: number,
  itemDurationSeconds: number,
): number {
  const count = Math.max(0, Math.trunc(itemCount));
  const durationMs = Math.max(1, Math.round(itemDurationSeconds * 1000));
  if (count <= 1) return 0;
  return Math.floor(wallboardPhotoCarouselElapsedMs(clock) / durationMs) % count;
}

export function wallboardPhotoCarouselNextDelayMs(
  clock: WallboardPhotoCarouselClock,
  itemDurationSeconds: number,
): number {
  const durationMs = Math.max(1, Math.round(itemDurationSeconds * 1000));
  const elapsedInItem = wallboardPhotoCarouselElapsedMs(clock) % durationMs;
  return Math.max(1, durationMs - elapsedInItem);
}
