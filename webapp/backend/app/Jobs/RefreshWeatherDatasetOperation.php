<?php

namespace App\Jobs;

use App\Services\WeatherDatasetOperationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RefreshWeatherDatasetOperation implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1200;

    public int $uniqueFor = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operationId)
    {
        $this->onConnection('knmi_realtime');
        $this->onQueue('knmi-realtime');
    }

    public function uniqueId(): string
    {
        return 'weather-dataset:'.$this->operationId;
    }

    public function handle(WeatherDatasetOperationService $operations): void
    {
        $operations->run($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(WeatherDatasetOperationService::class)->failFromWorker($this->operationId);
    }
}
