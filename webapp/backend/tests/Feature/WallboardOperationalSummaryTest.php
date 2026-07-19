<?php

namespace Tests\Feature;

use App\Models\AvailabilityStatus;
use App\Models\AvailabilityWeekPattern;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Models\Wallboard;
use App\Services\WallboardStateService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class WallboardOperationalSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_pilot_availability_uses_the_active_ocp_operator_cohort_and_current_schedule(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        $ocp = Team::query()->create([
            'code' => 'OCP',
            'name' => 'Operationeel Coordinatie Platform',
            'type' => 'base',
            'is_operational' => true,
        ]);
        $operatorRole = Role::query()->create([
            'name' => 'operator-pilot',
            'display_name' => 'Summary operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $coordinatorRole = Role::query()->create([
            'name' => 'incident-coordinator',
            'display_name' => 'Incident coordinator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
        ]);
        $adminRole = Role::query()->create([
            'name' => 'summary-admin',
            'display_name' => 'Summary admin',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);

        $available = $this->user('available-summary@example.test');
        $unavailable = $this->user('unavailable-summary@example.test');
        $pushDisabled = $this->user('push-disabled-summary@example.test', pushEnabled: false);
        $scheduledUnavailable = $this->user('scheduled-summary@example.test');
        foreach ([$available, $unavailable, $pushDisabled, $scheduledUnavailable] as $pilot) {
            $pilot->roles()->attach($operatorRole->id, ['created_at' => now()]);
            $pilot->teams()->attach($ocp->id, ['created_at' => now()]);
        }
        $this->availabilityStatus($unavailable, false);
        $this->availabilityStatus($pushDisabled, true);
        AvailabilityWeekPattern::query()->create([
            'user_id' => $scheduledUnavailable->id,
            'day_of_week' => now()->dayOfWeekIso,
            'day_part' => 'all_day',
            'is_available' => false,
        ]);

        $outsideOcp = $this->user('outside-ocp-summary@example.test');
        $outsideOcp->roles()->attach($operatorRole->id, ['created_at' => now()]);
        $admin = $this->user('admin-summary@example.test');
        $admin->roles()->attach($adminRole->id, ['created_at' => now()]);
        $admin->teams()->attach($ocp->id, ['created_at' => now()]);
        $coordinator = $this->user('coordinator-summary@example.test');
        $coordinator->roles()->attach($coordinatorRole->id, ['created_at' => now()]);
        $coordinator->teams()->attach($ocp->id, ['created_at' => now()]);
        $inactive = $this->user('inactive-summary@example.test', accountStatus: 'disabled');
        $inactive->roles()->attach($operatorRole->id, ['created_at' => now()]);
        $inactive->teams()->attach($ocp->id, ['created_at' => now()]);

        $state = app(WallboardStateService::class)->state($this->wallboard());

        $this->assertSame(
            ['available' => 1, 'total' => 4],
            $state['operational_summary']['pilot_availability'],
        );
    }

    public function test_recent_incidents_are_summary_specific_coordinate_independent_and_always_exclude_tests(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        $creator = $this->user('recent-summary@example.test');
        $real = $this->incident($creator, 'RECENT-REAL', 'resolved', false, now()->subMinutes(10));
        $test = $this->incident($creator, 'RECENT-TEST', 'cancelled', true, now()->subMinutes(5));

        $withoutTests = app(WallboardStateService::class)->state($this->wallboard(false));
        $this->assertSame(
            ['RECENT-REAL'],
            collect($withoutTests['operational_summary']['recent_incidents'])->pluck('reference')->all(),
        );
        $this->assertSame([], $withoutTests['map']['historical_incidents']);
        $this->assertArrayNotHasKey('latitude', $withoutTests['operational_summary']['recent_incidents'][0]);
        $this->assertArrayNotHasKey('longitude', $withoutTests['operational_summary']['recent_incidents'][0]);

        $withTests = app(WallboardStateService::class)->state($this->wallboard(true));
        $this->assertSame(
            ['RECENT-REAL'],
            collect($withTests['operational_summary']['recent_incidents'])->pluck('reference')->all(),
        );
        $this->assertFalse($withTests['operational_summary']['recent_incidents'][0]['is_test']);
        $this->assertSame($real->id, $withTests['operational_summary']['recent_incidents'][0]['id']);
        $this->assertNotContains(
            $test->id,
            collect($withTests['operational_summary']['recent_incidents'])->pluck('id')->all(),
        );
        $this->assertSame([], $withTests['wallboard']['configuration']['pages'][0]['options']);

        $defaultMap = app(WallboardStateService::class)->state($this->mapWallboard(false));
        $this->assertSame(
            ['RECENT-REAL'],
            collect($defaultMap['operational_summary']['recent_incidents'])->pluck('reference')->all(),
        );
        $testAwareMap = app(WallboardStateService::class)->state($this->mapWallboard(true));
        $this->assertSame(
            ['RECENT-REAL'],
            collect($testAwareMap['operational_summary']['recent_incidents'])->pluck('reference')->all(),
        );
    }

    public function test_test_dispatch_is_transient_only_while_real_alarm_also_keeps_the_persistent_override(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        SystemSetting::query()->create([
            'key' => 'dispatch.response_timeout_seconds',
            'value' => 300,
            'is_sensitive' => false,
        ]);
        $creator = $this->user('alarm-summary@example.test');
        $wallboard = $this->wallboard(false, incidentOverride: true);
        $testIncident = $this->incident($creator, 'TEST-TRANSIENT', 'dispatching', true);
        $testDispatch = $this->dispatch($testIncident, $creator);
        $cancelledNewerDispatch = $this->dispatch($testIncident, $creator);
        $cancelledNewerDispatch->forceFill([
            'status' => 'cancelled',
            'sent_at' => now()->addSecond(),
            'cancelled_at' => now()->addSecond(),
        ])->save();

        $testState = app(WallboardStateService::class)->state($wallboard);
        $this->assertFalse($testState['wallboard']['display']['incident_active']);
        $this->assertNull($testState['operational_summary']['active_alarm']);
        $this->assertSame($testDispatch->id, $testState['operational_summary']['transient_alert']['dispatch_id']);
        $this->assertTrue($testState['operational_summary']['transient_alert']['is_test']);
        $this->assertSame('2026-07-20T10:05:00+02:00', $testState['operational_summary']['transient_alert']['expires_at']);
        $this->assertSame(
            $testDispatch->id,
            app(WallboardStateService::class)->control($wallboard)['transient_alert']['dispatch_id'],
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:01', 'Europe/Amsterdam'));
        $realIncident = $this->incident($creator, 'REAL-ALARM', 'dispatching', false);
        $realDispatch = $this->dispatch($realIncident, $creator);
        $realState = app(WallboardStateService::class)->state($wallboard);

        $this->assertTrue($realState['wallboard']['display']['incident_active']);
        $this->assertSame('incident_override', $realState['wallboard']['display']['mode']);
        $this->assertSame($realIncident->id, $realState['operational_summary']['active_alarm']['id']);
        $this->assertSame($realDispatch->id, $realState['operational_summary']['transient_alert']['dispatch_id']);
        $this->assertFalse($realState['operational_summary']['transient_alert']['is_test']);
        $this->assertStringNotContainsString('INTERN-ALARM-GEHEIM', json_encode($realState, JSON_THROW_ON_ERROR));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:05:02', 'Europe/Amsterdam'));
        $afterFocus = app(WallboardStateService::class)->state($wallboard);
        $this->assertNull($afterFocus['operational_summary']['transient_alert']);
        $this->assertSame($realIncident->id, $afterFocus['operational_summary']['active_alarm']['id']);
        $this->assertTrue($afterFocus['wallboard']['display']['incident_active']);
    }

    public function test_test_alarm_focus_expires_after_five_minutes_and_returns_to_the_static_page(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        SystemSetting::query()->create([
            'key' => 'dispatch.response_timeout_seconds',
            'value' => 300,
            'is_sensitive' => false,
        ]);
        $creator = $this->user('static-test-focus@example.test');
        $wallboard = $this->wallboard(true);
        $testIncident = $this->incident($creator, 'STATIC-TEST', 'dispatching', true);
        $dispatch = $this->dispatch($testIncident, $creator);
        $service = app(WallboardStateService::class);

        $duringFocus = $service->state($wallboard);
        $this->assertSame('static', $duringFocus['wallboard']['display']['mode']);
        $this->assertFalse($duringFocus['wallboard']['display']['incident_active']);
        $this->assertNull($duringFocus['operational_summary']['active_alarm']);
        $this->assertSame($dispatch->id, $duringFocus['operational_summary']['transient_alert']['dispatch_id']);
        $this->assertSame('2026-07-20T10:05:00+02:00', $duringFocus['operational_summary']['transient_alert']['expires_at']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:05:00', 'Europe/Amsterdam'));
        $afterFocus = $service->state($wallboard);
        $this->assertNull($afterFocus['operational_summary']['transient_alert']);
        $this->assertNull($afterFocus['operational_summary']['active_alarm']);
        $this->assertSame('static', $afterFocus['wallboard']['display']['mode']);
        $this->assertFalse($afterFocus['wallboard']['display']['incident_active']);
    }

    public function test_summary_fetches_only_real_active_incidents_even_when_map_markers_are_disabled(): void
    {
        $creator = $this->user('summary-active-count@example.test');
        $configuration = WallboardConfiguration::defaults();
        $configuration['map']['show_active_incidents'] = false;
        $configuration['map']['show_live_locations'] = false;
        $configuration['map']['show_routes'] = false;
        $configuration['map']['show_summary'] = true;
        $configuration['map']['show_test_incidents'] = true;
        $wallboard = Wallboard::query()->create([
            'name' => 'Alleen samenvattingsbalk',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'is_enabled' => true,
            'rotation_started_at' => now(),
        ]);
        $real = $this->incident($creator, 'ACTIVE-REAL', 'active', false);
        $test = $this->incident($creator, 'ACTIVE-TEST', 'active', true);

        $state = app(WallboardStateService::class)->state($wallboard);

        $this->assertSame([$real->id], collect($state['map']['incidents'])->pluck('id')->all());
        $this->assertNotContains($test->id, collect($state['map']['incidents'])->pluck('id')->all());
        $this->assertFalse($state['wallboard']['configuration']['map']['show_test_incidents']);
    }

    public function test_wallboard_historical_layer_excludes_test_incidents(): void
    {
        $creator = $this->user('historical-summary@example.test');
        $configuration = WallboardConfiguration::defaults();
        $configuration['map']['show_historical_incidents'] = true;
        $wallboard = Wallboard::query()->create([
            'name' => 'Historische operationele kaart',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'is_enabled' => true,
            'rotation_started_at' => now(),
        ]);
        $real = $this->incident($creator, 'HISTORY-REAL', 'resolved', false, now()->subMinutes(10));
        $real->forceFill(['latitude' => 52.09, 'longitude' => 5.12])->save();
        $test = $this->incident($creator, 'HISTORY-TEST', 'cancelled', true, now()->subMinutes(5));
        $test->forceFill(['latitude' => 52.10, 'longitude' => 5.13])->save();

        $state = app(WallboardStateService::class)->state($wallboard);

        $this->assertSame([$real->id], collect($state['map']['historical_incidents'])->pluck('id')->all());
        $this->assertNotContains($test->id, collect($state['map']['historical_incidents'])->pluck('id')->all());
    }

    public function test_full_state_resolves_internal_ticker_items_without_adding_them_to_control_feed(): void
    {
        $configuration = WallboardConfiguration::defaults();
        $configuration['ticker'] = [
            'enabled' => true,
            'sources' => [[
                'id' => 'internal-briefing',
                'type' => 'internal',
                'label' => 'Meldkamer',
                'text' => 'Start briefing om 14:00.',
            ]],
        ];
        $wallboard = Wallboard::query()->create([
            'name' => 'Ticker wallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize($configuration),
            'is_enabled' => true,
            'rotation_started_at' => now(),
        ]);
        $service = app(WallboardStateService::class);

        $state = $service->state($wallboard);

        $this->assertSame([[
            'source_id' => 'internal-briefing',
            'source_type' => 'internal',
            'source_label' => 'Meldkamer',
            'text' => 'Start briefing om 14:00.',
        ]], $state['ticker']['items']);
        $this->assertArrayNotHasKey('ticker', $service->control($wallboard));
    }

    private function wallboard(bool $showTestIncidents = false, bool $incidentOverride = false): Wallboard
    {
        return Wallboard::query()->create([
            'name' => 'Operationele samenvatting',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize([
                'pages' => [[
                    'id' => 'summary',
                    'name' => 'Samenvatting',
                    'type' => 'summary',
                    'duration_seconds' => 30,
                    'options' => ['show_test_incidents' => $showTestIncidents],
                ]],
                'incident_override' => ['enabled' => $incidentOverride, 'page_id' => 'summary'],
            ]),
            'is_enabled' => true,
            'rotation_started_at' => now(),
        ]);
    }

    private function mapWallboard(bool $showTestIncidents): Wallboard
    {
        $configuration = WallboardConfiguration::defaults();
        $configuration['map']['show_test_incidents'] = $showTestIncidents;

        return Wallboard::query()->create([
            'name' => 'Kaart met samenvatting',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => $configuration,
            'is_enabled' => true,
            'rotation_started_at' => now(),
        ]);
    }

    private function user(
        string $email,
        bool $pushEnabled = true,
        string $accountStatus = 'active',
    ): User {
        return User::query()->create([
            'name' => $email,
            'first_name' => 'Wallboard',
            'last_name' => 'Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => $accountStatus,
            'push_enabled' => $pushEnabled,
        ]);
    }

    private function availabilityStatus(User $user, bool $available): AvailabilityStatus
    {
        return AvailabilityStatus::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => $available ? 'available' : 'unavailable',
            'is_available' => $available,
            'is_system_applied' => false,
            'effective_at' => now(),
        ]);
    }

    private function incident(
        User $creator,
        string $reference,
        string $status,
        bool $isTest,
        ?\DateTimeInterface $closedAt = null,
    ): Incident {
        return Incident::query()->create([
            'reference' => $reference,
            'title' => 'Wallboard incident '.$reference,
            'description' => 'INTERN-ALARM-GEHEIM',
            'internal_notes' => 'INTERN-ALARM-GEHEIM',
            'priority' => 'high',
            'status' => $status,
            'is_test' => $isTest,
            'location_label' => 'Utrecht',
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now()->subMinute(),
            'closed_at' => $closedAt,
        ]);
    }

    private function dispatch(Incident $incident, User $creator): DispatchRequest
    {
        return DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $creator->id,
            'requested_by_name' => $creator->name,
            'requested_by_email' => $creator->email,
            'status' => 'sent',
            'priority' => $incident->priority,
            'message' => 'INTERN-ALARM-GEHEIM',
            'sent_at' => now(),
        ]);
    }
}
