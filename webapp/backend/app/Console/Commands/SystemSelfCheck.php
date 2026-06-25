<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class SystemSelfCheck extends Command
{
    protected $signature = 'dis:self-check';

    protected $description = 'Run local DIS operational self-checks for deployment and monitoring.';

    public function handle(): int
    {
        DB::connection()->getPdo();
        Cache::put('self-check', 'ok', 30);
        Storage::disk('local')->put('self-check.txt', 'ok');
        $storageOk = Storage::disk('local')->get('self-check.txt') === 'ok';
        Storage::disk('local')->delete('self-check.txt');

        if (Cache::get('self-check') !== 'ok' || ! $storageOk) {
            $this->error('DIS self-check failed.');
            return self::FAILURE;
        }

        $this->info('DIS self-check passed.');

        return self::SUCCESS;
    }
}

