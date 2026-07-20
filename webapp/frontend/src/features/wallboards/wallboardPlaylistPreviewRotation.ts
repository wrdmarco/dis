export interface WallboardPlaylistPreviewRotationState {
  pageIndex: number;
  remainingMilliseconds: number;
  deadlineEpochMilliseconds: number | null;
  pageStartedAtEpochMilliseconds: number;
  sequence: number;
}

export function createWallboardPlaylistPreviewRotation(
  pageDurationsMilliseconds: readonly number[],
  running: boolean,
  now = Date.now(),
): WallboardPlaylistPreviewRotationState {
  const remainingMilliseconds = previewPageDuration(pageDurationsMilliseconds, 0);

  return {
    pageIndex: 0,
    remainingMilliseconds,
    deadlineEpochMilliseconds: running ? now + remainingMilliseconds : null,
    pageStartedAtEpochMilliseconds: now,
    sequence: 0,
  };
}

export function resumeWallboardPlaylistPreviewRotation(
  state: WallboardPlaylistPreviewRotationState,
  now = Date.now(),
): WallboardPlaylistPreviewRotationState {
  if (state.deadlineEpochMilliseconds !== null) return state;

  return {
    ...state,
    deadlineEpochMilliseconds: now + Math.max(1, state.remainingMilliseconds),
  };
}

export function pauseWallboardPlaylistPreviewRotation(
  state: WallboardPlaylistPreviewRotationState,
  now = Date.now(),
): WallboardPlaylistPreviewRotationState {
  if (state.deadlineEpochMilliseconds === null) return state;

  return {
    ...state,
    remainingMilliseconds: Math.max(1, state.deadlineEpochMilliseconds - now),
    deadlineEpochMilliseconds: null,
  };
}

export function advanceWallboardPlaylistPreviewRotation(
  state: WallboardPlaylistPreviewRotationState,
  pageDurationsMilliseconds: readonly number[],
  running: boolean,
  now = Date.now(),
): WallboardPlaylistPreviewRotationState {
  const pageCount = pageDurationsMilliseconds.length;
  const pageIndex = pageCount <= 0 ? 0 : (state.pageIndex + 1) % pageCount;
  const remainingMilliseconds = previewPageDuration(pageDurationsMilliseconds, pageIndex);

  return {
    pageIndex,
    remainingMilliseconds,
    deadlineEpochMilliseconds: running ? now + remainingMilliseconds : null,
    pageStartedAtEpochMilliseconds: now,
    sequence: state.sequence + 1,
  };
}

export function selectWallboardPlaylistPreviewPage(
  state: WallboardPlaylistPreviewRotationState,
  pageIndex: number,
  pageDurationsMilliseconds: readonly number[],
  running: boolean,
  now = Date.now(),
): WallboardPlaylistPreviewRotationState {
  const safeIndex = pageDurationsMilliseconds.length <= 0
    ? 0
    : Math.min(pageDurationsMilliseconds.length - 1, Math.max(0, Math.floor(pageIndex)));
  const remainingMilliseconds = previewPageDuration(pageDurationsMilliseconds, safeIndex);

  return {
    pageIndex: safeIndex,
    remainingMilliseconds,
    deadlineEpochMilliseconds: running ? now + remainingMilliseconds : null,
    pageStartedAtEpochMilliseconds: now,
    sequence: state.sequence + 1,
  };
}

export function wallboardPlaylistPreviewRemainingMilliseconds(
  state: WallboardPlaylistPreviewRotationState,
  now = Date.now(),
): number {
  return state.deadlineEpochMilliseconds === null
    ? Math.max(1, state.remainingMilliseconds)
    : Math.max(0, state.deadlineEpochMilliseconds - now);
}

function previewPageDuration(pageDurationsMilliseconds: readonly number[], pageIndex: number): number {
  const value = pageDurationsMilliseconds[pageIndex];
  return Number.isFinite(value) ? Math.max(1, Math.round(value)) : 1;
}
