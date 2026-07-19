<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\SystemUpdateStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SystemUpdateStatusTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_running_public_status_exposes_a_client_countdown_baseline(): void
    {
        Cache::forget('system.update.status');
        Event::fake();
        config()->set('dis.updates.system_estimated_duration_seconds', 1500);
        CarbonImmutable::setTestNow('2026-07-19T10:00:00+02:00');

        $service = app(SystemUpdateStatusService::class);
        $service->start('Systeemupdate gestart.', true);

        CarbonImmutable::setTestNow('2026-07-19T10:04:00+02:00');
        $status = $service->publicStatus();

        self::assertSame('2026-07-19T10:00:00+02:00', $status['started_at']);
        self::assertSame(1500, $status['estimated_duration_seconds']);
        self::assertSame('2026-07-19T10:25:00+02:00', $status['estimated_completion_at']);
        self::assertSame(1260, $status['remaining_seconds']);
        self::assertSame('fallback', $status['estimate_source']);
        self::assertTrue($status['includes_system_updates']);
    }

    public function test_successful_completion_records_duration_for_the_next_estimate(): void
    {
        Cache::forget('system.update.status');
        Event::fake();
        CarbonImmutable::setTestNow('2026-07-19T10:00:00+02:00');

        $service = app(SystemUpdateStatusService::class);
        $service->start('App-update gestart.', false);
        CarbonImmutable::setTestNow('2026-07-19T10:10:00+02:00');
        $service->finish(0);

        self::assertSame([600], SystemSetting::value('system.update_duration_history')['application'] ?? null);
        self::assertSame(0, $service->publicStatus()['remaining_seconds']);
    }

    public function test_failed_or_implausibly_short_runs_do_not_pollute_history(): void
    {
        Cache::forget('system.update.status');
        Event::fake();
        CarbonImmutable::setTestNow('2026-07-19T10:00:00+02:00');

        $service = app(SystemUpdateStatusService::class);
        $service->start('App-update gestart.', false);
        CarbonImmutable::setTestNow('2026-07-19T10:00:20+02:00');
        $service->finish(0);

        self::assertNull(SystemSetting::query()->find('system.update_duration_history'));

        Cache::forget('system.update.status');
        CarbonImmutable::setTestNow('2026-07-19T11:00:00+02:00');
        $service->start('App-update gestart.', false);
        CarbonImmutable::setTestNow('2026-07-19T11:10:00+02:00');
        $service->finish(1);

        self::assertNull(SystemSetting::query()->find('system.update_duration_history'));
    }
}
