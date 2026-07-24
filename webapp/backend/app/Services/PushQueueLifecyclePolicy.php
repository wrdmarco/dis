<?php

namespace App\Services;

use Illuminate\Contracts\Queue\Job;

final class PushQueueLifecyclePolicy
{
    public function timedOutJobIsExhausted(Job $job): bool
    {
        if ($job->hasFailed()) {
            return true;
        }

        $retryUntil = $job->retryUntil();
        if (is_int($retryUntil)) {
            return $retryUntil <= now()->getTimestamp();
        }

        $maxTries = $job->maxTries();

        return is_int($maxTries)
            && $maxTries > 0
            && (int) $job->attempts() >= $maxTries;
    }

    public function timeoutVisibilityDelaySeconds(Job $job): int
    {
        $retryAfter = max(1, (int) config('queue.connections.push.retry_after', 240));
        $timeout = $job->timeout();
        $workerTimeout = is_int($timeout) && $timeout > 0
            ? $timeout
            : max(
                1,
                (int) config('dis.queue_monitor.queues.push.worker_timeout_seconds', 180),
            );

        // Redis retry_after starts when the job is reserved, while JobTimedOut
        // fires after the worker timeout. Only the remaining reservation delay
        // belongs in next_attempt_at.
        return max(1, $retryAfter - $workerTimeout);
    }

    public function maximumLifecycleSeconds(): int
    {
        $retryAfter = max(1, (int) config('queue.connections.push.retry_after', 240));
        $workerTimeout = max(
            1,
            (int) config('dis.queue_monitor.queues.push.worker_timeout_seconds', 180),
        );
        $maxAttempts = max(
            1,
            (int) config('dis.queue_monitor.queues.push.max_attempts', 4),
        );

        return (($maxAttempts - 1) * $retryAfter) + $workerTimeout;
    }

    public function staleActiveAfterSeconds(): int
    {
        $configured = max(
            1,
            (int) config('dis.queue_monitor.queues.push.stale_active_after_seconds', 7200),
        );

        // Avoid raising a stale warning before four complete worst-case
        // timeout lifecycles have elapsed. The reconciler deliberately keeps
        // the job non-terminal because Redis wait time itself is unbounded.
        return max($configured, $this->maximumLifecycleSeconds() * 4);
    }
}
