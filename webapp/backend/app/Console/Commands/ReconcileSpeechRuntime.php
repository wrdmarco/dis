<?php

namespace App\Console\Commands;

use App\Services\SpeechRuntimeReconciliationService;
use Illuminate\Console\Command;

final class ReconcileSpeechRuntime extends Command
{
    protected $signature = 'speech:reconcile-runtime';

    protected $description = 'Fail closed and rebuild speech runtime indexes after a data restore.';

    public function handle(SpeechRuntimeReconciliationService $reconciliation): int
    {
        $result = $reconciliation->reconcile();
        $this->components->info(sprintf(
            'Speech runtime reconciled: %d model(s) invalidated, %d audio asset(s) invalidated, regeneration %s.',
            $result['models_invalidated'],
            $result['audio_invalidated'],
            $result['regeneration_queued'] ? 'queued' : 'not queued',
        ));

        return self::SUCCESS;
    }
}
