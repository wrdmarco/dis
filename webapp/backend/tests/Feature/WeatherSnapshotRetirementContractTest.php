<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class WeatherSnapshotRetirementContractTest extends TestCase
{
    #[Test]
    public function backups_exclude_all_retired_weather_snapshot_trees(): void
    {
        $backup = $this->repositoryFile('scripts/backup.sh');

        foreach ([
            "--exclude='webapp/backend/storage/app/knmi-forecast'",
            "--exclude='webapp/backend/storage/app/eumetsat-lightning'",
        ] as $excludedTree) {
            $this->assertStringContainsString($excludedTree, $backup);
            $this->assertSame(1, substr_count($backup, $excludedTree));
        }
    }

    #[Test]
    public function restore_removes_retired_weather_files_before_permission_repair(): void
    {
        $restore = $this->repositoryFile('scripts/restore.sh');

        $storageReplacement = strrpos(
            $restore,
            'replace_managed_tree "${RESTORED_DATA}/webapp/backend/storage"',
        );
        $retirement = strrpos(
            $restore,
            'bash "${SCRIPT_DIR}/retire-weather-snapshots.sh"',
        );
        $permissionRepair = strrpos($restore, 'repair_restored_data_permissions');

        $this->assertIsInt($storageReplacement);
        $this->assertIsInt($retirement);
        $this->assertIsInt($permissionRepair);
        $this->assertLessThan($retirement, $storageReplacement);
        $this->assertLessThan($permissionRepair, $retirement);
        $this->assertStringNotContainsString('dis:reconcile-knmi-after-restore', $restore);
        $this->assertStringNotContainsString('dis:refresh-knmi-precipitation-outlook', $restore);
    }

    private function repositoryFile(string $relativePath): string
    {
        $path = base_path('../../'.$relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Repository file could not be read: '.$relativePath);

        return $contents;
    }
}
