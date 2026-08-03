<?php

namespace Tests\Unit;

use App\Services\SystemDataUsageService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemDataUsageServiceTest extends TestCase
{
    private string $fixtureRoot;

    private string $snapshotPath;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-03T12:00:00Z'));
        $this->fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-system-data-usage-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot, 0700, true);
        $this->snapshotPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'storage-usage.json';
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        foreach (glob($this->fixtureRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            if (is_file($path) || is_link($path)) {
                unlink($path);
            }
        }
        rmdir($this->fixtureRoot);

        parent::tearDown();
    }

    #[Test]
    public function it_projects_only_allowlisted_directories_and_sorts_largest_first(): void
    {
        $this->writeSnapshot([
            'webapp' => 2_048,
            'storage' => 2_048,
            'backup' => 4_096,
            'secrets' => 64,
            '../../private/customer-name' => 9_999_999,
            'unmanaged-personal-folder' => 8_888_888,
        ]);

        $snapshot = $this->service()->snapshot();

        self::assertSame('2026-08-03T11:59:00Z', $snapshot['generated_at']);
        self::assertFalse($snapshot['stale']);
        self::assertSame([
            [
                'name' => 'backup',
                'label' => 'backup',
                'description' => 'Lokale back-upgegevens.',
                'size_bytes' => 4_096,
            ],
            [
                'name' => 'storage',
                'label' => 'storage',
                'description' => 'Algemene blijvende applicatieopslag.',
                'size_bytes' => 2_048,
            ],
            [
                'name' => 'webapp',
                'label' => 'webapp',
                'description' => 'Blijvende opslag van de webapplicatie.',
                'size_bytes' => 2_048,
            ],
        ], $snapshot['directories']);
        self::assertStringNotContainsString('secrets', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('customer-name', json_encode($snapshot, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('unmanaged-personal-folder', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function it_marks_old_and_far_future_snapshots_as_stale(): void
    {
        $this->writeSnapshot(['storage' => 100], '2026-08-03T08:59:59Z');
        self::assertTrue($this->service()->snapshot()['stale']);

        $this->writeSnapshot(['storage' => 100], '2026-08-03T12:05:01Z');
        self::assertTrue($this->service()->snapshot()['stale']);
    }

    #[Test]
    public function it_fails_closed_for_missing_malformed_or_oversized_snapshots(): void
    {
        self::assertSame($this->unavailable(), $this->service()->snapshot());

        file_put_contents($this->snapshotPath, '{"version":1,"generated_at":"private/path"}');
        chmod($this->snapshotPath, 0640);
        self::assertSame($this->unavailable(), $this->service()->snapshot());

        file_put_contents($this->snapshotPath, str_repeat('x', 65_537));
        chmod($this->snapshotPath, 0640);
        self::assertSame($this->unavailable(), $this->service()->snapshot());
    }

    #[Test]
    public function it_rejects_symbolic_links_and_hard_links(): void
    {
        $outside = $this->fixtureRoot.DIRECTORY_SEPARATOR.'outside.json';
        file_put_contents($outside, json_encode($this->payload(['storage' => 500]), JSON_THROW_ON_ERROR));
        chmod($outside, 0640);

        $symlinkCreated = PHP_OS_FAMILY === 'Windows' ? false : @symlink($outside, $this->snapshotPath);
        if ($symlinkCreated) {
            self::assertSame($this->unavailable(), $this->service()->snapshot());
            unlink($this->snapshotPath);
        }

        $hardlinkCreated = @link($outside, $this->snapshotPath);
        if ($hardlinkCreated) {
            self::assertSame($this->unavailable(), $this->service()->snapshot());
        }

        self::assertTrue($symlinkCreated || $hardlinkCreated || PHP_OS_FAMILY === 'Windows');
    }

    #[Test]
    public function it_rejects_group_or_world_writable_snapshots_on_unix(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix permission bits are not available on Windows.');
        }

        $this->writeSnapshot(['storage' => 100]);
        chmod($this->snapshotPath, 0666);

        self::assertSame($this->unavailable(), $this->service()->snapshot());
    }

    /** @param array<string, int> $directories */
    private function writeSnapshot(array $directories, string $generatedAt = '2026-08-03T11:59:00Z'): void
    {
        file_put_contents(
            $this->snapshotPath,
            json_encode($this->payload($directories, $generatedAt), JSON_THROW_ON_ERROR),
        );
        chmod($this->snapshotPath, 0640);
    }

    /**
     * @param  array<string, int>  $directories
     * @return array{version: int, generated_at: string, directories: array<string, int>}
     */
    private function payload(array $directories, string $generatedAt = '2026-08-03T11:59:00Z'): array
    {
        return [
            'version' => 1,
            'generated_at' => $generatedAt,
            'directories' => $directories,
        ];
    }

    private function service(): SystemDataUsageService
    {
        return new SystemDataUsageService(
            snapshotPath: $this->snapshotPath,
            staleAfterSeconds: 10_800,
            requireRootOwner: false,
        );
    }

    /** @return array{generated_at: null, stale: true, directories: array{}} */
    private function unavailable(): array
    {
        return [
            'generated_at' => null,
            'stale' => true,
            'directories' => [],
        ];
    }
}
