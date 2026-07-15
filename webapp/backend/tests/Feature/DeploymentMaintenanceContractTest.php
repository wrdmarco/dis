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

        $maintenance = strpos($script, 'enable_deployment_maintenance "${BACKEND_DIR}"');
        $stop = strpos($script, 'stop_dis_deployment_services');
        $migration = strpos($script, 'artisan" migrate --force');
        $health = strrpos($script, 'healthcheck.sh');
        $services = strrpos($script, 'require_dis_runtime_services');
        $open = strrpos($script, 'complete_deployment_maintenance');

        self::assertIsInt($maintenance);
        self::assertIsInt($stop);
        self::assertIsInt($migration);
        self::assertIsInt($health);
        self::assertIsInt($services);
        self::assertIsInt($open);
        self::assertLessThan($migration, $maintenance);
        self::assertLessThan($migration, $stop);
        self::assertGreaterThan($health, $open);
        self::assertGreaterThan($services, $open);
        self::assertStringContainsString('DIS_DEFER_OPERATIONAL_SERVICES', $script);
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

    public function test_update_exit_is_fail_closed_and_parent_owns_the_final_unlock(): void
    {
        $script = $this->read('scripts/update.sh');

        self::assertStringContainsString('trap \'update_exit_handler "$?"\' EXIT', $script);
        self::assertStringContainsString('Update failed; maintenance remains enabled', $script);
        self::assertStringContainsString("bash \"\${SCRIPT_DIR}/deploy.sh\"\n    stop_dis_deployment_services", $script);
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
