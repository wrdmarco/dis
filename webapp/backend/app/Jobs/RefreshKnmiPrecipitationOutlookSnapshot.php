<?php

namespace App\Jobs;

use App\Services\KnmiPrecipitationImportService;
use App\Services\KnmiPrecipitationOutlookService;
use App\Services\WallboardForecastLocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshKnmiPrecipitationOutlookSnapshot implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 540;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection('knmi_realtime');
        $this->onQueue('knmi-realtime');
    }

    public function uniqueId(): string
    {
        return 'knmi-precipitation-outlook';
    }

    public function handle(
        KnmiPrecipitationImportService $imports,
        WallboardForecastLocationService $locations,
        KnmiPrecipitationOutlookService $outlooks,
    ): void {
        $imports->refresh();
        $outlooks->prewarmResolution($locations->resolve([
            'location_mode' => WallboardForecastLocationService::MODE_NETHERLANDS,
        ]));
    }
}
