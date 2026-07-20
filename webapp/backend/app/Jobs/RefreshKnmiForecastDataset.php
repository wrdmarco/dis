<?php

namespace App\Jobs;

use App\Services\KnmiForecastImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RefreshKnmiForecastDataset implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // 3,000s download + 61 x 30s GRIB validation leaves 2,370s for
    // metadata, extraction, hashing and atomic activation.
    public int $timeout = 7200;

    public int $uniqueFor = 10800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $operationId)
    {
        $this->onConnection('knmi');
        $this->onQueue('knmi');
    }

    public function uniqueId(): string
    {
        return 'knmi-forecast:'.$this->operationId;
    }

    public function handle(KnmiForecastImportService $imports): void
    {
        $imports->run($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        app(KnmiForecastImportService::class)->failFromWorker($this->operationId);
    }
}
