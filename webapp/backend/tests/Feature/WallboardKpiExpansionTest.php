<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\DroneType;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\PilotIncidentReport;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repositories\WallboardKpiRepository;
use App\Services\PilotIncidentReportDroneSnapshotService;
use App\Services\PilotIncidentReportFormService;
use App\Services\PilotIncidentReportService;
use App\Services\WallboardKpiService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardKpiExpansionTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_month_metrics_and_drone_type_pie_use_only_positive_submitted_non_test_reports(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', 'Europe/Amsterdam'));
        $user = $this->user();
        [$typeA, $assetA] = $this->drone('Autel', 'EVO Max 4T KPI', 'KPI-DRONE-A');
        [$typeB, $assetB] = $this->drone('DJI', 'Matrice 30T KPI', 'KPI-DRONE-B');

        $this->report(
            $this->incident($user, false, CarbonImmutable::parse('2026-06-01T08:00:00Z')),
            $user,
            'submitted',
            30,
            CarbonImmutable::parse('2026-06-30T22:00:00Z'),
            ['drone_used' => (string) $assetA->id],
            ['drone_used' => [
                'asset_id' => (string) $assetA->id,
                'manufacturer' => 'Autel',
                'model' => 'EVO Max 4T KPI',
            ]],
        );
        $this->report(
            $this->incident($user),
            $user,
            'submitted',
            45,
            CarbonImmutable::parse('2026-07-15T10:00:00Z'),
            ['drone_used' => (string) $assetB->id],
        );
        $this->report(
            $this->incident($user),
            $user,
            'submitted',
            15,
            CarbonImmutable::parse('2026-07-31T21:59:59Z'),
        );
        $this->report($this->incident($user), $user, 'draft', 80, CarbonImmutable::parse('2026-07-10T10:00:00Z'), ['drone_used' => (string) $assetA->id]);
        $this->report($this->incident($user), $user, 'submitted', 0, CarbonImmutable::parse('2026-07-10T10:00:00Z'), ['drone_used' => (string) $assetA->id]);
        $this->report($this->incident($user), $user, 'submitted', 20, CarbonImmutable::parse('2026-07-31T22:00:00Z'), ['drone_used' => (string) $assetA->id]);
        $this->report($this->incident($user, true), $user, 'submitted', 60, CarbonImmutable::parse('2026-07-10T10:00:00Z'), ['drone_used' => (string) $assetA->id]);

        $typeA->update(['model' => 'Hernoemd model KPI']);
        $typeB->delete();

        $result = app(WallboardKpiService::class)->pages($this->configuration([
            'flight_reports_this_month',
            'flight_minutes_this_month',
            'average_flight_minutes_this_month',
            'drones_flown_distribution',
        ]));
        $metrics = collect($result['pages']['kpi']['metrics'])->keyBy('key');

        self::assertSame(3, $metrics['flight_reports_this_month']['value']);
        self::assertSame(90, $metrics['flight_minutes_this_month']['value']);
        self::assertSame(30.0, $metrics['average_flight_minutes_this_month']['value']);
        self::assertSame('pie', $metrics['drones_flown_distribution']['visualization']);
        self::assertSame(3, $metrics['drones_flown_distribution']['value']);
        $segments = collect($metrics['drones_flown_distribution']['segments'])->keyBy('label');
        self::assertSame(1, $segments['Autel EVO Max 4T KPI']['value']);
        self::assertSame(1, $segments['DJI Matrice 30T KPI']['value']);
        self::assertSame(1, $segments['Onbekend']['value']);
        self::assertSame(3, $segments->sum('value'));
        self::assertSame('juli 2026 · ingediend · >0 vliegminuten', $metrics['flight_reports_this_month']['context']);
        self::assertSame('juli 2026 · 1 type per rapport · onbekend apart', $metrics['drones_flown_distribution']['context']);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString((string) $assetA->id, $encoded);
        self::assertStringNotContainsString($user->email, $encoded);
    }

    public function test_province_and_country_distributions_use_persisted_canonical_aggregates_only(): void
    {
        $user = $this->user();
        $this->incidentWithLocation($user, 'NL', '26');
        $this->incidentWithLocation($user, 'NL', '20');
        $this->incidentWithLocation($user, 'BE', null);
        $this->incidentWithLocation($user, 'DE', null);
        $this->incidentWithLocation($user, null, null);
        $this->incidentWithLocation($user, 'NL', '25', true);

        $result = app(WallboardKpiService::class)->pages($this->configuration([
            'incidents_by_province',
            'incidents_by_country',
        ]));
        $metrics = collect($result['pages']['kpi']['metrics'])->keyBy('key');
        $provinces = collect($metrics['incidents_by_province']['segments'])->keyBy('label');
        $countries = collect($metrics['incidents_by_country']['segments'])->keyBy('label');

        self::assertSame('bar', $metrics['incidents_by_province']['visualization']);
        self::assertCount(13, $provinces);
        self::assertSame(1, $provinces['Utrecht']['value']);
        self::assertSame(1, $provinces['Groningen']['value']);
        self::assertSame(1, $provinces['Onbekend']['value']);
        self::assertSame(3, $metrics['incidents_by_province']['value']);
        self::assertSame('Sinds registratie · Nederland + onbekend', $metrics['incidents_by_province']['context']);

        self::assertCount(4, $countries);
        self::assertSame(2, $countries['Nederland']['value']);
        self::assertSame(1, $countries['België']['value']);
        self::assertSame(1, $countries['Duitsland']['value']);
        self::assertSame(1, $countries['Onbekend']['value']);
        self::assertSame(5, $metrics['incidents_by_country']['value']);
    }

    public function test_drone_distribution_uses_only_valid_historical_snapshots_in_stored_priority_order(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', 'Europe/Amsterdam'));
        $submittedAt = CarbonImmutable::parse('2026-07-20T10:00:00Z');
        $user = $this->user();
        [, $preferred] = $this->drone('DJI', 'Preferred KPI', 'KPI-HISTORY-PREFERRED');
        [, $firstStored] = $this->drone('Autel', 'First Stored KPI', 'KPI-HISTORY-FIRST');
        [, $laterStored] = $this->drone('Parrot', 'Later Stored KPI', 'KPI-HISTORY-LATER');

        $this->report(
            $this->incident($user),
            $user,
            'submitted',
            20,
            $submittedAt,
            [
                'legacy_first' => (string) $firstStored->id,
                'drone_used' => (string) $preferred->id,
            ],
            [
                'legacy_first' => [
                    'asset_id' => (string) $firstStored->id,
                    'manufacturer' => 'Autel',
                    'model' => 'First Stored KPI',
                ],
                'drone_used' => [
                    'asset_id' => (string) $preferred->id,
                    'manufacturer' => 'DJI',
                    'model' => 'Preferred KPI',
                ],
            ],
        );
        $this->report(
            $this->incident($user),
            $user,
            'submitted',
            20,
            $submittedAt,
            [
                'broken_snapshot' => (string) $preferred->id,
                'legacy_first' => (string) $firstStored->id,
                'legacy_later' => (string) $laterStored->id,
            ],
            [
                'broken_snapshot' => [
                    'asset_id' => (string) $preferred->id,
                    'manufacturer' => '',
                    'model' => 'Invalid KPI',
                ],
                'legacy_first' => [
                    'asset_id' => (string) $firstStored->id,
                    'manufacturer' => 'Autel',
                    'model' => 'First Stored KPI',
                ],
                'legacy_later' => [
                    'asset_id' => (string) $laterStored->id,
                    'manufacturer' => 'Parrot',
                    'model' => 'Later Stored KPI',
                ],
            ],
        );
        $this->report(
            $this->incident($user),
            $user,
            'submitted',
            20,
            $submittedAt,
            ['arbitrary_custom_field' => (string) $laterStored->id],
            [],
        );

        $start = CarbonImmutable::parse('2026-06-30T22:00:00Z');
        $end = CarbonImmutable::parse('2026-07-31T22:00:00Z');
        self::assertSame(3, PilotIncidentReport::query()->count());
        self::assertSame(3, PilotIncidentReport::query()
            ->where('submitted_at', '>=', $start)
            ->where('submitted_at', '<', $end)
            ->count());
        $segments = collect(app(WallboardKpiRepository::class)->droneFlightDistribution(
            $start,
            $end,
            [],
        ))->keyBy('label');

        self::assertArrayHasKey('DJI Preferred KPI', $segments->all(), json_encode($segments->all(), JSON_THROW_ON_ERROR));
        self::assertSame(1, $segments['DJI Preferred KPI']['value']);
        self::assertSame(1, $segments['Autel First Stored KPI']['value']);
        self::assertSame(1, $segments['Onbekend']['value']);
        self::assertArrayNotHasKey('Parrot Later Stored KPI', $segments->all());
    }

    public function test_ratio_charts_emit_real_part_and_remainder_and_preserve_ring_mode(): void
    {
        $configuration = $this->configuration(
            ['pilots_available', 'pilot_availability_rate'],
            ['pilots_available' => 'pie', 'pilot_availability_rate' => 'ring'],
        );
        $result = app(WallboardKpiService::class)->pages($configuration, [
            'available' => 3,
            'total' => 5,
            'en_route' => 1,
            'on_scene' => 0,
            'push_disabled' => 1,
        ]);
        $metrics = collect($result['pages']['kpi']['metrics'])->keyBy('key');

        self::assertSame('pie', $metrics['pilots_available']['visualization']);
        self::assertSame([3, 2], array_column($metrics['pilots_available']['segments'], 'value'));
        self::assertSame('ring', $metrics['pilot_availability_rate']['visualization']);
        self::assertSame(60.0, $metrics['pilot_availability_rate']['value']);
        self::assertSame([3, 2], array_column($metrics['pilot_availability_rate']['segments'], 'value'));
    }

    #[DataProvider('daylightSavingDays')]
    public function test_today_metrics_bind_amsterdam_dst_day_boundaries_as_utc(
        string $nowAmsterdam,
        string $startUtc,
        string $lastInsideUtc,
        string $endUtc,
    ): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($nowAmsterdam, 'Europe/Amsterdam'));
        $user = $this->user();
        $this->incident($user, false, CarbonImmutable::parse($startUtc));
        $this->incident($user, false, CarbonImmutable::parse($lastInsideUtc));
        $this->incident($user, false, CarbonImmutable::parse($endUtc));

        $result = app(WallboardKpiService::class)->pages($this->configuration(['incidents_opened_today']));

        self::assertSame(2, $result['pages']['kpi']['metrics'][0]['value']);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function daylightSavingDays(): iterable
    {
        yield '23-hour spring day' => [
            '2026-03-29 12:00:00',
            '2026-03-28T23:00:00Z',
            '2026-03-29T21:59:59Z',
            '2026-03-29T22:00:00Z',
        ];
        yield '25-hour autumn day' => [
            '2026-10-25 12:00:00',
            '2026-10-24T22:00:00Z',
            '2026-10-25T22:59:59Z',
            '2026-10-25T23:00:00Z',
        ];
    }

    public function test_snapshot_backfill_is_immutable_and_released_current_selection_stays_visible_and_valid(): void
    {
        $user = $this->user();
        [$typeA, $assetA] = $this->drone('DJI', 'Matrice 350 RTK KPI', 'KPI-HISTORY-A');
        [, $assetB] = $this->drone('Autel', 'Alpha KPI', 'KPI-HISTORY-B');
        $incident = $this->incident($user);
        $report = $this->report(
            $incident,
            $user,
            'submitted',
            25,
            now(),
            ['summary' => 'Historisch rapport', 'drone_used' => (string) $assetA->id],
        );

        $this->artisan('dis:backfill-pilot-report-drone-snapshots', ['--batch' => '1'])
            ->assertExitCode(0);
        $snapshot = $report->refresh()->drone_usage_snapshot;
        self::assertSame('Matrice 350 RTK KPI', $snapshot['drone_used']['model']);

        $typeA->update(['model' => 'Nieuw model']);
        $typeA->delete();
        $snapshotService = app(PilotIncidentReportDroneSnapshotService::class);
        self::assertEquals($snapshot, $snapshotService->capture(
            ['drone_used' => (string) $assetA->id],
            $snapshot,
            ['drone_used'],
        ));
        $changed = $snapshotService->capture(
            ['drone_used' => (string) $assetB->id],
            $snapshot,
            ['drone_used'],
        );
        self::assertSame('Alpha KPI', $changed['drone_used']['model']);

        $formService = app(PilotIncidentReportFormService::class);
        $droneField = collect($formService->fields($user, incident: $incident))->firstWhere('key', 'drone_used');
        self::assertNotNull($droneField);
        self::assertContains((string) $assetA->id, array_column($droneField['options'], 'value'));
        self::assertStringContainsString('historische selectie', collect($droneField['options'])->firstWhere('value', (string) $assetA->id)['label']);

        $currentPayload = ['custom_fields' => [
            'summary' => 'Historisch rapport bijgewerkt',
            'drone_used' => (string) $assetA->id,
        ]];
        self::assertTrue(Validator::make($currentPayload, $formService->validationRules($user, $incident))->passes());
        $unauthorizedPayload = $currentPayload;
        $unauthorizedPayload['custom_fields']['drone_used'] = (string) $assetB->id;
        self::assertFalse(Validator::make($unauthorizedPayload, $formService->validationRules($user, $incident))->passes());

        $segments = app(WallboardKpiRepository::class)->droneFlightDistribution(
            now()->subDay()->setTimezone('UTC'),
            now()->addDay()->setTimezone('UTC'),
            ['drone_used'],
        );
        self::assertSame([['label' => 'DJI Matrice 350 RTK KPI', 'value' => 1]], $segments);
    }

    public function test_snapshot_history_survives_a_removed_or_retyped_drone_field_and_later_report_edit(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', 'Europe/Amsterdam'));
        $submittedAt = CarbonImmutable::parse('2026-07-20T10:00:00Z');
        $user = $this->user();
        [, $asset] = $this->drone('DJI', 'Historic Edit KPI', 'KPI-HISTORY-EDIT');
        $incident = $this->incident($user);
        $snapshot = [
            'drone_used' => [
                'asset_id' => (string) $asset->id,
                'manufacturer' => 'DJI',
                'model' => 'Historic Edit KPI',
            ],
        ];
        $this->report(
            $incident,
            $user,
            'submitted',
            25,
            $submittedAt,
            ['summary' => 'Oorspronkelijk', 'drone_used' => (string) $asset->id],
            $snapshot,
        );
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $user->id,
            'requested_by_name' => $user->name,
            'requested_by_email' => $user->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Historische KPI-test',
            'sent_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'response_status' => 'accepted',
            'notified_at' => now(),
        ]);

        $retypedFields = collect(app(PilotIncidentReportFormService::class)->defaultFields())
            ->map(static fn (array $field): array => $field['key'] === 'drone_used'
                ? array_replace($field, ['type' => 'text', 'option_source' => 'manual'])
                : $field)
            ->all();
        SystemSetting::query()->updateOrCreate(
            ['key' => PilotIncidentReportFormService::SETTING_KEY],
            ['value' => $retypedFields, 'is_sensitive' => false],
        );

        $edited = app(PilotIncidentReportService::class)->submitForActor(
            $incident,
            $user,
            $user,
            [
                'flight_minutes' => 25,
                'custom_fields' => ['summary' => 'Na wijziging van het formulier'],
            ],
        );

        self::assertEquals($snapshot, $edited->drone_usage_snapshot);
        self::assertSame((string) $asset->id, $edited->custom_fields['drone_used']);
        self::assertSame([], app(PilotIncidentReportFormService::class)->droneFieldKeys());

        SystemSetting::query()
            ->where('key', PilotIncidentReportFormService::SETTING_KEY)
            ->firstOrFail()
            ->update([
                'value' => collect($retypedFields)
                    ->reject(static fn (array $field): bool => $field['key'] === 'drone_used')
                    ->values()
                    ->all(),
            ]);
        $edited = app(PilotIncidentReportService::class)->submitForActor(
            $incident,
            $user,
            $user,
            [
                'flight_minutes' => 25,
                'custom_fields' => ['summary' => 'Na verwijderen van het veld'],
            ],
        );

        self::assertEquals($snapshot, $edited->drone_usage_snapshot);
        self::assertSame((string) $asset->id, $edited->custom_fields['drone_used']);
        self::assertSame(
            [['label' => 'DJI Historic Edit KPI', 'value' => 1]],
            app(WallboardKpiRepository::class)->droneFlightDistribution(
                CarbonImmutable::parse('2026-06-30T22:00:00Z'),
                CarbonImmutable::parse('2026-07-31T22:00:00Z'),
                [],
            ),
        );
    }

    public function test_incident_aware_form_config_does_not_reveal_unassigned_incidents(): void
    {
        $operator = $this->user();
        $creator = $this->user();
        $permission = Permission::query()->firstOrCreate(
            ['name' => 'incidents.assigned.view'],
            ['category' => 'test', 'display_name' => 'Assigned incidents', 'description' => 'Test'],
        );
        $role = Role::query()->create([
            'name' => 'kpi-operator-'.strtolower((string) Str::ulid()),
            'display_name' => 'KPI operator',
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $role->permissions()->attach($permission->id);
        $operator->roles()->attach($role->id, ['created_at' => now()]);
        $assigned = $this->incident($creator, false, now());
        $unassigned = $this->incident($creator, false, now());
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $assigned->id,
            'requested_by' => $creator->id,
            'requested_by_name' => $creator->name,
            'requested_by_email' => $creator->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'KPI test',
            'sent_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $operator->id,
            'user_name' => $operator->name,
            'user_email' => $operator->email,
            'response_status' => 'accepted',
            'notified_at' => now(),
        ]);
        $token = $operator->createToken('KPI operator test', ['*', 'client:operator'], now()->addHour())->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/pilot-report/form-config?target=operator&incident_id='.$assigned->id)
            ->assertOk();
        $this->withToken($token)
            ->getJson('/api/pilot-report/form-config?target=operator&incident_id='.$unassigned->id)
            ->assertNotFound();
    }

    /**
     * @param  list<string>  $visibleMetrics
     * @param  array<string, string>  $visualizations
     * @return array<string, mixed>
     */
    private function configuration(array $visibleMetrics, array $visualizations = []): array
    {
        return WallboardConfiguration::normalize([
            'pages' => [[
                'id' => 'kpi',
                'name' => 'KPI-overzicht',
                'type' => 'kpi',
                'duration_seconds' => 30,
                'options' => [
                    'visible_metrics' => $visibleMetrics,
                    'metric_visualizations' => $visualizations,
                ],
            ]],
        ]);
    }

    private function user(): User
    {
        $key = (string) Str::ulid();

        return User::query()->create([
            'name' => 'KPI Test',
            'first_name' => 'KPI',
            'last_name' => 'Test',
            'email' => strtolower($key).'@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
        ]);
    }

    private function incident(
        User $user,
        bool $isTest = false,
        ?\DateTimeInterface $openedAt = null,
    ): Incident {
        return Incident::query()->create([
            'reference' => 'KPI-'.Str::ulid(),
            'title' => 'KPI-uitbreiding',
            'priority' => 'normal',
            'status' => 'resolved',
            'is_test' => $isTest,
            'location_label' => 'Testlocatie',
            'created_by' => $user->id,
            'created_by_name' => $user->name,
            'created_by_email' => $user->email,
            'opened_at' => $openedAt ?? now()->subDay(),
            'closed_at' => now(),
        ]);
    }

    private function incidentWithLocation(
        User $user,
        ?string $countryCode,
        ?string $provinceCode,
        bool $isTest = false,
    ): Incident {
        $incident = $this->incident($user, $isTest);
        $incident->forceFill([
            'country_code' => $countryCode,
            'country_name' => match ($countryCode) {
                'NL' => 'Nederland',
                'BE' => 'België',
                'DE' => 'Duitsland',
                default => null,
            },
            'country_source' => $countryCode === null ? null : 'test',
            'country_resolved_at' => $countryCode === null ? null : now(),
            'province_code' => $provinceCode,
            'province_name' => $provinceCode,
            'province_source' => $provinceCode === null ? null : 'test',
            'province_resolved_at' => $provinceCode === null ? null : now(),
        ])->save();

        return $incident;
    }

    /** @return array{DroneType, Asset} */
    private function drone(string $manufacturer, string $model, string $tag): array
    {
        $type = DroneType::query()->create([
            'manufacturer' => $manufacturer,
            'model' => $model,
            'has_thermal' => false,
            'has_spotlight' => false,
            'has_speaker' => false,
            'is_active' => true,
        ]);
        $asset = Asset::query()->create([
            'asset_tag' => $tag,
            'name' => $tag,
            'type' => 'drone',
            'drone_type_id' => $type->id,
            'status' => 'ready',
        ]);

        return [$type, $asset];
    }

    /** @param array<string, mixed> $customFields
     * @param  array<string, mixed>|null  $snapshot
     */
    private function report(
        Incident $incident,
        User $user,
        string $status,
        int $flightMinutes,
        \DateTimeInterface $submittedAt,
        array $customFields = [],
        ?array $snapshot = null,
    ): PilotIncidentReport {
        return PilotIncidentReport::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => $status,
            'summary' => 'KPI-rapport',
            'flight_minutes' => $flightMinutes,
            'custom_fields' => $customFields,
            'drone_usage_snapshot' => $snapshot,
            'prepared_at' => $submittedAt,
            'submitted_at' => $submittedAt,
        ]);
    }
}
