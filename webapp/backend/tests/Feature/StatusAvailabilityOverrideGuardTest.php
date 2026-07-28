<?php

namespace Tests\Feature;

use App\Models\AvailabilityOverride;
use App\Models\AvailabilityStatus;
use App\Models\AvailabilityWeekPattern;
use App\Models\User;
use App\Models\UserVacation;
use App\Services\AvailabilityScheduleService;
use App\Services\StatusService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StatusAvailabilityOverrideGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_unavailable_all_day_override_blocks_direct_available_status(): void
    {
        $user = $this->user('status-guard-blocked@example.test');
        $this->override(
            $user,
            today()->subDay()->toDateString(),
            today()->addDay()->toDateString(),
            false,
        );

        try {
            app(StatusService::class)->setStatus($user, 'available', $user);
            $this->fail('An active unavailable all-day override must block a direct available status.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Deze gebruiker heeft voor vandaag een niet-beschikbare planning en kan niet beschikbaar worden gezet.'],
                $exception->errors()['status'] ?? [],
            );
        }

        $this->assertDatabaseCount('availability_statuses', 0);
    }

    public function test_only_the_current_authoritative_all_day_override_blocks_available_status(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 14:00:00', config('app.timezone')));

        $future = $this->user('status-guard-future@example.test');
        $this->override(
            $future,
            today()->addDay()->toDateString(),
            today()->addDays(2)->toDateString(),
            false,
        );

        $expired = $this->user('status-guard-expired@example.test');
        $this->override(
            $expired,
            today()->subDays(2)->toDateString(),
            today()->subDay()->toDateString(),
            false,
        );

        $dayPart = $this->user('status-guard-day-part@example.test');
        $this->override(
            $dayPart,
            today()->toDateString(),
            today()->toDateString(),
            false,
            'morning',
        );

        $available = $this->user('status-guard-available@example.test');
        $this->override(
            $available,
            today()->toDateString(),
            today()->toDateString(),
            true,
        );

        $legacy = $this->user('status-guard-legacy@example.test');
        UserVacation::query()->create([
            'user_id' => $legacy->id,
            'starts_at' => today(),
            'ends_at' => today()->addDay(),
            'status' => UserVacation::STATUS_ACTIVE,
            'created_by' => $legacy->id,
        ]);

        foreach ([$future, $expired, $dayPart, $available, $legacy] as $user) {
            $record = app(StatusService::class)->setStatus($user, 'available', $user);

            $this->assertSame('available', $record->status);
            $this->assertTrue((bool) $record->is_available);
        }
    }

    public function test_newest_current_all_day_override_is_authoritative_when_ranges_overlap(): void
    {
        $user = $this->user('status-guard-overlap@example.test');
        $olderUnavailable = $this->override(
            $user,
            today()->subDay()->toDateString(),
            today()->addDay()->toDateString(),
            false,
        );
        $olderUnavailable->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();
        $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            true,
        );

        $record = app(StatusService::class)->setStatus($user, 'available', $user);

        $this->assertSame('available', $record->status);
        $this->assertTrue((bool) $record->is_available);
    }

    public function test_available_all_day_override_wins_over_older_unavailable_day_part_everywhere(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 14:00:00', config('app.timezone')));
        $user = $this->user('status-guard-afternoon-unavailable@example.test');
        $dayPart = $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            false,
            'afternoon',
        );
        $dayPart->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();
        $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            true,
        );

        $schedule = app(AvailabilityScheduleService::class);
        $this->assertTrue($schedule->isAvailable($user));
        $this->assertTrue($schedule->availabilityByUser(collect([$user]))[(string) $user->id]);

        $manualStatus = app(StatusService::class)->setStatus($user, 'available', $user);
        $this->assertSame('available', $manualStatus->status);

        app(StatusService::class)->setStatus($user, 'unavailable', $user);
        $this->assertSame(0, Artisan::call('dis:apply-availability-schedule-statuses'));
        $this->assertSame('available', $this->latestStatus($user)?->status);
    }

    public function test_unavailable_all_day_override_wins_over_older_available_day_part_everywhere(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 14:00:00', config('app.timezone')));
        $user = $this->user('status-guard-afternoon-available@example.test');
        $dayPart = $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            true,
            'afternoon',
        );
        $dayPart->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();
        $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            false,
        );
        app(StatusService::class)->setStatus($user, 'unavailable', $user);

        $schedule = app(AvailabilityScheduleService::class);
        $this->assertFalse($schedule->isAvailable($user));
        $this->assertFalse($schedule->availabilityByUser(collect([$user]))[(string) $user->id]);

        try {
            app(StatusService::class)->setStatus($user, 'available', $user);
            $this->fail('An unavailable all-day override must block an older available day-part override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(0, Artisan::call('dis:apply-availability-schedule-statuses'));
        $this->assertSame('unavailable', $this->latestStatus($user)?->status);
    }

    public function test_unavailable_all_day_override_wins_over_newer_available_day_part_everywhere(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 14:00:00', config('app.timezone')));
        $user = $this->user('status-guard-newer-afternoon-available@example.test');
        $allDay = $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            false,
        );
        $allDay->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();
        $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            true,
            'afternoon',
        );
        app(StatusService::class)->setStatus($user, 'unavailable', $user);

        $schedule = app(AvailabilityScheduleService::class);
        $availability = $schedule->availabilityFor($user);
        $this->assertFalse($availability['is_available']);
        $this->assertSame('override', $availability['source']);
        $this->assertFalse($schedule->availabilityByUser(collect([$user]))[(string) $user->id]);

        try {
            app(StatusService::class)->setStatus($user, 'available', $user);
            $this->fail('An unavailable all-day override must block a newer available day-part override.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(0, Artisan::call('dis:apply-availability-schedule-statuses'));
        $this->assertSame('unavailable', $this->latestStatus($user)?->status);
    }

    public function test_unavailable_all_day_period_blocks_every_day_part_on_inclusive_boundaries(): void
    {
        $user = $this->user('status-guard-inclusive-vacation@example.test');
        $vacation = $this->override($user, '2026-07-27', '2026-07-29', false);
        $vacation->forceFill(['updated_at' => CarbonImmutable::parse('2026-07-26 12:00:00')])->saveQuietly();
        $this->override($user, '2026-07-27', '2026-07-27', true, 'morning');
        $this->override($user, '2026-07-29', '2026-07-29', true, 'evening');

        $schedule = app(AvailabilityScheduleService::class);

        $this->assertTrue($schedule->isAvailable($user, CarbonImmutable::parse('2026-07-26 23:59:59')));
        $this->assertFalse($schedule->isAvailable($user, CarbonImmutable::parse('2026-07-27 00:00:00')));
        $this->assertFalse($schedule->isAvailable($user, CarbonImmutable::parse('2026-07-28 14:00:00')));
        $this->assertFalse($schedule->isAvailable($user, CarbonImmutable::parse('2026-07-29 23:59:59')));
        $this->assertTrue($schedule->isAvailable($user, CarbonImmutable::parse('2026-07-30 00:00:00')));
    }

    public function test_effective_week_pattern_is_used_by_reads_manual_guard_and_scheduler(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-27 14:00:00', config('app.timezone')));
        $user = $this->user('status-guard-week-pattern@example.test');
        $dayPart = $this->weekPattern($user, false, 'afternoon');
        $dayPart->forceFill(['updated_at' => now()->subMinute()])->saveQuietly();
        $this->weekPattern($user, true);

        $schedule = app(AvailabilityScheduleService::class);
        $availability = $schedule->availabilityFor($user);
        $this->assertFalse($availability['is_available']);
        $this->assertSame('week_pattern', $availability['source']);
        $this->assertFalse($schedule->availabilityByUser(collect([$user]))[(string) $user->id]);

        try {
            app(StatusService::class)->setStatus($user, 'available', $user);
            $this->fail('The effective unavailable week pattern must block a manual available status.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(0, Artisan::call('dis:apply-availability-schedule-statuses'));
        $this->assertSame('unavailable', $this->latestStatus($user)?->status);
    }

    public function test_deprecated_vacation_command_alias_uses_only_availability_schedule(): void
    {
        $user = $this->user('status-guard-command@example.test');
        app(StatusService::class)->setStatus($user, 'available', $user);
        $this->override(
            $user,
            today()->toDateString(),
            today()->toDateString(),
            false,
        );
        $legacyVacation = UserVacation::query()->create([
            'user_id' => $user->id,
            'starts_at' => today(),
            'ends_at' => today()->addDay(),
            'status' => UserVacation::STATUS_ACTIVE,
            'created_by' => $user->id,
        ]);

        $this->assertSame(0, Artisan::call('dis:apply-vacation-statuses'));

        $this->assertStringContainsString(
            'Deprecated command alias; use dis:apply-availability-schedule-statuses.',
            Artisan::output(),
        );
        $this->assertSame('unavailable', $this->latestStatus($user)?->status);
        $this->assertSame(UserVacation::STATUS_ACTIVE, $legacyVacation->refresh()->status);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Planningstatus gebruiker',
            'first_name' => 'Planningstatus',
            'last_name' => 'Gebruiker',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function override(
        User $user,
        string $startsAt,
        string $endsAt,
        bool $isAvailable,
        string $dayPart = 'all_day',
    ): AvailabilityOverride {
        return AvailabilityOverride::query()->create([
            'user_id' => $user->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'day_part' => $dayPart,
            'is_available' => $isAvailable,
            'created_by' => $user->id,
        ]);
    }

    private function latestStatus(User $user): ?AvailabilityStatus
    {
        return $user->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    private function weekPattern(
        User $user,
        bool $isAvailable,
        string $dayPart = 'all_day',
    ): AvailabilityWeekPattern {
        return AvailabilityWeekPattern::query()->create([
            'user_id' => $user->id,
            'day_of_week' => now()->dayOfWeekIso,
            'day_part' => $dayPart,
            'is_available' => $isAvailable,
            'created_by' => $user->id,
        ]);
    }
}
