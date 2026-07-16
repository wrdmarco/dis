export const SYSTEM_METRICS_POLL_INTERVAL_MS = 3_000;

interface SystemMetricsPollingOptions<TimerHandle> {
  load: () => Promise<void>;
  isHidden: () => boolean;
  schedule: (callback: () => void, delayMs: number) => TimerHandle;
  cancel: (handle: TimerHandle) => void;
  subscribeVisibility: (listener: () => void) => () => void;
  onError?: (error: unknown) => void;
  onSettled?: () => void;
  intervalMs?: number;
}

export function startSystemMetricsPolling<TimerHandle>(
  options: SystemMetricsPollingOptions<TimerHandle>,
): () => void {
  const intervalMs = options.intervalMs ?? SYSTEM_METRICS_POLL_INTERVAL_MS;
  let stopped = false;
  let inFlight = false;
  let timer: TimerHandle | undefined;

  const clearScheduledPoll = () => {
    if (timer !== undefined) {
      options.cancel(timer);
      timer = undefined;
    }
  };

  const scheduleNextPoll = () => {
    clearScheduledPoll();
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
      await options.load();
    } catch (error) {
      if (!stopped) {
        options.onError?.(error);
      }
    } finally {
      inFlight = false;
      if (!stopped) {
        options.onSettled?.();
        scheduleNextPoll();
      }
    }
  };

  const handleVisibilityChange = () => {
    clearScheduledPoll();
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
    clearScheduledPoll();
    unsubscribeVisibility();
  };
}
