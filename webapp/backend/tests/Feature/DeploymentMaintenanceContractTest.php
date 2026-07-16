<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class DeploymentMaintenanceContractTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryRoot = dirname(__DIR__, 4);
    }

    public function test_deploy_stops_runtime_before_migrations_and_only_opens_after_verification(): void
    {
        $script = $this->read('scripts/deploy.sh');
        $common = $this->read('scripts/lib/common.sh');

        $frontendMaintenance = strpos($script, 'enable_frontend_maintenance');
        $stop = strpos($script, 'stop_dis_deployment_services');
        $backendMaintenance = strpos($script, 'enable_backend_deployment_maintenance "${BACKEND_DIR}"');
        $migration = strpos($script, 'artisan" migrate --force');
        $health = strrpos($script, 'healthcheck.sh');
        $services = strrpos($script, 'require_dis_runtime_services');
        $open = strrpos($script, 'complete_deployment_maintenance');

        self::assertIsInt($frontendMaintenance);
        self::assertIsInt($stop);
        self::assertIsInt($backendMaintenance);
        self::assertIsInt($migration);
        self::assertIsInt($health);
        self::assertIsInt($services);
        self::assertIsInt($open);
        self::assertLessThan($stop, $frontendMaintenance);
        self::assertLessThan($backendMaintenance, $stop);
        self::assertLessThan($migration, $backendMaintenance);
        self::assertLessThan($migration, $stop);
        self::assertGreaterThan($health, $open);
        self::assertGreaterThan($services, $open);
        self::assertStringContainsString('DIS_DEFER_OPERATIONAL_SERVICES', $script);
        self::assertStringNotContainsString('enable_deployment_maintenance "${BACKEND_DIR}"', $script);
        self::assertStringContainsString(
            'run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" down',
            $common,
        );
        self::assertStringContainsString(
            'run_cmd runuser -u "${DIS_USER}" -- php "${backend_dir}/artisan" up',
            $common,
        );
        self::assertStringNotContainsString('run_cmd php "${backend_dir}/artisan"', $common);
        self::assertStringContainsString('stage_backend_maintenance_files "${backend_dir}"', $common);
        self::assertStringContainsString('finalize_backend_maintenance_files "${backend_dir}"', $common);
        self::assertStringContainsString('clear_backend_maintenance_files "${backend_dir}"', $common);
        self::assertStringContainsString('u:www-data:r--', $common);
        self::assertStringContainsString('Deployment failed; maintenance remains enabled', $script);
        self::assertStringContainsString('systemctl stop dis-backup-request.timer', $common);
        self::assertStringContainsString('systemctl stop dis-backup-request.path', $common);
        self::assertStringNotContainsString("run_cmd systemctl stop dis-backup-request\n", $common);
        self::assertStringContainsString('systemctl show dis-backup-request --property=ActiveState --value', $common);
        self::assertStringContainsString('deployment was not allowed to interrupt it', $common);
        self::assertStringContainsString('systemctl start dis-backup-request.path', $common);
        self::assertStringContainsString('systemctl start dis-backup-request.timer', $common);
        self::assertStringContainsString('systemctl is-active --quiet dis-backup-request.path', $common);
        self::assertStringContainsString('systemctl is-active --quiet dis-backup-request.timer', $common);
        self::assertStringContainsString('dis:check-backup-request-worker --timeout=30', $common);

        $completeBody = substr(
            $common,
            (int) strpos($common, 'complete_deployment_maintenance()'),
            (int) strpos($common, 'stop_dis_deployment_services()') - (int) strpos($common, 'complete_deployment_maintenance()'),
        );
        self::assertLessThan(
            strpos($completeBody, 'disable_frontend_maintenance'),
            strpos($completeBody, 'prepare_backend_for_deployment_verification'),
        );
    }

    public function test_deploy_reconciles_generated_backend_cache_before_web_identity_self_check(): void
    {
        $script = $this->read('scripts/deploy.sh');

        $preClearInvalidation = strpos($script, 'invalidate_backend_generated_cache "${BACKEND_DIR}"');
        $cacheClear = strpos($script, 'artisan" optimize:clear');
        $packageRegeneration = strpos($script, 'regenerate_backend_package_manifest "${BACKEND_DIR}"');
        $migration = strpos($script, 'artisan" migrate --force');
        self::assertIsInt($preClearInvalidation);
        self::assertIsInt($cacheClear);
        self::assertIsInt($packageRegeneration);
        self::assertIsInt($migration);

        $cacheRepair = strpos(
            $script,
            'reconcile_backend_generated_cache_permissions "${BACKEND_DIR}"',
            $packageRegeneration + 1,
        );
        $webSelfCheck = strpos(
            $script,
            'run_cmd runuser -u www-data -- php "${BACKEND_DIR}/artisan" dis:self-check',
            $packageRegeneration,
        );

        self::assertIsInt($cacheRepair);
        self::assertIsInt($webSelfCheck);
        self::assertTrue($preClearInvalidation < $cacheClear);
        self::assertTrue($cacheClear < $packageRegeneration);
        self::assertTrue($packageRegeneration < $migration);
        self::assertTrue($migration < $cacheRepair);
        self::assertTrue($cacheRepair < $webSelfCheck);
        self::assertSame(1, substr_count($script, 'artisan" optimize:clear'));
        self::assertFalse(strpos($script, 'artisan" optimize:clear', $webSelfCheck));

        $common = $this->read('scripts/lib/common.sh');
        $permissionRepair = substr(
            $common,
            (int) strpos($common, 'reconcile_backend_generated_cache_permissions()'),
            (int) strpos($common, 'invalidate_backend_generated_cache()')
                - (int) strpos($common, 'reconcile_backend_generated_cache_permissions()'),
        );
        self::assertStringContainsString('repair_managed_tree "${cache_dir}"', $permissionRepair);
        self::assertStringContainsString('secure_path_operation acl-tree "${cache_dir}" www-data r-x r--', $permissionRepair);

        $cacheInvalidation = substr(
            $common,
            (int) strpos($common, 'invalidate_backend_generated_cache()'),
            (int) strpos($common, 'regenerate_backend_package_manifest()')
                - (int) strpos($common, 'invalidate_backend_generated_cache()'),
        );
        self::assertStringContainsString('run_cmd rm -f -- "${cache_dir}/"*.php', $cacheInvalidation);

        $manifestRegeneration = substr(
            $common,
            (int) strpos($common, 'regenerate_backend_package_manifest()'),
            (int) strpos($common, 'backend_dependency_state_is_current()')
                - (int) strpos($common, 'regenerate_backend_package_manifest()'),
        );
        self::assertSame(1, substr_count($manifestRegeneration, 'invalidate_backend_generated_cache'));
        self::assertSame(1, substr_count($manifestRegeneration, 'reconcile_backend_generated_cache_permissions'));
        self::assertStringContainsString('package:discover --ansi', $manifestRegeneration);
        $manifestInvalidation = strpos(
            $manifestRegeneration,
            'invalidate_backend_generated_cache "${backend_dir}"',
        );
        $packageDiscovery = strpos($manifestRegeneration, 'package:discover --ansi');
        self::assertIsInt($manifestInvalidation);
        self::assertIsInt($packageDiscovery);
        self::assertTrue($manifestInvalidation < $packageDiscovery);
        self::assertStringContainsString('require_user_can_open_file_for_reading', $manifestRegeneration);
        self::assertStringContainsString('require_user_cannot_open_file_for_writing', $manifestRegeneration);
        self::assertStringContainsString('require_user_cannot_create_file_in_directory', $manifestRegeneration);
        self::assertStringContainsString('packages.php services.php', $manifestRegeneration);

        $selfHeal = $this->read('scripts/self-heal-permissions.sh');
        self::assertStringContainsString(
            'secure_path_operation acl-tree "${BACKEND_DIR}/bootstrap/cache" www-data r-x r--',
            $selfHeal,
        );
        self::assertStringNotContainsString(
            '"${backend_runtime_leaves[@]}" "${BACKEND_DIR}/bootstrap/cache"',
            $selfHeal,
        );
    }

    public function test_update_regenerates_the_package_manifest_after_cache_cleanup(): void
    {
        $script = $this->read('scripts/update.sh');
        $cacheCleanup = substr(
            $script,
            (int) strpos($script, 'clear_application_caches()'),
            (int) strpos($script, 'self_heal_permissions()')
                - (int) strpos($script, 'clear_application_caches()'),
        );

        $clear = strpos($cacheCleanup, 'artisan" optimize:clear');
        $invalidate = strpos($cacheCleanup, 'invalidate_backend_generated_cache "${backend_dir}"');
        $remove = strpos($cacheCleanup, '"${backend_dir}/bootstrap/cache/"*.php');
        $regenerate = strpos($cacheCleanup, 'regenerate_backend_package_manifest "${backend_dir}"');

        self::assertIsInt($clear);
        self::assertIsInt($invalidate);
        self::assertIsInt($remove);
        self::assertIsInt($regenerate);
        self::assertTrue($invalidate < $clear);
        self::assertTrue($clear < $remove);
        self::assertTrue($remove < $regenerate);
    }

    public function test_restore_regenerates_and_reconciles_manifests_before_web_identity_self_check(): void
    {
        $script = $this->read('scripts/restore.sh');

        $regenerate = strpos($script, 'regenerate_backend_package_manifest "${APP_ROOT}/webapp/backend"');
        $migration = strpos($script, 'artisan" migrate --force');
        $reconcile = strpos(
            $script,
            'reconcile_backend_generated_cache_permissions "${APP_ROOT}/webapp/backend"',
        );
        $webSelfCheck = strpos(
            $script,
            'run_cmd runuser -u www-data -- php "${APP_ROOT}/webapp/backend/artisan" dis:self-check',
        );

        self::assertIsInt($regenerate);
        self::assertIsInt($migration);
        self::assertIsInt($reconcile);
        self::assertIsInt($webSelfCheck);
        self::assertTrue($regenerate < $migration);
        self::assertTrue($migration < $reconcile);
        self::assertTrue($reconcile < $webSelfCheck);
    }

    public function test_update_preflights_and_waits_for_a_stable_frontend_before_reopening(): void
    {
        $update = $this->read('scripts/update.sh');
        $common = $this->read('scripts/lib/common.sh');
        $frontendUnit = $this->read('infrastructure/systemd/dis-frontend.service');

        $preflight = strpos($update, 'require_dis_frontend_release_artifacts');
        $exitTrap = strpos($update, 'trap \'update_exit_handler "$?"\' EXIT');
        $maintenance = strpos($update, 'enable_frontend_maintenance', (int) $exitTrap);
        self::assertIsInt($preflight);
        self::assertIsInt($exitTrap);
        self::assertIsInt($maintenance);
        self::assertTrue($preflight < $exitTrap);
        self::assertTrue($preflight < $maintenance);
        self::assertStringContainsString('Update failed during phase', $update);
        self::assertStringContainsString('Permission self-heal failed during update phase', $update);

        $artifactCheck = substr(
            $common,
            (int) strpos($common, 'require_dis_frontend_release_artifacts()'),
            (int) strpos($common, 'report_systemd_service_failure()')
                - (int) strpos($common, 'require_dis_frontend_release_artifacts()'),
        );
        self::assertStringContainsString('.next/BUILD_ID', $artifactCheck);
        self::assertStringContainsString('node_modules/next/dist/bin/next', $artifactCheck);
        self::assertStringContainsString('.next/server', $artifactCheck);
        self::assertStringContainsString('.next/static', $artifactCheck);
        self::assertStringContainsString('runuser -u "${DIS_USER}" -- test -r', $artifactCheck);

        $stableWait = substr(
            $common,
            (int) strpos($common, 'wait_for_systemd_service_stable()'),
            (int) strpos($common, 'wait_for_dis_frontend_http_readiness()')
                - (int) strpos($common, 'wait_for_systemd_service_stable()'),
        );
        self::assertStringContainsString('required_samples="${3:-2}"', $stableWait);
        self::assertStringContainsString('stable_samples=$((stable_samples + 1))', $stableWait);
        self::assertStringContainsString('report_systemd_service_failure', $stableWait);

        $frontendReadiness = substr(
            $common,
            (int) strpos($common, 'wait_for_dis_frontend_http_readiness()'),
            (int) strpos($common, 'start_dis_operational_services()')
                - (int) strpos($common, 'wait_for_dis_frontend_http_readiness()'),
        );
        self::assertStringContainsString('http://127.0.0.1:3000/login', $frontendReadiness);
        self::assertStringContainsString('required_samples="${2:-2}"', $frontendReadiness);
        self::assertStringContainsString('systemctl is-active --quiet dis-frontend.service', $frontendReadiness);

        $webRequirement = substr(
            $common,
            (int) strpos($common, 'require_dis_web_services()'),
            (int) strpos($common, 'require_dis_runtime_services()')
                - (int) strpos($common, 'require_dis_web_services()'),
        );
        self::assertStringContainsString('wait_for_systemd_service_stable', $webRequirement);
        self::assertStringContainsString('wait_for_dis_frontend_http_readiness', $webRequirement);

        self::assertStringContainsString('ExecStartPre=/usr/bin/test -r /opt/dis/webapp/frontend/.next/BUILD_ID', $frontendUnit);
        self::assertStringContainsString(
            'ExecStart=/usr/bin/node /opt/dis/webapp/frontend/node_modules/next/dist/bin/next start --hostname 127.0.0.1 --port 3000',
            $frontendUnit,
        );
        self::assertStringNotContainsString('ExecStart=/usr/bin/npm run start', $frontendUnit);
    }

    public function test_backup_request_timer_sweeps_the_worker_and_is_managed_with_the_path_unit(): void
    {
        $timer = $this->read('infrastructure/systemd/dis-backup-request.timer');
        $service = $this->read('infrastructure/systemd/dis-backup-request.service');
        $common = $this->read('scripts/lib/common.sh');
        $deploy = $this->read('scripts/deploy.sh');
        $update = $this->read('scripts/update.sh');
        $uninstall = $this->read('scripts/uninstall.sh');

        self::assertStringContainsString('OnBootSec=1min', $timer);
        self::assertStringContainsString('OnUnitInactiveSec=1min', $timer);
        self::assertStringContainsString('Unit=dis-backup-request.service', $timer);
        self::assertStringContainsString('WantedBy=timers.target', $timer);
        self::assertStringContainsString('TimeoutStartSec=30min', $service);
        self::assertStringNotContainsString('Requires=dis-backup-mount.service', $service);
        self::assertStringNotContainsString('dis-backup-mount.service', $service);
        self::assertStringContainsString('infrastructure/systemd/dis-backup-request.timer', $common);
        self::assertStringContainsString('/etc/systemd/system/dis-backup-request.timer', $common);
        self::assertStringContainsString('dis-backup-request.path dis-backup-request.timer', $deploy);
        self::assertStringContainsString('dis-backup-request.path dis-backup-request.timer', $update);
        self::assertStringContainsString('dis-backup-request.timer dis-backup-request.path', $uninstall);
        self::assertStringContainsString('/etc/systemd/system/dis-backup-request.timer', $uninstall);
        self::assertStringContainsString('remove_legacy_backup_entrypoints', $deploy);
        self::assertStringContainsString('remove_legacy_backup_entrypoints', $update);
        self::assertStringContainsString('systemctl disable --now dis-backup-mount.service', $common);
        self::assertStringContainsString('/usr/local/bin/dis-backup-verify', $common);
        self::assertStringContainsString('/usr/local/bin/dis-backup-restore', $common);

        $runtimeCheck = substr(
            $common,
            (int) strpos($common, 'require_dis_runtime_services()'),
            (int) strpos($common, 'load_data_path_from_env()') - (int) strpos($common, 'require_dis_runtime_services()'),
        );
        $pathActive = strpos($runtimeCheck, 'systemctl is-active --quiet dis-backup-request.path');
        $timerActive = strpos($runtimeCheck, 'systemctl is-active --quiet dis-backup-request.timer');
        self::assertIsInt($pathActive);
        self::assertIsInt($timerActive);

        $startServices = substr(
            $common,
            (int) strpos($common, 'start_dis_operational_services()'),
            (int) strpos($common, 'require_dis_web_services()') - (int) strpos($common, 'start_dis_operational_services()'),
        );
        $pathStart = strpos($startServices, 'systemctl start dis-backup-request.path');
        $timerStart = strpos($startServices, 'systemctl start dis-backup-request.timer');
        $brokerCheck = strpos($startServices, 'dis:check-backup-request-worker --timeout=30');
        $schedulerStart = strpos($startServices, 'for service in dis-queue dis-scheduler dis-websocket');
        self::assertIsInt($pathStart);
        self::assertIsInt($timerStart);
        self::assertIsInt($brokerCheck);
        self::assertIsInt($schedulerStart);
        self::assertTrue($pathStart < $brokerCheck);
        self::assertTrue($timerStart < $brokerCheck);
        self::assertTrue($brokerCheck < $schedulerStart);

        $restore = $this->read('scripts/restore.sh');
        self::assertStringContainsString('DIS_SKIP_BACKUP_REQUEST_PROBE=1 start_dis_operational_services', $restore);
        self::assertStringContainsString('systemctl stop dis-backup-request.timer', $restore);
    }

    public function test_previous_updater_compatibility_sources_survive_the_checkout_boundary(): void
    {
        foreach ([
            'scripts/backup-mount.sh',
            'scripts/backup-verify-runner.sh',
            'scripts/backup-restore-runner.sh',
            'infrastructure/systemd/dis-backup-mount.service',
        ] as $path) {
            self::assertFileExists($this->repositoryRoot.'/'.$path);
            self::assertStringContainsString('upgrade-compatibility source', $this->read($path));
        }

        $deploy = $this->read('scripts/deploy.sh');
        $update = $this->read('scripts/update.sh');
        foreach (['backup-mount.sh', 'backup-verify-runner.sh', 'backup-restore-runner.sh'] as $legacyScript) {
            self::assertStringNotContainsString('/scripts/'.$legacyScript.'" /usr/local/bin/', $deploy);
            self::assertStringNotContainsString('/scripts/'.$legacyScript.'" /usr/local/bin/', $update);
        }
        self::assertStringNotContainsString(
            'infrastructure/systemd/dis-backup-mount.service" /etc/systemd/system/',
            $deploy,
        );
        self::assertStringNotContainsString(
            'infrastructure/systemd/dis-backup-mount.service" /etc/systemd/system/',
            $update,
        );

        $common = $this->read('scripts/lib/common.sh');
        $selfHeal = $this->read('scripts/self-heal-permissions.sh');
        $dependencyInstall = strpos($deploy, 'run_cmd chmod -R u=rwX,go=rX "${BACKEND_DIR}/vendor"');
        $stateRecord = strpos($deploy, 'record_backend_dependency_state "${BACKEND_DIR}"');
        $postInstallSelfHeal = strpos(
            $deploy,
            'APP_ROOT="${APP_ROOT}" bash "${SCRIPT_DIR}/self-heal-permissions.sh"',
            (int) $stateRecord,
        );
        self::assertIsInt($dependencyInstall);
        self::assertIsInt($stateRecord);
        self::assertIsInt($postInstallSelfHeal);
        self::assertTrue($dependencyInstall < $stateRecord);
        self::assertTrue($stateRecord < $postInstallSelfHeal);
        self::assertStringContainsString('backend_dependency_state_is_current()', $common);
        self::assertStringContainsString('record_backend_dependency_state()', $common);
        self::assertStringContainsString('root_controlled_bundle_source_is_safe "${lock_file}"', $common);
        self::assertStringContainsString('root_owned_runtime_file_is_safe "${marker}" 644', $common);
        self::assertStringContainsString('backend_dependency_state_is_current "${BACKEND_DIR}"', $selfHeal);
        self::assertStringContainsString('regenerate_backend_package_manifest "${BACKEND_DIR}"', $selfHeal);
    }

    public function test_update_exit_is_fail_closed_and_parent_owns_the_final_unlock(): void
    {
        $script = $this->read('scripts/update.sh');

        self::assertStringContainsString('trap \'update_exit_handler "$?"\' EXIT', $script);
        self::assertStringContainsString('Update failed after system or application mutation started', $script);
        self::assertStringContainsString('recover_current_release_after_pre_mutation_failure', $script);
        self::assertStringContainsString('backup-key-cutover-v2.pending', $script);
        self::assertStringContainsString('UPDATE_MUTATION_STARTED=1', $script);
        self::assertStringContainsString(
            "bash \"\${SCRIPT_DIR}/deploy.sh\"\n    UPDATE_PHASE=\"stopping services after nested deployment\"\n    stop_dis_deployment_services",
            $script,
        );
        self::assertStringContainsString('DIS_DEPLOYMENT_OWNER=update', $script);
        self::assertStringContainsString('DIS_DEFER_OPERATIONAL_SERVICES=1', $script);

        $failureHandler = substr(
            $script,
            (int) strpos($script, 'update_exit_handler()'),
            (int) strpos($script, 'verify_update_and_open_production()') - (int) strpos($script, 'update_exit_handler()'),
        );
        self::assertStringNotContainsString('put_webapp_in_production', $failureHandler);
        self::assertStringNotContainsString('complete_deployment_maintenance', $failureHandler);

        $stop = strpos($script, 'stop_dis_deployment_services', (int) strpos($script, "trap 'update_exit_handler"));
        $dependencies = strrpos($script, 'apt-get install -y cifs-utils');
        $deploy = strrpos($script, 'bash "${SCRIPT_DIR}/deploy.sh"');
        $postDeployStop = strpos($script, 'stop_dis_deployment_services', (int) $deploy);
        $health = strrpos($script, 'healthcheck.sh');
        $services = strrpos($script, 'require_dis_runtime_services');
        $open = strrpos($script, 'put_webapp_in_production');

        self::assertIsInt($stop);
        self::assertIsInt($dependencies);
        self::assertIsInt($deploy);
        self::assertIsInt($postDeployStop);
        self::assertIsInt($health);
        self::assertIsInt($services);
        self::assertIsInt($open);
        self::assertTrue($stop < $dependencies);
        self::assertTrue($deploy < $postDeployStop);
        self::assertGreaterThan($health, $open);
        self::assertGreaterThan($services, $open);
    }

    public function test_invalid_runtime_config_uses_an_isolated_local_pre_update_backup(): void
    {
        $update = $this->read('scripts/update.sh');
        $backup = $this->read('scripts/backup.sh');
        $verify = $this->read('scripts/verify-backup.sh');
        $common = $this->read('scripts/lib/common.sh');

        self::assertStringContainsString('if ! (load_backup_runtime_config "${config_file}")', $update);
        self::assertStringContainsString('DIS_SAFE_LOCAL_PREUPDATE_BACKUP=1 APP_ROOT=', $update);
        self::assertStringContainsString('env DIS_SAFE_LOCAL_PREUPDATE_BACKUP=1 APP_ROOT=', $update);
        self::assertStringContainsString('load_backup_runtime_config_for_operation', $backup);
        self::assertStringContainsString('load_backup_runtime_config_for_operation', $verify);
        self::assertStringContainsString('BACKUP_TARGET=local', $common);
        self::assertStringContainsString('BACKUP_ROOT="${DIS_DATA_PATH}/backup"', $common);
        self::assertStringContainsString('BACKUP_RETENTION_COUNT=0', $common);
        self::assertStringContainsString('unset BACKUP_SAMBA_SHARE', $common);
        self::assertStringContainsString('.key == "BACKUP_RETENTION_COUNT"', $common);
        self::assertStringContainsString('(.value | type) == "number"', $common);
        self::assertStringContainsString('.value == (.value | floor)', $common);
    }

    public function test_trusted_local_backup_operations_ignore_invalid_global_runtime_configuration(): void
    {
        $worker = $this->read('scripts/backup-request-worker.sh');
        $common = $this->read('scripts/lib/common.sh');
        $backup = $this->read('scripts/backup.sh');
        $verify = $this->read('scripts/verify-backup.sh');
        $restore = $this->read('scripts/restore.sh');

        self::assertStringContainsString('if [ "${target}" = "local" ]', $worker);
        self::assertStringContainsString('safe_local_backup=1', $worker);
        self::assertSame(4, substr_count($worker, 'DIS_SAFE_LOCAL_BACKUP="${safe_local_backup}"'));
        self::assertSame(4, substr_count($worker, 'DIS_SAFE_LOCAL_PREUPDATE_BACKUP=0'));
        $requestValidation = strpos($worker, "if ! jq -e '");
        $targetExtraction = strpos($worker, 'target="$(jq -r');
        $safeMode = strpos($worker, 'safe_local_backup=0');
        self::assertIsInt($requestValidation);
        self::assertIsInt($targetExtraction);
        self::assertIsInt($safeMode);
        self::assertTrue($requestValidation < $targetExtraction);
        self::assertTrue($targetExtraction < $safeMode);
        self::assertStringContainsString(
            'if [ "${target}" = "samba" ] && ! ensure_samba_backup_mount',
            $worker,
        );
        self::assertStringContainsString('DIS_SAFE_LOCAL_BACKUP must be 0 or 1', $common);
        self::assertStringContainsString('DIS_EFFECTIVE_SAFE_LOCAL_BACKUP=1', $common);
        self::assertStringContainsString('Using isolated local backup configuration.', $common);
        self::assertStringContainsString('safe_local_backup_retention_count', $common);
        self::assertStringContainsString('unset BACKUP_RETENTION_COUNT', $common);
        self::assertStringContainsString('load_backup_runtime_config "${config_file}"', $common);
        self::assertStringContainsString('DIS_EFFECTIVE_SAFE_LOCAL_BACKUP', $backup);
        self::assertStringContainsString('load_backup_runtime_config_for_operation', $verify);
        self::assertStringContainsString('load_backup_runtime_config_for_operation', $restore);

        foreach ([$backup, $verify, $restore] as $script) {
            $capture = strpos($script, 'REQUESTED_SAFE_LOCAL_BACKUP="${DIS_SAFE_LOCAL_BACKUP:-0}"');
            $environment = strpos($script, 'source "${APP_ROOT}/.env"');
            if ($environment === false) {
                $environment = strpos($script, 'source "${ENV_FILE}"');
            }
            $restoreTrustedValue = strpos(
                $script,
                'DIS_SAFE_LOCAL_BACKUP="${REQUESTED_SAFE_LOCAL_BACKUP}"',
            );
            $load = strpos($script, 'load_backup_runtime_config_for_operation');
            self::assertIsInt($capture);
            self::assertIsInt($environment);
            self::assertIsInt($restoreTrustedValue);
            self::assertIsInt($load);
            self::assertTrue($capture < $environment);
            self::assertTrue($environment < $restoreTrustedValue);
            self::assertTrue($restoreTrustedValue < $load);
        }
    }

    public function test_nginx_blocks_operational_surfaces_but_keeps_narrow_recovery_routes(): void
    {
        $config = $this->read('infrastructure/nginx/dis.conf');
        $bootstrap = $this->read('webapp/backend/bootstrap/app.php');

        self::assertStringContainsString('location = /api/developer/system/maintenance', $config);
        self::assertStringContainsString('location = /health', $config);
        self::assertStringContainsString('location ^~ /api/', $config);
        self::assertStringContainsString('location /ws/', $config);
        self::assertStringContainsString('location /app/', $config);
        self::assertStringContainsString('location ^~ /apk/', $config);
        self::assertGreaterThanOrEqual(5, substr_count($config, 'if (-f /opt/dis/maintenance/frontend.lock)'));
        self::assertStringContainsString('location @dis_api_maintenance', $config);
        self::assertStringContainsString('"code":"maintenance"', $config);
        self::assertStringContainsString("'api/developer/system/maintenance'", $bootstrap);
    }

    public function test_nginx_forwards_the_generated_canonical_host_to_every_application_upstream(): void
    {
        $config = $this->read('infrastructure/nginx/dis.conf');
        $deploy = $this->read('scripts/deploy.sh');
        $setup = $this->read('scripts/setup.sh');
        $update = $this->read('scripts/update.sh');

        self::assertSame(3, substr_count($config, 'proxy_set_header Host $server_name;'));
        self::assertSame(3, substr_count($config, 'proxy_set_header X-Forwarded-Host $server_name;'));
        self::assertSame(5, substr_count($config, 'fastcgi_param HTTP_HOST $server_name;'));
        self::assertSame(5, substr_count($config, 'fastcgi_param HTTP_X_FORWARDED_HOST $server_name;'));
        self::assertStringNotContainsString('proxy_set_header Host $host;', $config);
        self::assertStringNotContainsString('proxy_set_header X-Forwarded-Host $host;', $config);
        self::assertStringNotContainsString('fastcgi_param HTTP_X_FORWARDED_HOST $host;', $config);
        self::assertStringContainsString('proxy_hide_header Refresh;', $config);
        self::assertStringContainsString('canonical_public_host()', $deploy);
        self::assertStringContainsString('prepare_canonical_nginx_source()', $deploy);
        self::assertStringContainsString('s/server_name _;/server_name ${public_host} _;/', $deploy);
        self::assertStringContainsString('"${authority}" == *"/"*', $deploy);
        self::assertStringContainsString('server_name ${public_host} _;', $deploy);
        self::assertStringContainsString('s/server_name _;/server_name ${DOMAIN} _;/', $setup);
        self::assertStringContainsString('s/server_name _;/server_name ${host} _;/', $update);

        $prepare = strrpos($deploy, "\nprepare_canonical_nginx_source\n");
        $install = strpos($deploy, 'install -m 0644 "${NGINX_SOURCE}"');
        self::assertIsInt($prepare);
        self::assertIsInt($install);
        self::assertTrue($prepare < $install);
    }

    public function test_healthcheck_requires_an_exact_healthy_json_response_with_bounded_timeouts(): void
    {
        $script = $this->read('scripts/healthcheck.sh');

        self::assertStringContainsString('--connect-timeout "${HEALTH_CONNECT_TIMEOUT_SECONDS:-5}"', $script);
        self::assertStringContainsString('--max-time "${HEALTH_MAX_TIME_SECONDS:-15}"', $script);
        self::assertStringContainsString('[ "${status}" != "200" ]', $script);
        self::assertStringContainsString("jq -e '.data.status == \"ok\"'", $script);
        self::assertStringNotContainsString('[ "${status}" -lt 200 ]', $script);
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($this->repositoryRoot.'/'.$path);
        self::assertNotFalse($contents);

        return $contents;
    }
}
