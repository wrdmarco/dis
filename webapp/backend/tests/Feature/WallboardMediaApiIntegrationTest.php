<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardMediaAsset;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Repositories\WallboardMediaAssetRepository;
use App\Services\WallboardMediaPlaylistService;
use App\Services\WallboardMediaStateService;
use App\Services\WallboardMediaUsageSynchronizer;
use App\Services\WallboardPlaylistService;
use App\Services\WallboardSessionService;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardMediaApiIntegrationTest extends TestCase
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

    public function test_media_management_requires_authentication_completed_two_factor_and_permission(): void
    {
        $this->getJson('/api/admin/wallboard-media/folders')->assertUnauthorized();

        $unprivileged = $this->user('media-unprivileged@example.test');
        $this->asAdminClient($unprivileged)
            ->getJson('/api/admin/wallboard-media/folders')
            ->assertForbidden();

        $manager = $this->user('media-pending@example.test', ['wallboards.manage']);
        $pendingToken = $manager->createToken(
            'Pending wallboard media 2FA',
            ['2fa:pending', 'client:web'],
            now()->addMinutes(5),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($pendingToken)
            ->getJson('/api/admin/wallboard-media/folders')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'two_factor_required');

        $this->asAdminClient($manager)
            ->getJson('/api/admin/wallboard-media/folders')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_media_content_is_session_scoped_immutable_and_supports_strong_etag_revalidation(): void
    {
        $manager = $this->user('media-delivery@example.test', ['wallboards.manage']);
        $asset = $this->storedAsset($manager);
        $mediaPlaylist = app(WallboardMediaPlaylistService::class)->create([
            'name' => 'Entreebeelden',
            'asset_ids' => [(string) $asset->id],
        ], $manager, Request::create('/api/admin/wallboard-media/playlists', 'POST'));
        $configuration = $this->photoConfiguration((string) $mediaPlaylist->id, 15);
        $wallboardPlaylist = app(WallboardPlaylistService::class)->create([
            'name' => 'Entreewallboard',
            'configuration' => $configuration,
        ], $manager, Request::create('/api/admin/wallboard-playlists', 'POST'));
        $wallboard = $this->wallboard($manager, $wallboardPlaylist, (array) $wallboardPlaylist->configuration);
        $deploymentAsset = $this->storedAsset($manager, 'Actieve inzet');
        $deploymentMediaPlaylist = app(WallboardMediaPlaylistService::class)->create([
            'name' => 'Actieve inzetbeelden',
            'asset_ids' => [(string) $deploymentAsset->id],
        ], $manager, Request::create('/api/admin/wallboard-media/playlists', 'POST'));
        $deploymentPlaylist = app(WallboardPlaylistService::class)->create([
            'name' => 'Actieve inzetwallboard',
            'purpose' => WallboardPlaylist::PURPOSE_ALARM,
            'configuration' => $this->photoConfiguration((string) $deploymentMediaPlaylist->id, 15),
        ], $manager, Request::create('/api/admin/wallboard-playlists', 'POST'));
        $wallboard->forceFill(['active_incident_playlist_id' => $deploymentPlaylist->id])->save();
        self::assertSame(
            (string) $deploymentPlaylist->id,
            (string) $wallboard->fresh()?->active_incident_playlist_id,
        );
        $this->assertDatabaseHas('wallboard_media_playlist_usages', [
            'wallboard_playlist_id' => (string) $deploymentPlaylist->id,
            'media_playlist_id' => (string) $deploymentMediaPlaylist->id,
        ]);
        self::assertNotNull(app(WallboardMediaAssetRepository::class)->authorizedForWallboard(
            (string) $deploymentAsset->id,
            [(string) $deploymentPlaylist->id],
        ));
        $uri = '/api/wallboard/media/'.$asset->id;
        $deploymentUri = '/api/wallboard/media/'.$deploymentAsset->id;

        $this->get($uri)
            ->assertUnauthorized()
            ->assertHeader('Cache-Control', 'no-store, private');

        $credential = $this->wallboardCredential($wallboard);
        $response = $this->wallboardGet($uri, $credential);
        $response->assertOk();
        self::assertSame($this->imageBody(), $response->streamedContent());
        $response->assertHeader('Content-Type', 'image/webp');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $etag = (string) $response->headers->get('ETag');
        self::assertSame('"'.hash('sha256', $this->imageBody()).'"', $etag);
        self::assertSame(
            'immutable, max-age=31536000, private',
            $response->headers->get('Cache-Control'),
        );
        $deploymentResponse = $this->wallboardGet($deploymentUri, $credential);
        $deploymentResponse->assertOk();
        self::assertSame($this->imageBody(), $deploymentResponse->streamedContent());

        foreach ([$etag, 'W/'.$etag] as $validator) {
            $notModified = $this->wallboardGet($uri, $credential, ['If-None-Match' => $validator]);
            $notModified->assertStatus(304);
            $notModified->assertHeader('ETag', $etag);
            $notModified->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');
            $notModified->assertHeaderMissing('Content-Type');
            $notModified->assertHeaderMissing('Content-Length');
        }

        $unassignedPlaylist = app(WallboardPlaylistService::class)->create([
            'name' => 'Ongekoppeld',
            'configuration' => $this->mapConfiguration(),
        ], $manager, Request::create('/api/admin/wallboard-playlists', 'POST'));
        $unassignedWallboard = $this->wallboard(
            $manager,
            $unassignedPlaylist,
            (array) $unassignedPlaylist->configuration,
        );
        $this->wallboardGet($uri, $this->wallboardCredential($unassignedWallboard))
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->wallboardGet($deploymentUri, $this->wallboardCredential($unassignedWallboard))
            ->assertNotFound()
            ->assertHeader('Cache-Control', 'no-store, private');

        foreach (['/api/wallboard/state', '/api/wallboard/control'] as $liveUri) {
            $this->wallboardGet($liveUri, $credential)
                ->assertOk()
                ->assertHeader('Cache-Control', 'no-store, private');
        }

        $admin = $this->asAdminClient($manager)
            ->withHeader('If-None-Match', '')
            ->get('/api/admin/wallboard-media/assets/'.$asset->id.'/content');
        $admin->assertOk()->assertHeader('ETag', $etag);
        self::assertSame('immutable, max-age=3600, private', $admin->headers->get('Cache-Control'));
        $this->asAdminClient($manager)
            ->withHeader('If-None-Match', $etag)
            ->get('/api/admin/wallboard-media/assets/'.$asset->id.'/content')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag)
            ->assertHeader('Cache-Control', 'immutable, max-age=3600, private');

        $partial = $this->wallboardGet($uri, $credential, [
            'If-None-Match' => '',
            'Range' => 'bytes=2-9',
        ]);
        $partial->assertStatus(206);
        self::assertSame(substr($this->imageBody(), 2, 8), $partial->streamedContent());
        $partial->assertHeader('ETag', $etag);
        $partial->assertHeader('Accept-Ranges', 'bytes');
        $partial->assertHeader('Content-Range', 'bytes 2-9/'.strlen($this->imageBody()));
        $partial->assertHeader('Content-Length', '8');
        $partial->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

        $invalidRange = $this->wallboardGet(
            $uri,
            $credential,
            ['Range' => 'bytes='.strlen($this->imageBody()).'-'],
        );
        $invalidRange->assertStatus(416);
        $invalidRange->assertHeader('Content-Range', 'bytes */'.strlen($this->imageBody()));
        $invalidRange->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_image_upload_persists_and_delivers_a_verified_webp_thumbnail(): void
    {
        if (! function_exists('imagecreatefrompng') || ! function_exists('imagewebp')) {
            self::markTestSkipped('De lokale test-PHP heeft geen GD/WebP; productie installeert php8.5-gd.');
        }

        $source = $this->pngBody(1920, 1080);
        $manager = $this->user('media-thumbnail-upload@example.test', ['wallboards.manage']);
        $uploaded = UploadedFile::fake()->createWithContent('thumbnail-source.png', $source);

        $created = $this->asAdminClient($manager)
            ->post('/api/admin/wallboard-media/assets', [
                'file' => $uploaded,
                'display_name' => 'Thumbnail regressietest',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.status', WallboardMediaAsset::STATUS_READY)
            ->assertJsonPath('data.processing_progress', 100);

        $assetId = (string) $created->json('data.id');
        $thumbnailUrl = $created->json('data.thumbnail_url');
        self::assertSame(
            '/api/admin/wallboard-media/assets/'.$assetId.'/thumbnail',
            $thumbnailUrl,
        );
        $asset = WallboardMediaAsset::query()->findOrFail($assetId);
        self::assertSame(1920, $asset->width);
        self::assertSame(1080, $asset->height);
        self::assertNotNull($asset->thumbnail_storage_path);
        self::assertSame('image/webp', $asset->thumbnail_mime_type);
        self::assertGreaterThan(0, (int) $asset->thumbnail_byte_size);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $asset->thumbnail_sha256);
        Storage::disk('local')->assertExists((string) $asset->thumbnail_storage_path);

        $thumbnail = $this->asAdminClient($manager)
            ->get((string) $thumbnailUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('ETag', '"'.(string) $asset->thumbnail_sha256.'"');
        $body = $thumbnail->streamedContent();
        self::assertSame((int) $asset->thumbnail_byte_size, strlen($body));
        self::assertSame((string) $asset->thumbnail_sha256, hash('sha256', $body));

        $contentPath = (string) $asset->storage_path;
        $thumbnailPath = (string) $asset->thumbnail_storage_path;
        $this->asAdminClient($manager)
            ->deleteJson('/api/admin/wallboard-media/assets/'.$assetId, ['expected_version' => 1])
            ->assertNoContent();
        Storage::disk('local')->assertMissing($contentPath);
        Storage::disk('local')->assertMissing($thumbnailPath);
    }

    public function test_image_upload_rejects_each_source_dimension_below_full_hd(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagewebp')) {
            self::markTestSkipped('De lokale test-PHP heeft geen GD/WebP; productie installeert php8.5-gd.');
        }

        $manager = $this->user('media-small-upload@example.test', ['wallboards.manage']);
        foreach ([
            'te smal' => [1919, 1080],
            'te laag' => [1920, 1079],
        ] as $case => [$width, $height]) {
            $uploaded = UploadedFile::fake()->createWithContent(
                "{$case}.png",
                $this->pngBody($width, $height),
            );
            $this->asAdminClient($manager)
                ->post('/api/admin/wallboard-media/assets', ['file' => $uploaded], ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'validation_failed')
                ->assertJsonPath(
                    'error.details.file.0',
                    'De afbeelding moet minimaal 1920 bij 1080 pixels zijn, liggend of staand.',
                );
        }
        self::assertSame(0, WallboardMediaAsset::query()->count());
    }

    public function test_media_upload_rejects_two_file_fields_without_processing_either(): void
    {
        $manager = $this->user('media-exclusive-upload@example.test', ['wallboards.manage']);

        $this->asAdminClient($manager)
            ->post('/api/admin/wallboard-media/assets', [
                'file' => UploadedFile::fake()->create('video.mp4', 1, 'video/mp4'),
                'image' => UploadedFile::fake()->create('image.webp', 1, 'image/webp'),
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        self::assertSame(0, WallboardMediaAsset::query()->count());
    }

    public function test_non_media_partial_responses_remain_non_cacheable(): void
    {
        $request = Request::create('/api/not-wallboard-media', 'GET');
        $response = response('partial', 206, [
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600, immutable',
            'Content-Range' => 'bytes 0-6/7',
            'ETag' => '"partial"',
        ]);

        $secured = app(SecurityHeaders::class)->apply($request, $response);

        self::assertSame('no-store, private', $secured->headers->get('Cache-Control'));
        self::assertSame('no-cache', $secured->headers->get('Pragma'));
        self::assertSame('0', $secured->headers->get('Expires'));
    }

    public function test_media_playlist_item_changes_rederive_duration_and_invalidate_linked_wallboards(): void
    {
        $manager = $this->user('media-versioning@example.test', ['wallboards.manage']);
        $first = $this->storedAsset($manager, 'Eerste');
        $second = $this->storedAsset($manager, 'Tweede');
        $request = Request::create('/api/admin/wallboard-media/playlists', 'POST');
        $mediaService = app(WallboardMediaPlaylistService::class);
        $mediaPlaylist = $mediaService->create([
            'name' => 'Wisselende beelden',
            'asset_ids' => [(string) $first->id],
        ], $manager, $request);
        $configuration = $this->photoConfiguration((string) $mediaPlaylist->id, 20);
        $wallboardPlaylist = app(WallboardPlaylistService::class)->create([
            'name' => 'Versieplaylist',
            'configuration' => $configuration,
        ], $manager, Request::create('/api/admin/wallboard-playlists', 'POST'));
        $wallboard = $this->wallboard(
            $manager,
            $wallboardPlaylist,
            (array) $wallboardPlaylist->configuration,
        );
        $wallboard->refresh();

        self::assertSame(20, $wallboardPlaylist->configuration['pages'][0]['duration_seconds']);
        self::assertSame(1, $wallboardPlaylist->version);
        self::assertSame(1, $wallboard->config_version);
        self::assertSame(1, $wallboard->control_version);

        $updated = $mediaService->update($mediaPlaylist, [
            'expected_version' => 1,
            'asset_ids' => [(string) $first->id, (string) $second->id],
        ], $manager, Request::create('/api/admin/wallboard-media/playlists/'.$mediaPlaylist->id, 'PATCH'));

        self::assertSame(2, $updated->version);
        $wallboardPlaylist->refresh();
        $wallboard->refresh();
        self::assertSame(2, $wallboardPlaylist->version);
        self::assertSame(40, $wallboardPlaylist->configuration['pages'][0]['duration_seconds']);
        self::assertSame(2, $wallboard->config_version);
        self::assertSame(2, $wallboard->control_version);
        self::assertSame(40, $wallboard->configuration['pages'][0]['duration_seconds']);

        $photoPage = app(WallboardMediaStateService::class)
            ->pages($wallboard, (array) $wallboard->configuration)['photos'];
        self::assertSame(2, $photoPage['media_playlist_version']);
        self::assertSame(40, $photoPage['total_duration_seconds']);
        self::assertCount(2, $photoPage['items']);
    }

    public function test_video_rotation_duration_is_derived_when_configuration_is_persisted(): void
    {
        $manager = $this->user('video-duration-persistence@example.test', ['wallboards.manage']);
        $client = $this->asAdminClient($manager);
        $page = [
            'id' => 'video',
            'name' => 'Video',
            'type' => 'video',
            'duration_seconds' => 5,
            'options' => [
                'url' => 'https://youtu.be/dQw4w9WgXcQ',
                'video_duration_seconds' => 3595,
            ],
        ];

        $created = $client->postJson('/api/admin/wallboard-playlists', [
            'name' => 'Videoplaylist',
            'configuration' => ['pages' => [$page]],
        ])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.configuration.pages.0.duration_seconds', 3600)
            ->assertJsonPath('data.configuration.pages.0.options.video_duration_seconds', 3595)
            ->assertJsonPath(
                'data.configuration.pages.0.options.url',
                'https://www.youtube.com/embed/dQw4w9WgXcQ',
            );

        $page['duration_seconds'] = 3600;
        $page['options']['video_duration_seconds'] = 1;
        $client->patchJson('/api/admin/wallboard-playlists/'.$created->json('data.id'), [
            'expected_version' => 1,
            'configuration' => ['pages' => [$page]],
        ])->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.configuration.pages.0.duration_seconds', 6)
            ->assertJsonPath('data.configuration.pages.0.options.video_duration_seconds', 1);

        $page['options']['video_duration_seconds'] = 3596;
        $client->postJson('/api/admin/wallboard-playlists', [
            'name' => 'Te lange video',
            'configuration' => ['pages' => [$page]],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [
                'configuration.pages.0.options.video_duration_seconds',
            ]]]);

        $page['options']['video_duration_seconds'] = 60;
        $page['options']['duration_override'] = 10;
        $client->postJson('/api/admin/wallboard-playlists', [
            'name' => 'Onbetrouwbare duur',
            'configuration' => ['pages' => [$page]],
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => [
                'configuration.pages.0.options',
            ]]]);
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => 'Wallboard Media Test',
            'first_name' => 'Wallboard',
            'last_name' => 'Media Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'wallboard-media-test-'.Str::lower((string) Str::ulid()),
            'display_name' => 'Wallboard media test role',
            'can_use_admin_app' => true,
            'can_use_operator_app' => false,
        ]);
        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'system_configuration',
                    'description' => 'Test permission',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken(
            'Wallboard media admin test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function wallboardCredential(Wallboard $wallboard): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => app(WallboardSessionService::class)->tokenHash($secret),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);

        return $session->id.'.'.$secret;
    }

    /** @param array<string, string> $headers */
    private function wallboardGet(string $uri, string $cookie, array $headers = []): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);

        return $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $cookie)
            ->withCredentials()
            ->withHeaders(['Origin' => 'https://dis.example.test', ...$headers])
            ->get($uri);
    }

    private function storedAsset(User $actor, string $name = 'Testfoto'): WallboardMediaAsset
    {
        $id = (string) Str::ulid();
        $body = $this->imageBody();
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

    private function imageBody(): string
    {
        $body = base64_decode(
            'UklGRiIAAABXRUJQVlA4ICAAAADQAQCdASoBAAEAL0AcJaQAA3AA/v89WAAAAA==',
            true,
        );
        self::assertIsString($body);

        return $body;
    }

    private function pngBody(int $width, int $height): string
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
        ob_start();
        $encoded = imagepng($image);
        $body = ob_get_clean();
        imagedestroy($image);
        self::assertTrue($encoded);
        self::assertIsString($body);

        return $body;
    }

    /** @return array<string, mixed> */
    private function photoConfiguration(string $mediaPlaylistId, int $itemDurationSeconds): array
    {
        return [
            'rotation_enabled' => true,
            'pages' => [[
                'id' => 'photos',
                'name' => 'Foto\'s',
                'type' => WallboardMediaUsageSynchronizer::PAGE_TYPE,
                'duration_seconds' => 5,
                'options' => [
                    'media_playlist_id' => $mediaPlaylistId,
                    'item_duration_seconds' => $itemDurationSeconds,
                ],
            ]],
            'incident_override' => ['enabled' => false, 'page_id' => 'photos'],
        ];
    }

    /** @return array<string, mixed> */
    private function mapConfiguration(): array
    {
        return [
            'rotation_enabled' => true,
            'pages' => [[
                'id' => 'map',
                'name' => 'Kaart',
                'type' => 'map',
                'duration_seconds' => 30,
                'options' => [],
            ]],
            'incident_override' => ['enabled' => false, 'page_id' => 'map'],
        ];
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
