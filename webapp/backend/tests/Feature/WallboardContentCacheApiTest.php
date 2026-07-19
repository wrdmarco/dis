<?php

namespace Tests\Feature;

use App\Contracts\WallboardContentProvider;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\Support\MutableWallboardContentProvider;
use Tests\TestCase;

final class WallboardContentCacheApiTest extends TestCase
{
    use RefreshDatabase;

    private MutableWallboardContentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new MutableWallboardContentProvider;
        $this->app->instance(WallboardContentProvider::class, $this->provider);
    }

    public function test_session_scoped_content_endpoints_use_private_revalidation_and_live_feeds_remain_no_store(): void
    {
        $wallboard = $this->wallboard();
        $credential = $this->wallboardCredential($wallboard);

        foreach (['static', 'news', 'ticker'] as $content) {
            $this->getJson('/api/wallboard/'.$content)
                ->assertUnauthorized()
                ->assertHeader('Cache-Control', 'no-store, private')
                ->assertHeaderMissing('ETag');
        }
        $this->getJson('/api/wallboard/cache')
            ->assertUnauthorized()
            ->assertHeaderMissing('Clear-Site-Data');

        $this->wallboardGet('/api/wallboard/cache', $credential)
            ->assertNoContent()
            ->assertHeader('Clear-Site-Data', '"cache"')
            ->assertHeader('Cache-Control', 'no-store, private');

        $static = $this->wallboardGet('/api/wallboard/static', $credential)
            ->assertOk()
            ->assertHeader('Vary', 'Cookie')
            ->assertJsonPath('data.wallboard.id', $wallboard->id)
            ->assertJsonPath('data.wallboard.config_version', 1)
            ->assertJsonPath(
                'data.wallboard.configuration.pages.0.options.content.blocks.0.runs.0.text',
                'Start briefing om 14:00.',
            )
            ->assertJsonPath('data.media.photo_pages', [])
            ->assertJsonMissingPath('data.wallboard.updated_at');
        $this->assertRevalidatable($static);
        $staticEtag = (string) $static->headers->get('ETag');

        // Heartbeats update the wallboard row's generic updated_at timestamp,
        // but that operational write must not invalidate static presentation.
        $wallboard->forceFill(['last_seen_at' => now()->addMinute()])->save();
        $this->assertNotModified('/api/wallboard/static', $credential, $staticEtag);

        $news = $this->wallboardGet('/api/wallboard/news', $credential)
            ->assertOk()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.pages.news.items.0.title', 'Veilig nieuws');
        $this->assertRevalidatable($news);
        $this->assertNotModified(
            '/api/wallboard/news',
            $credential,
            (string) $news->headers->get('ETag'),
        );

        $ticker = $this->wallboardGet('/api/wallboard/ticker', $credential)
            ->assertOk()
            ->assertJsonPath('data.revision', 1)
            ->assertJsonPath('data.items.0.text', 'Operationeel bericht');
        $this->assertRevalidatable($ticker);
        $this->assertNotModified(
            '/api/wallboard/ticker',
            $credential,
            (string) $ticker->headers->get('ETag'),
        );

        $control = $this->wallboardGet('/api/wallboard/control', $credential)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Clear-Site-Data')
            ->assertJsonPath('data.content_versions.static', 's:1');
        self::assertMatchesRegularExpression(
            '/^1:[a-f0-9]{64}$/D',
            (string) $control->json('data.content_versions.news'),
        );
        self::assertMatchesRegularExpression(
            '/^1:[a-f0-9]{64}$/D',
            (string) $control->json('data.content_versions.ticker'),
        );

        $this->wallboardGet('/api/wallboard/live', $credential)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data' => ['generated_at', 'maintenance', 'operational_summary', 'map']])
            ->assertJsonMissingPath('data.wallboard')
            ->assertJsonMissingPath('data.news')
            ->assertJsonMissingPath('data.ticker')
            ->assertJsonMissingPath('data.media');

        // The original endpoint deliberately remains available during a
        // rolling update and retains the complete legacy contract.
        $this->wallboardGet('/api/wallboard/state', $credential)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('Clear-Site-Data')
            ->assertJsonPath('data.wallboard.id', $wallboard->id)
            ->assertJsonPath('data.news.pages.news.items.0.title', 'Veilig nieuws')
            ->assertJsonPath('data.ticker.items.0.text', 'Operationeel bericht')
            ->assertJsonPath('data.media.photo_pages', [])
            ->assertJsonStructure(['data' => ['operational_summary', 'map']]);
    }

    private function assertRevalidatable(TestResponse $response): void
    {
        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-cache', $cacheControl);
        self::assertStringNotContainsString('no-store', $cacheControl);
        self::assertMatchesRegularExpression(
            '/^"[a-f0-9]{64}"$/D',
            (string) $response->headers->get('ETag'),
        );
    }

    private function assertNotModified(string $uri, string $credential, string $etag): void
    {
        foreach ([$etag, 'W/'.$etag] as $validator) {
            $response = $this->wallboardGet($uri, $credential, [
                'If-None-Match' => '"unrelated", '.$validator,
            ])->assertStatus(304)
                ->assertHeader('ETag', $etag)
                ->assertHeader('Vary', 'Cookie')
                ->assertHeaderMissing('Content-Type')
                ->assertHeaderMissing('Content-Length');
            $this->assertRevalidatable($response);
            self::assertSame('', $response->getContent());
        }
    }

    private function wallboard(): Wallboard
    {
        $configuration = WallboardConfiguration::normalize([
            'rotation_enabled' => true,
            'pages' => [
                [
                    'id' => 'message',
                    'name' => 'Mededeling',
                    'type' => 'message',
                    'duration_seconds' => 20,
                    'options' => ['body' => 'Start briefing om 14:00.'],
                ],
                [
                    'id' => 'news',
                    'name' => 'Nieuws',
                    'type' => 'news',
                    'duration_seconds' => 30,
                    'options' => ['sources' => ['ndt'], 'max_items' => 6],
                ],
            ],
            'ticker' => [
                'enabled' => true,
                'sources' => [[
                    'id' => 'intern',
                    'type' => 'internal',
                    'label' => 'Melding',
                    'text' => 'Operationeel bericht',
                ]],
            ],
            'incident_override' => ['enabled' => false, 'page_id' => 'message'],
        ]);
        $playlist = WallboardPlaylist::query()->create([
            'name' => 'Cacheplaylist',
            'configuration' => $configuration,
            'version' => 1,
        ]);

        return Wallboard::query()->create([
            'name' => 'Cachewallboard',
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
            'configuration' => $configuration,
            'config_version' => 1,
            'rotation_started_at' => now(),
            'is_enabled' => true,
        ]);
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
    private function wallboardGet(string $uri, string $credential, array $headers = []): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);

        return $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $credential)
            ->withCredentials()
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => 'https://dis.example.test',
                ...$headers,
            ])
            ->get($uri);
    }
}
