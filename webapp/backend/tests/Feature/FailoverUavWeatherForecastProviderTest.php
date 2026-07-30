<?php

namespace Tests\Feature;

use App\Contracts\UavWeatherForecastProvider;
use App\Services\FailoverUavWeatherForecastProvider;
use RuntimeException;
use Tests\TestCase;

final class FailoverUavWeatherForecastProviderTest extends TestCase
{
    public function test_fresh_complete_primary_is_returned_without_calling_fallback(): void
    {
        $primaryReading = $this->reading('DMI', complete: true, stale: false);
        $primary = new FailoverStubUavWeatherForecastProvider($primaryReading);
        $fallback = new FailoverStubUavWeatherForecastProvider(
            $this->reading('DWD', complete: true, stale: false),
        );

        $reading = (new FailoverUavWeatherForecastProvider($primary, $fallback))
            ->forResolution(['complete' => true]);

        $this->assertSame($primaryReading, $reading);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(0, $fallback->calls);
    }

    public function test_stale_primary_is_replaced_by_fresh_complete_fallback(): void
    {
        $primary = new FailoverStubUavWeatherForecastProvider(
            $this->reading('DMI', complete: true, stale: true),
        );
        $fallbackReading = $this->reading('DWD', complete: true, stale: false);
        $fallback = new FailoverStubUavWeatherForecastProvider($fallbackReading);

        $reading = (new FailoverUavWeatherForecastProvider($primary, $fallback))
            ->forResolution(['complete' => true]);

        $this->assertSame($fallbackReading, $reading);
        $this->assertSame(1, $primary->calls);
        $this->assertSame(1, $fallback->calls);
    }

    public function test_unavailable_primary_is_replaced_by_fresh_complete_fallback(): void
    {
        $primary = new FailoverStubUavWeatherForecastProvider(
            $this->reading('DMI', complete: false, stale: false),
        );
        $fallbackReading = $this->reading('DWD', complete: true, stale: false);
        $fallback = new FailoverStubUavWeatherForecastProvider($fallbackReading);

        $reading = (new FailoverUavWeatherForecastProvider($primary, $fallback))
            ->forResolution(['complete' => true]);

        $this->assertSame($fallbackReading, $reading);
    }

    public function test_failed_or_stale_fallback_preserves_original_primary_result(): void
    {
        $primaryReading = $this->reading('DMI', complete: true, stale: true);
        $primary = new FailoverStubUavWeatherForecastProvider($primaryReading);
        $fallback = new FailoverStubUavWeatherForecastProvider(
            $this->reading('DWD', complete: true, stale: true),
        );

        $provider = new FailoverUavWeatherForecastProvider($primary, $fallback);
        $this->assertSame(
            $primaryReading,
            $provider->forResolution(['complete' => true]),
        );

        $throwingFallback = new FailoverStubUavWeatherForecastProvider(
            new RuntimeException('provider failed'),
        );
        $this->assertSame(
            $primaryReading,
            (new FailoverUavWeatherForecastProvider($primary, $throwingFallback))
                ->forResolution(['complete' => true]),
        );
    }

    public function test_container_resolves_the_composite_provider(): void
    {
        $this->assertInstanceOf(
            FailoverUavWeatherForecastProvider::class,
            app(UavWeatherForecastProvider::class),
        );
    }

    /** @return array<string, mixed> */
    private function reading(string $source, bool $complete, bool $stale): array
    {
        return [
            'complete' => $complete,
            'stale' => $stale,
            'source' => ['name' => $source],
        ];
    }
}

final class FailoverStubUavWeatherForecastProvider implements UavWeatherForecastProvider
{
    public int $calls = 0;

    /** @param array<string, mixed>|\Throwable $result */
    public function __construct(private readonly array|\Throwable $result) {}

    /**
     * @param  array<string, mixed>  $resolution
     * @return array<string, mixed>
     */
    public function forResolution(array $resolution): array
    {
        unset($resolution);
        $this->calls++;
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
