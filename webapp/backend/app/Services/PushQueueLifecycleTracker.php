<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Repositories\PushQueueWorkItemRepository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Str;

final class PushQueueLifecycleTracker
{
    /** @var array<string, string> */
    private const SAFE_MESSAGE_TYPES = [
        'device_presence_ping' => 'device_presence_ping',
        'dispatch_request' => 'dispatch_request',
        'dispatch_response_sync' => 'dispatch_response_sync',
        'dispatch_update' => 'dispatch_update',
        'incident_cancelled' => 'incident_cancelled',
        'incident_preannouncement' => 'incident_preannouncement',
        'location_share_request' => 'location_share_request',
        'location_sharing_stopped' => 'location_sharing_stopped',
        'manual_admin' => 'manual_admin',
        'session_revoked' => 'session_revoked',
    ];

    public function __construct(
        private readonly PushQueueWorkItemRepository $workItems,
        private readonly PushQueueLifecyclePolicy $policy,
    ) {}

    public function queued(JobQueued $event): void
    {
        if (! $this->isQueuedPushNotification($event)) {
            return;
        }

        /** @var SendFcmNotification $notification */
        $notification = $event->job;
        $this->workItems->queued(
            $this->opaqueJobId($event->id),
            self::SAFE_MESSAGE_TYPES[$notification->messageType] ?? 'push_notification',
            $this->safeOutboxId($notification->dispatchPushOutboxId),
            is_int($event->delay) ? $event->delay : null,
        );
    }

    public function processing(JobProcessing $event): void
    {
        if (! $this->isRuntimePushNotification($event->connectionName, $event->job)) {
            return;
        }

        $this->workItems->processing(
            $this->opaqueJobId($event->job->getJobId()),
            max(1, (int) $event->job->attempts()),
        );
    }

    public function retrying(JobReleasedAfterException $event): void
    {
        if (! $this->isRuntimePushNotification($event->connectionName, $event->job)) {
            return;
        }

        $this->workItems->retrying(
            $this->opaqueJobId($event->job->getJobId()),
            max(1, (int) $event->job->attempts()),
            is_int($event->backoff) ? $event->backoff : null,
        );
    }

    public function processed(JobProcessed $event): void
    {
        if (! $this->isRuntimePushNotification($event->connectionName, $event->job)) {
            return;
        }

        $this->workItems->completed(
            $this->opaqueJobId($event->job->getJobId()),
            max(1, (int) $event->job->attempts()),
        );
    }

    public function failed(JobFailed $event): void
    {
        if (! $this->isRuntimePushNotification($event->connectionName, $event->job)) {
            return;
        }

        $this->workItems->failed(
            $this->opaqueJobId($event->job->getJobId()),
            max(1, (int) $event->job->attempts()),
            $event->exception instanceof TimeoutExceededException
                ? 'queue_job_timeout_exhausted'
                : 'queue_job_failed',
        );
    }

    public function timedOut(JobTimedOut $event): void
    {
        if (! $this->isRuntimePushNotification($event->connectionName, $event->job)) {
            return;
        }

        $jobId = $this->opaqueJobId($event->job->getJobId());
        $attempts = max(1, (int) $event->job->attempts());
        if ($this->policy->timedOutJobIsExhausted($event->job)) {
            $this->workItems->failed(
                $jobId,
                $attempts,
                'queue_job_timeout_exhausted',
            );

            return;
        }

        $this->workItems->retrying(
            $jobId,
            $attempts,
            $this->policy->timeoutVisibilityDelaySeconds($event->job),
            'queue_timeout_retry_scheduled',
        );
    }

    private function isQueuedPushNotification(JobQueued $event): bool
    {
        return $event->connectionName === 'push'
            && $event->queue === 'push'
            && $event->job instanceof SendFcmNotification
            && $event->id !== null;
    }

    private function isRuntimePushNotification(string $connectionName, Job $job): bool
    {
        return $connectionName === 'push'
            && $job->getQueue() === 'push'
            && $job->resolveName() === SendFcmNotification::class
            && $job->getJobId() !== null;
    }

    private function opaqueJobId(string|int|null $jobId): string
    {
        return hash('sha256', (string) $jobId);
    }

    private function safeOutboxId(?string $outboxId): ?string
    {
        return is_string($outboxId) && Str::isUlid($outboxId) ? $outboxId : null;
    }
}
