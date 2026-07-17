<?php

namespace Tests\Feature;

use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ApiDateTimeTest extends TestCase
{
    public function test_api_datetime_preserves_the_stored_application_wall_clock_in_summer(): void
    {
        config()->set('app.timezone', 'Europe/Amsterdam');
        $utc = CarbonImmutable::parse('2026-07-17T07:00:00.123456+00:00');

        $serialized = ApiDateTime::dateTime($utc);

        $this->assertSame('2026-07-17T07:00:00+02:00', $serialized);
    }

    public function test_api_datetime_preserves_the_stored_application_wall_clock_in_winter(): void
    {
        config()->set('app.timezone', 'Europe/Amsterdam');
        $utc = CarbonImmutable::parse('2026-01-17T07:00:00+00:00');

        $serialized = ApiDateTime::dateTime($utc);

        $this->assertSame('2026-01-17T07:00:00+01:00', $serialized);
    }
}
