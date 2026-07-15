<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackupRequestWorkerProbeTest extends TestCase
{
    #[Test]
    public function backup_worker_probe_and_all_other_command_classes_are_auto_discovered(): void
    {
        $commands = Artisan::all();

        self::assertArrayHasKey('dis:check-backup-request-worker', $commands);
        self::assertArrayHasKey('dis:run-scheduled-backup', $commands);
        self::assertArrayHasKey('dis:revoke-all-authentication-state', $commands);
        self::assertSame(
            '30',
            $commands['dis:check-backup-request-worker']->getDefinition()->getOption('timeout')->getDefault(),
        );

        $bootstrap = $this->repositoryFile('webapp/backend/bootstrap/app.php');
        self::assertStringContainsString('->withCommands()', $bootstrap);
        self::assertStringNotContainsString('->withCommands([', $bootstrap);
    }

    #[Test]
    public function worker_probe_protocol_is_scheduler_only_and_returns_without_running_a_backup(): void
    {
        $worker = $this->repositoryFile('scripts/backup-request-worker.sh');

        self::assertStringContainsString('test("^(create|verify|restore|probe)$")', $worker);
        self::assertStringContainsString('.operation == "probe"', $worker);
        self::assertStringContainsString('and .target == "local"', $worker);
        self::assertStringContainsString('and .backup_path == null', $worker);
        self::assertStringContainsString('and .actor_id == null', $worker);
        self::assertStringContainsString('[ "${operation}" = "probe" ] && [ "${request_owner}" != "${DIS_USER}" ]', $worker);

        $probeStart = strpos($worker, 'if [ "${operation}" = "probe" ]; then');
        $operationDispatch = strpos($worker, 'case "${operation}" in');
        self::assertIsInt($probeStart);
        self::assertIsInt($operationDispatch);
        self::assertLessThan($operationDispatch, $probeStart);

        $probeBlock = substr($worker, $probeStart, $operationDispatch - $probeStart);
        self::assertStringContainsString(
            'write_result "${result_file}" "succeeded" 0 "Backup request worker is healthy." "${request_owner}"',
            $probeBlock,
        );
        self::assertStringContainsString('rm -f -- "${running_file}"', $probeBlock);
        self::assertStringContainsString("return\n", $probeBlock);
        self::assertStringNotContainsString('backup.sh', $probeBlock);

        self::assertStringContainsString('discard_invalid_pending_request "${request_file}"', $worker);
        self::assertStringContainsString('mv -T -- "${request_file}" "${quarantine}"', $worker);
        self::assertStringContainsString('secure_path_operation remove-tree "${entry}"', $worker);
        self::assertStringContainsString('recover_abandoned_request "${running_file}"', $worker);
        self::assertStringContainsString('backup_request_recovered request_id=${request_id} state=failed exit_code=124', $worker);
        self::assertStringContainsString("process_request \"\${request_file}\"\n    # Bound one systemd invocation to one request.", $worker);
        self::assertStringContainsString("request.\n    break", $worker);
    }

    private function repositoryFile(string $relativePath): string
    {
        $contents = file_get_contents(base_path('../../'.$relativePath));
        self::assertNotFalse($contents, 'Repository file could not be read: '.$relativePath);

        return $contents;
    }
}
