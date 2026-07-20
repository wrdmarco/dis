<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class KnmiBackupRestoreContractTest extends TestCase
{
    #[Test]
    public function backups_exclude_the_complete_reproducible_knmi_cache_tree(): void
    {
        $backup = $this->repositoryFile('scripts/backup.sh');

        $this->assertStringContainsString(
            "--exclude='webapp/backend/storage/app/knmi-forecast'",
            $backup,
        );
        $this->assertSame(
            1,
            substr_count($backup, "--exclude='webapp/backend/storage/app/knmi-forecast'"),
        );
    }

    #[Test]
    public function restore_reconciles_knmi_state_after_migrations_before_services_restart(): void
    {
        $restore = $this->repositoryFile('scripts/restore.sh');
        $service = $this->repositoryFile('webapp/backend/app/Services/KnmiForecastRestoreService.php');

        $migration = strpos($restore, 'artisan" migrate --force');
        $reconciliation = strpos($restore, 'dis:reconcile-knmi-after-restore');
        $precipitationRefresh = strpos($restore, 'dis:refresh-knmi-precipitation-outlook');
        $serviceRestart = strpos($restore, 'restart_dis_web_services_for_verification');

        $this->assertIsInt($migration);
        $this->assertIsInt($reconciliation);
        $this->assertIsInt($precipitationRefresh);
        $this->assertIsInt($serviceRestart);
        $this->assertLessThan($reconciliation, $migration);
        $this->assertLessThan($precipitationRefresh, $reconciliation);
        $this->assertLessThan($serviceRestart, $precipitationRefresh);
        $this->assertStringContainsString('DB::transaction(function () use ($configured): array', $service);
        $this->assertStringContainsString('->requestRefresh(scheduled: true)', $service);
    }

    private function repositoryFile(string $relativePath): string
    {
        $path = base_path('../../'.$relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Repository file could not be read: '.$relativePath);

        return $contents;
    }
}
