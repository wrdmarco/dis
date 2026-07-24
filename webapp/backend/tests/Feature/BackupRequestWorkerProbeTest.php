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

        self::assertStringContainsString('test("^(create|prune|verify|restore|probe)$")', $worker);
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

    #[Test]
    public function manual_retention_is_an_authenticated_explicit_target_root_operation(): void
    {
        $worker = $this->repositoryFile('scripts/backup-request-worker.sh');
        $prune = $this->repositoryFile('scripts/prune-backups.sh');
        $retention = $this->repositoryFile('scripts/lib/backup-retention.sh');
        $routes = $this->repositoryFile('webapp/backend/routes/api.php');

        self::assertStringContainsString(
            '((.operation == "create" or .operation == "prune") and .backup_path == null)',
            $worker,
        );
        self::assertStringContainsString(
            '(.runtime_config_sha256 | type == "string" and test("^[a-f0-9]{64}$"))',
            $worker,
        );
        self::assertStringContainsString(
            '.operation != "prune"',
            $worker,
        );
        self::assertStringContainsString(
            'EXPECTED_BACKUP_RUNTIME_CONFIG_SHA256="${runtime_config_sha256}"',
            $worker,
        );
        self::assertStringContainsString(
            'backup_retention_requested request_id=${request_id} claimed_actor_id=${actor_id} target=${target}',
            $worker,
        );
        self::assertStringContainsString(
            'backup_retention_completed request_id=${request_id} claimed_actor_id=${actor_id} target=${target} state=succeeded exit_code=0',
            $worker,
        );
        self::assertStringContainsString(
            'backup_retention_completed request_id=${request_id} claimed_actor_id=${actor_id} target=${target} state=failed exit_code=${exit_code}',
            $worker,
        );
        self::assertStringContainsString('bash "${SCRIPT_DIR}/prune-backups.sh"', $worker);
        self::assertStringContainsString(
            '[ "${request_owner}" = "www-data" ] && [ -z "${actor_id}" ]',
            $worker,
        );
        self::assertStringContainsString('"${SCRIPT_DIR}/prune-backups.sh"', $worker);
        self::assertStringContainsString('"${SCRIPT_DIR}/lib/backup-retention.sh"', $worker);
        self::assertStringContainsString('require_file "${runtime_file}"', $worker);
        self::assertStringContainsString(
            'root_controlled_bundle_source_is_safe "${runtime_file}"',
            $worker,
        );
        self::assertLessThan(
            strpos($worker, 'if [ "${operation}" = "probe" ]; then'),
            strpos($worker, 'root_controlled_bundle_source_is_safe "${runtime_file}"'),
        );
        self::assertStringContainsString('REQUESTED_BACKUP_TARGET="${BACKUP_TARGET:-}"', $prune);
        self::assertStringContainsString(
            'REQUESTED_RUNTIME_CONFIG_SHA256="${EXPECTED_BACKUP_RUNTIME_CONFIG_SHA256:-}"',
            $prune,
        );
        self::assertStringContainsString('acquire_dis_operation_lock backup', $prune);
        self::assertStringContainsString('require_backup_runtime_config_binding', $prune);
        self::assertStringContainsString('BACKUP_ROOT="$(resolve_backup_root "${APP_ROOT}")"', $prune);
        self::assertStringContainsString('prune_old_backups "${BACKUP_ROOT}"', $prune);
        self::assertLessThan(
            strpos($prune, 'BACKUP_ROOT="$(resolve_backup_root "${APP_ROOT}")"'),
            strpos($prune, 'require_backup_runtime_config_binding'),
        );

        self::assertStringContainsString('backup_runtime_config_sha256()', $retention);
        self::assertStringContainsString('require_backup_runtime_config_binding()', $retention);
        self::assertStringContainsString(
            'Backup runtime configuration changed before retention execution.',
            $retention,
        );
        self::assertStringContainsString(
            '[ "${BACKUP_TARGET:-}" = "${requested_target}" ]',
            $retention,
        );
        self::assertStringContainsString(
            'require_root_controlled_parent "${requested_root%/}/.retention-boundary"',
            $retention,
        );
        self::assertStringContainsString("-regex '.*/[0-9]{8}T[0-9]{6}Z$'", $retention);
        self::assertStringContainsString('[ ! -L "${candidate}" ]', $retention);
        self::assertStringContainsString('[ "${candidate_device}" = "${root_device}" ]', $retention);
        self::assertStringContainsString('secure_path_operation remove-tree "${root}/${backup_id}"', $retention);
        self::assertStringContainsString(
            "Route::post('/admin/backups/prune', [BackupController::class, 'prune'])->middleware('permission:backups.manage')",
            $routes,
        );
    }

    private function repositoryFile(string $relativePath): string
    {
        $contents = file_get_contents(base_path('../../'.$relativePath));
        self::assertNotFalse($contents, 'Repository file could not be read: '.$relativePath);

        return $contents;
    }
}
