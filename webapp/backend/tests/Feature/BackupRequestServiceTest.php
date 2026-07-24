<?php

namespace Tests\Feature;

use App\Services\BackupRequestService;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

final class BackupRequestServiceTest extends TestCase
{
    private string $requestRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requestRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-backup-request-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($this->requestRoot, 0770, true));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->requestRoot)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->requestRoot, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    rmdir($entry->getPathname());
                } else {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->requestRoot);
        }

        parent::tearDown();
    }

    public function test_create_publishes_a_complete_request_and_returns_the_worker_result(): void
    {
        $requestId = str_repeat('a', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $resultPath = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $requestPayload = null;

        $service = new BackupRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function (int $microseconds) use ($pending, $resultPath, &$requestPayload): void {
                $this->assertSame(500_000, $microseconds);
                $contents = file_get_contents($pending);
                $this->assertIsString($contents);
                $requestPayload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($resultPath, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Backup completed.',
                    'finished_at' => '2026-07-15T02:16:00Z',
                ], JSON_THROW_ON_ERROR)));
            },
        );

        $result = $service->create('local', null, 5);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame($requestId, $result['request_id']);
        $this->assertStringContainsString($requestId, $result['output']);
        $this->assertStringContainsString('Backup completed.', $result['output']);
        $this->assertSame('create', $requestPayload['operation'] ?? null);
        $this->assertSame('local', $requestPayload['target'] ?? null);
        $this->assertNull($requestPayload['backup_path'] ?? null);
        $this->assertNull($requestPayload['actor_id'] ?? null);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) ($requestPayload['created_at'] ?? ''),
        );
        $this->assertFileDoesNotExist($pending);
        $this->assertFileDoesNotExist($resultPath);
        $this->assertFileDoesNotExist($this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.tmp');
    }

    public function test_publication_failure_does_not_overwrite_an_existing_pending_request(): void
    {
        $requestId = str_repeat('b', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $this->assertNotFalse(file_put_contents($pending, 'existing-request'));

        $service = new BackupRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
        );

        $result = $service->probe();

        $this->assertSame(1, $result['exit_code']);
        $this->assertSame($requestId, $result['request_id']);
        $this->assertStringContainsString($requestId, $result['output']);
        $this->assertStringContainsString('niet veilig en duurzaam', $result['output']);
        $this->assertSame('existing-request', file_get_contents($pending));
        $this->assertFileDoesNotExist($this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.tmp');
    }

    public function test_prune_publishes_an_explicit_target_request_and_returns_the_worker_result(): void
    {
        $requestId = str_repeat('e', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $resultPath = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.result';
        $requestPayload = null;
        $actorId = '01J00000000000000000000000';
        $runtimeConfigSha256 = str_repeat('f', 64);

        $service = new BackupRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static fn (): float => 0.0,
            sleeper: function (int $microseconds) use ($pending, $resultPath, &$requestPayload): void {
                $this->assertSame(500_000, $microseconds);
                $contents = file_get_contents($pending);
                $this->assertIsString($contents);
                $requestPayload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
                $this->assertTrue(unlink($pending));
                $this->assertNotFalse(file_put_contents($resultPath, json_encode([
                    'state' => 'succeeded',
                    'exit_code' => 0,
                    'output' => 'Backup retention applied.',
                    'finished_at' => '2026-07-24T12:00:00Z',
                ], JSON_THROW_ON_ERROR)));
            },
        );

        $result = $service->prune('samba', $actorId, $runtimeConfigSha256, 5);

        $this->assertSame(0, $result['exit_code']);
        $this->assertSame($requestId, $result['request_id']);
        $this->assertStringContainsString('Backup retention applied.', $result['output']);
        $this->assertSame('prune', $requestPayload['operation'] ?? null);
        $this->assertSame('samba', $requestPayload['target'] ?? null);
        $this->assertNull($requestPayload['backup_path'] ?? null);
        $this->assertSame($actorId, $requestPayload['actor_id'] ?? null);
        $this->assertSame($runtimeConfigSha256, $requestPayload['runtime_config_sha256'] ?? null);
        $this->assertSame([
            'operation',
            'target',
            'backup_path',
            'actor_id',
            'created_at',
            'runtime_config_sha256',
        ], array_keys($requestPayload));
        $this->assertArrayNotHasKey('BACKUP_SAMBA_PASSWORD', $requestPayload);
    }

    public function test_prune_rejects_an_invalid_runtime_configuration_fingerprint_before_publication(): void
    {
        $service = new BackupRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => str_repeat('f', 32),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Backup prune runtime configuration fingerprint is invalid.');

        try {
            $service->prune('local', null, 'not-a-sha256', 5);
        } finally {
            $this->assertSame([], glob($this->requestRoot.DIRECTORY_SEPARATOR.'*') ?: []);
        }
    }

    public function test_timeout_before_claim_reports_that_the_path_service_did_not_claim_the_request(): void
    {
        $requestId = str_repeat('c', 32);
        $clockValues = [0.0, 2.0];
        $service = new BackupRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static function () use (&$clockValues): float {
                return array_shift($clockValues) ?? 2.0;
            },
            sleeper: static function (int $microseconds): void {},
        );

        $result = $service->probe(1);

        $this->assertSame(124, $result['exit_code']);
        $this->assertSame($requestId, $result['request_id']);
        $this->assertStringContainsString($requestId, $result['output']);
        $this->assertStringContainsString('niet binnen 1 seconde', $result['output']);
        $this->assertStringContainsString('geclaimd', $result['output']);
        $this->assertStringContainsString('dis-backup-request.path', $result['output']);
        $this->assertFileDoesNotExist($this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending');
    }

    public function test_timeout_after_claim_reports_that_the_worker_did_not_finish(): void
    {
        $requestId = str_repeat('d', 32);
        $pending = $this->requestRoot.DIRECTORY_SEPARATOR.$requestId.'.pending';
        $clockValues = [0.0, 0.0, 2.0];
        $service = new BackupRequestService(
            requestRootOverride: $this->requestRoot,
            requestIdGenerator: static fn (): string => $requestId,
            monotonicClock: static function () use (&$clockValues): float {
                return array_shift($clockValues) ?? 2.0;
            },
            sleeper: function (int $microseconds) use ($pending): void {
                $this->assertSame(500_000, $microseconds);
                $this->assertTrue(unlink($pending));
            },
        );

        $result = $service->probe(1);

        $this->assertSame(124, $result['exit_code']);
        $this->assertSame($requestId, $result['request_id']);
        $this->assertStringContainsString($requestId, $result['output']);
        $this->assertStringContainsString('geclaimd, maar niet binnen 1 seconde afgerond', $result['output']);
        $this->assertStringContainsString('worker-logs', $result['output']);
    }

    public function test_operation_timeout_defaults_leave_room_for_worker_shutdown_and_result_publication(): void
    {
        $this->assertInstanceOf(BackupRequestService::class, app(BackupRequestService::class));
        $this->assertSame(1020, BackupRequestService::CREATE_TIMEOUT_SECONDS);
        $this->assertSame(1020, BackupRequestService::PRUNE_TIMEOUT_SECONDS);
        $this->assertSame(720, BackupRequestService::VERIFY_TIMEOUT_SECONDS);
        $this->assertSame(30, BackupRequestService::PROBE_TIMEOUT_SECONDS);
    }
}
