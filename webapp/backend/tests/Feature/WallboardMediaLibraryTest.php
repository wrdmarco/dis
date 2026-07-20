<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaPlaylist;
use App\Models\WallboardMediaPlaylistItem;
use App\Models\WallboardPlaylist;
use App\Services\WallboardMediaCleanupService;
use App\Services\WallboardMediaDeliveryService;
use App\Services\WallboardMediaFolderService;
use App\Services\WallboardMediaImageProcessor;
use App\Services\WallboardMediaPlaylistService;
use App\Services\WallboardMediaQuotaService;
use App\Services\WallboardMediaStateService;
use App\Services\WallboardMediaUsageSynchronizer;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class WallboardMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('wallboard_media.disk', 'local');
        config()->set('wallboard_media.minimum_free_bytes', 0);
        config()->set('wallboard_media.max_total_bytes', 100 * 1024 * 1024);
    }

    public function test_folder_tree_rejects_duplicate_names_cycles_and_non_empty_deletion(): void
    {
        $actor = $this->actor();
        $request = Request::create('/api/admin/wallboard-media/folders', 'POST');
        $service = app(WallboardMediaFolderService::class);
        $root = $service->create(['name' => 'Operaties'], $actor, $request);
        $child = $service->create([
            'name' => 'Foto’s',
            'parent_id' => (string) $root->id,
        ], $actor, $request);

        try {
            $service->create(['name' => '  operaties  '], $actor, $request);
            self::fail('Een hoofdletterongevoelige dubbele mapnaam moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('name', $exception->errors());
        }

        try {
            $service->update($root, [
                'expected_version' => 1,
                'parent_id' => (string) $child->id,
            ], $actor, $request);
            self::fail('Een mapcyclus moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('parent_id', $exception->errors());
        }

        $this->expectException(ConflictHttpException::class);
        $service->delete($root, 1, $actor, $request);
    }

    public function test_usage_projection_limits_delivery_to_the_assigned_wallboard_playlist(): void
    {
        $actor = $this->actor();
        $asset = $this->storedAsset($actor);
        $mediaPlaylist = WallboardMediaPlaylist::query()->create([
            'name' => 'Promofoto’s',
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        WallboardMediaPlaylistItem::query()->create([
            'media_playlist_id' => $mediaPlaylist->id,
            'media_asset_id' => $asset->id,
            'position' => 0,
        ]);
        $configuration = $this->photoConfiguration((string) $mediaPlaylist->id, 12);
        $wallboardPlaylist = WallboardPlaylist::query()->create([
            'name' => 'Hoofdscherm',
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        app(WallboardMediaUsageSynchronizer::class)->synchronize($wallboardPlaylist, $configuration);
        $wallboard = $this->wallboard($actor, $wallboardPlaylist, $configuration);
        $wallboard->forceFill([
            'configuration' => ['pages' => [[
                'id' => 'stale-map',
                'type' => 'map',
                'duration_seconds' => 30,
                'options' => [],
            ]]],
        ])->save();

        $state = app(WallboardMediaStateService::class)->pages($wallboard, $configuration);
        self::assertSame(12, $state['photos']['item_duration_seconds']);
        self::assertSame(12, $state['photos']['total_duration_seconds']);
        self::assertSame('/api/wallboard/media/'.$asset->id, $state['photos']['items'][0]['image_url']);
        self::assertSame(1, $state['photos']['items'][0]['media_asset_version']);
        self::assertNotNull(app(WallboardMediaDeliveryService::class)->forWallboard($wallboard, $asset));

        $otherConfiguration = ['pages' => [['id' => 'map', 'type' => 'map', 'duration_seconds' => 30, 'options' => []]]];
        $otherPlaylist = WallboardPlaylist::query()->create([
            'name' => 'Ander scherm',
            'configuration' => $otherConfiguration,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $otherWallboard = $this->wallboard($actor, $otherPlaylist, $otherConfiguration);
        self::assertNull(app(WallboardMediaDeliveryService::class)->forWallboard($otherWallboard, $asset));

        Storage::disk('local')->put((string) $asset->storage_path, 'tampered');
        self::assertNull(app(WallboardMediaDeliveryService::class)->forAdmin($asset));
    }

    public function test_existing_lowercase_photo_playlist_is_resolved_from_uppercase_configuration_id(): void
    {
        $actor = $this->actor();
        $asset = $this->storedAsset($actor);
        $mediaPlaylist = WallboardMediaPlaylist::query()->create([
            'name' => 'Bestaande fotoplaylist',
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        WallboardMediaPlaylistItem::query()->create([
            'media_playlist_id' => $mediaPlaylist->id,
            'media_asset_id' => $asset->id,
            'position' => 0,
        ]);
        $configuration = $this->photoConfiguration(strtoupper((string) $mediaPlaylist->id), 10);
        $normalized = WallboardConfiguration::normalize($configuration);
        self::assertSame(
            (string) $mediaPlaylist->id,
            $normalized['pages'][0]['options']['media_playlist_id'],
        );

        $wallboardPlaylist = WallboardPlaylist::query()->create([
            'name' => 'Playlist met uppercase verwijzing',
            'configuration' => $normalized,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $synchronized = app(WallboardMediaUsageSynchronizer::class)->synchronize(
            $wallboardPlaylist,
            $configuration,
        );
        self::assertSame(10, $synchronized['pages'][0]['duration_seconds']);
        self::assertDatabaseHas('wallboard_media_playlist_usages', [
            'wallboard_playlist_id' => (string) $wallboardPlaylist->id,
            'media_playlist_id' => (string) $mediaPlaylist->id,
        ]);
    }

    public function test_media_playlist_preserves_order_and_rejects_deletion_while_in_use(): void
    {
        $actor = $this->actor();
        $first = $this->storedAsset($actor, 'Eerste');
        $second = $this->storedAsset($actor, 'Tweede');
        $request = Request::create('/api/admin/wallboard-media/playlists', 'POST');
        $service = app(WallboardMediaPlaylistService::class);
        $playlist = $service->create([
            'name' => 'Entree',
            'asset_ids' => [(string) $second->id, (string) $first->id],
        ], $actor, $request);

        self::assertSame(
            [(string) $second->id, (string) $first->id],
            $playlist->items->pluck('media_asset_id')->all(),
        );
        $playlist = $service->update($playlist, [
            'expected_version' => 1,
            'asset_ids' => [(string) $first->id, (string) $second->id],
        ], $actor, $request);
        self::assertSame(
            [(string) $first->id, (string) $second->id],
            $playlist->items->pluck('media_asset_id')->all(),
        );

        $configuration = $this->photoConfiguration((string) $playlist->id, 10);
        $wallboardPlaylist = WallboardPlaylist::query()->create([
            'name' => 'Entree wallboard',
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        app(WallboardMediaUsageSynchronizer::class)->synchronize($wallboardPlaylist, $configuration);

        $this->expectException(ConflictHttpException::class);
        $service->delete($playlist, 2, $actor, $request);
    }

    public function test_usage_projection_rejects_empty_and_overlong_carousels(): void
    {
        $actor = $this->actor();
        $empty = WallboardMediaPlaylist::query()->create([
            'name' => 'Leeg',
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $wallboardPlaylist = WallboardPlaylist::query()->create([
            'name' => 'Scherm',
            'configuration' => ['pages' => []],
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        try {
            app(WallboardMediaUsageSynchronizer::class)->synchronize(
                $wallboardPlaylist,
                $this->photoConfiguration((string) $empty->id, 10),
            );
            self::fail('Een lege fotoplaylist moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('configuration.pages', $exception->errors());
        }

        $asset = $this->storedAsset($actor);
        for ($position = 0; $position < 13; $position++) {
            $copy = $position === 0 ? $asset : $this->storedAsset($actor, 'Foto '.$position);
            WallboardMediaPlaylistItem::query()->create([
                'media_playlist_id' => $empty->id,
                'media_asset_id' => $copy->id,
                'position' => $position,
            ]);
        }
        try {
            app(WallboardMediaUsageSynchronizer::class)->synchronize(
                $wallboardPlaylist,
                $this->photoConfiguration((string) $empty->id, 300),
            );
            self::fail('Een fotocarrousel langer dan 3600 seconden moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('configuration.pages', $exception->errors());
        }
    }

    public function test_every_admin_configuration_contract_strictly_validates_photo_carousels(): void
    {
        $playlistId = (string) Str::ulid();
        $valid = $this->photoConfiguration($playlistId, 10);

        foreach ($this->configurationRequestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => $valid,
            ]);
            self::assertSame(
                [
                    'media_playlist_id' => $playlistId,
                    'item_duration_seconds' => 10,
                ],
                $validated['configuration']['pages'][0]['options'],
            );

            foreach ([
                'invalid playlist id' => ['media_playlist_id' => 'not-a-ulid'],
                'duration below minimum' => ['item_duration_seconds' => 4],
                'duration above maximum' => ['item_duration_seconds' => 301],
                'numeric duration string' => ['item_duration_seconds' => '10'],
                'unexpected option' => ['untrusted_asset_url' => 'https://example.test/image.webp'],
            ] as $case => $changes) {
                $configuration = $valid;
                $configuration['pages'][0]['options'] = [
                    ...$configuration['pages'][0]['options'],
                    ...$changes,
                ];
                try {
                    $this->validateRequest($request, [
                        ...$basePayload,
                        'configuration' => $configuration,
                    ]);
                    self::fail("Ongeldige fotocarrousel geaccepteerd: {$case}.");
                } catch (ValidationException $exception) {
                    self::assertNotSame([], $exception->errors(), $case);
                }
            }
        }
    }

    public function test_image_processor_rejects_svg_before_decode(): void
    {
        $upload = UploadedFile::fake()->createWithContent(
            'script.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->expectException(ValidationException::class);
        app(WallboardMediaImageProcessor::class)->process($upload);
    }

    public function test_image_processor_rejects_animation_markers_and_oversized_dimensions_before_gd(): void
    {
        $webp = base64_decode(
            'UklGRiIAAABXRUJQVlA4ICAAAADQAQCdASoBAAEAL0AcJaQAA3AA/v89WAAAAA==',
            true,
        );
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($webp);
        self::assertIsString($png);

        foreach ([
            ['animated.webp', $webp.'ANIM'],
            ['animated.png', $png.'acTL'],
        ] as [$name, $contents]) {
            $upload = $this->uploadedFile($name, $contents);
            try {
                app(WallboardMediaImageProcessor::class)->process($upload);
                self::fail('Een animatiemarkering moet vóór de GD-decode worden geweigerd.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('image', $exception->errors());
            } finally {
                @unlink((string) $upload->getRealPath());
            }
        }

        config()->set('wallboard_media.max_source_pixels', 1);
        $oversizedPng = substr_replace($png, pack('N', 2), 16, 4);
        $upload = $this->uploadedFile('oversized.png', $oversizedPng);
        try {
            app(WallboardMediaImageProcessor::class)->process($upload);
            self::fail('Een afbeelding boven de pixellimiet moet vóór de GD-decode worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('image', $exception->errors());
        } finally {
            @unlink((string) $upload->getRealPath());
        }
    }

    public function test_quota_and_orphan_cleanup_fail_closed_without_image_decoder(): void
    {
        config()->set('wallboard_media.max_total_bytes', 1);
        try {
            app(WallboardMediaQuotaService::class)->reserve(2, static fn (): bool => true);
            self::fail('Een upload boven het totaalquotum moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('image', $exception->errors());
        }
        config()->set('wallboard_media.max_total_bytes', 100 * 1024 * 1024);
        config()->set('wallboard_media.orphan_grace_seconds', 3600);

        $actor = $this->actor();
        $protected = $this->storedAsset($actor, 'Beschermd');
        $deleted = $this->storedAsset($actor, 'Verwijderd');
        $deleted->delete();
        $mediaPlaylist = WallboardMediaPlaylist::query()->create([
            'name' => 'Verweesde projectie',
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        WallboardMediaPlaylistItem::query()->create([
            'media_playlist_id' => $mediaPlaylist->id,
            'media_asset_id' => $protected->id,
            'position' => 0,
        ]);
        $configuration = $this->photoConfiguration((string) $mediaPlaylist->id, 10);
        $sourcePlaylist = WallboardPlaylist::query()->create([
            'name' => 'Tijdelijke bron',
            'configuration' => $configuration,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        app(WallboardMediaUsageSynchronizer::class)->synchronize($sourcePlaylist, $configuration);
        $sourcePlaylist->delete();
        $oldOrphan = 'wallboard-media/objects/'.Str::ulid().'.webp';
        $recentOrphan = 'wallboard-media/objects/'.Str::ulid().'.webp';
        $oldStaging = 'wallboard-media/staging/upload-old';
        Storage::disk('local')->put($oldOrphan, 'orphan');
        Storage::disk('local')->put($recentOrphan, 'recent');
        Storage::disk('local')->put($oldStaging, 'staging');
        foreach ([$protected->storage_path, $deleted->storage_path, $oldOrphan, $oldStaging] as $path) {
            self::assertTrue(touch(Storage::disk('local')->path((string) $path), now()->subHours(2)->getTimestamp()));
        }

        $result = app(WallboardMediaCleanupService::class)->cleanup();
        self::assertSame([
            'staging_deleted' => 1,
            'objects_deleted' => 2,
            'usages_deleted' => 1,
        ], $result);
        Storage::disk('local')->assertExists((string) $protected->storage_path);
        Storage::disk('local')->assertExists($recentOrphan);
        Storage::disk('local')->assertMissing((string) $deleted->storage_path);
        Storage::disk('local')->assertMissing($oldOrphan);
        Storage::disk('local')->assertMissing($oldStaging);
    }

    public function test_image_processor_preserves_full_hd_and_normalizes_larger_sources_proportionally(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            self::markTestSkipped('De lokale test-PHP heeft geen GD/WebP; productie installeert php8.5-gd.');
        }

        foreach ([
            'exact Full HD' => [1920, 1080, 1920, 1080],
            'grote 4K-foto' => [3840, 2160, 1920, 1080],
            'grote 3:2-foto' => [3000, 2000, 1620, 1080],
        ] as $case => [$sourceWidth, $sourceHeight, $expectedWidth, $expectedHeight]) {
            $path = $this->solidPngPath($sourceWidth, $sourceHeight);
            $result = null;
            try {
                $result = app(WallboardMediaImageProcessor::class)->process(
                    new UploadedFile($path, 'test.png', 'image/png', null, true),
                );
                self::assertSame('image/webp', (new \finfo(FILEINFO_MIME_TYPE))->file($result->temporaryPath), $case);
                self::assertSame(hash_file('sha256', $result->temporaryPath), $result->sha256, $case);
                self::assertSame($expectedWidth, $result->width, $case);
                self::assertSame($expectedHeight, $result->height, $case);
                self::assertNotNull($result->thumbnailTemporaryPath, $case);
                self::assertSame(
                    'image/webp',
                    (new \finfo(FILEINFO_MIME_TYPE))->file($result->thumbnailTemporaryPath),
                    $case,
                );
            } finally {
                if ($result !== null) {
                    @unlink($result->temporaryPath);
                    @unlink((string) $result->thumbnailTemporaryPath);
                }
                @unlink($path);
            }
        }
    }

    private function solidPngPath(int $width, int $height): string
    {
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
        $path = tempnam(sys_get_temp_dir(), 'wallboard-media-test-');
        self::assertIsString($path);
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);

        return $path;
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'Media Beheerder',
            'first_name' => 'Media',
            'last_name' => 'Beheerder',
            'email' => 'wallboard-media-'.Str::lower((string) Str::ulid()).'@example.test',
            'password' => bcrypt('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function uploadedFile(string $name, string $contents): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wallboard-media-source-');
        self::assertIsString($path);
        self::assertSame(strlen($contents), file_put_contents($path, $contents));

        return new UploadedFile($path, $name, null, null, true);
    }

    private function storedAsset(User $actor, string $name = 'Testfoto'): WallboardMediaAsset
    {
        $id = (string) Str::ulid();
        $body = base64_decode(
            'UklGRiIAAABXRUJQVlA4ICAAAADQAQCdASoBAAEAL0AcJaQAA3AA/v89WAAAAA==',
            true,
        );
        self::assertIsString($body);
        $path = 'wallboard-media/objects/'.$id.'.webp';
        Storage::disk('local')->put($path, $body);

        return WallboardMediaAsset::query()->create([
            'id' => $id,
            'folder_id' => null,
            'display_name' => $name,
            'original_name' => $name.'.webp',
            'storage_path' => $path,
            'sha256' => hash('sha256', $body),
            'mime_type' => 'image/webp',
            'byte_size' => strlen($body),
            'width' => 1,
            'height' => 1,
            'status' => WallboardMediaAsset::STATUS_READY,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function photoConfiguration(string $mediaPlaylistId, int $duration): array
    {
        return [
            'rotation_enabled' => true,
            'pages' => [[
                'id' => 'photos',
                'name' => 'Foto\'s',
                'type' => WallboardMediaUsageSynchronizer::PAGE_TYPE,
                'duration_seconds' => $duration,
                'options' => [
                    'media_playlist_id' => $mediaPlaylistId,
                    'item_duration_seconds' => $duration,
                ],
            ]],
            'incident_override' => ['enabled' => false, 'page_id' => 'photos'],
        ];
    }

    /** @return list<array{0: FormRequest, 1: array<string, int|string>}> */
    private function configurationRequestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Fotoscherm']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Fotoplaylist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function validateRequest(FormRequest $request, array $payload): array
    {
        $request->initialize($payload);
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator->validate();
    }

    /** @param array<string, mixed> $configuration */
    private function wallboard(
        User $actor,
        WallboardPlaylist $playlist,
        array $configuration,
    ): Wallboard {
        return Wallboard::query()->create([
            'name' => 'Mediawallboard '.Str::ulid(),
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'rotation_started_at' => now(),
            'is_enabled' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }
}
