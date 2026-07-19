<?php

namespace Tests\Feature;

use App\Contracts\WallboardContentProvider;
use App\Models\Wallboard;
use App\Models\WallboardContentSnapshot;
use App\Models\WallboardPlaylist;
use App\Services\WallboardContentSnapshotService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MutableWallboardContentProvider;
use Tests\TestCase;

final class WallboardContentSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private MutableWallboardContentProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = new MutableWallboardContentProvider;
        $this->app->instance(WallboardContentProvider::class, $this->provider);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_revisions_change_only_for_normalized_payload_changes_and_failures_keep_last_valid_snapshot(): void
    {
        CarbonImmutable::setTestNow('2026-07-19 10:00:00');
        [$playlist, $wallboard] = $this->playlistAndWallboard();
        $service = app(WallboardContentSnapshotService::class);

        self::assertSame(
            ['snapshots' => 2, 'failures' => 0],
            $service->refreshPlaylist($playlist),
        );
        $news = $this->snapshot($playlist, WallboardContentSnapshot::KIND_NEWS);
        $ticker = $this->snapshot($playlist, WallboardContentSnapshot::KIND_TICKER);
        self::assertSame(1, $news->revision);
        self::assertSame(1, $ticker->revision);
        self::assertSame('2026-07-19T10:00:00+00:00', $news->payload['generated_at']);
        self::assertSame($news->checked_at?->timestamp, $news->updated_at?->timestamp);

        CarbonImmutable::setTestNow('2026-07-19 10:05:00');
        $this->provider->newsPayload['generated_at'] = '2026-07-19T10:05:00+00:00';
        $service->refreshPlaylist($playlist);
        $unchangedNews = $this->snapshot($playlist, WallboardContentSnapshot::KIND_NEWS);
        self::assertSame(1, $unchangedNews->revision);
        self::assertSame(
            '2026-07-19T10:00:00+00:00',
            $unchangedNews->payload['generated_at'],
            'Volatile generation time must not replace an otherwise identical snapshot.',
        );
        self::assertSame('10:05:00', $unchangedNews->checked_at?->format('H:i:s'));
        self::assertSame('10:00:00', $unchangedNews->updated_at?->format('H:i:s'));

        CarbonImmutable::setTestNow('2026-07-19 10:10:00');
        $this->provider->newsPayload['pages']['news']['items'][0]['title'] = 'Gewijzigd nieuws';
        $this->provider->tickerPayload['items'][0]['text'] = 'Gewijzigd bericht';
        $service->refreshPlaylist($playlist);
        $changedNews = $this->snapshot($playlist, WallboardContentSnapshot::KIND_NEWS);
        $changedTicker = $this->snapshot($playlist, WallboardContentSnapshot::KIND_TICKER);
        self::assertSame(2, $changedNews->revision);
        self::assertSame(2, $changedTicker->revision);
        self::assertSame('Gewijzigd nieuws', $changedNews->payload['pages']['news']['items'][0]['title']);
        self::assertSame('10:10:00', $changedNews->updated_at?->format('H:i:s'));

        $beforeFailureNews = $changedNews->payload;
        $beforeFailureTicker = $changedTicker->payload;
        CarbonImmutable::setTestNow('2026-07-19 10:15:00');
        $this->provider->failNews = true;
        $this->provider->failTicker = true;
        self::assertSame(
            ['snapshots' => 0, 'failures' => 2],
            $service->refreshPlaylist($playlist),
        );
        $failedNews = $this->snapshot($playlist, WallboardContentSnapshot::KIND_NEWS);
        $failedTicker = $this->snapshot($playlist, WallboardContentSnapshot::KIND_TICKER);
        self::assertSame(2, $failedNews->revision);
        self::assertSame(2, $failedTicker->revision);
        self::assertSame($beforeFailureNews, $failedNews->payload);
        self::assertSame($beforeFailureTicker, $failedTicker->payload);
        self::assertSame('10:15:00', $failedNews->checked_at?->format('H:i:s'));
        self::assertSame('10:10:00', $failedNews->updated_at?->format('H:i:s'));

        $this->provider->failNews = false;
        $this->provider->failTicker = false;
        $beforeToken = $service->contentVersions($wallboard->refresh());
        $configuration = (array) $playlist->configuration;
        $configuration['pages'][1]['options']['sources'] = ['ndt', 'dronewatch'];
        $playlist->forceFill([
            'configuration' => WallboardConfiguration::normalize($configuration),
            'version' => 2,
        ])->save();
        $wallboard->forceFill([
            'configuration' => $playlist->configuration,
            'config_version' => 2,
        ])->save();
        $afterConfigToken = $service->contentVersions(Wallboard::query()->findOrFail($wallboard->id));
        self::assertSame('s:2', $afterConfigToken['static']);
        self::assertNotSame($beforeToken['news'], $afterConfigToken['news']);
        self::assertStringStartsWith('2:', $afterConfigToken['news']);

        CarbonImmutable::setTestNow('2026-07-19 10:20:00');
        $service->refreshPlaylist($playlist->refresh());
        self::assertSame(
            2,
            $this->snapshot($playlist, WallboardContentSnapshot::KIND_NEWS)->revision,
            'A configuration fingerprint change with identical output must not advance content revision.',
        );
    }

    public function test_command_and_scheduler_refresh_every_playlist_on_one_server_without_overlap(): void
    {
        [$playlist] = $this->playlistAndWallboard();

        $this->artisan('dis:refresh-wallboard-content')
            ->expectsOutputToContain('1 playlist(s), 2 snapshot(s), 0 failure(s)')
            ->assertSuccessful();
        self::assertSame(2, WallboardContentSnapshot::query()->where('playlist_id', $playlist->id)->count());

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => str_contains(
                $candidate->command ?? '',
                'dis:refresh-wallboard-content',
            ));
        self::assertInstanceOf(Event::class, $event);
        self::assertSame('*/5 * * * *', $event->expression);
        self::assertTrue($event->onOneServer);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(10, $event->expiresAt);
    }

    /** @return array{0: WallboardPlaylist, 1: Wallboard} */
    private function playlistAndWallboard(): array
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [
                ['id' => 'map', 'name' => 'Kaart', 'type' => 'map', 'duration_seconds' => 30, 'options' => []],
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
        ]);
        $playlist = WallboardPlaylist::query()->create([
            'name' => 'Snapshotplaylist',
            'configuration' => $configuration,
            'version' => 1,
        ]);
        $wallboard = Wallboard::query()->create([
            'name' => 'Snapshotwallboard',
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'config_version' => 1,
            'rotation_started_at' => now(),
            'is_enabled' => true,
        ]);

        return [$playlist, $wallboard];
    }

    private function snapshot(WallboardPlaylist $playlist, string $kind): WallboardContentSnapshot
    {
        return WallboardContentSnapshot::query()
            ->where('playlist_id', $playlist->id)
            ->where('kind', $kind)
            ->firstOrFail();
    }
}
