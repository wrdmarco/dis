<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Repositories\SystemUpdateDurationHistoryRepository;
use App\Services\SystemUpdateDurationEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SystemUpdateDurationEstimatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_separate_safe_fallbacks_without_history(): void
    {
        config()->set('dis.updates.application_estimated_duration_seconds', 840);
        config()->set('dis.updates.system_estimated_duration_seconds', 1440);

        $estimator = app(SystemUpdateDurationEstimator::class);

        self::assertSame([
            'duration_seconds' => 840,
            'source' => 'fallback',
            'sample_count' => 0,
        ], $estimator->estimate(false));
        self::assertSame(1440, $estimator->estimate(true)['duration_seconds']);
    }

    public function test_it_uses_a_bounded_median_with_safety_margin_and_separate_modes(): void
    {
        $history = app(SystemUpdateDurationHistoryRepository::class);
        foreach ([600, 720, 780, 900] as $duration) {
            $history->append(false, $duration);
        }
        foreach ([1200, 1500, 1800] as $duration) {
            $history->append(true, $duration);
        }

        $estimator = app(SystemUpdateDurationEstimator::class);

        self::assertSame([
            'duration_seconds' => 863,
            'source' => 'historical',
            'sample_count' => 4,
        ], $estimator->estimate(false));
        self::assertSame(1725, $estimator->estimate(true)['duration_seconds']);
    }

    public function test_it_ignores_invalid_samples_and_retains_only_twenty_valid_runs_per_mode(): void
    {
        $history = app(SystemUpdateDurationHistoryRepository::class);
        $estimator = app(SystemUpdateDurationEstimator::class);

        $estimator->recordSuccessfulRun(false, 29);
        $estimator->recordSuccessfulRun(false, 3601);
        foreach (range(1, 25) as $index) {
            $estimator->recordSuccessfulRun(false, 300 + $index);
        }

        $stored = SystemSetting::value('system.update_duration_history');
        self::assertIsArray($stored);
        self::assertCount(20, $stored['application']);
        self::assertSame(306, $stored['application'][0]);
        self::assertSame(325, $stored['application'][19]);
        self::assertArrayNotHasKey('system', $stored);
    }

    public function test_console_command_returns_only_the_bounded_integer_estimate(): void
    {
        config()->set('dis.updates.system_estimated_duration_seconds', 1600);

        $this->artisan('dis:estimate-update-duration', ['--system' => true])
            ->expectsOutput('1600')
            ->assertSuccessful();
    }
}
