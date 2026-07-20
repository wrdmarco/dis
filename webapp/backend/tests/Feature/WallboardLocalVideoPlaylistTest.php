<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardMediaAssetUsage;
use App\Models\WallboardPlaylist;
use App\Services\WallboardMediaAssetService;
use App\Services\WallboardMediaDeliveryService;
use App\Services\WallboardPlaylistService;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

final class WallboardLocalVideoPlaylistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config()->set('wallboard_media.disk', 'local');
        config()->set('wallboard_media.minimum_free_bytes', 0);
        config()->set('wallboard_media.max_total_bytes', 1024 * 1024 * 1024);
    }

    public function test_ready_mp4_is_projected_canonically_and_delivered_only_to_assigned_playlist(): void
    {
        $actor = $this->actor();
        $asset = $this->asset($actor, duration: 42);
        $playlist = app(WallboardPlaylistService::class)->create([
            'name' => 'Lokale video',
            'configuration' => $this->localVideoConfiguration((string) $asset->id),
        ], $actor, Request::create('/api/admin/wallboard-playlists', 'POST'));

        $canonicalUrl = WallboardConfiguration::localVideoUrl((string) $asset->id);
        self::assertSame($canonicalUrl, $playlist->configuration['pages'][0]['options']['url']);
        self::assertSame(1, $playlist->configuration['pages'][0]['options']['media_asset_version']);
        self::assertSame(42, $playlist->configuration['pages'][0]['options']['video_duration_seconds']);
        self::assertSame(47, $playlist->configuration['pages'][0]['duration_seconds']);
        $this->assertDatabaseHas('wallboard_media_asset_usages', [
            'wallboard_playlist_id' => (string) $playlist->id,
            'page_id' => 'lokale-video',
            'media_asset_id' => strtolower((string) $asset->id),
        ]);

        $assigned = $this->wallboard($actor, $playlist);
        self::assertNotNull(app(WallboardMediaDeliveryService::class)->forWallboard($assigned, $asset));

        $deploymentAsset = $this->asset($actor, duration: 21);
        $deploymentPlaylist = app(WallboardPlaylistService::class)->create([
            'name' => 'Lokale video actieve inzet',
            'configuration' => $this->localVideoConfiguration((string) $deploymentAsset->id),
        ], $actor, Request::create('/api/admin/wallboard-playlists', 'POST'));
        $assigned->active_incident_playlist_id = $deploymentPlaylist->id;
        self::assertNotNull(app(WallboardMediaDeliveryService::class)->forWallboard(
            $assigned,
            $deploymentAsset,
        ));

        $unassignedPlaylist = app(WallboardPlaylistService::class)->create([
            'name' => 'Geen video',
            'configuration' => $this->mapConfiguration((string) $asset->id),
        ], $actor, Request::create('/api/admin/wallboard-playlists', 'POST'));
        self::assertNull(app(WallboardMediaDeliveryService::class)->forWallboard(
            $this->wallboard($actor, $unassignedPlaylist),
            $deploymentAsset,
        ));

        $updated = app(WallboardPlaylistService::class)->update($playlist, [
            'name' => 'Lokale video verwijderd',
            'expected_version' => 1,
            'configuration' => $this->mapConfiguration(),
        ], $actor, Request::create('/api/admin/wallboard-playlists/'.$playlist->id, 'PATCH'));
        self::assertSame(0, WallboardMediaAssetUsage::query()
            ->where('wallboard_playlist_id', (string) $playlist->id)
            ->count());
        $assigned->playlist_id = $updated->id;
        self::assertNull(app(WallboardMediaDeliveryService::class)->forWallboard($assigned, $asset));
    }

    public function test_local_video_request_needs_only_asset_id_and_server_overwrites_client_duration(): void
    {
        $actor = $this->actor();
        $asset = $this->asset($actor, duration: 84);
        $payload = [
            'name' => 'Lokale video',
            'configuration' => $this->localVideoConfiguration((string) $asset->id, 3_595),
        ];
        $request = new StoreWallboardPlaylistRequest;
        $request->initialize($payload);
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }
        self::assertSame($payload, $validator->validate());

        $playlist = app(WallboardPlaylistService::class)->create(
            $payload,
            $actor,
            Request::create('/api/admin/wallboard-playlists', 'POST'),
        );
        self::assertSame(84, $playlist->configuration['pages'][0]['options']['video_duration_seconds']);
        self::assertSame(89, $playlist->configuration['pages'][0]['duration_seconds']);
    }

    public function test_non_ready_non_mp4_image_and_invalid_duration_assets_are_rejected(): void
    {
        $actor = $this->actor();
        $candidates = [
            $this->asset($actor, status: WallboardMediaAsset::STATUS_PROCESSING),
            $this->asset($actor, kind: WallboardMediaAsset::KIND_IMAGE, mimeType: 'image/webp'),
            $this->asset($actor, mimeType: 'video/webm'),
            $this->asset($actor, duration: null),
            $this->asset($actor, duration: 3_596),
        ];

        foreach ($candidates as $index => $asset) {
            try {
                app(WallboardPlaylistService::class)->create([
                    'name' => 'Ongeldige video '.$index,
                    'configuration' => $this->localVideoConfiguration((string) $asset->id),
                ], $actor, Request::create('/api/admin/wallboard-playlists', 'POST'));
                self::fail('Een niet volledig gecontroleerde MP4 mag niet worden gekoppeld.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey(
                    'configuration.pages.0.options.media_asset_id',
                    $exception->errors(),
                );
            }
        }
    }

    public function test_local_video_url_cannot_be_spoofed_and_referenced_video_cannot_be_deleted(): void
    {
        $actor = $this->actor();
        $asset = $this->asset($actor);
        $configuration = $this->localVideoConfiguration((string) $asset->id);
        $configuration['pages'][0]['options']['url'] = '/api/wallboard/media/'.strtolower((string) Str::ulid());
        try {
            WallboardConfiguration::normalize($configuration);
            self::fail('Een niet-canonieke lokale video-URL moet worden geweigerd.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('configuration.pages.0.options.url', $exception->errors());
        }

        $playlist = app(WallboardPlaylistService::class)->create([
            'name' => 'Beveiligde video',
            'configuration' => $this->localVideoConfiguration((string) $asset->id),
        ], $actor, Request::create('/api/admin/wallboard-playlists', 'POST'));
        self::assertNotNull($playlist);
        $this->expectException(ConflictHttpException::class);
        app(WallboardMediaAssetService::class)->delete(
            $asset,
            1,
            $actor,
            Request::create('/api/admin/wallboard-media/assets/'.$asset->id, 'DELETE'),
        );
    }

    private function actor(): User
    {
        return User::query()->create([
            'name' => 'Wallboard videobeheerder',
            'first_name' => 'Wallboard',
            'last_name' => 'Videobeheerder',
            'email' => 'wallboard-video-'.Str::lower((string) Str::ulid()).'@example.test',
            'password' => bcrypt('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function asset(
        User $actor,
        string $status = WallboardMediaAsset::STATUS_READY,
        string $kind = WallboardMediaAsset::KIND_VIDEO,
        string $mimeType = 'video/mp4',
        ?int $duration = 24,
    ): WallboardMediaAsset {
        $id = strtolower((string) Str::ulid());
        $body = $this->mp4();
        $suffix = $kind === WallboardMediaAsset::KIND_VIDEO ? '.mp4' : '.webp';
        $path = 'wallboard-media/objects/'.$id.$suffix;
        Storage::disk('local')->put($path, $body);

        return WallboardMediaAsset::query()->create([
            'id' => $id,
            'display_name' => 'Lokale video '.$id,
            'original_name' => 'video'.$suffix,
            'kind' => $kind,
            'storage_path' => $path,
            'sha256' => hash('sha256', $body),
            'mime_type' => $mimeType,
            'byte_size' => strlen($body),
            'width' => $kind === WallboardMediaAsset::KIND_IMAGE ? 1 : null,
            'height' => $kind === WallboardMediaAsset::KIND_IMAGE ? 1 : null,
            'duration_seconds' => $duration,
            'status' => $status,
            'version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function localVideoConfiguration(string $assetId, ?int $clientDuration = null): array
    {
        return [
            'rotation_enabled' => true,
            'pages' => [[
                'id' => 'lokale-video',
                'name' => 'Lokale video',
                'type' => 'video',
                'duration_seconds' => 30,
                'options' => [
                    'media_asset_id' => strtolower($assetId),
                    ...($clientDuration === null ? [] : ['video_duration_seconds' => $clientDuration]),
                ],
            ]],
            'incident_override' => ['enabled' => false, 'page_id' => 'lokale-video'],
        ];
    }

    /** @return array<string, mixed> */
    private function mapConfiguration(?string $embeddedAssetId = null): array
    {
        return [
            'rotation_enabled' => true,
            'pages' => [[
                'id' => 'map',
                'name' => $embeddedAssetId === null
                    ? 'Kaart'
                    : 'Kaart /api/wallboard/media/'.$embeddedAssetId,
                'type' => 'map',
                'duration_seconds' => 30,
                'options' => [],
            ]],
            'incident_override' => ['enabled' => false, 'page_id' => 'map'],
        ];
    }

    private function wallboard(User $actor, WallboardPlaylist $playlist): Wallboard
    {
        return Wallboard::query()->create([
            'name' => 'Video wallboard '.Str::ulid(),
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $playlist->configuration,
            'rotation_started_at' => now(),
            'is_enabled' => true,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function mp4(): string
    {
        $box = static fn (string $type, string $payload): string => pack('N', strlen($payload) + 8).$type.$payload;

        return $box('ftyp', 'isom'.pack('N', 0).'isommp42')
            .$box('moov', $box('mvhd', "\x00\x00\x00\x00".pack('N4', 0, 0, 1000, 24_000)))
            .$box('mdat', str_repeat("\x00", 32));
    }
}
