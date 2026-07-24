export const QUEUE_DEFAULT_POLL_INTERVAL_MS = 5_000;
export const QUEUE_MIN_POLL_INTERVAL_MS = 2_000;
export const QUEUE_MAX_POLL_INTERVAL_MS = 60_000;

interface QueuePollingOptions<TimerHandle> {
  load: () => Promise<number | null | undefined>;
  isHidden: () => boolean;
  schedule: (callback: () => void, delayMs: number) => TimerHandle;
  cancel: (handle: TimerHandle) => void;
  subscribeVisibility: (listener: () => void) => () => void;
  onError?: (error: unknown) => void;
  onSettled?: () => void;
}

export function queuePollIntervalMs(refreshAfterSeconds: number | null | undefined): number {
  if (typeof refreshAfterSeconds !== 'number' || !Number.isFinite(refreshAfterSeconds)) {
    return QUEUE_DEFAULT_POLL_INTERVAL_MS;
  }

  return Math.min(
    QUEUE_MAX_POLL_INTERVAL_MS,
    Math.max(QUEUE_MIN_POLL_INTERVAL_MS, Math.round(refreshAfterSeconds * 1_000)),
  );
}

export function startQueuePolling<TimerHandle>(
  options: QueuePollingOptions<TimerHandle>,
): () => void {
  let stopped = false;
  let inFlight = false;
  let timer: TimerHandle | undefined;
  let intervalMs = QUEUE_DEFAULT_POLL_INTERVAL_MS;

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

    timer = options.schedule(() => {
      timer = undefined;
      void load();
    }, intervalMs);
  };

  const load = async () => {
    if (stopped || inFlight || options.isHidden()) {
      return;
    }

    inFlight = true;
    try {
      intervalMs = queuePollIntervalMs(await options.load());
    } catch (error) {
      if (!stopped) {
        options.onError?.(error);
      }
    } finally {
      inFlight = false;
      if (!stopped) {
        options.onSettled?.();
        scheduleNext();
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

  return () => {
    stopped = true;
    clearTimer();
    unsubscribeVisibility();
  };
}
