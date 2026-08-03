<?php

namespace Tests\Feature;

use App\Contracts\RouteGeometryProvider;
use App\Contracts\RoutingProvider;
use App\Jobs\SendFcmNotification;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\Routing\RouteGeometryService;
use App\Services\Routing\RoutingService;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ManualPilotDispatchExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'dis.routing.enabled' => true,
            'dis.routing.provider' => 'osrm',
            'dis.routing.cache_ttl_seconds' => 0,
            'dis.routing.failure_cache_ttl_seconds' => 0,
            'dis.routing.fallback_speed_kmh' => 60,
            'dis.routing.osrm.base_url' => 'http://osrm.internal.test:5000',
            'dis.routing.osrm.allowed_hosts' => 'osrm.internal.test',
            'dis.routing.osrm.profile' => 'driving',
            'dis.routing.osrm.batch_size' => 50,
            'dis.dispatch.eta_ring_minutes' => 15,
        ]);
        Cache::flush();
        $this->forgetRoutingSingletons();
    }

    public function test_locked_dispatch_revalidation_excludes_assignment_created_during_routing_and_backfills_alarm(): void
    {
        Queue::fake();
        $dispatcher = $this->user('manual-race-dispatcher@example.test', 'Race Centralist');
        $team = $this->team('MANUAL-RACE');
        $manualPilot = $this->eligiblePilot(
            $team,
            'manual-race-pilot@example.test',
            'Handmatige Racepiloot',
            52.100000,
            5.100000,
        );
        $backupPilot = $this->eligiblePilot(
            $team,
            'manual-race-backup@example.test',
            'Beschikbare Reservepiloot',
            52.200000,
            5.200000,
        );
        $deployment = $this->deployment($dispatcher, $team, 'MANUAL-RACE-001');
        $assignmentInserted = false;
        $this->fakeRouting(function () use ($deployment, $manualPilot, $dispatcher, &$assignmentInserted): void {
            $this->assignManually($deployment, $manualPilot, $dispatcher);
            $assignmentInserted = true;
        });

        $dispatch = app(DispatchService::class)->create($deployment, [
            'priority' => 'normal',
            'message' => 'Alarmeer uitsluitend een nog niet gekoppelde piloot.',
            'target_team_id' => $team->id,
            'dispatch_recipient_count' => 1,
        ], $dispatcher);

        $this->assertTrue($assignmentInserted);
        $this->assertSame([$backupPilot->id], $dispatch->recipients()->pluck('user_id')->all());
        $this->assertDatabaseMissing('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $manualPilot->id,
        ]);
        $preview = app(DispatchService::class)->previewForDeployment($deployment->refresh());
        $this->assertSame(
            [$backupPilot->id],
            collect($preview['recipients'])->pluck('id')->all(),
        );

        $sent = app(DispatchService::class)->markSent($dispatch, $dispatcher);
        $this->assertSame('sent', $sent->status);
        $this->assertAlarmOnlyTargets($sent, $manualPilot, $backupPilot);
    }

    public function test_escalation_excludes_manual_participant_and_alarms_other_eligible_pilot(): void
    {
        Queue::fake();
        $dispatcher = $this->user('manual-escalation-dispatcher@example.test', 'Opschaling Centralist');
        $primaryTeam = $this->team('MANUAL-PRIMARY');
        $escalationTeam = $this->team('MANUAL-ESCALATION');
        $manualPilot = $this->eligiblePilot(
            $escalationTeam,
            'manual-escalation-pilot@example.test',
            'Handmatige Opschalingspiloot',
            52.100000,
            5.100000,
        );
        $backupPilot = $this->eligiblePilot(
            $escalationTeam,
            'manual-escalation-backup@example.test',
            'Beschikbare Opschalingspiloot',
            52.200000,
            5.200000,
        );
        $deployment = $this->deployment($dispatcher, $primaryTeam, 'MANUAL-ESCALATION-001');
        $this->assignManually($deployment, $manualPilot, $dispatcher);
        $existingDispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $dispatcher->id,
            'requested_by_name' => $dispatcher->name,
            'requested_by_email' => $dispatcher->email,
            'target_team_id' => $primaryTeam->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Bestaande alarmering voor het primaire team.',
            'sent_at' => now(),
        ]);
        $this->fakeRouting();

        $escalated = app(DispatchService::class)->escalate(
            $existingDispatch,
            $dispatcher,
            [$escalationTeam->id],
        );

        $this->assertSame('escalated', $escalated->status);
        $newDispatch = DispatchRequest::query()
            ->where('deployment_id', $deployment->id)
            ->where('target_team_id', $escalationTeam->id)
            ->sole();
        $this->assertSame('sent', $newDispatch->status);
        $this->assertSame([$backupPilot->id], $newDispatch->recipients()->pluck('user_id')->all());
        $this->assertDatabaseMissing('dispatch_recipients', [
            'dispatch_request_id' => $newDispatch->id,
            'user_id' => $manualPilot->id,
        ]);
        $this->assertAlarmOnlyTargets($newDispatch, $manualPilot, $backupPilot);
    }

    private function assertAlarmOnlyTargets(
        DispatchRequest $dispatch,
        User $manualPilot,
        User $alarmPilot,
    ): void {
        $manualTokenId = (string) $manualPilot->fcmTokens()->sole()->id;
        $alarmTokenId = (string) $alarmPilot->fcmTokens()->sole()->id;
        $outboxes = DispatchPushOutbox::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->get();

        $this->assertCount(1, $outboxes);
        $this->assertSame($alarmTokenId, (string) $outboxes->sole()->fcm_token_id);
        $this->assertFalse($outboxes->contains(
            fn (DispatchPushOutbox $outbox): bool => (string) $outbox->fcm_token_id === $manualTokenId,
        ));
        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use (
            $dispatch,
            $alarmTokenId,
        ): bool {
            return $job->messageType === 'dispatch_request'
                && $job->dispatchRequestId === $dispatch->id
                && $job->fcmTokenId === $alarmTokenId;
        });
        Queue::assertPushed(SendFcmNotification::class, 1);
        Queue::assertNotPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->fcmTokenId === $manualTokenId,
        );
    }

    private function assignManually(Deployment $deployment, User $pilot, User $actor): void
    {
        DeploymentPilotAssignment::query()->create([
            'deployment_id' => $deployment->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'assigned_by' => $actor->id,
            'assigned_by_name' => $actor->name,
            'assigned_by_email' => $actor->email,
            'reason' => 'Piloot is al zonder alarmering aan de inzet gekoppeld.',
            'assigned_at' => now(),
        ]);
    }

    private function eligiblePilot(
        Team $targetTeam,
        string $email,
        string $name,
        float $latitude,
        float $longitude,
    ): User {
        $pilot = $this->user($email, $name);
        $pilot->forceFill([
            'push_enabled' => true,
            'home_city' => 'Teststad',
            'home_latitude' => $latitude,
            'home_longitude' => $longitude,
        ])->save();
        $pilot->roles()->attach($this->operatorPilotRole()->id, ['created_at' => now()]);
        $pilot->teams()->attach($this->baseTeam()->id, ['created_at' => now()]);
        if ((string) $targetTeam->id !== (string) $this->baseTeam()->id) {
            $pilot->teams()->attach($targetTeam->id, ['created_at' => now()]);
        }
        $pilot->statuses()->create([
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'status' => 'available',
            'is_available' => true,
            'effective_at' => now(),
        ]);
        $plainProviderToken = 'manual-dispatch-'.$pilot->id;
        $session = $pilot->createToken(
            'Manual dispatch exclusion device',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        FcmToken::query()->create([
            'user_id' => $pilot->id,
            'device_id' => 'manual-dispatch-device-'.$pilot->id,
            'token' => $plainProviderToken,
            'token_hash' => hash('sha256', $plainProviderToken),
            'personal_access_token_id' => $session->id,
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $asset = Asset::query()->create([
            'asset_tag' => 'AST-MANUAL-'.str()->upper((string) str()->ulid()),
            'name' => 'Inzetmiddel '.$name,
            'type' => 'support_equipment',
            'status' => 'assigned',
            'maintenance_due_at' => today()->addYear()->toDateString(),
        ]);
        AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'user_id' => $pilot->id,
            'assigned_by' => $pilot->id,
            'assigned_at' => now(),
        ]);

        return $pilot;
    }

    private function user(string $email, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => str($name)->before(' ')->toString(),
            'last_name' => str($name)->after(' ')->toString(),
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => false,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function operatorPilotRole(): Role
    {
        return Role::query()->firstOrCreate(
            ['name' => 'operator-pilot'],
            [
                'display_name' => 'Operator / Pilot',
                'description' => 'Operationele piloot',
                'can_use_operator_app' => true,
                'can_use_admin_app' => false,
            ],
        );
    }

    private function baseTeam(): Team
    {
        return Team::query()->firstOrCreate(
            ['code' => 'OCP'],
            [
                'name' => 'Operationeel Coördinatie Platform',
                'type' => 'base',
                'is_operational' => true,
            ],
        );
    }

    private function team(string $code): Team
    {
        return Team::query()->create([
            'code' => $code,
            'name' => $code,
            'type' => 'base',
            'is_operational' => true,
        ]);
    }

    private function deployment(User $creator, Team $team, string $reference): Deployment
    {
        $deployment = Deployment::query()->create([
            'reference' => $reference,
            'title' => 'Handmatige pilot dispatch-uitsluiting',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'latitude' => 52.300000,
            'longitude' => 5.300000,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'team_id' => $team->id,
            'opened_at' => now(),
        ]);
        $deployment->teams()->attach($team->id, ['created_at' => now()]);

        return $deployment;
    }

    private function fakeRouting(?Closure $beforeFirstResponse = null): void
    {
        $called = false;
        Http::fake(function (HttpRequest $request) use ($beforeFirstResponse, &$called) {
            if (! $called) {
                $called = true;
                $beforeFirstResponse?->__invoke();
            }

            preg_match('#/table/v1/driving/([^?]+)#', urldecode($request->url()), $matches);
            $coordinates = array_values(array_filter(explode(';', $matches[1] ?? '')));
            if ($coordinates !== []) {
                array_pop($coordinates);
            }
            $routes = collect($coordinates)
                ->values()
                ->map(fn (string $coordinate, int $index): array => [
                    'duration' => ($index + 1) * 300,
                    'distance' => ($index + 1) * 5_000,
                ]);

            return Http::response([
                'code' => 'Ok',
                'durations' => $routes->map(fn (array $route): array => [$route['duration']])->all(),
                'distances' => $routes->map(fn (array $route): array => [$route['distance']])->all(),
            ]);
        });
    }

    private function forgetRoutingSingletons(): void
    {
        app()->forgetInstance(RouteGeometryService::class);
        app()->forgetInstance(RouteGeometryProvider::class);
        app()->forgetInstance(RoutingService::class);
        app()->forgetInstance(RoutingProvider::class);
    }
}
