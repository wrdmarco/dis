export const ADMIN_SYSTEM_LOG_POLL_INTERVAL_MS = 2_000;
export const ADMIN_SYSTEM_LOG_MIN_POLL_INTERVAL_MS = 1_000;
export const ADMIN_SYSTEM_LOG_MAX_POLL_INTERVAL_MS = 30_000;
export const ADMIN_SYSTEM_LOG_CLIENT_LINE_LIMIT = 2_000;
const DUTCH_DECIMAL = new Intl.NumberFormat('nl-NL', { maximumFractionDigits: 1 });

export interface AdminSystemLogLineWindow {
  lines: string[];
  truncated: boolean;
}

export function adminSystemLogPath(
  filename: string,
  cursor = 0,
  generation = '',
  checkpoint = '',
  latest = false,
): string {
  const source = latest ? 'latest' : encodeURIComponent(filename);
  const path = `/admin/system/logs/${source}`;
  if (generation === '' || checkpoint === '') {
    return path;
  }

  const safeCursor = Number.isFinite(cursor) ? Math.max(0, Math.floor(cursor)) : 0;

  return `${path}?cursor=${encodeURIComponent(String(safeCursor))}&generation=${encodeURIComponent(generation)}&checkpoint=${encodeURIComponent(checkpoint)}`;
}

export function adminSystemLogChunkRequiresReset(
  currentGeneration: string,
  nextGeneration: string,
  serverReset: boolean,
): boolean {
  return serverReset
    || (currentGeneration !== '' && currentGeneration !== nextGeneration);
}

export function mergeAdminSystemLogLines(
  current: string[],
  incoming: string[],
  reset: boolean,
  limit = ADMIN_SYSTEM_LOG_CLIENT_LINE_LIMIT,
): AdminSystemLogLineWindow {
  if (!reset && incoming.length === 0) {
    return { lines: current, truncated: false };
  }

  const safeLimit = Number.isFinite(limit) ? Math.max(1, Math.floor(limit)) : ADMIN_SYSTEM_LOG_CLIENT_LINE_LIMIT;
  const combined = reset ? incoming : [...current, ...incoming];
  const truncated = combined.length > safeLimit;

  return {
    lines: truncated ? combined.slice(-safeLimit) : combined,
    truncated,
  };
}

export function normalizeAdminSystemLogPollInterval(value: number): number {
  if (!Number.isFinite(value)) {
    return ADMIN_SYSTEM_LOG_POLL_INTERVAL_MS;
  }

  return Math.min(
    ADMIN_SYSTEM_LOG_MAX_POLL_INTERVAL_MS,
    Math.max(ADMIN_SYSTEM_LOG_MIN_POLL_INTERVAL_MS, Math.round(value)),
  );
}

export function adminSystemLogShouldFollow(
  scrollHeight: number,
  scrollTop: number,
  clientHeight: number,
  threshold = 80,
): boolean {
  const safeThreshold = Number.isFinite(threshold) ? Math.max(0, threshold) : 80;

  return scrollHeight - scrollTop - clientHeight <= safeThreshold;
}

export function formatAdminSystemLogBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes < 0) {
    return '-';
  }

  if (bytes < 1_024) {
    return `${Math.round(bytes)} B`;
  }

  const units = ['KiB', 'MiB', 'GiB', 'TiB'];
  let value = bytes / 1_024;
  let unitIndex = 0;
  while (value >= 1_024 && unitIndex < units.length - 1) {
    value /= 1_024;
    unitIndex += 1;
  }

  return `${DUTCH_DECIMAL.format(value)} ${units[unitIndex]}`;
}

interface AdminSystemLogPollingOptions<TimerHandle> {
  load: () => Promise<void>;
  isHidden: () => boolean;
  schedule: (callback: () => void, delayMs: number) => TimerHandle;
  cancel: (handle: TimerHandle) => void;
  subscribeVisibility: (listener: () => void) => () => void;
  onError?: (error: unknown) => void;
  onSettled?: () => void;
  intervalMs?: number | (() => number);
}

export interface AdminSystemLogPollingController {
  refresh: () => void;
  stop: () => void;
}

export function startAdminSystemLogPolling<TimerHandle>(
  options: AdminSystemLogPollingOptions<TimerHandle>,
): AdminSystemLogPollingController {
  let stopped = false;
  let inFlight = false;
  let refreshPending = false;
  let timer: TimerHandle | undefined;

  const clearTimer = () => {
    if (timer !== undefined) {
      options.cancel(timer);
      timer = undefined;
    }
  };

  const scheduleNext = () => {
    clearTimer();
    if (stopped || options.isHidden()) {
      return;
    }

    const configuredInterval = typeof options.intervalMs === 'function'
      ? options.intervalMs()
      : options.intervalMs ?? ADMIN_SYSTEM_LOG_POLL_INTERVAL_MS;
    timer = options.schedule(() => {
      timer = undefined;
      void load();
    }, normalizeAdminSystemLogPollInterval(configuredInterval));
  };

  const load = async () => {
    if (stopped || options.isHidden()) {
      return;
    }
    if (inFlight) {
      refreshPending = true;
      return;
    }

    clearTimer();
    inFlight = true;
    try {
      await options.load();
    } catch (error) {
      if (!stopped) {
        options.onError?.(error);
      }
    } finally {
      inFlight = false;
      if (!stopped) {
        options.onSettled?.();
        if (refreshPending && !options.isHidden()) {
          refreshPending = false;
          void load();
        } else {
          refreshPending = false;
          scheduleNext();
        }
      }
    }
  };

  const handleVisibilityChange = () => {
    clearTimer();
    if (!options.isHidden()) {
      void load();
    }
  };

  const unsubscribeVisibility = options.subscribeVisibility(handleVisibilityChange);
  if (!options.isHidden()) {
    void load();
  }

  return {
    refresh: () => {
      void load();
    },
    stop: () => {
      stopped = true;
      refreshPending = false;
      clearTimer();
      unsubscribeVisibility();
    },
  };
}
