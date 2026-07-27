<?php

namespace App\Console\Commands;

use App\Models\PilotDeploymentReport;
use App\Services\PilotDeploymentReportDroneSnapshotService;
use App\Services\PilotDeploymentReportFormService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class BackfillPilotReportDroneSnapshots extends Command
{
    protected $signature = 'dis:backfill-pilot-report-drone-snapshots {--batch=50 : Maximum reports to snapshot}';

    protected $description = 'Backfill immutable drone type snapshots for existing pilot reports';

    public function handle(
        PilotDeploymentReportDroneSnapshotService $snapshotService,
        PilotDeploymentReportFormService $formService,
    ): int {
        $batchOption = $this->option('batch');
        if (! is_string($batchOption)
            || preg_match('/^[1-9][0-9]{0,2}$/', $batchOption) !== 1
            || (int) $batchOption > 100) {
            $this->error('The --batch option must be an integer from 1 through 100.');

            return self::INVALID;
        }
        $fieldKeys = $formService->droneFieldKeys();
        $reports = PilotDeploymentReport::query()
            ->whereNull('drone_usage_snapshot')
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit((int) $batchOption)
            ->get(['id', 'custom_fields']);

        $updated = 0;
        foreach ($reports as $report) {
            $snapshots = $snapshotService->capture(
                is_array($report->custom_fields) ? $report->custom_fields : [],
                [],
                $fieldKeys,
            );
            $updated += DB::table('pilot_deployment_reports')
                ->where('id', $report->id)
                ->whereNull('drone_usage_snapshot')
                ->update([
                    'drone_usage_snapshot' => json_encode($snapshots, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                ]);
        }

        $this->info(sprintf('Stored immutable drone snapshots for %d pilot report(s).', $updated));

        return self::SUCCESS;
    }
}
