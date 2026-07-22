<?php

namespace App\Jobs;

use App\Services\EumetsatLightningImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RefreshEumetsatLightningSnapshot implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public bool $failOnTimeout = true;

    public function __construct()
    {
        $this->onConnection('knmi_realtime');
        $this->onQueue('knmi-realtime');
    }

    public function uniqueId(): string
    {
        return 'eumetsat-lightning-radar';
    }

    public function handle(EumetsatLightningImportService $imports): void
    {
        $imports->refresh();
    }
}
