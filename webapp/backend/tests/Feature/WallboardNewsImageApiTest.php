<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Services\WallboardNewsService;
use App\Services\WallboardSessionService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class WallboardNewsImageApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-19 12:00:00', 'Europe/Amsterdam'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    public function test_registered_news_image_requires_wallboard_session_and_returns_hardened_private_response(): void
    {
        $feedUrl = 'https://news.example.org/feed.xml';
        $articleUrl = 'https://news.example.org/articles/eerste';
        $imageUrl = 'https://news.example.org/images/eerste.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $this->assertIsString($png);
        Http::fake([
            $feedUrl => Http::response(
                '<?xml version="1.0"?><rss xmlns:media="http://search.yahoo.com/mrss/"><channel><item>'
                .'<title>Eerste bericht</title><link>'.$articleUrl.'</link>'
                .'<pubDate>Sun, 19 Jul 2026 09:45:00 +0000</pubDate>'
                .'<media:content url="'.$imageUrl.'" type="image/png" />'
                .'</item></channel></rss>',
                200,
                ['Content-Type' => 'application/rss+xml'],
            ),
            $imageUrl => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);
        $service = new WallboardNewsService(static fn (string $host): array => ['93.184.216.34']);
        $this->app->instance(WallboardNewsService::class, $service);
        $item = $service->pages([[
            'id' => 'news',
            'type' => 'news',
            'options' => [
                'sources' => [],
                'custom_sources' => [['id' => 'eigen', 'label' => 'Eigen', 'url' => $feedUrl]],
                'max_items' => 1,
            ],
        ]])['pages']['news']['items'][0];
        $path = (string) $item['image_url'];
        $this->assertMatchesRegularExpression('#^/api/wallboard/news-images/[a-f0-9]{64}$#', $path);

        $this->get($path)->assertUnauthorized();

        $response = $this->wallboardGet($path, $this->wallboardCredential());
        $response->assertOk()->assertContent($png);
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    private function wallboardCredential(): string
    {
        $user = User::query()->create([
            'name' => 'Wallboard News User',
            'first_name' => 'Wallboard',
            'last_name' => 'News User',
            'email' => 'wallboard-news-image@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $wallboard = Wallboard::query()->create([
            'name' => 'Nieuws wallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::defaults(),
            'is_enabled' => true,
            'rotation_started_at' => now(),
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => null,
        ]);

        return $session->id.'.'.$secret;
    }

    private function wallboardGet(string $uri, string $cookie): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);

        return $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $cookie)
            ->withCredentials()
            ->withHeaders(['Origin' => 'https://dis.example.test'])
            ->get($uri);
    }
}
