<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistItem;
use App\Services\WallboardMediaAssetService;
use App\Services\WallboardMediaImageProcessor;
use App\Services\WallboardMediaThumbnailBackfillService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WallboardMediaThumbnailBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Cache::flush();
        config()->set('wallboard_media.disk', 'local');
        config()->set('wallboard_media.minimum_free_bytes', 0);
        config()->set('wallboard_media.max_total_bytes', 100 * 1024 * 1024);
    }

    public function test_legacy_ready_image_gets_a_verified_thumbnail_and_rerun_is_a_no_op(): void
    {
        $source = $this->webp(320, 180);
        $asset = $this->legacyAsset($source);

        $first = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);
        self::assertSame(1, $first['backfilled']);
        self::assertSame(0, $first['failures']);

        $asset->refresh();
        $thumbnailPath = 'wallboard-media/objects/'.(string) $asset->id.'.thumbnail.webp';
        self::assertSame($thumbnailPath, $asset->thumbnail_storage_path);
        self::assertSame('image/webp', $asset->thumbnail_mime_type);
        self::assertSame(
            hash_file('sha256', Storage::disk('local')->path($thumbnailPath)),
            $asset->thumbnail_sha256,
        );
        self::assertSame(filesize(Storage::disk('local')->path($thumbnailPath)), $asset->thumbnail_byte_size);
        Storage::disk('local')->assertExists((string) $asset->storage_path);

        $second = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);
        self::assertSame(1, $second['unchanged']);
        self::assertSame(0, $second['backfilled']);
        self::assertSame(0, $second['failures']);
    }

    public function test_oversized_landscape_is_normalized_and_invalidates_linked_playlists_once(): void
    {
        $asset = $this->legacyAsset($this->webp(3840, 2160), version: 4);
        $firstPlaylist = $this->linkedPlaylist($asset, 'Hal', 3);
        $secondPlaylist = $this->linkedPlaylist($asset, 'Kantine', 8);
        $originalSha256 = $asset->sha256;
        config()->set('wallboard_media.max_total_bytes', (int) $asset->byte_size);

        $first = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);

        self::assertSame(1, $first['normalized']);
        self::assertSame(0, $first['failures']);
        $asset->refresh();
        self::assertSame(1920, $asset->width);
        self::assertSame(1080, $asset->height);
        self::assertSame(5, $asset->version);
        self::assertNotSame($originalSha256, $asset->sha256);
        $this->assertStoredMetadata($asset);
        self::assertSame(4, $firstPlaylist->fresh()->version);
        self::assertSame(9, $secondPlaylist->fresh()->version);

        $normalizedAttributes = $asset->getAttributes();
        $normalizedSource = Storage::disk('local')->get((string) $asset->storage_path);
        $second = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);

        self::assertSame(1, $second['unchanged']);
        self::assertSame(0, $second['normalized']);
        self::assertSame($normalizedAttributes, $asset->fresh()->getAttributes());
        self::assertSame($normalizedSource, Storage::disk('local')->get((string) $asset->storage_path));
        self::assertSame(4, $firstPlaylist->fresh()->version);
        self::assertSame(9, $secondPlaylist->fresh()->version);
    }

    public function test_oversized_portrait_is_proportionally_fitted_inside_full_hd(): void
    {
        $asset = $this->legacyAsset($this->webp(1080, 1920), version: 6);

        $result = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);

        self::assertSame(1, $result['normalized']);
        self::assertSame(0, $result['failures']);
        $asset->refresh();
        self::assertSame(607, $asset->width);
        self::assertSame(1080, $asset->height);
        self::assertSame(7, $asset->version);
        $this->assertStoredMetadata($asset);
    }

    public function test_small_and_exact_full_hd_sources_are_not_upscaled_or_versioned(): void
    {
        $small = $this->legacyAsset($this->webp(960, 540), version: 5);
        $exact = $this->legacyAsset($this->webp(1920, 1080), version: 11);
        $this->installCanonicalThumbnail($small);
        $this->installCanonicalThumbnail($exact);
        $smallBefore = $small->fresh()->getAttributes();
        $exactBefore = $exact->fresh()->getAttributes();

        $result = app(WallboardMediaThumbnailBackfillService::class)->backfill(2);

        self::assertSame(2, $result['unchanged']);
        self::assertSame(0, $result['normalized']);
        self::assertSame($smallBefore, $small->fresh()->getAttributes());
        self::assertSame($exactBefore, $exact->fresh()->getAttributes());
    }

    public function test_database_failure_after_swap_restores_original_files_and_metadata(): void
    {
        Log::spy();
        $asset = $this->legacyAsset($this->webp(3840, 2160), version: 13);
        $this->installCanonicalThumbnail($asset);
        $playlist = $this->linkedPlaylist($asset, 'Rollback', 6);
        $assetBefore = $asset->fresh()->getAttributes();
        $sourceBefore = Storage::disk('local')->get((string) $asset->storage_path);
        $thumbnailBefore = Storage::disk('local')->get((string) $asset->thumbnail_storage_path);

        WallboardMediaAsset::updating(static function (WallboardMediaAsset $candidate) use ($asset): void {
            if ((string) $candidate->id === (string) $asset->id && $candidate->isDirty('sha256')) {
                throw new \RuntimeException('Forced metadata persistence failure.');
            }
        });
        try {
            $result = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);
        } finally {
            WallboardMediaAsset::getEventDispatcher()?->forget(
                'eloquent.updating: '.WallboardMediaAsset::class,
            );
        }

        self::assertSame(1, $result['failures']);
        self::assertSame($assetBefore, $asset->fresh()->getAttributes());
        self::assertSame($sourceBefore, Storage::disk('local')->get((string) $asset->storage_path));
        self::assertSame(
            $thumbnailBefore,
            Storage::disk('local')->get((string) $asset->thumbnail_storage_path),
        );
        self::assertSame(6, $playlist->fresh()->version);
        self::assertSame([], Storage::disk('local')->allFiles('wallboard-media/staging'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_normalization_finishes_slow_file_preparation_before_taking_coordination_lock(): void
    {
        $asset = $this->legacyAsset($this->webp(3840, 2160), version: 3);
        $this->installCanonicalThumbnail($asset);
        $sourcePath = (string) $asset->storage_path;
        $thumbnailPath = (string) $asset->thumbnail_storage_path;
        $sourceBefore = Storage::disk('local')->get($sourcePath);
        $thumbnailBefore = Storage::disk('local')->get($thumbnailPath);
        $observedCoordinationLock = false;

        DB::listen(function (QueryExecuted $query) use (
            &$observedCoordinationLock,
            $sourcePath,
            $thumbnailPath,
            $sourceBefore,
            $thumbnailBefore,
        ): void {
            if (! str_contains(strtolower($query->sql), 'wallboard_media_coordination_locks')) {
                return;
            }

            $observedCoordinationLock = true;
            $stagingFiles = glob(Storage::disk('local')->path('wallboard-media/staging/*'));
            self::assertIsArray($stagingFiles);
            self::assertCount(4, $stagingFiles);
            self::assertSame($sourceBefore, Storage::disk('local')->get($sourcePath));
            self::assertSame($thumbnailBefore, Storage::disk('local')->get($thumbnailPath));
        });

        $result = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);

        self::assertTrue($observedCoordinationLock);
        self::assertSame(1, $result['normalized']);
        self::assertSame(0, $result['failures']);
        self::assertSame([], Storage::disk('local')->allFiles('wallboard-media/staging'));
    }

    public function test_version_conflict_at_the_lock_boundary_skips_without_swapping_files(): void
    {
        $asset = $this->legacyAsset($this->webp(3840, 2160), version: 5);
        $this->installCanonicalThumbnail($asset);
        $sourcePath = (string) $asset->storage_path;
        $thumbnailPath = (string) $asset->thumbnail_storage_path;
        $sourceBefore = Storage::disk('local')->get($sourcePath);
        $thumbnailBefore = Storage::disk('local')->get($thumbnailPath);
        $conflictInjected = false;

        DB::listen(function (QueryExecuted $query) use (&$conflictInjected, $asset): void {
            if ($conflictInjected
                || ! str_contains(strtolower($query->sql), 'wallboard_media_coordination_locks')) {
                return;
            }

            $conflictInjected = true;
            DB::table('wallboard_media_assets')
                ->where('id', (string) $asset->id)
                ->update(['version' => 6]);
        });

        $result = app(WallboardMediaThumbnailBackfillService::class)->backfill(1);

        self::assertTrue($conflictInjected);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['normalized']);
        self::assertSame(0, $result['failures']);
        self::assertSame(6, $asset->fresh()->version);
        self::assertSame($sourceBefore, Storage::disk('local')->get($sourcePath));
        self::assertSame($thumbnailBefore, Storage::disk('local')->get($thumbnailPath));
        self::assertSame([], Storage::disk('local')->allFiles('wallboard-media/staging'));
    }

    public function test_upload_minimum_is_orientation_independent_and_output_uses_full_hd_bounds(): void
    {
        foreach ([
            [1920, 1080, 1920, 1080],
            [1080, 1920, 607, 1080],
        ] as [$sourceWidth, $sourceHeight, $expectedWidth, $expectedHeight]) {
            $processed = app(WallboardMediaImageProcessor::class)->process(
                UploadedFile::fake()->createWithContent(
                    "minimum-{$sourceWidth}x{$sourceHeight}.webp",
                    $this->webp($sourceWidth, $sourceHeight),
                ),
            );
            try {
                self::assertSame($expectedWidth, $processed->width);
                self::assertSame($expectedHeight, $processed->height);
            } finally {
                @unlink($processed->temporaryPath);
                @unlink((string) $processed->thumbnailTemporaryPath);
            }
        }

        foreach ([[1920, 1079], [1919, 1080]] as [$width, $height]) {
            try {
                app(WallboardMediaImageProcessor::class)->process(
                    UploadedFile::fake()->createWithContent(
                        "too-small-{$width}x{$height}.webp",
                        $this->webp($width, $height),
                    ),
                );
                self::fail("{$width}x{$height} must be rejected as too small.");
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('image', $exception->errors());
            }
        }
    }

    public function test_corrupt_and_missing_sources_leave_database_and_original_content_untouched(): void
    {
        Log::spy();
        $corrupt = $this->legacyAsset('not-a-webp', width: 3840, height: 2160);
        $missing = $this->legacyAsset('will-be-removed', width: 3840, height: 2160);
        Storage::disk('local')->delete((string) $missing->storage_path);

        $result = app(WallboardMediaThumbnailBackfillService::class)->backfill(2);

        self::assertSame(2, $result['failures']);
        self::assertSame('not-a-webp', Storage::disk('local')->get((string) $corrupt->storage_path));
        foreach ([$corrupt, $missing] as $asset) {
            $asset->refresh();
            self::assertSame(WallboardMediaAsset::STATUS_READY, $asset->status);
            self::assertNull($asset->thumbnail_storage_path);
            self::assertNull($asset->thumbnail_sha256);
            Storage::disk('local')->assertMissing(
                'wallboard-media/objects/'.(string) $asset->id.'.thumbnail.webp',
            );
        }
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_deleting_a_legacy_image_removes_source_and_canonical_thumbnail(): void
    {
        $source = 'source-bytes';
        $asset = $this->legacyAsset($source);
        $thumbnailPath = 'wallboard-media/objects/'.(string) $asset->id.'.thumbnail.webp';
        Storage::disk('local')->put($thumbnailPath, 'thumbnail-bytes');

        app(WallboardMediaAssetService::class)->delete(
            $asset,
            1,
            $this->actor(),
            Request::create('/api/admin/wallboard-media/assets/'.$asset->id, 'DELETE'),
        );

        Storage::disk('local')->assertMissing((string) $asset->storage_path);
        Storage::disk('local')->assertMissing($thumbnailPath);
        self::assertSoftDeleted('wallboard_media_assets', ['id' => (string) $asset->id]);
    }

    public function test_backfill_is_scheduled_with_cross_server_and_overlap_guards(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($candidate): bool => str_contains(
                (string) $candidate->command,
                'dis:backfill-wallboard-media-thumbnails',
            ),
        );

        self::assertNotNull($event);
        self::assertTrue($event->onOneServer);
        self::assertTrue($event->withoutOverlapping);
    }

    private function legacyAsset(
        string $body,
        ?int $width = null,
        ?int $height = null,
        int $version = 1,
    ): WallboardMediaAsset {
        $actor = $this->actor();
        $id = (string) Str::ulid();
        $path = 'wallboard-media/objects/'.$id.'.webp';
        Storage::disk('local')->put($path, $body);
        $dimensions = @getimagesize(Storage::disk('local')->path($path));

        return WallboardMediaAsset::query()->create([
            'id' => $id,
            'display_name' => 'Legacyfoto',
            'original_name' => 'legacy.webp',
            'kind' => WallboardMediaAsset::KIND_IMAGE,
            'storage_path' => $path,
            'sha256' => hash('sha256', $body),
            'mime_type' => 'image/webp',
            'byte_size' => strlen($body),
            'width' => $width ?? (is_array($dimensions) ? (int) $dimensions[0] : 1),
            'height' => $height ?? (is_array($dimensions) ? (int) $dimensions[1] : 1),
            'status' => WallboardMediaAsset::STATUS_READY,
            'version' => $version,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function linkedPlaylist(
        WallboardMediaAsset $asset,
        string $name,
        int $version,
    ): WallboardMediaPlaylist {
        $actor = $this->actor();
        $playlist = WallboardMediaPlaylist::query()->create([
            'name' => $name,
            'version' => $version,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        WallboardMediaPlaylistItem::query()->create([
            'media_playlist_id' => (string) $playlist->id,
            'media_asset_id' => (string) $asset->id,
            'position' => 0,
        ]);

        return $playlist;
    }

    private function installCanonicalThumbnail(WallboardMediaAsset $asset): void
    {
        $processed = app(WallboardMediaImageProcessor::class)->createThumbnailFromStoredWebp(
            Storage::disk('local')->path((string) $asset->storage_path),
        );
        $thumbnailPath = 'wallboard-media/objects/'.(string) $asset->id.'.thumbnail.webp';
        try {
            $body = file_get_contents($processed->temporaryPath);
            self::assertIsString($body);
            Storage::disk('local')->put($thumbnailPath, $body);
            $asset->forceFill([
                'thumbnail_storage_path' => $thumbnailPath,
                'thumbnail_sha256' => $processed->sha256,
                'thumbnail_mime_type' => 'image/webp',
                'thumbnail_byte_size' => $processed->byteSize,
            ])->save();
        } finally {
            @unlink($processed->temporaryPath);
        }
    }

    private function assertStoredMetadata(WallboardMediaAsset $asset): void
    {
        $sourcePath = Storage::disk('local')->path((string) $asset->storage_path);
        $sourceDimensions = getimagesize($sourcePath);
        self::assertIsArray($sourceDimensions);
        self::assertSame($asset->width, $sourceDimensions[0]);
        self::assertSame($asset->height, $sourceDimensions[1]);
        self::assertSame(hash_file('sha256', $sourcePath), $asset->sha256);
        self::assertSame(filesize($sourcePath), $asset->byte_size);

        $thumbnailPath = Storage::disk('local')->path((string) $asset->thumbnail_storage_path);
        self::assertSame('image/webp', $asset->thumbnail_mime_type);
        self::assertSame(hash_file('sha256', $thumbnailPath), $asset->thumbnail_sha256);
        self::assertSame(filesize($thumbnailPath), $asset->thumbnail_byte_size);
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'Media Beheerder',
            'first_name' => 'Media',
            'last_name' => 'Beheerder',
            'email' => 'thumbnail-'.Str::lower((string) Str::ulid()).'@example.test',
            'password' => bcrypt('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function webp(int $width, int $height): string
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            self::markTestSkipped('GD/WebP is required for the production thumbnail pipeline.');
        }
        $image = imagecreatetruecolor($width, $height);
        self::assertInstanceOf(\GdImage::class, $image);
        imagefilledrectangle(
            $image,
            0,
            0,
            $width - 1,
            $height - 1,
            imagecolorallocate($image, 20, 120, 220),
        );
        ob_start();
        self::assertTrue(imagewebp($image, null, 88));
        $bytes = ob_get_clean();
        imagedestroy($image);
        self::assertIsString($bytes);

        return $bytes;
    }
}
