<?php

namespace App\Console\Commands;

use App\Services\KnmiForecastRestoreService;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileKnmiAfterRestore extends Command
{
    protected $signature = 'dis:reconcile-knmi-after-restore';

    protected $description = 'Clear unrestored KNMI cache metadata and queue a fresh modelset after backup restore';

    public function handle(KnmiForecastRestoreService $restore): int
    {
        try {
            $result = $restore->reconcile();
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('KNMI-cache kon na backupherstel niet veilig worden hersteld.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'KNMI-cachemetadata gewist: %d bewerking(en), %d modelset(s).',
            $result['operations_cleared'],
            $result['snapshots_cleared'],
        ));
        if ($result['refresh_operation_id'] === null) {
            $this->components->info('Geen KNMI-update ingepland: Open Data is niet geconfigureerd.');

            return self::SUCCESS;
        }

        $this->components->info('Nieuwe KNMI-update ingepland: '.$result['refresh_operation_id']);

        return self::SUCCESS;
    }
}
