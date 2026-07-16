<?php

namespace Tests\Unit;

use App\Services\SystemMetricsService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SystemMetricsServiceTest extends TestCase
{
    private string $fixtureRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dis-system-metrics-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->fixtureRoot.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->fixtureRoot);

        parent::tearDown();
    }

    #[Test]
    public function it_parses_proc_metrics_and_uses_a_non_blocking_cpu_delta(): void
    {
        $this->writeProcFixtures(
            stat: "cpu  100 0 50 850 0 0 0 0 0 0\ncpu0 50 0 25 425 0 0 0 0 0 0\ncpu1 50 0 25 425 0 0 0 0 0 0\n",
            meminfo: "MemTotal:        8000000 kB\nMemAvailable:    3000000 kB\n",
            uptime: "12345.67 999.00\n",
            loadavg: "0.42 0.31 0.20 1/100 123\n",
        );
        $service = $this->service('host-a');

        $first = $service->snapshot();
        self::assertNull($first['cpu']['usage_percent']);

        file_put_contents(
            $this->fixtureRoot.DIRECTORY_SEPARATOR.'stat',
            "cpu  160 0 70 870 0 0 0 0 0 0\ncpu0 80 0 35 435 0 0 0 0 0 0\ncpu1 80 0 35 435 0 0 0 0 0 0\n",
        );
        $second = $service->snapshot();

        self::assertSame(80.0, $second['cpu']['usage_percent']);
        self::assertSame(2, $second['cpu']['logical_processors']);
        self::assertSame(0.42, $second['cpu']['load_average_1m']);
        self::assertSame(12345, $second['uptime_seconds']);
        self::assertSame(8_192_000_000, $second['memory']['total_bytes']);
        self::assertSame(5_120_000_000, $second['memory']['used_bytes']);
        self::assertSame(3_072_000_000, $second['memory']['available_bytes']);
        self::assertSame(62.5, $second['memory']['usage_percent']);
        self::assertSame('DIS data', $second['disk']['label']);
        self::assertIsInt($second['disk']['total_bytes']);
        self::assertGreaterThan(0, $second['disk']['total_bytes']);
        self::assertSame(
            $second['disk']['total_bytes'] - $second['disk']['available_bytes'],
            $second['disk']['used_bytes'],
        );
    }

    #[Test]
    public function it_returns_nullable_metrics_for_missing_or_malformed_proc_data(): void
    {
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'stat', "cpu invalid\n");
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'meminfo', "MemTotal: secret\n");
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'uptime', "not-a-number\n");
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'loadavg', "invalid\n");

        $snapshot = $this->service('host-b')->snapshot();

        self::assertNull($snapshot['uptime_seconds']);
        self::assertSame([
            'usage_percent' => null,
            'logical_processors' => null,
            'load_average_1m' => null,
        ], $snapshot['cpu']);
        self::assertSame([
            'total_bytes' => null,
            'used_bytes' => null,
            'available_bytes' => null,
            'usage_percent' => null,
        ], $snapshot['memory']);
        self::assertSame('DIS data', $snapshot['disk']['label']);
    }

    #[Test]
    public function cpu_samples_are_separated_by_host_identity(): void
    {
        $this->writeProcFixtures(
            stat: "cpu  100 0 50 850 0 0 0 0\ncpu0 100 0 50 850 0 0 0 0\n",
            meminfo: "MemTotal: 1000 kB\nMemAvailable: 500 kB\n",
            uptime: "1.0 0.0\n",
            loadavg: "0.01 0.01 0.01 1/1 1\n",
        );
        $cache = new Repository(new ArrayStore);
        $firstHost = $this->service('host-first', $cache);
        $secondHost = $this->service('host-second', $cache);

        self::assertNull($firstHost->snapshot()['cpu']['usage_percent']);
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'stat', "cpu  120 0 60 870 0 0 0 0\ncpu0 120 0 60 870 0 0 0 0\n");
        self::assertNull($secondHost->snapshot()['cpu']['usage_percent']);
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'stat', "cpu  140 0 70 890 0 0 0 0\ncpu0 140 0 70 890 0 0 0 0\n");

        self::assertSame(60.0, $firstHost->snapshot()['cpu']['usage_percent']);
    }

    #[Test]
    public function an_invalid_configured_data_path_is_not_masked_by_another_filesystem(): void
    {
        $this->writeProcFixtures(
            stat: "cpu  100 0 50 850 0 0 0 0\ncpu0 100 0 50 850 0 0 0 0\n",
            meminfo: "MemTotal: 1000 kB\nMemAvailable: 500 kB\n",
            uptime: "1.0 0.0\n",
            loadavg: "0.01 0.01 0.01 1/1 1\n",
        );
        $missingDataPath = $this->fixtureRoot.DIRECTORY_SEPARATOR.'missing-data-volume';
        $service = new SystemMetricsService(
            cache: new Repository(new ArrayStore),
            procRoot: $this->fixtureRoot,
            diskPath: $missingDataPath,
            hostIdentity: 'host-invalid-disk',
        );

        self::assertSame([
            'label' => 'DIS data',
            'total_bytes' => null,
            'used_bytes' => null,
            'available_bytes' => null,
            'usage_percent' => null,
        ], $service->snapshot()['disk']);
    }

    private function service(string $hostIdentity, ?Repository $cache = null): SystemMetricsService
    {
        return new SystemMetricsService(
            cache: $cache ?? new Repository(new ArrayStore),
            procRoot: $this->fixtureRoot,
            diskPath: $this->fixtureRoot,
            hostIdentity: $hostIdentity,
        );
    }

    private function writeProcFixtures(string $stat, string $meminfo, string $uptime, string $loadavg): void
    {
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'stat', $stat);
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'meminfo', $meminfo);
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'uptime', $uptime);
        file_put_contents($this->fixtureRoot.DIRECTORY_SEPARATOR.'loadavg', $loadavg);
    }
}
