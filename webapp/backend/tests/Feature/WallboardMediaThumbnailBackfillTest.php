<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WallboardMediaAsset;
use App\Services\WallboardMediaAssetService;
use App\Services\WallboardMediaThumbnailBackfillService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_corrupt_and_missing_sources_leave_database_and_original_content_untouched(): void
    {
        Log::spy();
        $corrupt = $this->legacyAsset('not-a-webp');
        $missing = $this->legacyAsset('will-be-removed');
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

    private function legacyAsset(string $body): WallboardMediaAsset
    {
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
            'width' => is_array($dimensions) ? (int) $dimensions[0] : 1,
            'height' => is_array($dimensions) ? (int) $dimensions[1] : 1,
            'status' => WallboardMediaAsset::STATUS_READY,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
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
