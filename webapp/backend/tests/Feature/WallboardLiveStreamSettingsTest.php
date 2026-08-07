<?php

namespace Tests\Feature;

use App\Services\WallboardLiveStreamProcessService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class WallboardLiveStreamSettingsTest extends TestCase
{
    private string $runtimeDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeDirectory = storage_path('framework/testing/dis-wallboard-live-'.Str::lower((string) Str::ulid()));
        $this->configure();
    }

    public function test_enabled_settings_are_strict_and_use_an_isolated_hls_subdirectory(): void
    {
        $stream = app(WallboardLiveStreamProcessService::class);
        $settings = $stream->settings();

        $this->assertTrue($settings['enabled']);
        $this->assertSame('stream.example.test', $settings['public_host']);
        $this->assertSame('0.0.0.0', $settings['rtmps_bind_address']);
        $this->assertSame(1936, $settings['rtmps_port']);
        $this->assertTrue($settings['stream_key_configured']);
        $this->assertSame('/etc/letsencrypt/live/stream.example.test/fullchain.pem', $settings['tls_certificate_path']);
        $this->assertSame('/etc/letsencrypt/live/stream.example.test/privkey.pem', $settings['tls_private_key_path']);
        $this->assertSame($this->runtimeDirectory, $settings['runtime_directory']);
        $this->assertSame(
            $this->runtimeDirectory.DIRECTORY_SEPARATOR.'hls',
            $settings['output_directory'],
        );
        $this->assertSame('stream.example.test', $stream->rtmpsHost());
        $this->assertSame('index.m3u8', WallboardLiveStreamProcessService::MANIFEST_FILE);
        $this->assertSame(1, preg_match(
            WallboardLiveStreamProcessService::SEGMENT_PATTERN,
            'segment-00000000000000000001.ts',
        ));
    }

    #[DataProvider('invalidStreamKeyProvider')]
    public function test_enabled_stream_rejects_weak_or_url_unsafe_stream_keys(string $streamKey): void
    {
        config()->set('wallboard_live_stream.stream_key', $streamKey);

        $this->expectException(RuntimeException::class);
        app(WallboardLiveStreamProcessService::class)->settings();
    }

    /** @return iterable<string, array{0: string}> */
    public static function invalidStreamKeyProvider(): iterable
    {
        yield 'missing' => [''];
        yield 'too short' => [str_repeat('a', 31)];
        yield 'too long' => [str_repeat('a', 80)];
        yield 'all identical' => [str_repeat('a', 32)];
        yield 'query separator' => ['StrongSecretThatBreaksTheQueryValue&42'];
        yield 'path separator' => ['StrongSecretThatBreaksThePathValue/42'];
        yield 'space' => ['Strong Secret Is Definitely Not URL Safe 42'];
        yield 'control byte' => ["StrongSecretWithForbiddenNewlineValue42\n"];
    }

    #[DataProvider('invalidListenerProvider')]
    public function test_enabled_stream_rejects_invalid_listener_configuration(
        string $key,
        mixed $value,
    ): void {
        config()->set('wallboard_live_stream.'.$key, $value);

        $this->expectException(RuntimeException::class);
        app(WallboardLiveStreamProcessService::class)->settings();
    }

    /** @return iterable<string, array{0: string, 1: mixed}> */
    public static function invalidListenerProvider(): iterable
    {
        yield 'missing public host' => ['public_host', ''];
        yield 'public host with scheme' => ['public_host', 'https://stream.example.test'];
        yield 'public host with port' => ['public_host', 'stream.example.test:1936'];
        yield 'wildcard public host' => ['public_host', '0.0.0.0'];
        yield 'multicast public host' => ['public_host', '239.1.2.3'];
        yield 'hostname bind' => ['rtmps_bind_address', 'stream.example.test'];
        yield 'multicast bind' => ['rtmps_bind_address', '239.1.2.3'];
        yield 'privileged port' => ['rtmps_port', 443];
        yield 'internal RTMP port' => ['rtmps_port', 19350];
        yield 'internal auth port' => ['rtmps_port', 19351];
        yield 'port beyond range' => ['rtmps_port', 65536];
        yield 'relative certificate' => ['tls_certificate_path', 'fullchain.pem'];
        yield 'certificate traversal' => ['tls_certificate_path', '/etc/letsencrypt/../private/fullchain.pem'];
        yield 'relative private key' => ['tls_private_key_path', 'privkey.pem'];
    }

    public function test_output_directory_cannot_escape_the_isolated_runtime_hls_directory(): void
    {
        config()->set('wallboard_live_stream.output_directory', $this->runtimeDirectory);

        $this->expectException(RuntimeException::class);
        app(WallboardLiveStreamProcessService::class)->settings();
    }

    public function test_disabled_service_remains_valid_without_a_stream_key_or_tls_configuration(): void
    {
        config()->set('wallboard_live_stream.enabled', false);
        config()->set('wallboard_live_stream.stream_key', '');
        config()->set('wallboard_live_stream.public_host', '');
        config()->set('wallboard_live_stream.tls_certificate_path', '');
        config()->set('wallboard_live_stream.tls_private_key_path', '');

        $settings = app(WallboardLiveStreamProcessService::class)->settings();

        $this->assertFalse($settings['enabled']);
        $this->assertFalse($settings['stream_key_configured']);
        $this->assertNull($settings['public_host']);
    }

    public function test_laravel_has_no_network_ingest_execution_path(): void
    {
        $this->assertFileDoesNotExist(app_path('Console/Commands/RunWallboardLiveStream.php'));
        $source = file_get_contents(app_path('Services/WallboardLiveStreamProcessService.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('new Process(', $source);
        $this->assertStringNotContainsString('function command(', $source);
        $this->assertStringNotContainsString('prepareOutputDirectory', $source);
        $this->assertStringNotContainsString('passphrase=', $source);
        $this->assertStringNotContainsString('rtmps://', $source);
    }

    private function configure(): void
    {
        config()->set('wallboard_live_stream', [
            'enabled' => true,
            'public_host' => 'stream.example.test',
            'rtmps_bind_address' => '0.0.0.0',
            'rtmps_port' => 1936,
            'stream_key' => 'Secure_URL-Safe.Stream-Key~2026_A9',
            'tls_certificate_path' => '/etc/letsencrypt/live/stream.example.test/fullchain.pem',
            'tls_private_key_path' => '/etc/letsencrypt/live/stream.example.test/privkey.pem',
            'runtime_directory' => $this->runtimeDirectory,
            'output_directory' => $this->runtimeDirectory.DIRECTORY_SEPARATOR.'hls',
            'segment_duration_seconds' => 2,
            'segment_list_size' => 6,
            'manifest_stale_seconds' => 12,
            'max_manifest_bytes' => 64 * 1024,
            'max_segment_bytes' => 6 * 1024 * 1024,
        ]);
    }
}
