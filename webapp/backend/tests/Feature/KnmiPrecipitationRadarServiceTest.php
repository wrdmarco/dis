<?php

namespace Tests\Feature;

use App\DTO\KnmiPrecipitationRemoteFile;
use App\Exceptions\KnmiPrecipitationImportException;
use App\Repositories\KnmiPrecipitationSnapshotRepository;
use App\Services\KnmiPrecipitationHdf5Reader;
use App\Services\KnmiPrecipitationRadarService;
use Carbon\CarbonImmutable;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class KnmiPrecipitationRadarServiceTest extends TestCase
{
    private string $storageRoot;

    /** @var list<int> */
    private array $extractedFrames = [];

    private ?string $atlasTemplatePath = null;

    /** @var array<string, mixed>|null */
    private ?array $atlasTemplate = null;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-20T21:20:00Z');
        Cache::flush();
        $this->storageRoot = storage_path('framework/testing/knmi-radar-'.str()->lower((string) str()->ulid()));
        File::makeDirectory($this->storageRoot, 0770, true);
        config()->set([
            'dis.knmi_precipitation.storage_root' => $this->storageRoot,
            'dis.knmi_precipitation.radar_dataset' => 'radar_forecast',
            'dis.knmi_precipitation.radar_version' => '2.0',
            'dis.knmi_precipitation.radar_minimum_bytes' => 8,
            'dis.knmi_precipitation.radar_maximum_bytes' => 100_000,
            'dis.knmi_precipitation.probability_dataset' => 'seamless_precipitation_ensemble_forecast_probabilities',
            'dis.knmi_precipitation.probability_version' => '1.0',
            'dis.knmi_precipitation.probability_minimum_bytes' => 8,
            'dis.knmi_precipitation.probability_maximum_bytes' => 100_000,
            'dis.knmi_precipitation.retain_releases' => 2,
            'dis.knmi_precipitation.maximum_reference_age_seconds' => 1800,
            'dis.knmi_precipitation.integrity_cache_seconds' => 300,
            'dis.knmi_precipitation.query_timeout_seconds' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        File::deleteDirectory($this->storageRoot);
        parent::tearDown();
    }

    public function test_valid_atlas_metadata_and_exact_active_file_are_exposed_without_a_public_path(): void
    {
        $manifest = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:10:00Z'));

        $metadata = app(KnmiPrecipitationRadarService::class)->metadata();
        $file = app(KnmiPrecipitationRadarService::class)->file($manifest['snapshot_id']);

        $this->assertTrue($metadata['available']);
        $this->assertFalse($metadata['stale']);
        $this->assertSame($manifest['snapshot_id'], $metadata['snapshot_id']);
        $this->assertSame('2026-07-20T21:10:00+00:00', $metadata['reference_time']);
        $this->assertSame([
            'width' => 3500,
            'height' => 3825,
            'columns' => 5,
            'rows' => 5,
            'frame_width' => 700,
            'frame_height' => 765,
            'frame_count' => 25,
        ], $metadata['atlas']);
        $this->assertCount(25, $metadata['frames']);
        $this->assertSame([
            'index' => 0,
            'valid_at' => '2026-07-20T21:10:00+00:00',
            'lead_minutes' => 0,
        ], $metadata['frames'][0]);
        $this->assertSame([
            'index' => 24,
            'valid_at' => '2026-07-20T23:10:00+00:00',
            'lead_minutes' => 120,
        ], $metadata['frames'][24]);
        $this->assertSame('KNMI radar_forecast 2.0', $metadata['source']['name']);
        $this->assertSame('CC BY 4.0', $metadata['source']['license']);
        $this->assertStringNotContainsString($this->storageRoot, json_encode($metadata, JSON_THROW_ON_ERROR));
        $this->assertIsArray($file);
        $this->assertSame('image/png', $file['media_type']);
        $this->assertSame($manifest['atlas']['sha256'], $file['sha256']);
        $this->assertFileExists($file['path']);
        $this->assertSame(0, $this->atlasPaletteIndex($file['path'], 0, 0));
        $this->assertGreaterThan(0, $this->atlasPaletteIndex($file['path'], 1, 0));
        $this->assertNull(app(KnmiPrecipitationRadarService::class)->file('20260720T211000Z-0000000000000000'));
        $this->assertSame(range(1, 25), $this->extractedFrames);
    }

    public function test_reference_more_than_ten_minutes_in_the_future_is_stale_and_unavailable_to_consumers(): void
    {
        $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:35:00Z'));

        $metadata = app(KnmiPrecipitationRadarService::class)->metadata();

        $this->assertTrue($metadata['available']);
        $this->assertTrue($metadata['stale']);
        $this->assertStringContainsString('toekomst', $metadata['availability_note']);
    }

    public function test_repository_and_cache_failures_are_caught_fail_closed(): void
    {
        $manifest = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:10:00Z'));
        Cache::shouldReceive('remember')->andThrow(new \RuntimeException('cache unavailable'));

        $metadata = app(KnmiPrecipitationRadarService::class)->metadata();
        $file = app(KnmiPrecipitationRadarService::class)->file($manifest['snapshot_id']);

        $this->assertFalse($metadata['available']);
        $this->assertNull($metadata['snapshot_id']);
        $this->assertNull($file);
    }

    public function test_file_lookup_keeps_only_the_active_and_single_retained_v2_snapshot_available(): void
    {
        $first = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:00:00Z'));
        $second = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:05:00Z'));

        $this->assertIsArray(app(KnmiPrecipitationRadarService::class)->file($first['snapshot_id']));
        $this->assertIsArray(app(KnmiPrecipitationRadarService::class)->file($second['snapshot_id']));

        $third = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:10:00Z'));

        $this->assertNull(app(KnmiPrecipitationRadarService::class)->file($first['snapshot_id']));
        $this->assertIsArray(app(KnmiPrecipitationRadarService::class)->file($second['snapshot_id']));
        $this->assertIsArray(app(KnmiPrecipitationRadarService::class)->file($third['snapshot_id']));
        $this->assertCount(2, glob($this->storageRoot.'/releases/*', GLOB_ONLYDIR) ?: []);
    }

    public function test_tampered_atlas_fails_closed_for_metadata_and_file_access(): void
    {
        $manifest = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:10:00Z'));
        $snapshot = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $atlasPath = $snapshot['paths']['atlas'];
        $contents = file_get_contents($atlasPath);
        $contents[strlen($contents) - 12] = $contents[strlen($contents) - 12] === 'x' ? 'y' : 'x';
        file_put_contents($atlasPath, $contents, LOCK_EX);
        clearstatcache(true, $atlasPath);
        Cache::flush();

        $metadata = app(KnmiPrecipitationRadarService::class)->metadata();

        $this->assertFalse($metadata['available']);
        $this->assertNull($metadata['snapshot_id']);
        $this->assertNull(app(KnmiPrecipitationRadarService::class)->file($manifest['snapshot_id']));
    }

    public function test_invalid_frame_index_is_rejected_before_activation(): void
    {
        $reference = CarbonImmutable::parse('2026-07-20T21:10:00Z');
        [$staging, $files, $sha256, $atlas] = $this->stagedSnapshot($reference);
        $atlas['frames'][3]['index'] = 4;

        try {
            app(KnmiPrecipitationSnapshotRepository::class)->activate($staging, $files, $sha256, $atlas);
            $this->fail('An invalid radar atlas frame index was activated.');
        } catch (KnmiPrecipitationImportException $exception) {
            $this->assertSame('local_data_invalid', $exception->publicCode);
        }

        $this->assertNull(app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot());
    }

    public function test_out_of_bounds_frame_data_is_rejected_and_no_png_is_left_behind(): void
    {
        $staging = app(KnmiPrecipitationSnapshotRepository::class)->createStagingDirectory();
        $radarPath = $staging.DIRECTORY_SEPARATOR.'RAD_NL25_RAC_FM_202607202110.h5';
        file_put_contents($radarPath, $this->hdfBody('r', 256), LOCK_EX);
        $frameOutput = $this->frameOutput(5_000);
        Process::fake(static fn (PendingProcess $process) => Process::result(output: $frameOutput));
        $destination = $staging.DIRECTORY_SEPARATOR.KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FILENAME;

        try {
            app(KnmiPrecipitationHdf5Reader::class)->renderRadarAtlas(
                $radarPath,
                CarbonImmutable::parse('2026-07-20T21:10:00Z'),
                $destination,
            );
            $this->fail('An out-of-bounds radar intensity was rendered.');
        } catch (KnmiPrecipitationImportException $exception) {
            $this->assertSame('local_data_invalid', $exception->publicCode);
        }

        $this->assertFileDoesNotExist($destination);
    }

    public function test_invalid_successor_atlas_keeps_the_previous_snapshot_active(): void
    {
        $previous = $this->activateSnapshot(CarbonImmutable::parse('2026-07-20T21:10:00Z'));
        [$staging, $files, $sha256, $atlas] = $this->stagedSnapshot(
            CarbonImmutable::parse('2026-07-20T21:15:00Z'),
        );
        $atlasPath = $staging.DIRECTORY_SEPARATOR.$atlas['filename'];
        file_put_contents($atlasPath, 'tampered', FILE_APPEND | LOCK_EX);
        clearstatcache(true, $atlasPath);

        try {
            app(KnmiPrecipitationSnapshotRepository::class)->activate($staging, $files, $sha256, $atlas);
            $this->fail('A tampered successor radar atlas was activated.');
        } catch (KnmiPrecipitationImportException $exception) {
            $this->assertSame('download_integrity_failed', $exception->publicCode);
        }

        $active = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();
        $this->assertSame($previous['snapshot_id'], $active['snapshot_id']);
        $this->assertCount(1, glob($this->storageRoot.'/releases/*', GLOB_ONLYDIR) ?: []);
    }

    public function test_legacy_manifest_remains_readable_for_point_data_but_has_no_radar_atlas(): void
    {
        $snapshotId = '20260720T211000Z-0000000000000000';
        $releaseRelative = 'releases/'.$snapshotId;
        $release = $this->storageRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $releaseRelative);
        File::makeDirectory($release, 0770, true);
        $radarFilename = 'RAD_NL25_RAC_FM_202607202110.h5';
        $probabilityFilename = 'KNMI_PYSTEPS_BLEND_PROB_202607202110.nc';
        $radarPath = $release.DIRECTORY_SEPARATOR.$radarFilename;
        $probabilityPath = $release.DIRECTORY_SEPARATOR.$probabilityFilename;
        file_put_contents($radarPath, $this->hdfBody('r', 256), LOCK_EX);
        file_put_contents($probabilityPath, $this->hdfBody('p', 320), LOCK_EX);
        file_put_contents($this->storageRoot.'/active.json', json_encode([
            'version' => 1,
            'snapshot_id' => $snapshotId,
            'reference_time' => '2026-07-20T21:10:00+00:00',
            'activated_at' => '2026-07-20T21:11:00+00:00',
            'files' => [
                'radar' => [
                    'dataset' => 'radar_forecast',
                    'dataset_version' => '2.0',
                    'filename' => $radarFilename,
                    'relative_path' => $releaseRelative.'/'.$radarFilename,
                    'size_bytes' => 256,
                    'sha256' => hash_file('sha256', $radarPath),
                ],
                'probability' => [
                    'dataset' => 'seamless_precipitation_ensemble_forecast_probabilities',
                    'dataset_version' => '1.0',
                    'filename' => $probabilityFilename,
                    'relative_path' => $releaseRelative.'/'.$probabilityFilename,
                    'size_bytes' => 320,
                    'sha256' => hash_file('sha256', $probabilityPath),
                ],
            ],
        ], JSON_THROW_ON_ERROR), LOCK_EX);

        $legacy = app(KnmiPrecipitationSnapshotRepository::class)->activeSnapshot();

        $this->assertIsArray($legacy);
        $this->assertSame(1, $legacy['version']);
        $this->assertSame(realpath($radarPath), $legacy['paths']['radar']);
        $this->assertSame(realpath($probabilityPath), $legacy['paths']['probability']);
        $this->assertArrayNotHasKey('atlas', $legacy);
        $this->assertFalse(app(KnmiPrecipitationRadarService::class)->metadata()['available']);
        $this->assertNull(app(KnmiPrecipitationRadarService::class)->file($snapshotId));
    }

    /** @return array<string, mixed> */
    private function activateSnapshot(CarbonImmutable $reference): array
    {
        [$staging, $files, $sha256, $atlas] = $this->stagedSnapshot($reference);

        return app(KnmiPrecipitationSnapshotRepository::class)->activate($staging, $files, $sha256, $atlas);
    }

    /**
     * @return array{
     *   string,
     *   array{radar: KnmiPrecipitationRemoteFile, probability: KnmiPrecipitationRemoteFile},
     *   array{radar: string, probability: string},
     *   array<string, mixed>
     * }
     */
    private function stagedSnapshot(CarbonImmutable $reference): array
    {
        $staging = app(KnmiPrecipitationSnapshotRepository::class)->createStagingDirectory();
        $key = $reference->format('YmdHi');
        $radarFilename = 'RAD_NL25_RAC_FM_'.$key.'.h5';
        $probabilityFilename = 'KNMI_PYSTEPS_BLEND_PROB_'.$key.'.nc';
        $radarPath = $staging.DIRECTORY_SEPARATOR.$radarFilename;
        $probabilityPath = $staging.DIRECTORY_SEPARATOR.$probabilityFilename;
        file_put_contents($radarPath, $this->hdfBody('r', 256), LOCK_EX);
        file_put_contents($probabilityPath, $this->hdfBody('p', 320), LOCK_EX);
        $files = [
            'radar' => new KnmiPrecipitationRemoteFile('radar_forecast', '2.0', $radarFilename, 256, $reference),
            'probability' => new KnmiPrecipitationRemoteFile(
                'seamless_precipitation_ensemble_forecast_probabilities',
                '1.0',
                $probabilityFilename,
                320,
                $reference,
            ),
        ];
        $sha256 = [
            'radar' => hash_file('sha256', $radarPath),
            'probability' => hash_file('sha256', $probabilityPath),
        ];
        $atlasPath = $staging.DIRECTORY_SEPARATOR.KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FILENAME;
        if ($this->atlasTemplatePath === null || $this->atlasTemplate === null) {
            $this->fakeValidFrameExtraction();
            $atlas = app(KnmiPrecipitationHdf5Reader::class)->renderRadarAtlas(
                $radarPath,
                $reference,
                $atlasPath,
            );
            Process::swap(new ProcessFactory);
            gc_collect_cycles();
            $templateDirectory = $this->storageRoot.DIRECTORY_SEPARATOR.'atlas-template';
            File::makeDirectory($templateDirectory, 0770, true);
            $this->atlasTemplatePath = $templateDirectory.DIRECTORY_SEPARATOR.'radar-atlas.png';
            copy($atlasPath, $this->atlasTemplatePath);
            $this->atlasTemplate = $atlas;
        } else {
            copy($this->atlasTemplatePath, $atlasPath);
            $atlas = [
                ...$this->atlasTemplate,
                'frames' => array_map(
                    static fn (int $index): array => [
                        'index' => $index,
                        'valid_at' => $reference->addMinutes($index * 5)->toIso8601String(),
                        'lead_minutes' => $index * 5,
                    ],
                    range(0, 24),
                ),
            ];
        }

        return [$staging, $files, $sha256, $atlas];
    }

    private function fakeValidFrameExtraction(): void
    {
        $frameOutput = $this->frameOutput(20, 1);
        Process::fake(function (PendingProcess $process) use ($frameOutput) {
            $command = is_array($process->command) ? $process->command : [];
            $datasetIndex = array_search('-d', $command, true);
            $dataset = $datasetIndex === false ? '' : (string) ($command[$datasetIndex + 1] ?? '');
            if (preg_match('/\A\/image([1-9]|1\d|2[0-5])\/image_data\z/D', $dataset, $matches) !== 1
                || in_array('-S', $command, true)
                || ! in_array('765,700', $command, true)) {
                return Process::result(exitCode: 1, errorOutput: 'unexpected extraction');
            }
            $this->extractedFrames[] = (int) $matches[1];

            return Process::result(output: $frameOutput);
        });
    }

    private function frameOutput(int $value, int $pixelIndex = 0): string
    {
        $pixelCount = 765 * 700;
        if ($pixelIndex < 0 || $pixelIndex >= $pixelCount) {
            throw new \InvalidArgumentException('Test radar pixel index is invalid.');
        }

        return 'DATASET "image_data" { DATA { (0,0): '
            .str_repeat('0, ', $pixelIndex)
            .$value.', '
            .str_repeat('0, ', $pixelCount - $pixelIndex - 1)
            .'} }';
    }

    private function atlasPaletteIndex(string $path, int $x, int $y): int
    {
        if (function_exists('imagecreatefrompng') && function_exists('imagecolorat') && function_exists('imagedestroy')) {
            $image = imagecreatefrompng($path);
            $this->assertNotFalse($image);
            try {
                return imagecolorat($image, $x, $y);
            } finally {
                imagedestroy($image);
            }
        }

        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $offset = 8;
        $compressed = '';
        while ($offset + 12 <= strlen($contents)) {
            $length = unpack('N', substr($contents, $offset, 4))[1];
            $type = substr($contents, $offset + 4, 4);
            $data = substr($contents, $offset + 8, $length);
            if ($type === 'IDAT') {
                $compressed .= $data;
            }
            $offset += 12 + $length;
        }
        $scanlines = gzuncompress($compressed);
        $this->assertIsString($scanlines);
        $rowBytes = KnmiPrecipitationHdf5Reader::RADAR_ATLAS_WIDTH + 1;
        $this->assertSame("\0", $scanlines[$y * $rowBytes]);

        return ord($scanlines[($y * $rowBytes) + 1 + $x]);
    }

    private function hdfBody(string $fill, int $size): string
    {
        return "\x89HDF\r\n\x1a\n".str_repeat($fill, $size - 8);
    }
}
