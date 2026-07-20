<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\SystemUpdateDurationEstimator;
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

    public function test_nested_deployment_phase_does_not_finish_or_poison_update_history(): void
    {
        Cache::forget('system.update.status');
        Event::fake();
        CarbonImmutable::setTestNow('2026-07-19T10:00:00+02:00');

        $service = app(SystemUpdateStatusService::class);
        $service->start('App-update gestart.', false);

        CarbonImmutable::setTestNow('2026-07-19T10:05:00+02:00');
        $service->append('Nested deployment phase finished');

        self::assertSame('running', $service->publicStatus()['state']);
        self::assertNull(SystemSetting::query()->find('system.update_duration_history'));

        CarbonImmutable::setTestNow('2026-07-19T10:10:00+02:00');
        $service->append('DIS system and application update completed.');

        self::assertSame('succeeded', $service->publicStatus()['state']);
        self::assertSame([600], SystemSetting::value('system.update_duration_history')['application'] ?? null);

        CarbonImmutable::setTestNow('2026-07-19T10:10:05+02:00');
        $service->finish(0);
        self::assertSame([600], SystemSetting::value('system.update_duration_history')['application'] ?? null);
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

    public function test_runner_measurement_survives_application_cache_clear_and_preserves_update_mode(): void
    {
        Cache::forget('system.update.status');
        Event::fake();
        CarbonImmutable::setTestNow('2026-07-19T10:00:00+02:00');

        $service = app(SystemUpdateStatusService::class);
        $service->start('Systeemupdate gestart.', true);

        // Deployment runs optimize:clear. The root runner remains alive and
        // supplies its end-to-end measurement when Laravel comes back.
        Cache::forget('system.update.status');
        CarbonImmutable::setTestNow('2026-07-19T10:22:00+02:00');
        $service->finish(0, 1320, true);

        self::assertSame([1320], SystemSetting::value('system.update_duration_history')['system'] ?? null);
        self::assertArrayNotHasKey('application', SystemSetting::value('system.update_duration_history'));
        self::assertTrue($service->publicStatus()['includes_system_updates']);

        $estimate = app(SystemUpdateDurationEstimator::class)->estimate(true);
        self::assertSame('historical', $estimate['source']);
        self::assertSame(1518, $estimate['duration_seconds']);
    }

    public function test_explicit_runner_measurement_is_recorded_only_once(): void
    {
        Cache::forget('system.update.status');
        Event::fake();
        CarbonImmutable::setTestNow('2026-07-19T10:00:00+02:00');

        $service = app(SystemUpdateStatusService::class);
        $service->start('App-update gestart.', false);
        CarbonImmutable::setTestNow('2026-07-19T10:12:00+02:00');
        $service->finish(0, 720, false);
        $service->finish(0, 720, false);

        self::assertSame([720], SystemSetting::value('system.update_duration_history')['application'] ?? null);
    }

    public function test_finish_command_forwards_the_measured_duration_and_system_mode(): void
    {
        Cache::forget('system.update.status');
        Event::fake();

        $this->artisan('dis:finish-update', [
            'exitCode' => '0',
            'durationSeconds' => '1260',
            '--system' => true,
        ])->assertSuccessful();

        self::assertSame([1260], SystemSetting::value('system.update_duration_history')['system'] ?? null);
        self::assertTrue(app(SystemUpdateStatusService::class)->publicStatus()['includes_system_updates']);
    }

    public function test_finish_command_rejects_an_invalid_measured_duration(): void
    {
        Cache::forget('system.update.status');
        Event::fake();

        $this->artisan('dis:finish-update', [
            'exitCode' => '0',
            'durationSeconds' => '12seconds',
        ])->assertFailed();

        self::assertNull(SystemSetting::query()->find('system.update_duration_history'));
        self::assertSame('idle', app(SystemUpdateStatusService::class)->publicStatus()['state']);
    }
}
