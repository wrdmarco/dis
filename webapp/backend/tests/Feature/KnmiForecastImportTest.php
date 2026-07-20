<?php

namespace Tests\Feature;

use App\DTO\KnmiOpenDataArchive;
use App\Exceptions\KnmiForecastImportException;
use App\Models\KnmiForecastOperation;
use App\Models\KnmiForecastSnapshot;
use App\Repositories\KnmiForecastSnapshotRepository;
use App\Services\KnmiForecastImportService;
use App\Services\KnmiForecastTarExtractor;
use App\Services\KnmiOpenDataClient;
use App\Services\KnmiOpenDataConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpClientFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class KnmiForecastImportTest extends TestCase
{
    use RefreshDatabase;

    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = storage_path('framework/testing/knmi-'.str()->lower((string) str()->ulid()));
        File::makeDirectory($this->storageRoot, 0770, true);
        config()->set([
            'dis.knmi_forecast.api_key' => 'knmi-open-data-key-123456',
            'dis.knmi_forecast.storage_root' => $this->storageRoot,
            'dis.knmi_forecast.minimum_archive_bytes' => 1,
            'dis.knmi_forecast.maximum_archive_bytes' => 2_000_000,
            'dis.knmi_forecast.retain_releases' => 2,
            'dis.wallboards.uav_forecast.knmi_edr_api_key' => null,
        ]);
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $path = is_array($command) ? end($command) : null;

            return is_string($path)
                ? Process::result(output: $this->semanticOutputForPath($path))
                : Process::result(exitCode: 1, errorOutput: 'invalid command');
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function test_storage_root_rejects_filesystem_roots_and_other_broad_paths_before_they_can_be_modified(): void
    {
        $configuration = app(KnmiOpenDataConfiguration::class);

        foreach (['/', '/opt', '../knmi', 'C:\\', 'C:\\data', '\\\\server\\share'] as $unsafeRoot) {
            config()->set('dis.knmi_forecast.storage_root', $unsafeRoot);

            try {
                $configuration->storageRoot();
                $this->fail('Unsafe KNMI storage root was accepted: '.$unsafeRoot);
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('storage root', strtolower($exception->getMessage()));
            }
        }

        config()->set('dis.knmi_forecast.storage_root', $this->storageRoot);
        $this->assertSame($this->storageRoot, $configuration->storageRoot());
    }

    public function test_open_data_client_downloads_the_complete_http_200_object_without_range_or_forwarded_key(): void
    {
        $body = 'complete-tar-body';
        $filename = 'HARM43_V1_P1_2026072015.tar';
        $downloadUrl = $this->downloadUrl($filename);
        $this->fakeKnmi($filename, $body, $downloadUrl);
        $destination = $this->storageRoot.'/download.tar';

        $client = app(KnmiOpenDataClient::class);
        $archive = $client->latestArchive();
        $sha256 = $client->download($archive, $destination, static function (): void {});

        $this->assertSame(strlen($body), $archive->sizeBytes);
        $this->assertSame(hash('sha256', $body), $sha256);
        $this->assertSame($body, file_get_contents($destination));
        Http::assertSent(function (Request $request) use ($downloadUrl): bool {
            if ($request->url() !== $downloadUrl) {
                return true;
            }

            return ! $request->hasHeader('Range') && ! $request->hasHeader('Authorization');
        });
        $downloadRequest = collect(Http::recorded())
            ->map(static fn (array $pair): Request => $pair[0])
            ->first(static fn (Request $request): bool => $request->url() === $downloadUrl);
        $this->assertInstanceOf(Request::class, $downloadRequest);
        $this->assertFalse($downloadRequest->hasHeader('Range'));
        $this->assertFalse($downloadRequest->hasHeader('Authorization'));
    }

    public function test_client_rejects_an_unapproved_presigned_host_before_download(): void
    {
        $filename = 'HARM43_V1_P1_2026072015.tar';
        $this->fakeKnmi($filename, 'payload', 'https://attacker.example.test/'.$filename.'?signature=secret');
        $client = app(KnmiOpenDataClient::class);

        try {
            $client->download($client->latestArchive(), $this->storageRoot.'/blocked.tar', static function (): void {});
            $this->fail('Unsafe download URL was accepted.');
        } catch (KnmiForecastImportException $exception) {
            $this->assertSame('download_url_invalid', $exception->publicCode);
        }
        $this->assertFileDoesNotExist($this->storageRoot.'/blocked.tar');
        Http::assertNotSent(static fn (Request $request): bool => $request->url() === 'https://attacker.example.test/'.$filename.'?signature=secret');
    }

    public function test_client_rejects_partial_content_even_when_the_body_size_matches(): void
    {
        $filename = 'HARM43_V1_P1_2026072015.tar';
        $body = 'partial-content';
        $downloadUrl = $this->downloadUrl($filename);
        Http::fake(function (Request $request) use ($filename, $body, $downloadUrl) {
            if (str_ends_with($request->url(), '/files?maxKeys=1&orderBy=created&sorting=desc')) {
                return Http::response(['files' => [['filename' => $filename, 'size' => strlen($body)]]]);
            }
            if (str_ends_with($request->url(), '/files/'.$filename.'/url')) {
                return Http::response(['temporaryDownloadUrl' => $downloadUrl]);
            }

            return Http::response($body, 206, [
                'Content-Length' => (string) strlen($body),
                'Content-Range' => 'bytes 0-'.(strlen($body) - 1).'/'.strlen($body),
            ]);
        });
        $client = app(KnmiOpenDataClient::class);

        try {
            $client->download($client->latestArchive(), $this->storageRoot.'/partial.tar', static function (): void {});
            $this->fail('HTTP 206 was accepted for a full archive download.');
        } catch (KnmiForecastImportException $exception) {
            $this->assertSame('download_failed', $exception->publicCode);
        }
    }

    public function test_strict_tar_parser_extracts_all_61_grib_members_and_rejects_corruption(): void
    {
        $filename = 'HARM43_V1_P1_2026072015.tar';
        $tar = $this->validTar('2026072015');
        $archivePath = $this->storageRoot.'/'.$filename;
        file_put_contents($archivePath, $tar);
        $destination = $this->storageRoot.'/extracted';
        File::makeDirectory($destination, 0770, true);
        $archive = new KnmiOpenDataArchive($filename, strlen($tar));

        $manifest = app(KnmiForecastTarExtractor::class)->extract(
            $archivePath,
            $destination,
            $archive,
            hash('sha256', $tar),
        );

        $this->assertCount(61, $manifest['members']);
        $this->assertSame(range(0, 60), array_column($manifest['members'], 'lead_hours'));
        $this->assertSame('HA43_N20_202607201500_00000_GB', $manifest['members'][0]['filename']);
        $this->assertSame('HA43_N20_202607201500_06000_GB', $manifest['members'][60]['filename']);
        $this->assertCount(61, glob($destination.'/HA43_N20_*_GB') ?: []);

        $corrupt = $tar;
        $corrupt[0] = $corrupt[0] === 'X' ? 'Y' : 'X';
        $corruptPath = $this->storageRoot.'/corrupt.tar';
        file_put_contents($corruptPath, $corrupt);
        $corruptDestination = $this->storageRoot.'/corrupt';
        File::makeDirectory($corruptDestination, 0770, true);
        $this->expectException(KnmiForecastImportException::class);
        app(KnmiForecastTarExtractor::class)->extract(
            $corruptPath,
            $corruptDestination,
            new KnmiOpenDataArchive($filename, strlen($corrupt)),
            hash('sha256', $corrupt),
        );
    }

    public function test_import_activates_only_a_complete_release_deletes_tar_and_rolls_back_invalid_successor(): void
    {
        $firstFilename = 'HARM43_V1_P1_2026072015.tar';
        $firstTar = $this->validTar('2026072015');
        $this->fakeKnmi($firstFilename, $firstTar, $this->downloadUrl($firstFilename));
        $firstOperation = $this->operation();

        app(KnmiForecastImportService::class)->run($firstOperation->id);

        $firstOperation->refresh();
        $this->assertSame(KnmiForecastOperation::STATE_SUCCEEDED, $firstOperation->state, (string) $firstOperation->error_code.' '.$firstOperation->message);
        $this->assertSame(100, $firstOperation->progress_percent);
        $snapshot = KnmiForecastSnapshot::query()->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)->firstOrFail();
        $this->assertSame(61, $snapshot->member_count);
        $release = $this->storageRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $snapshot->release_directory);
        $this->assertDirectoryExists($release);
        $this->assertFileExists($release.'/manifest.json');
        $this->assertSame([], glob($release.'/*.tar') ?: []);
        $this->assertSame([], glob($this->storageRoot.'/staging/*/*.tar') ?: []);
        Process::assertRanTimes(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === '/usr/bin/grib_get', 61);

        $member = app(KnmiForecastSnapshotRepository::class)->closestMember(now()->setTimezone('UTC'));
        $this->assertNotNull($member);
        $this->assertFileExists($member['path']);
        $this->assertStringStartsWith(realpath($release), $member['path']);

        $secondFilename = 'HARM43_V1_P1_2026072016.tar';
        $invalidTar = 'not-a-tar';
        Http::swap(new HttpClientFactory);
        $this->fakeKnmi($secondFilename, $invalidTar, $this->downloadUrl($secondFilename));
        $secondOperation = $this->operation();
        app(KnmiForecastImportService::class)->run($secondOperation->id);

        $secondOperation->refresh();
        $this->assertSame(KnmiForecastOperation::STATE_FAILED, $secondOperation->state);
        $this->assertSame('archive_invalid', $secondOperation->error_code);
        $this->assertSame($snapshot->id, KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->value('id'));
        $this->assertDirectoryExists($release);
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);
    }

    public function test_same_latest_filename_finishes_without_downloading_again(): void
    {
        $filename = 'HARM43_V1_P1_2026072015.tar';
        $tar = $this->validTar('2026072015');
        $this->fakeKnmi($filename, $tar, $this->downloadUrl($filename));
        $first = $this->operation();
        app(KnmiForecastImportService::class)->run($first->id);
        Http::swap(new HttpClientFactory);
        Http::fake(function (Request $request) use ($filename, $tar) {
            if (str_ends_with($request->url(), '/files?maxKeys=1&orderBy=created&sorting=desc')) {
                return Http::response(['files' => [['filename' => $filename, 'size' => strlen($tar)]]]);
            }

            return Http::response([], 500);
        });
        $second = $this->operation();

        app(KnmiForecastImportService::class)->run($second->id);

        $second->refresh();
        $this->assertSame(KnmiForecastOperation::STATE_SUCCEEDED, $second->state);
        $this->assertTrue($second->unchanged);
        $this->assertSame(0, $second->downloaded_bytes);
        Http::assertSentCount(1);
    }

    public function test_same_filename_is_reimported_when_active_member_integrity_is_broken(): void
    {
        $filename = 'HARM43_V1_P1_2026072015.tar';
        $tar = $this->validTar('2026072015');
        $this->fakeKnmi($filename, $tar, $this->downloadUrl($filename));
        $first = $this->operation();
        app(KnmiForecastImportService::class)->run($first->id);
        $original = KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->firstOrFail();
        $member = $original->manifest['members'][0];
        $memberPath = $this->storageRoot.'/'.str_replace('/', DIRECTORY_SEPARATOR, $original->release_directory).'/'.$member['filename'];
        chmod($memberPath, 0660);
        file_put_contents($memberPath, 'GRIBbroken7777');
        Http::swap(new HttpClientFactory);
        $this->fakeKnmi($filename, $tar, $this->downloadUrl($filename));
        $repair = $this->operation();

        app(KnmiForecastImportService::class)->run($repair->id);

        $repair->refresh();
        $replacement = KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->firstOrFail();
        $this->assertSame(KnmiForecastOperation::STATE_SUCCEEDED, $repair->state);
        $this->assertFalse($repair->unchanged);
        $this->assertNotSame($original->id, $replacement->id);
        $this->assertSame($filename, $replacement->source_filename);
        $this->assertDatabaseCount('knmi_forecast_snapshots', 2);
    }

    public function test_semantically_invalid_successor_keeps_the_previous_snapshot_active(): void
    {
        $firstFilename = 'HARM43_V1_P1_2026072015.tar';
        $firstTar = $this->validTar('2026072015');
        $this->fakeKnmi($firstFilename, $firstTar, $this->downloadUrl($firstFilename));
        $first = $this->operation();
        app(KnmiForecastImportService::class)->run($first->id);
        $active = KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->firstOrFail();

        $secondFilename = 'HARM43_V1_P1_2026072016.tar';
        $secondTar = $this->validTar('2026072016');
        Http::swap(new HttpClientFactory);
        $this->fakeKnmi($secondFilename, $secondTar, $this->downloadUrl($secondFilename));
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            $path = is_array($command) ? end($command) : null;
            if (! is_string($path)) {
                return Process::result(exitCode: 1);
            }
            $output = $this->semanticOutputForPath($path);

            return Process::result(output: str_ends_with($path, '_01700_GB')
                ? str_replace('73 105 0 0', '73 100 0 0', $output)
                : $output);
        });
        $second = $this->operation();

        app(KnmiForecastImportService::class)->run($second->id);

        $second->refresh();
        $this->assertSame(KnmiForecastOperation::STATE_FAILED, $second->state);
        $this->assertSame('grib_semantic_invalid', $second->error_code);
        $this->assertSame($active->id, KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->value('id'));
        $this->assertSame([], glob($this->storageRoot.'/staging/*') ?: []);
    }

    public function test_new_import_sweeps_only_old_unreferenced_ulid_directories(): void
    {
        $stagingId = strtolower((string) str()->ulid());
        $orphanId = strtolower((string) str()->ulid());
        $staging = $this->storageRoot.'/staging/'.$stagingId;
        $orphan = $this->storageRoot.'/releases/'.$orphanId;
        File::makeDirectory($staging, 0770, true);
        File::makeDirectory($orphan, 0770, true);
        file_put_contents($staging.'/partial.tar', 'partial');
        file_put_contents($orphan.'/orphan.grib', 'orphan');
        $external = $this->storageRoot.'/external-hardlink-target.txt';
        file_put_contents($external, 'external-content');
        chmod($external, 0640);
        $externalMode = fileperms($external) & 0777;
        $hardlinkCreated = @link($external, $staging.'/hardlink');
        touch($staging, now()->subHours(5)->getTimestamp());
        touch($orphan, now()->subHours(5)->getTimestamp());
        $protected = $this->storageRoot.'/protected-target';
        File::makeDirectory($protected, 0770, true);
        file_put_contents($protected.'/keep.txt', 'keep');
        $symlink = $this->storageRoot.'/staging/'.strtolower((string) str()->ulid());
        $symlinkCreated = @symlink($protected, $symlink);

        $filename = 'HARM43_V1_P1_2026072015.tar';
        $tar = $this->validTar('2026072015');
        $this->fakeKnmi($filename, $tar, $this->downloadUrl($filename));
        $operation = $this->operation();
        app(KnmiForecastImportService::class)->run($operation->id);

        $this->assertDirectoryDoesNotExist($staging);
        $this->assertDirectoryDoesNotExist($orphan);
        $this->assertFileExists($protected.'/keep.txt');
        $this->assertSame('external-content', file_get_contents($external));
        if ($hardlinkCreated) {
            $this->assertSame($externalMode, fileperms($external) & 0777);
        }
        if ($symlinkCreated) {
            $this->assertTrue(is_link($symlink));
        }
        $this->assertSame(KnmiForecastOperation::STATE_SUCCEEDED, $operation->refresh()->state);
    }

    private function operation(): KnmiForecastOperation
    {
        return KnmiForecastOperation::query()->create([
            'state' => KnmiForecastOperation::STATE_QUEUED,
            'stage' => 'queued',
            'active_key' => KnmiForecastOperation::ACTIVE_KEY,
            'message' => 'Queued by test.',
            'progress_percent' => 0,
            'downloaded_bytes' => 0,
        ]);
    }

    private function fakeKnmi(string $filename, string $body, string $downloadUrl): void
    {
        Http::fake(function (Request $request) use ($filename, $body, $downloadUrl) {
            if (str_ends_with($request->url(), '/files?maxKeys=1&orderBy=created&sorting=desc')) {
                return Http::response(['files' => [['filename' => $filename, 'size' => strlen($body)]]], 200);
            }
            if (str_ends_with($request->url(), '/files/'.rawurlencode($filename).'/url')) {
                return Http::response(['temporaryDownloadUrl' => $downloadUrl], 200);
            }
            if ($request->url() === $downloadUrl) {
                return Http::response($body, 200, [
                    'Content-Length' => (string) strlen($body),
                    'Content-Type' => 'application/x-tar',
                ]);
            }

            return Http::response([], 404);
        });
    }

    private function downloadUrl(string $filename): string
    {
        return 'https://knmi-kdp-datasets-eu-west-1.s3.eu-west-1.amazonaws.com/harmonie_arome_cy43_p1/1.0/'.$filename.'?signature=test';
    }

    private function validTar(string $run): string
    {
        $grib = 'GRIB'."\x00\x00\x0c"."\x01".'7777';
        $leads = array_merge([1, 0, 8, 7], array_values(array_diff(range(0, 60), [1, 0, 8, 7])));
        $tar = '';
        foreach ($leads as $lead) {
            $pax = $this->paxRecord('mtime', '1784568248.0320306');
            $tar .= $this->tarHeader('././@PaxHeader', strlen($pax), 'x');
            $tar .= $pax.str_repeat("\0", (512 - strlen($pax) % 512) % 512);
            $name = sprintf('HA43_N20_%s00_%03d00_GB', $run, $lead);
            $tar .= $this->tarHeader($name, strlen($grib), '0');
            $tar .= $grib.str_repeat("\0", (512 - strlen($grib) % 512) % 512);
        }

        return $tar.str_repeat("\0", 1024);
    }

    private function paxRecord(string $key, string $value): string
    {
        $body = $key.'='.$value."\n";
        $length = strlen($body) + 2;
        do {
            $record = $length.' '.$body;
            $next = strlen($record);
            $changed = $next !== $length;
            $length = $next;
        } while ($changed);

        return $record;
    }

    private function tarHeader(string $name, int $size, string $type): string
    {
        $field = static fn (string $value, int $length): string => str_pad($value, $length, "\0");
        $octal = static fn (int $value, int $length): string => str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT)."\0";
        $header = $field($name, 100)
            .$octal($type === '0' ? 0666 : 0, 8)
            .$octal(0, 8)
            .$octal(0, 8)
            .$octal($size, 12)
            .$octal(1784568248, 12)
            .str_repeat(' ', 8)
            .$type
            .str_repeat("\0", 100)
            ."ustar\0"
            .'00'
            .$field('root', 32)
            .$field('root', 32)
            .$octal(0, 8)
            .$octal(0, 8)
            .str_repeat("\0", 155)
            .str_repeat("\0", 12);
        $checksum = array_sum(unpack('C*', $header));

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    private function semanticOutputForPath(string $path): string
    {
        $filename = basename($path);
        if (preg_match('/\AHA43_N20_(\d{12})_(\d{3})00_GB\z/D', $filename, $match) !== 1) {
            return '';
        }
        $run = Carbon::createFromFormat('!YmdHi', $match[1], 'UTC');
        if ($run === false) {
            return '';
        }
        $valid = $run->copy()->addHours((int) $match[2]);

        return implode("\n", [
            sprintf('71 105 0 0 %s %d %s %d', $run->format('Ymd'), (int) $run->format('Hi'), $valid->format('Ymd'), (int) $valid->format('Hi')),
            sprintf('73 105 0 0 %s %d %s %d', $run->format('Ymd'), (int) $run->format('Hi'), $valid->format('Ymd'), (int) $valid->format('Hi')),
            sprintf('74 105 0 0 %s %d %s %d', $run->format('Ymd'), (int) $run->format('Hi'), $valid->format('Ymd'), (int) $valid->format('Hi')),
            sprintf('75 105 0 0 %s %d %s %d', $run->format('Ymd'), (int) $run->format('Hi'), $valid->format('Ymd'), (int) $valid->format('Hi')),
            sprintf('186 200 0 0 %s %d %s %d', $run->format('Ymd'), (int) $run->format('Hi'), $valid->format('Ymd'), (int) $valid->format('Hi')),
        ])."\n";
    }
}
