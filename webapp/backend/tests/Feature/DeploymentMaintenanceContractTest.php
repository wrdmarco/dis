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
        self::assertStringContainsString('systemctl stop dis-backup-request.path', $common);
        self::assertStringContainsString('systemctl stop dis-backup-request', $common);
        self::assertStringContainsString('systemctl start dis-backup-request.path', $common);
        self::assertStringContainsString('systemctl is-active --quiet dis-backup-request.path', $common);

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
