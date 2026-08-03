<?php

namespace Tests\Feature;

use App\Contracts\OperationalRadarProvider;
use App\Services\OperationalRadarCacheWarmupService;
use App\Support\OperationalRadarContent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use RuntimeException;
use Tests\TestCase;

final class OperationalRadarCacheWarmupTest extends TestCase
{
    public function test_it_warms_only_the_reference_frame_for_each_supported_layer(): void
    {
        $provider = new RadarCacheWarmupProviderStub([
            'precipitation' => [
                'status' => 'available',
                'reference_time' => '2026-08-03T10:20:00+00:00',
                'frames' => [
                    $this->frame(-5, '20260803T101500Z-o-1111111111111111'),
                    $this->frame(0, '20260803T102000Z-o-2222222222222222'),
                    $this->frame(0, '20260803T102000Z-o-3333333333333333'),
                ],
            ],
            'lightning' => [
                'status' => 'stale',
                'reference_time' => '2026-08-03T10:15:00+00:00',
                'frames' => [
                    $this->frame(-5, '20260803T101000Z-o-4444444444444444', 'lightning'),
                    $this->frame(
                        null,
                        '20260803T101500Z-o-5555555555555555',
                        'lightning',
                        '2026-08-03T10:15:00+00:00',
                    ),
                ],
            ],
            'unsupported' => [
                'status' => 'available',
                'frames' => [
                    $this->frame(0, '20260803T102000Z-o-6666666666666666'),
                ],
            ],
        ]);
        $this->app->instance(OperationalRadarProvider::class, $provider);

        $result = $this->app->make(OperationalRadarCacheWarmupService::class)
            ->warmReferenceFrames();

        self::assertSame(['requested' => 2, 'warmed' => 2], $result);
        self::assertSame([
            ['precipitation', '20260803T102000Z-o-2222222222222222'],
            ['lightning', '20260803T101500Z-o-5555555555555555'],
        ], $provider->fileCalls);
    }

    public function test_unavailable_or_invalid_metadata_is_skipped_fail_safe(): void
    {
        $provider = new RadarCacheWarmupProviderStub([
            'precipitation' => [
                'status' => 'unavailable',
                'reference_time' => '2026-08-03T10:20:00+00:00',
                'frames' => [$this->frame(0, '20260803T102000Z-o-1111111111111111')],
            ],
            'lightning' => [
                'status' => 'available',
                'reference_time' => '2026-08-03T10:20:00+00:00',
                'frames' => [
                    [
                        ...$this->frame(0, '20260803T102000Z-o-2222222222222222', 'lightning'),
                        'image_url' => 'https://attacker.example/api/operational-weather/radar/lightning/20260803T102000Z-o-2222222222222222.png',
                    ],
                    [
                        ...$this->frame(0, '20260803T102000Z-o-3333333333333333', 'lightning'),
                        'image_url' => '/api/operational-weather/radar/precipitation/20260803T102000Z-o-3333333333333333.png',
                    ],
                    [
                        ...$this->frame(0, '20260803T102000Z-o-4444444444444444', 'lightning'),
                        'image_url' => '/api/operational-weather/radar/lightning/20260803T102000Z-o-4444444444444444.png?retry=1',
                    ],
                ],
            ],
        ]);
        $this->app->instance(OperationalRadarProvider::class, $provider);

        $result = $this->app->make(OperationalRadarCacheWarmupService::class)
            ->warmReferenceFrames();

        self::assertSame(['requested' => 0, 'warmed' => 0], $result);
        self::assertSame([], $provider->fileCalls);
    }

    public function test_provider_failures_do_not_abort_other_layers_or_the_command(): void
    {
        $provider = new RadarCacheWarmupProviderStub([
            'precipitation' => [
                'status' => 'available',
                'frames' => [$this->frame(0, '20260803T102000Z-o-1111111111111111')],
            ],
            'lightning' => [
                'status' => 'available',
                'frames' => [$this->frame(0, '20260803T102000Z-o-2222222222222222', 'lightning')],
            ],
        ], fileFailures: ['precipitation']);
        $this->app->instance(OperationalRadarProvider::class, $provider);

        $this->artisan('dis:warm-operational-radar-cache')
            ->expectsOutput('Operational radar cache warmup complete: 2 frame(s) requested, 1 warmed.')
            ->assertSuccessful();

        self::assertSame([
            ['precipitation', '20260803T102000Z-o-1111111111111111'],
            ['lightning', '20260803T102000Z-o-2222222222222222'],
        ], $provider->fileCalls);

        $metadataFailure = new RadarCacheWarmupProviderStub([], metadataFailure: true);
        $this->app->instance(OperationalRadarProvider::class, $metadataFailure);

        self::assertSame(
            ['requested' => 0, 'warmed' => 0],
            $this->app->make(OperationalRadarCacheWarmupService::class)->warmReferenceFrames(),
        );
    }

    public function test_scheduler_runs_the_shared_warmup_in_the_background_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => str_contains(
                $candidate->command ?? '',
                'dis:warm-operational-radar-cache',
            ));

        self::assertInstanceOf(Event::class, $event);
        self::assertSame('*/5 * * * *', $event->expression);
        self::assertTrue($event->onOneServer);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(10, $event->expiresAt);
        self::assertTrue($event->runInBackground);
    }

    /**
     * @return array{lead_minutes: int|null, valid_at: string, image_url: string}
     */
    private function frame(
        ?int $leadMinutes,
        string $snapshot,
        string $kind = 'precipitation',
        string $validAt = '2026-08-03T10:20:00+00:00',
    ): array {
        return [
            'lead_minutes' => $leadMinutes,
            'valid_at' => $validAt,
            'image_url' => "/api/operational-weather/radar/{$kind}/{$snapshot}.png",
        ];
    }
}

final class RadarCacheWarmupProviderStub implements OperationalRadarProvider
{
    /** @var list<array{0: string, 1: string}> */
    public array $fileCalls = [];

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $fileFailures
     */
    public function __construct(
        private readonly array $metadata,
        private readonly bool $metadataFailure = false,
        private readonly array $fileFailures = [],
    ) {}

    public function metadata(): array
    {
        if ($this->metadataFailure) {
            throw new RuntimeException('Metadata is unavailable.');
        }

        return $this->metadata;
    }

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent
    {
        $this->fileCalls[] = [$kind, $snapshotId];
        if (in_array($kind, $this->fileFailures, true)) {
            throw new RuntimeException('Frame is unavailable.');
        }

        return OperationalRadarContent::fromBody($kind.'-'.$snapshotId);
    }
}
