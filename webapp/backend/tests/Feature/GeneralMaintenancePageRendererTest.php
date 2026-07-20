<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminDeveloperController;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionClass;
use Tests\TestCase;

use function Dis\MaintenancePage\renderMaintenancePage;

final class GeneralMaintenancePageRendererTest extends TestCase
{
    private string $repositoryRoot;

    private string $directory;

    private string $noticePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repositoryRoot = dirname(__DIR__, 4);
        require_once $this->repositoryRoot.'/scripts/render-maintenance-page.php';
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-general-maintenance-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->noticePath = $this->directory.DIRECTORY_SEPARATOR.'wallboard-status.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }

        parent::tearDown();
    }

    public function test_it_renders_the_absolute_update_countdown_from_a_valid_notice(): void
    {
        $this->writeNotice([
            'version' => 2,
            'active' => true,
            'kind' => 'update',
            'started_at' => '2026-07-20T10:00:00Z',
            'estimated_duration_seconds' => 900,
            'estimated_completion_at' => '2026-07-20T10:15:00Z',
            'expires_at' => '2026-07-20T16:00:00Z',
        ]);

        $rendered = renderMaintenancePage(
            $this->templatePath(),
            $this->noticePath,
            $this->epoch('2026-07-20T10:05:00Z'),
        );

        self::assertStringContainsString('data-maintenance-kind="update"', $rendered);
        self::assertStringContainsString('data-started-epoch-seconds="1784541600"', $rendered);
        self::assertStringContainsString('data-estimated-duration-seconds="900"', $rendered);
        self::assertStringContainsString('data-estimated-completion-epoch-seconds="1784542500"', $rendered);
        self::assertStringContainsString('role="timer"', $rendered);
        self::assertStringContainsString('Nog even geduld', $rendered);
        self::assertStringContainsString('Serverstatus controleren', $rendered);
    }

    public function test_it_keeps_manual_maintenance_honest_without_an_invented_estimate(): void
    {
        $this->writeNotice([
            'version' => 1,
            'active' => true,
            'kind' => 'maintenance',
            'started_at' => '2026-07-20T10:00:00Z',
            'expires_at' => '2026-07-20T12:00:00Z',
        ]);

        $rendered = renderMaintenancePage(
            $this->templatePath(),
            $this->noticePath,
            $this->epoch('2026-07-20T10:05:00Z'),
        );

        self::assertStringContainsString('data-maintenance-kind="maintenance"', $rendered);
        self::assertStringContainsString('data-started-epoch-seconds="1784541600"', $rendered);
        self::assertStringContainsString('data-estimated-duration-seconds="0"', $rendered);
        self::assertStringContainsString('data-estimated-completion-epoch-seconds="0"', $rendered);
    }

    public function test_it_fails_closed_for_expired_or_extended_notice_data(): void
    {
        $this->writeNotice([
            'version' => 2,
            'active' => true,
            'kind' => 'update',
            'started_at' => '2026-07-20T08:00:00Z',
            'estimated_duration_seconds' => 900,
            'estimated_completion_at' => '2026-07-20T08:15:00Z',
            'expires_at' => '2026-07-20T09:00:00Z',
            'message' => '<script>onerror=alert(1)</script>',
        ]);

        $rendered = renderMaintenancePage(
            $this->templatePath(),
            $this->noticePath,
            $this->epoch('2026-07-20T10:05:00Z'),
        );

        self::assertStringContainsString(
            'data-maintenance-kind="maintenance" data-started-epoch-seconds="0" data-estimated-duration-seconds="0" data-estimated-completion-epoch-seconds="0"',
            $rendered,
        );
        self::assertStringNotContainsString('onerror=alert', $rendered);
    }

    public function test_the_shared_template_is_standalone_responsive_and_auto_recovers(): void
    {
        $template = $this->read('webapp/backend/resources/views/errors/503.blade.php');

        self::assertStringContainsString('<meta http-equiv="refresh" content="20">', $template);
        self::assertStringContainsString('class="maintenance-shell"', $template);
        self::assertStringContainsString('class="update-icon"', $template);
        self::assertStringContainsString('Automatisch herstel is actief', $template);
        self::assertStringContainsString('@media (max-width: 620px)', $template);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $template);
        self::assertStringNotContainsString('src="http', $template);
        self::assertStringNotContainsString('href="http', $template);
        self::assertStringNotContainsString('@for', $template);
        self::assertStringNotContainsString('Â', $template);
    }

    public function test_nginx_allows_only_the_exact_inline_style_and_script(): void
    {
        $template = $this->read('webapp/backend/resources/views/errors/503.blade.php');
        $nginx = $this->read('infrastructure/nginx/dis.conf');
        self::assertSame(1, preg_match('/<style>(.*?)<\/style>/s', $template, $styleMatch));
        self::assertSame(1, preg_match('/<script>(.*?)<\/script>/s', $template, $scriptMatch));

        $styleHash = 'sha256-'.base64_encode(hash('sha256', $styleMatch[1], true));
        $scriptHash = 'sha256-'.base64_encode(hash('sha256', $scriptMatch[1], true));
        self::assertStringContainsString("style-src '{$styleHash}'", $nginx);
        self::assertStringContainsString("script-src '{$scriptHash}'", $nginx);
        self::assertStringContainsString("'sha256-FEXUkgpC3PlmFkN2GgDFJMfTYEYQZzQC97pDyLAblN0='", $nginx);
        self::assertStringContainsString("'sha256-2I5+J0GujPJSvg9ZbrNoDTD/XBnTyZqqWgKAz06SGAI='", $nginx);
        self::assertStringNotContainsString("script-src 'unsafe-inline'", $nginx);
    }

    public function test_deployment_renders_and_replaces_the_page_atomically(): void
    {
        $common = $this->read('scripts/lib/common.sh');
        $controller = $this->read('webapp/backend/app/Http/Controllers/AdminDeveloperController.php');
        $renderer = $this->read('scripts/render-maintenance-page.php');

        self::assertStringContainsString('/scripts/render-maintenance-page.php', $common);
        self::assertStringContainsString('/webapp/backend/resources/views/errors/503.blade.php', $common);
        self::assertStringContainsString('"${WALLBOARD_MAINTENANCE_NOTICE_PATH}" > "${temporary}"', $common);
        self::assertStringContainsString('mktemp "$(dirname "${page_path}")/.dis-maintenance-page.XXXXXX"', $common);
        self::assertStringContainsString('run_cmd mv -fT -- "${temporary}" "${page_path}"', $common);
        self::assertStringContainsString('0:0:644:1', $common);
        self::assertStringContainsString("resource_path('views/errors/503.blade.php')", $controller);
        self::assertStringNotContainsString('drone-lane', $controller);
        self::assertStringContainsString("tempnam(\$directory, '.dis-maintenance-')", $controller);
        self::assertStringContainsString('File::move($temporary, $path)', $controller);
        self::assertStringNotContainsString('if (! is_file($pagePath))', $controller);
        self::assertStringContainsString('writeAll(STDOUT, renderMaintenancePage(', $renderer);
    }

    public function test_release_activates_the_matching_csp_before_publishing_the_rich_page(): void
    {
        $deploy = $this->read('scripts/deploy.sh');
        $bootstrap = strpos($deploy, 'enable_frontend_maintenance bootstrap');
        $nginxInstall = strpos($deploy, 'run_cmd install -m 0644 "${NGINX_SOURCE}"');
        $nginxTest = strpos($deploy, 'run_cmd nginx -t', (int) $nginxInstall);
        $nginxRestart = strpos($deploy, 'run_cmd systemctl restart nginx', (int) $nginxTest);
        $richPage = strpos($deploy, 'write_maintenance_page', (int) $nginxRestart);

        self::assertIsInt($bootstrap);
        self::assertIsInt($nginxInstall);
        self::assertIsInt($nginxTest);
        self::assertIsInt($nginxRestart);
        self::assertIsInt($richPage);
        self::assertTrue(
            $bootstrap < $nginxInstall
            && $nginxInstall < $nginxTest
            && $nginxTest < $nginxRestart
            && $nginxRestart < $richPage,
        );
        self::assertStringContainsString("Preserving the parent operation's compatible maintenance page", $deploy);

        $common = $this->read('scripts/lib/common.sh');
        $bootstrapWriter = $this->functionBody(
            $common,
            'write_bootstrap_maintenance_page() (',
            'enable_frontend_maintenance() {',
        );
        self::assertStringContainsString('<meta http-equiv="refresh" content="20">', $bootstrapWriter);
        self::assertStringNotContainsString('<style>', $bootstrapWriter);
        self::assertStringNotContainsString('<script>', $bootstrapWriter);
    }

    public function test_developer_fallback_atomically_replaces_stale_page_metadata(): void
    {
        $pagePath = $this->directory.DIRECTORY_SEPARATOR.'__dis_maintenance.html';
        $lockPath = $this->directory.DIRECTORY_SEPARATOR.'frontend.lock';
        file_put_contents($pagePath, '<body data-maintenance-kind="update">stale update</body>');

        $reflection = new ReflectionClass(AdminDeveloperController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('tryWriteFrontendMaintenanceLock');

        self::assertTrue($method->invoke($controller, $this->directory, $pagePath, $lockPath));
        $page = file_get_contents($pagePath);
        self::assertIsString($page);
        self::assertStringContainsString('class="maintenance-shell"', $page);
        self::assertStringContainsString('data-maintenance-kind="maintenance"', $page);
        self::assertStringNotContainsString('stale update', $page);
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0644, fileperms($pagePath) & 0777);
        }
        self::assertTrue(is_file($lockPath));
        self::assertFalse(is_link($pagePath));
        self::assertFalse(is_link($lockPath));
    }

    /** @param array<string, mixed> $payload */
    private function writeNotice(array $payload): void
    {
        file_put_contents($this->noticePath, json_encode($payload, JSON_THROW_ON_ERROR));
        clearstatcache(true, $this->noticePath);
    }

    private function templatePath(): string
    {
        return $this->repositoryRoot.'/webapp/backend/resources/views/errors/503.blade.php';
    }

    private function read(string $relativePath): string
    {
        $contents = file_get_contents($this->repositoryRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
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

    private function epoch(string $timestamp): int
    {
        return (new DateTimeImmutable($timestamp, new DateTimeZone('UTC')))->getTimestamp();
    }
}
