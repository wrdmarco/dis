<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BackupPrivilegeBoundaryTest extends TestCase
{
    #[Test]
    public function backup_runtime_configuration_is_parsed_as_untrusted_data(): void
    {
        $common = $this->repositoryFile('scripts/lib/common.sh');
        $this->assertStringContainsString('conv=excl', $common);
        $this->assertStringNotContainsString('oflag=excl', $common);
        $backup = $this->repositoryFile('scripts/backup.sh');
        $verify = $this->repositoryFile('scripts/verify-backup.sh');
        $restore = $this->repositoryFile('scripts/restore.sh');

        foreach ([$backup, $verify, $restore] as $script) {
            $this->assertStringNotContainsString('source "${APP_ROOT}/webapp/backend/storage/app/backup-config.env"', $script);
            $this->assertStringContainsString('load_backup_runtime_config', $script);
        }

        $this->assertStringContainsString('keys_unsorted - $allowed', $common);
        $this->assertStringContainsString('jq -j', $common);
        $this->assertStringNotContainsString('eval ', $common);
    }

    #[Test]
    public function root_worker_claims_requests_and_uploads_before_using_them(): void
    {
        $worker = $this->repositoryFile('scripts/backup-request-worker.sh');
        $unit = $this->repositoryFile('infrastructure/systemd/dis-backup-request.service');

        $this->assertStringContainsString('WORK_DIR="${DIS_DATA_PATH}/backup-request-work"', $worker);
        $this->assertStringContainsString('temporary_result="$(mktemp "${WORK_DIR}/.result.XXXXXX")"', $worker);
        $this->assertStringContainsString('mv -T -- "${backup_path}" "${claimed_backup_path}"', $worker);
        $this->assertStringContainsString('[ -L "${running_file}" ] || [ ! -f "${running_file}" ]', $worker);
        $this->assertStringContainsString('[ -L "${claimed_backup_path}" ] || [ ! -d "${claimed_backup_path}" ]', $worker);
        $this->assertStringContainsString('mv -fT -- "${temporary_result}" "${result_file}"', $worker);
        $this->assertStringContainsString('[ "${request_owner}" = "www-data" ] && [ -z "${actor_id}" ]', $worker);
        $this->assertStringContainsString('restore_requested request_id=${request_id} claimed_actor_id=${actor_id}', $worker);
        $this->assertStringContainsString('"actor_id", "created_at"', $worker);

        $this->assertStringContainsString('ProtectSystem=strict', $unit);
        $this->assertStringContainsString('RuntimeDirectoryMode=0700', $unit);
        $this->assertStringContainsString('ReadOnlyPaths=/opt/dis', $unit);
    }

    #[Test]
    public function web_runtime_has_no_backup_sudo_or_group_escalation_path(): void
    {
        $sudoers = $this->repositoryFile('infrastructure/sudoers/dis-update');
        $permissionScripts = implode("\n", [
            $this->repositoryFile('scripts/install.sh'),
            $this->repositoryFile('scripts/deploy.sh'),
            $this->repositoryFile('scripts/self-heal-permissions.sh'),
        ]);

        $this->assertDoesNotMatchRegularExpression('/^%dis\s/m', $sudoers);
        $this->assertSame(1, preg_match_all('/^dis\s+.*NOPASSWD:\s+DIS_UPDATE$/m', $sudoers));
        $this->assertDoesNotMatchRegularExpression('/^dis\s+.*DIS_(?:BACKUP|REBOOT|MAINTENANCE)/m', $sudoers);
        $this->assertDoesNotMatchRegularExpression('/^www-data .*DIS_BACKUP/m', $sudoers);
        $this->assertStringNotContainsString('Cmnd_Alias DIS_BACKUP', $sudoers);
        $this->assertStringNotContainsString('usermod -aG "${DIS_GROUP}" www-data', $permissionScripts);
        $this->assertStringContainsString('gpasswd -d www-data "${DIS_GROUP}"', $permissionScripts);
    }

    #[Test]
    public function only_authenticated_encrypted_backup_uploads_are_accepted(): void
    {
        $controller = $this->repositoryFile('webapp/backend/app/Http/Controllers/BackupController.php');
        $backup = $this->repositoryFile('scripts/backup.sh');
        $verify = $this->repositoryFile('scripts/verify-backup.sh');
        $permissions = $this->repositoryFile('scripts/self-heal-permissions.sh');
        $securePath = $this->repositoryFile('scripts/lib/secure-path.py');

        $this->assertStringContainsString("['backup.payload.enc', 'BACKUP.HMAC', 'SHA256SUMS', 'manifest.json']", $controller);
        $this->assertStringNotContainsString("['database.dump', 'storage.tar.gz', 'source.tar.gz'", $controller);
        $this->assertStringContainsString("fopen(\$destination, 'xb')", $controller);
        $this->assertStringContainsString('stream_copy_to_stream($stream, $output, $entrySize + 1)', $controller);
        $this->assertStringContainsString("fopen(\$path, 'xb')", $controller);
        $this->assertStringContainsString('fsync($handle)', $controller);
        $this->assertStringContainsString("return \$dataPath.'/backup-imports';", $controller);

        $this->assertStringContainsString('BACKUP_WORK="$(mktemp -d "${DIS_DATA_PATH}/backup-request-work/backup.XXXXXX")"', $backup);
        $this->assertStringContainsString('backup_authentication_tag "${PUBLISH_STAGING}/SHA256SUMS"', $backup);
        $this->assertStringContainsString('run_cmd mv -T -- "${PUBLISH_STAGING}" "${TARGET}"', $backup);
        $this->assertStringContainsString('run_cmd mv -nT -- "${PENDING_TARGET}" "${TARGET}"', $backup);
        $this->assertStringContainsString('durably_sync_backup_tree "${PENDING_TARGET}"', $backup);
        $this->assertStringContainsString('durably_sync_backup_tree "${TARGET}"', $backup);
        $this->assertStringNotContainsString('backup_authentication_tag "${TARGET}/SHA256SUMS"', $backup);
        $this->assertStringContainsString('verify_backup_snapshot_identity "${BACKUP_PATH}"', $verify);
        $this->assertStringContainsString('chmod 0600 "${DIS_DATA_PATH}/secrets/backup-encryption.key"', $permissions);
        $this->assertStringContainsString('chmod 1730 "${DIS_DATA_PATH}/backup-imports" "${DIS_DATA_PATH}/backup-requests"', $permissions);
        $this->assertStringContainsString('chmod 0700 "${DIS_DATA_PATH}/backup-request-work"', $permissions);
        $this->assertStringContainsString('os.fsync(entry_fd)', $securePath);
        $this->assertStringContainsString('os.fsync(parent_fd)', $securePath);
    }

    #[Test]
    public function restore_is_queued_after_the_http_response_and_extracted_without_links(): void
    {
        $controller = $this->repositoryFile('webapp/backend/app/Http/Controllers/BackupController.php');
        $worker = $this->repositoryFile('scripts/backup-request-worker.sh');
        $restore = $this->repositoryFile('scripts/restore.sh');
        $common = $this->repositoryFile('scripts/lib/common.sh');
        $extractor = $this->repositoryFile('scripts/lib/safe-extract.py');

        $this->assertStringContainsString("'state' => 'queued'", $controller);
        $this->assertStringContainsString('app()->terminating(static function ()', $controller);
        $this->assertStringNotContainsString("runBackupRequest('restore'", $controller);
        $this->assertStringContainsString('sync -f "${restore_block_file}"', $worker);
        $this->assertStringContainsString('BACKUP_INPUT_ALREADY_SNAPSHOTTED=1', $worker);
        $this->assertStringContainsString('extract_storage_backup_archive', $restore);
        $this->assertStringContainsString('validate_storage_backup_archive "${STAGING}/storage.tar.gz"', $this->repositoryFile('scripts/backup.sh'));
        $this->assertStringContainsString('extract_storage_backup_archive "${PAYLOAD_ROOT}/storage.tar.gz"', $this->repositoryFile('scripts/verify-backup.sh'));
        $this->assertStringContainsString('python3 -I -S', $common);
        $this->assertStringContainsString('if not (member.isdir() or member.isreg())', $extractor);
        $this->assertStringContainsString('open(target, "xb", buffering=0)', $extractor);

        $preflight = strpos($restore, 'extract_storage_backup_archive "${PAYLOAD_ROOT}/storage.tar.gz"');
        $maintenance = strpos($restore, 'enable_deployment_maintenance "${APP_ROOT}/webapp/backend"');
        $databaseRestore = strpos($restore, 'PGPASSWORD="${DB_PASSWORD}" run_cmd pg_restore');
        $this->assertIsInt($preflight);
        $this->assertIsInt($maintenance);
        $this->assertIsInt($databaseRestore);
        $this->assertLessThan($maintenance, $preflight);
        $this->assertLessThan($databaseRestore, $preflight);
    }

    #[Test]
    public function samba_backups_require_a_non_executable_smb_311_mount(): void
    {
        $common = $this->repositoryFile('scripts/lib/common.sh');
        $controller = $this->repositoryFile('webapp/backend/app/Http/Controllers/BackupController.php');

        $this->assertStringContainsString('[ "${version}" = "3.1.1" ]', $common);
        foreach (['nosuid', 'nodev', 'noexec', 'nosymfollow', 'nounix', 'forceuid', 'forcegid'] as $option) {
            $this->assertStringContainsString($option, $common);
        }
        $this->assertStringContainsString("'in:3.1.1'", $controller);
    }

    #[Test]
    public function legacy_group_readable_backup_keys_are_rotated_out_of_normal_restore_trust(): void
    {
        $common = $this->repositoryFile('scripts/lib/common.sh');
        $deploy = $this->repositoryFile('scripts/deploy.sh');
        $setup = $this->repositoryFile('scripts/setup.sh');
        $restore = $this->repositoryFile('scripts/restore.sh');

        $this->assertStringContainsString('backup_key_generation_is_current', $common);
        $this->assertStringContainsString('${DIS_DATA_PATH}/legacy-backup-state', $common);
        $this->assertStringContainsString('legacy-backup-encryption.key', $common);
        $this->assertStringContainsString('normal_legacy_restore=disabled', $common);
        $this->assertStringContainsString('finalize_backup_key_cutover', $common);
        $this->assertStringContainsString('DIS_BACKUP_KEY_CUTOVER_ALLOWED=1 ensure_backup_encryption_key', $deploy);
        $this->assertStringContainsString('DIS_BACKUP_KEY_CUTOVER_ALLOWED=1 ensure_backup_encryption_key', $setup);
        $this->assertStringNotContainsString('Fresh backup key generation', $common);
        $this->assertStringContainsString('finalize_backup_key_cutover "${APP_ROOT}"', $deploy);
        $this->assertStringContainsString('require_backup_encryption_key >/dev/null', $restore);
        $this->assertStringNotContainsString('chown root:"${DIS_GROUP}" "${key_file}"', $common);
        $this->assertStringNotContainsString('chmod 0640 "${key_file}"', $common);

        $durableBackup = strpos($common, 'durably_sync_backup_tree "${backup_path}"');
        $cutoverCompletion = strpos($common, 'run_cmd rm -f -- "${pending_file}"');
        $this->assertIsInt($durableBackup);
        $this->assertIsInt($cutoverCompletion);
        $this->assertLessThan($cutoverCompletion, $durableBackup);
    }

    private function repositoryFile(string $relativePath): string
    {
        $path = base_path('../../'.$relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, 'Repository file could not be read: '.$relativePath);

        return $contents;
    }
}
