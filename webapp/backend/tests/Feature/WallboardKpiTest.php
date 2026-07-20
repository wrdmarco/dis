<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Models\Asset;
use App\Models\AvailabilityStatus;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Services\WallboardKpiService;
use App\Services\WallboardPlaylistPreviewService;
use App\Services\WallboardStateService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardKpiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_kpi_configuration_defaults_to_the_complete_canonical_catalog_and_all_admin_contracts_accept_it(): void
    {
        $defaultConfiguration = WallboardConfiguration::normalize([
            'pages' => [$this->kpiPage([])],
        ]);
        $explicitEmpty = WallboardConfiguration::normalize([
            'pages' => [$this->kpiPage(['visible_metrics' => []])],
        ]);
        $canonicalized = WallboardConfiguration::normalize([
            'pages' => [$this->kpiPage([
                'visible_metrics' => ['drones_ready', 'pilots_available', 'incidents_total'],
            ])],
        ]);

        $this->assertContains('kpi', WallboardConfiguration::PAGE_TYPES);
        $this->assertCount(42, WallboardConfiguration::KPI_VISIBLE_METRICS);
        $this->assertSame(
            WallboardConfiguration::KPI_VISIBLE_METRICS,
            $defaultConfiguration['pages'][0]['options']['visible_metrics'],
        );
        $this->assertSame([], $explicitEmpty['pages'][0]['options']['visible_metrics']);
        $this->assertSame(
            ['pilots_available', 'incidents_total', 'drones_ready'],
            $canonicalized['pages'][0]['options']['visible_metrics'],
        );

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$this->kpiPage([])]],
            ]);

            $this->assertSame('kpi', $validated['configuration']['pages'][0]['type']);
        }
    }

    #[DataProvider('invalidKpiOptionsProvider')]
    public function test_kpi_options_fail_closed(array $options, string $errorPrefix): void
    {
        $page = $this->kpiPage($options);

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Ongeldige KPI-configuratie had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertErrorPrefix($exception, $errorPrefix);
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Ongeldige KPI-configuratie had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertErrorPrefix($exception, $errorPrefix);
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidKpiOptionsProvider(): iterable
    {
        yield 'geen lijst' => [
            ['visible_metrics' => 'pilots_total'],
            'configuration.pages.0.options.visible_metrics',
        ];
        yield 'associatieve lijst' => [
            ['visible_metrics' => ['eerste' => 'pilots_total']],
            'configuration.pages.0.options.visible_metrics',
        ];
        yield 'dubbele KPI' => [
            ['visible_metrics' => ['pilots_total', 'pilots_total']],
            'configuration.pages.0.options.visible_metrics',
        ];
        yield 'onbekende KPI' => [
            ['visible_metrics' => ['pilots_total', 'geheime_kpi']],
            'configuration.pages.0.options.visible_metrics',
        ];
        yield 'optie van ander paginatype' => [
            ['visible_metrics' => ['pilots_total'], 'show_test_incidents' => true],
            'configuration.pages.0.options',
        ];
    }

    public function test_kpi_values_use_live_privacy_safe_cohorts_and_are_available_in_state_live_and_preview(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        [$available, $enRoute, $onScene, $pushDisabled] = $this->pilotCohort();

        $activeLow = $this->incident($available, 'KPI-ACTIVE-LOW', 'active', 'low', false, now()->subHour());
        $dispatchingNormal = $this->incident($available, 'KPI-DISPATCH-NORMAL', 'dispatching', 'normal', false, now()->subDay());
        $inProgressHigh = $this->incident($available, 'KPI-PROGRESS-HIGH', 'in_progress', 'high', false, now()->subHours(2));
        $this->incident($available, 'KPI-ACTIVE-CRITICAL', 'active', 'critical', false, now()->subMinutes(30));
        $this->incident($available, 'KPI-RESOLVED-TODAY', 'resolved', 'normal', false, now()->subDays(2), now()->subHour());
        $this->incident($available, 'KPI-CANCELLED-TODAY', 'cancelled', 'normal', false, now()->subMinutes(20), now()->subMinutes(10));
        $this->incident($available, 'KPI-RESOLVED-OLD', 'resolved', 'normal', false, now()->subDays(5), now()->subDays(4));
        $this->incident($available, 'KPI-CANCELLED-OLD', 'cancelled', 'normal', false, now()->subDays(4), now()->subDays(3));
        $this->incident($available, 'KPI-TEST', 'resolved', 'critical', true, now()->subHour(), now()->subMinutes(5));
        $deletedIncident = $this->incident($available, 'KPI-DELETED', 'active', 'low', false, now()->subMinute());
        $deletedIncident->delete();

        $oldDispatch = $this->dispatch($activeLow, $available, 'sent', now()->subMinutes(8));
        $newDispatch = $this->dispatch($activeLow, $available, 'escalated', now()->subMinutes(4));
        $otherIncidentDispatch = $this->dispatch($dispatchingNormal, $available, 'sent', now()->subMinutes(3));
        $this->dispatch($inProgressHigh, $available, 'sent', now()->subMinutes(2));
        $this->recipient($oldDispatch, $available, 'accepted', now()->subMinutes(7), now()->subMinutes(6));
        $this->recipient($oldDispatch, $enRoute, 'pending');
        $this->recipient($newDispatch, $available, 'declined', now()->subMinutes(3), now()->subMinutes(2));
        $this->recipient($otherIncidentDispatch, $available, 'accepted', now()->subMinutes(2), now()->subMinute());
        $this->recipient($otherIncidentDispatch, $onScene, 'no_response', now()->subMinute(), now()->subMinute());

        $testIncident = Incident::query()->where('reference', 'KPI-TEST')->firstOrFail();
        $testDispatch = $this->dispatch($testIncident, $available, 'sent', now());
        $this->recipient($testDispatch, $pushDisabled, 'accepted', now(), now());
        $resolvedIncident = Incident::query()->where('reference', 'KPI-RESOLVED-TODAY')->firstOrFail();
        $resolvedDispatch = $this->dispatch($resolvedIncident, $available, 'sent', now());
        $this->recipient($resolvedDispatch, $pushDisabled, 'accepted', now(), now());
        $draftDispatch = $this->dispatch($activeLow, $available, 'draft', now());
        $this->recipient($draftDispatch, $pushDisabled, 'accepted', now(), now());
        $unsentDispatch = $this->dispatch($activeLow, $available, 'sent', null);
        $this->recipient($unsentDispatch, $pushDisabled, 'accepted', now(), now());

        $this->asset('DRONE-READY', 'drone', 'ready');
        $this->asset('DRONE-MAINTENANCE', 'drone', 'maintenance');
        $this->asset('VEHICLE-UNAVAILABLE', 'vehicle', 'unavailable');
        $this->asset('BATTERY-ASSIGNED', 'battery', 'assigned');
        $this->asset('VEHICLE-RETIRED', 'vehicle', 'retired');
        $deletedAsset = $this->asset('DRONE-DELETED', 'drone', 'ready');
        $deletedAsset->delete();

        $wallboard = $this->wallboard([
            $this->kpiPage([], 'kpi-all'),
            $this->kpiPage(['visible_metrics' => []], 'kpi-empty'),
        ]);
        $service = app(WallboardStateService::class);
        $state = $service->state($wallboard);
        $metrics = collect($state['kpi']['pages']['kpi-all']['metrics'])->keyBy('key');

        $expectedValues = [
            'pilots_available' => 1,
            'pilots_unavailable' => 3,
            'pilots_total' => 4,
            'pilot_availability_rate' => 25.0,
            'pilots_en_route' => 1,
            'pilots_on_scene' => 1,
            'pilots_push_disabled' => 1,
            'incidents_total' => 4,
            'incidents_registered_total' => 8,
            'incidents_active' => 2,
            'incidents_dispatching' => 1,
            'incidents_in_progress' => 1,
            'incidents_low' => 1,
            'incidents_normal' => 1,
            'incidents_high' => 1,
            'incidents_critical' => 1,
            'incidents_opened_today' => 4,
            'incidents_resolved_today' => 1,
            'incidents_cancelled_today' => 1,
            'incidents_resolved_total' => 2,
            'incidents_cancelled_total' => 2,
            'assets_total' => 5,
            'assets_ready' => 1,
            'assets_maintenance' => 1,
            'assets_unavailable' => 2,
            'assets_issues' => 2,
            'drones_total' => 2,
            'drones_ready' => 1,
            'responses_targeted' => 4,
            'responses_contacted' => 3,
            'responses_pending' => 1,
            'responses_accepted' => 1,
            'responses_declined' => 1,
            'responses_no_response' => 1,
            'dispatches_active' => 4,
            'dispatch_acceptance_rate' => 25.0,
        ];

        $this->assertSame(WallboardConfiguration::KPI_VISIBLE_METRICS, $metrics->keys()->all());
        foreach ($expectedValues as $key => $value) {
            $this->assertSame($value, $metrics[$key]['value'], $key);
            $this->assertSame($key, $metrics[$key]['key']);
            $this->assertIsString($metrics[$key]['label']);
            $this->assertContains($metrics[$key]['category'], ['pilots', 'incidents', 'assets', 'responses', 'flight']);
        }
        $this->assertSame('%', $metrics['pilot_availability_rate']['unit']);
        $this->assertSame('%', $metrics['dispatch_acceptance_rate']['unit']);
        $this->assertNull($metrics['pilots_total']['unit']);
        $this->assertSame([], $state['kpi']['pages']['kpi-empty']['metrics']);
        $this->assertStringNotContainsString($available->email, json_encode($state['kpi'], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($enRoute->name, json_encode($state['kpi'], JSON_THROW_ON_ERROR));

        $live = $service->live($wallboard);
        $this->assertSame($expectedValues['incidents_total'], collect($live['kpi']['pages']['kpi-all']['metrics'])->keyBy('key')['incidents_total']['value']);
        $this->assertArrayNotHasKey('kpi', $service->staticContent($wallboard));
        $this->assertArrayNotHasKey('kpi', $service->control($wallboard));

        $playlist = $wallboard->playlist()->firstOrFail();
        $preview = app(WallboardPlaylistPreviewService::class)->state($playlist, $playlist->configuration);
        $this->assertSame(
            $expectedValues['drones_total'],
            collect($preview['kpi']['pages']['kpi-all']['metrics'])->keyBy('key')['drones_total']['value'],
        );
    }

    public function test_percentage_metrics_are_null_when_their_denominator_is_zero_and_selection_is_respected(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->kpiPage(['visible_metrics' => [
                'dispatch_acceptance_rate',
                'pilot_availability_rate',
            ]])],
        ]);

        $result = app(WallboardKpiService::class)->pages($configuration);
        $metrics = $result['pages']['kpi']['metrics'];

        $this->assertSame(
            ['pilot_availability_rate', 'dispatch_acceptance_rate'],
            array_column($metrics, 'key'),
        );
        $this->assertSame([null, null], array_column($metrics, 'value'));
    }

    /** @return list<array{0: FormRequest, 1: array<string, int|string>}> */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'KPI-wallboard']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'KPI-playlist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function kpiPage(array $options, string $id = 'kpi'): array
    {
        return [
            'id' => $id,
            'name' => 'KPI-overzicht',
            'type' => 'kpi',
            'duration_seconds' => 30,
            'options' => $options,
        ];
    }

    /** @param list<array<string, mixed>> $pages */
    private function wallboard(array $pages): Wallboard
    {
        $configuration = WallboardConfiguration::normalize(['pages' => $pages]);
        $playlist = WallboardPlaylist::query()->create([
            'name' => 'KPI-playlist',
            'configuration' => $configuration,
            'version' => 1,
        ]);

        return Wallboard::query()->create([
            'name' => 'KPI-wallboard',
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
            'configuration' => $configuration,
            'config_version' => 1,
            'rotation_started_at' => now(),
            'is_enabled' => true,
        ]);
    }

    /** @return array{0: User, 1: User, 2: User, 3: User} */
    private function pilotCohort(): array
    {
        $team = Team::query()->create([
            'code' => 'OCP',
            'name' => 'Operationeel Coordinatie Platform',
            'type' => 'base',
            'is_operational' => true,
        ]);
        $role = Role::query()->create([
            'name' => 'operator-pilot',
            'display_name' => 'KPI-piloot',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $available = $this->user('kpi-available@example.test');
        $enRoute = $this->user('kpi-en-route@example.test');
        $onScene = $this->user('kpi-on-scene@example.test');
        $pushDisabled = $this->user('kpi-push-disabled@example.test', false);
        foreach ([$available, $enRoute, $onScene, $pushDisabled] as $pilot) {
            $pilot->teams()->attach($team->id, ['created_at' => now()]);
            $pilot->roles()->attach($role->id, ['created_at' => now()]);
        }
        $this->availabilityStatus($enRoute, 'en_route', false);
        $this->availabilityStatus($onScene, 'on_scene', false);
        $this->availabilityStatus($pushDisabled, 'unavailable', false);

        $outsider = $this->user('kpi-outsider@example.test');
        $outsider->roles()->attach($role->id, ['created_at' => now()]);

        return [$available, $enRoute, $onScene, $pushDisabled];
    }

    private function user(string $email, bool $pushEnabled = true): User
    {
        return User::query()->create([
            'name' => $email,
            'first_name' => 'KPI',
            'last_name' => 'Test',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => $pushEnabled,
        ]);
    }

    private function availabilityStatus(User $user, string $status, bool $isAvailable): AvailabilityStatus
    {
        return AvailabilityStatus::query()->create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => $status,
            'is_available' => $isAvailable,
            'is_system_applied' => false,
            'effective_at' => now(),
        ]);
    }

    private function incident(
        User $creator,
        string $reference,
        string $status,
        string $priority,
        bool $isTest,
        \DateTimeInterface $openedAt,
        ?\DateTimeInterface $closedAt = null,
    ): Incident {
        return Incident::query()->create([
            'reference' => $reference,
            'title' => 'KPI incident '.$reference,
            'description' => 'KPI-PRIVE-BESCHRIJVING',
            'internal_notes' => 'KPI-PRIVE-NOTITIE',
            'priority' => $priority,
            'status' => $status,
            'is_test' => $isTest,
            'location_label' => 'Utrecht',
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => $openedAt,
            'closed_at' => $closedAt,
        ]);
    }

    private function dispatch(
        Incident $incident,
        User $requester,
        string $status,
        ?\DateTimeInterface $sentAt,
    ): DispatchRequest {
        return DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $requester->id,
            'requested_by_name' => $requester->name,
            'requested_by_email' => $requester->email,
            'status' => $status,
            'priority' => $incident->priority,
            'message' => 'KPI-PRIVE-ALARMBERICHT',
            'sent_at' => $sentAt,
        ]);
    }

    private function recipient(
        DispatchRequest $dispatch,
        User $user,
        string $status,
        ?\DateTimeInterface $notifiedAt = null,
        ?\DateTimeInterface $respondedAt = null,
    ): DispatchRecipient {
        return DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'response_status' => $status,
            'notified_at' => $notifiedAt,
            'responded_at' => $respondedAt,
        ]);
    }

    private function asset(string $tag, string $type, string $status): Asset
    {
        return Asset::query()->create([
            'asset_tag' => $tag,
            'name' => $tag,
            'type' => $type,
            'status' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    private function validateRequest(FormRequest $request, array $payload): array
    {
        $request->initialize($payload);
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator->validate();
    }

    private function assertErrorPrefix(ValidationException $exception, string $prefix): void
    {
        $this->assertTrue(
            collect(array_keys($exception->errors()))
                ->contains(static fn (string $key): bool => str_starts_with($key, $prefix)),
            'Geen validatiefout gevonden voor '.$prefix.'.',
        );
    }
}
