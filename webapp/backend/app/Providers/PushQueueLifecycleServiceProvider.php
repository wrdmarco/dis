<?php

namespace App\Providers;

use App\Services\PushQueueLifecycleTracker;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class PushQueueLifecycleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(JobQueued::class, fn (JobQueued $event) => $this->capture('queued', $event));
        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->capture('processing', $event));
        Event::listen(
            JobReleasedAfterException::class,
            fn (JobReleasedAfterException $event) => $this->capture('retrying', $event),
        );
        Event::listen(JobProcessed::class, fn (JobProcessed $event) => $this->capture('processed', $event));
        Event::listen(JobFailed::class, fn (JobFailed $event) => $this->capture('failed', $event));
        Event::listen(JobTimedOut::class, fn (JobTimedOut $event) => $this->capture('timedOut', $event));
    }

    private function capture(string $method, object $event): void
    {
        try {
            $this->app->make(PushQueueLifecycleTracker::class)->{$method}($event);
        } catch (Throwable $exception) {
            // Monitoring is deliberately best-effort. A database or listener
            // failure must never turn an already queued operational push into
            // a failed dispatch call or alter worker acknowledgement.
            try {
                report($exception);
            } catch (Throwable) {
                // Even a secondary logging outage must not escape this listener.
            }
        }
    }
}
