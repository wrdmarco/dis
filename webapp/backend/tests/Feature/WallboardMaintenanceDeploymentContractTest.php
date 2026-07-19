<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

final class WallboardMaintenanceDeploymentContractTest extends TestCase
{
    private string $repositoryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryRoot = dirname(__DIR__, 4);
    }

    public function test_update_announces_before_the_frontend_lock_and_service_stop(): void
    {
        $update = $this->read('scripts/update.sh');
        $announce = strpos($update, 'announce_wallboard_maintenance update');
        $lock = strpos($update, 'enable_frontend_maintenance', (int) $announce);
        $stop = strpos($update, 'stop_dis_deployment_services', (int) $announce);

        self::assertIsInt($announce);
        self::assertIsInt($lock);
        self::assertIsInt($stop);
        self::assertTrue($announce < $lock && $lock < $stop);
        self::assertStringContainsString('WALLBOARD_MAINTENANCE_NOTICE_SECONDS=6', $this->read('scripts/lib/common.sh'));
    }

    public function test_direct_deploy_announces_once_but_nested_update_deploy_does_not(): void
    {
        $deploy = $this->read('scripts/deploy.sh');
        $guard = strpos($deploy, 'if [ "${DIS_DEPLOYMENT_OWNER}" = "deploy" ]; then');
        $announce = strpos($deploy, 'announce_wallboard_maintenance maintenance', (int) $guard);
        $lock = strpos($deploy, 'enable_frontend_maintenance', (int) $announce);

        self::assertIsInt($guard);
        self::assertIsInt($announce);
        self::assertIsInt($lock);
        self::assertTrue($guard < $announce && $announce < $lock);
        self::assertSame(1, substr_count($deploy, 'announce_wallboard_maintenance maintenance'));
        self::assertStringContainsString('DIS_DEPLOYMENT_OWNER="${DIS_DEPLOYMENT_OWNER:-deploy}"', $deploy);
        self::assertStringContainsString('DIS_DEPLOYMENT_OWNER=update', $this->read('scripts/update.sh'));
    }

    public function test_notice_is_atomic_bounded_and_only_cleared_after_successful_recovery(): void
    {
        $common = $this->read('scripts/lib/common.sh');
        self::assertStringContainsString('WALLBOARD_MAINTENANCE_NOTICE_TTL_SECONDS=21600', $common);
        self::assertStringContainsString('mktemp "${directory}/.wallboard-status.XXXXXX"', $common);
        self::assertStringContainsString('run_cmd chown root:root "${temporary}"', $common);
        self::assertStringContainsString('run_cmd chmod 0644 "${temporary}"', $common);
        self::assertStringContainsString('run_cmd mv -fT -- "${temporary}" "${WALLBOARD_MAINTENANCE_NOTICE_PATH}"', $common);
        self::assertStringContainsString('0:0:644:1', $common);
        self::assertStringContainsString('require_user_can_open_file_for_reading', $common);
        self::assertStringContainsString('if [ "${DRY_RUN:-0}" = "1" ]; then', $common);

        $complete = $this->functionBody($common, 'complete_deployment_maintenance()', 'stop_dis_deployment_services()');
        $backendUp = strpos($complete, 'prepare_backend_for_deployment_verification');
        $clear = strpos($complete, 'clear_wallboard_maintenance_notice');
        $unlock = strpos($complete, 'disable_frontend_maintenance');
        self::assertIsInt($backendUp);
        self::assertIsInt($clear);
        self::assertIsInt($unlock);
        self::assertTrue($backendUp < $clear && $clear < $unlock);

        self::assertStringNotContainsString('clear_wallboard_maintenance_notice', $this->functionBody(
            $this->read('scripts/update.sh'),
            'update_exit_handler()',
            'recover_current_release_after_pre_mutation_failure()',
        ));
        self::assertStringNotContainsString('clear_wallboard_maintenance_notice', $this->functionBody(
            $this->read('scripts/deploy.sh'),
            'deployment_exit_handler()',
            "trap 'deployment_exit_handler",
        ));
    }

    public function test_manual_maintenance_uses_the_same_announce_and_recovery_contract(): void
    {
        $maintenance = $this->read('scripts/maintenance.sh');
        $announce = strpos($maintenance, 'announce_wallboard_maintenance maintenance');
        $lock = strpos($maintenance, 'enable_deployment_maintenance', (int) $announce);

        self::assertIsInt($announce);
        self::assertIsInt($lock);
        self::assertTrue($announce < $lock);
        self::assertStringContainsString('complete_deployment_maintenance "${BACKEND_DIR}"', $maintenance);
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->repositoryRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
        self::assertIsString($contents);

        return $contents;
    }

    private function functionBody(string $source, string $startNeedle, string $endNeedle): string
    {
        $start = strpos($source, $startNeedle);
        self::assertIsInt($start);
        $end = strpos($source, $endNeedle, $start + strlen($startNeedle));
        self::assertIsInt($end);

        return substr($source, $start, $end - $start);
    }
}
