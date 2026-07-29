<?php

namespace Tests\Feature;

use App\Contracts\OperationalRadarProvider;
use App\Services\OperationalWeatherRadarPageService;
use App\Support\OperationalRadarContent;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class OperationalWeatherRadarPageServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_national_radar_state_resolves_without_loading_a_weather_forecast(): void
    {
        CarbonImmutable::setTestNow('2026-07-29T12:05:00Z');
        $metadata = [
            'precipitation' => ['status' => 'available'],
            'lightning' => ['status' => 'unavailable'],
        ];
        $this->app->instance(OperationalRadarProvider::class, new RadarMetadataStub($metadata));

        $state = $this->app->make(OperationalWeatherRadarPageService::class)
            ->stateForOptions(['location_mode' => 'netherlands']);

        self::assertSame('netherlands', $state['location']['mode']);
        self::assertSame('UAV Nederland', $state['location']['label']);
        self::assertIsFloat($state['location']['latitude']);
        self::assertIsFloat($state['location']['longitude']);
        self::assertGreaterThanOrEqual(50.7, $state['location']['latitude']);
        self::assertLessThanOrEqual(53.7, $state['location']['latitude']);
        self::assertGreaterThanOrEqual(3.2, $state['location']['longitude']);
        self::assertLessThanOrEqual(7.3, $state['location']['longitude']);
        self::assertSame('2026-07-29T12:05:00+00:00', $state['generated_at']);
        self::assertSame($metadata, $state['radar']);
    }
}

final class RadarMetadataStub implements OperationalRadarProvider
{
    /** @param array<string, mixed> $metadata */
    public function __construct(private readonly array $metadata) {}

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent
    {
        return null;
    }
}
