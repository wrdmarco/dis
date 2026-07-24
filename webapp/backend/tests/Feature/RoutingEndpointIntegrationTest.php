<?php

namespace Tests\Feature;

use App\Contracts\DispatchNotificationQueue;
use App\Contracts\RouteGeometryProvider;
use App\Contracts\RoutingProvider;
use App\Jobs\SendFcmNotification;
use App\Models\AvailabilityStatus;
use App\Models\Certification;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\LocationSharingConsent;
use App\Models\LocationUpdate;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\DispatchPushOutboxService;
use App\Services\LocationService;
use App\Services\Routing\RouteGeometryService;
use App\Services\Routing\RoutingService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RoutingEndpointIntegrationTest extends TestCase
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
            'dis.routing.osrm.geometry_max_routes' => 25,
            'dis.routing.osrm.geometry_concurrency' => 10,
            'dis.dispatch.eta_ring_minutes' => 15,
        ]);
        Cache::flush();
        $this->forgetRoutingSingletons();
    }

    public function test_dispatch_preview_uses_one_bulk_table_response_and_ranks_navigation_eta_in_fifteen_minute_rings(): void
    {
        $viewer = $this->user('routing-viewer@example.test', 'Routing Viewer');
        $this->grant($viewer, ['incidents.dispatch.view']);
        $team = $this->team('ROUTE-PREVIEW');
        $slowPilot = $this->eligiblePilot($team, 'slow@example.test', 'Anna Langzaam', 52.100000, 5.100000);
        $fastPilot = $this->eligiblePilot($team, 'fast@example.test', 'Zed Snel', 52.200000, 5.200000);
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'ROUTE-PREVIEW-001');

        Http::fake(function (HttpRequest $request) {
            preg_match('#/table/v1/driving/([^?]+)#', urldecode($request->url()), $matches);
            $coordinates = explode(';', $matches[1] ?? '');
            array_pop($coordinates);
            $routes = collect($coordinates)->map(fn (string $coordinate): array => match ($coordinate) {
                '5.100000,52.100000' => ['duration' => 1800.1, 'distance' => 20_000],
                '5.200000,52.200000' => ['duration' => 899.1, 'distance' => 5_000],
                default => ['duration' => null, 'distance' => null],
            });

            return Http::response([
                'code' => 'Ok',
                'durations' => $routes->map(fn (array $route): array => [$route['duration']])->all(),
                'distances' => $routes->map(fn (array $route): array => [$route['distance']])->all(),
            ]);
        });

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview')
            ->assertOk();

        Http::assertSentCount(1);
        $requestUrl = urldecode(Http::recorded()[0][0]->url());
        $this->assertStringContainsString('/table/v1/driving/', $requestUrl);
        $this->assertStringContainsString('5.100000,52.100000', $requestUrl);
        $this->assertStringContainsString('5.200000,52.200000', $requestUrl);
        $this->assertMatchesRegularExpression('#;5\.300000,52\.300000\?#', $requestUrl);
        $this->assertStringContainsString('sources=0;1', $requestUrl);
        $this->assertStringContainsString('destinations=2', $requestUrl);
        $this->assertStringContainsString('annotations=duration,distance', $requestUrl);

        $this->assertSame(
            [$fastPilot->id, $slowPilot->id],
            collect($response->json('data.recipients'))->pluck('id')->all(),
        );
        $response
            ->assertJsonPath('data.recipients.0.eta_minutes', 15)
            ->assertJsonPath('data.recipients.0.eta_source', 'navigation')
            ->assertJsonPath('data.recipients.1.eta_minutes', 45)
            ->assertJsonPath('data.recipients.1.eta_source', 'navigation');
    }

    public function test_dispatch_preview_remains_available_with_explicit_fallback_after_osrm_failure(): void
    {
        $viewer = $this->user('routing-fallback-viewer@example.test', 'Routing Fallback Viewer');
        $this->grant($viewer, ['incidents.dispatch.view']);
        $team = $this->team('ROUTE-FALLBACK');
        $pilot = $this->eligiblePilot($team, 'fallback@example.test', 'Fallback Pilot', 52.100000, 5.100000);
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'ROUTE-FALLBACK-001');

        Http::fake(['*' => Http::response([], 503)]);

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview')
            ->assertOk()
            ->assertJsonPath('data.recipients.0.id', $pilot->id)
            ->assertJsonPath('data.recipients.0.eta_source', 'fallback');

        Http::assertSentCount(1);
        $this->assertGreaterThan(0, $response->json('data.recipients.0.eta_minutes'));
        $this->assertSame(0, $response->json('data.recipients.0.eta_minutes') % 15);
    }

    public function test_dispatch_keeps_a_doze_sleeping_operator_reachable_without_treating_stale_devices_as_online(): void
    {
        config()->set('app.timezone', 'Europe/Amsterdam');
        DB::statement("SET LOCAL TIME ZONE 'UTC'");
        $viewer = $this->user('doze-viewer@example.test', 'Doze Viewer');
        $this->grant($viewer, ['incidents.dispatch.view']);
        $team = $this->team('DOZE-REACHABILITY');
        $sleepingPilot = $this->eligiblePilot($team, 'doze-sleeping@example.test', 'Doze Sleeping', 52.100000, 5.100000);
        $stalePilot = $this->eligiblePilot($team, 'doze-stale@example.test', 'Doze Stale', 52.200000, 5.200000);
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'DOZE-REACHABILITY-001');

        $sleepingPilot->fcmTokens()->update([
            'last_seen_at' => now()->subMinutes(FcmToken::onlineThresholdMinutes() + 1),
        ]);
        $stalePilot->fcmTokens()->update([
            'last_seen_at' => now()->subMinutes(FcmToken::pushReachabilityThresholdMinutes() + 1),
        ]);
        Http::fake(['*' => Http::response([], 503)]);

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview')
            ->assertOk();

        $this->assertSame(
            [$sleepingPilot->id],
            collect($response->json('data.recipients'))->pluck('id')->all(),
        );
        $this->assertFalse($sleepingPilot->fcmTokens()->firstOrFail()->is_online);
    }

    public function test_team_without_linked_certifications_does_not_inherit_global_dispatch_requirements(): void
    {
        config()->set('dis.routing.enabled', false);
        $viewer = $this->user('team-no-certificate-viewer@example.test', 'Team Certificate Viewer');
        $this->grant($viewer, ['incidents.dispatch.view']);
        $team = $this->team('NO-CERTIFICATE-REQUIREMENT');
        $pilot = $this->eligiblePilot(
            $team,
            'team-no-certificate-pilot@example.test',
            'Team Certificate Pilot',
            52.100000,
            5.100000,
        );
        $incident = $this->incident(
            $viewer,
            $team,
            52.300000,
            5.300000,
            'NO-CERTIFICATE-REQUIREMENT-001',
        );
        Certification::query()->create([
            'code' => 'GLOBAL-DISPATCH-CERTIFICATE',
            'name' => 'Globaal dispatchcertificaat',
            'is_required_for_dispatch' => true,
            'warning_days_before_expiry' => 30,
        ]);

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview')
            ->assertOk()
            ->assertJsonPath('data.recipients.0.id', $pilot->id)
            ->assertJsonPath('data.blocked_reason', null);
    }

    public function test_only_certifications_explicitly_linked_to_the_team_are_required(): void
    {
        config()->set('dis.routing.enabled', false);
        $viewer = $this->user('team-certificate-viewer@example.test', 'Required Certificate Viewer');
        $this->grant($viewer, ['incidents.dispatch.view']);
        $team = $this->team('EXPLICIT-CERTIFICATE-REQUIREMENT');
        $pilot = $this->eligiblePilot(
            $team,
            'team-certificate-pilot@example.test',
            'Required Certificate Pilot',
            52.100000,
            5.100000,
        );
        $incident = $this->incident(
            $viewer,
            $team,
            52.300000,
            5.300000,
            'EXPLICIT-CERTIFICATE-REQUIREMENT-001',
        );
        $certification = Certification::query()->create([
            'code' => 'TEAM-DISPATCH-CERTIFICATE',
            'name' => 'Teamgebonden dispatchcertificaat',
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ]);
        $team->requiredCertifications()->attach($certification->id, ['created_at' => now()]);

        $blocked = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview')
            ->assertOk()
            ->assertJsonCount(0, 'data.recipients');
        $this->assertStringContainsString(
            'verplichte geldige certificering',
            (string) $blocked->json('data.blocked_reason'),
        );

        UserCertification::query()->create([
            'user_id' => $pilot->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subDay()->toDateString(),
            'expires_at' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview')
            ->assertOk()
            ->assertJsonPath('data.recipients.0.id', $pilot->id)
            ->assertJsonPath('data.blocked_reason', null);
    }

    public function test_dispatch_preview_never_ranks_an_optimistic_fallback_before_a_navigation_route(): void
    {
        $viewer = $this->user('routing-source-viewer@example.test', 'Routing Source Viewer');
        $this->grant($viewer, ['incidents.dispatch.view']);
        $team = $this->team('ROUTE-SOURCE');
        $fallbackPilot = $this->eligiblePilot($team, 'near-fallback@example.test', 'Dichtbij Schatting', 52.299000, 5.299000);
        $navigationPilot = $this->eligiblePilot($team, 'far-navigation@example.test', 'Verweg Navigatie', 52.100000, 5.100000);
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'ROUTE-SOURCE-001');

        Http::fake(function (HttpRequest $request) {
            preg_match('#/table/v1/driving/([^?]+)#', urldecode($request->url()), $matches);
            $coordinates = explode(';', $matches[1] ?? '');
            array_pop($coordinates);
            $routes = collect($coordinates)->map(fn (string $coordinate): array => match ($coordinate) {
                '5.299000,52.299000' => ['duration' => null, 'distance' => null],
                '5.100000,52.100000' => ['duration' => 3600, 'distance' => 40_000],
                default => ['duration' => null, 'distance' => null],
            });

            return Http::response([
                'code' => 'Ok',
                'durations' => $routes->map(fn (array $route): array => [$route['duration']])->all(),
                'distances' => $routes->map(fn (array $route): array => [$route['distance']])->all(),
            ]);
        });

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/dispatch-preview?dispatch_recipient_count=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.recipients');

        $response
            ->assertJsonPath('data.recipients.0.id', $navigationPilot->id)
            ->assertJsonPath('data.recipients.0.eta_source', 'navigation');
        $this->assertNotSame($fallbackPilot->id, $response->json('data.recipients.0.id'));
    }

    public function test_dispatch_creation_revalidates_eligibility_after_routing_and_backfills_the_selection(): void
    {
        $dispatcher = $this->user('routing-dispatcher@example.test', 'Routing Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-REVALIDATE');
        $changedPilot = $this->eligiblePilot($team, 'changed@example.test', 'Eerste Gewijzigd', 52.100000, 5.100000);
        $backupPilot = $this->eligiblePilot($team, 'backup@example.test', 'Tweede Geldig', 52.200000, 5.200000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-REVALIDATE-001');

        Http::fake(function () use ($changedPilot) {
            $changedPilot->forceFill(['push_enabled' => false])->save();

            return Http::response([
                'code' => 'Ok',
                'durations' => [[300], [600]],
                'distances' => [[5_000], [10_000]],
            ]);
        });

        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Hercontroleer de route-selectie.',
                'target_team_id' => $team->id,
                'dispatch_recipient_count' => 1,
            ])
            ->assertCreated();

        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        $this->assertSame([$backupPilot->id], $dispatch->recipients()->pluck('user_id')->all());
    }

    public function test_dispatch_creation_retries_when_the_incident_route_target_changes_during_selection(): void
    {
        $dispatcher = $this->user('route-change-dispatcher@example.test', 'Route Change Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-TARGET-CHANGE');
        $oldTargetPilot = $this->eligiblePilot($team, 'old-target@example.test', 'A Old Target Pilot', 52.100000, 5.100000);
        $newTargetPilot = $this->eligiblePilot($team, 'new-target@example.test', 'B New Target Pilot', 52.200000, 5.200000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-TARGET-CHANGE-001');
        $requestNumber = 0;
        Http::fake(function (HttpRequest $request) use (&$requestNumber, $incident) {
            $requestNumber++;
            preg_match('#/table/v1/driving/([^?]+)#', urldecode($request->url()), $matches);
            $coordinates = explode(';', $matches[1] ?? '');
            $destination = array_pop($coordinates);
            if ($requestNumber === 1) {
                $incident->forceFill(['latitude' => 52.400000, 'longitude' => 5.400000])->save();
            }
            $routes = collect($coordinates)->map(function (string $coordinate) use ($destination): array {
                $newTarget = $destination === '5.400000,52.400000';
                $fast = $newTarget ? '5.200000,52.200000' : '5.100000,52.100000';

                return $coordinate === $fast
                    ? ['duration' => 300, 'distance' => 5_000]
                    : ['duration' => 900, 'distance' => 15_000];
            });

            return Http::response([
                'code' => 'Ok',
                'durations' => $routes->map(fn (array $route): array => [$route['duration']])->all(),
                'distances' => $routes->map(fn (array $route): array => [$route['distance']])->all(),
            ]);
        });

        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Gebruik de nieuwe incidentlocatie.',
                'target_team_id' => $team->id,
                'dispatch_recipient_count' => 1,
            ])
            ->assertCreated();

        Http::assertSentCount(2);
        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        $this->assertSame([$newTargetPilot->id], $dispatch->recipients()->pluck('user_id')->all());
        $this->assertNotSame($oldTargetPilot->id, $dispatch->recipients()->sole()->user_id);
    }

    public function test_repeated_send_does_not_enqueue_an_acknowledged_outbox_row_again(): void
    {
        $dispatcher = $this->user('idempotent-dispatcher@example.test', 'Idempotent Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-IDEMPOTENT');
        $pilot = $this->eligiblePilot($team, 'idempotent-pilot@example.test', 'Idempotent Pilot', 52.100000, 5.100000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-IDEMPOTENT-001');
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[300]],
            'distances' => [[5_000]],
        ])]);

        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Stuur deze alarmering.',
                'target_team_id' => $team->id,
            ])
            ->assertCreated();
        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        Queue::fake();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertOk();
        $lockingTables = $this->lockingTablesFromQueryLog();
        DB::disableQueryLog();
        $firstSentAt = $dispatch->refresh()->sent_at;
        $this->travel(1)->minute();
        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertOk();

        Queue::assertPushed(SendFcmNotification::class, 1);
        $outboxId = (string) DispatchPushOutbox::query()->sole()->id;
        Queue::assertPushed(
            SendFcmNotification::class,
            fn (SendFcmNotification $job): bool => $job->afterCommit === false
                && $job->dispatchPushOutboxId === $outboxId,
        );
        $this->assertTrue($firstSentAt->equalTo($dispatch->refresh()->sent_at));
        $this->assertSame([$pilot->id], $dispatch->recipients()->pluck('user_id')->all());
        $this->assertSame(
            ['incidents', 'dispatch_requests', 'dispatch_recipients', 'dispatch_push_outbox'],
            array_values(array_unique($lockingTables)),
            'Dispatch writes must lock incident -> dispatch -> recipient/outbox.',
        );
    }

    public function test_only_one_non_cancelled_dispatch_can_be_created_for_the_same_incident_and_team(): void
    {
        $dispatcher = $this->user('duplicate-dispatcher@example.test', 'Duplicate Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-DUPLICATE');
        $this->eligiblePilot($team, 'duplicate-pilot@example.test', 'Duplicate Pilot', 52.100000, 5.100000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-DUPLICATE-001');
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[300]],
            'distances' => [[5_000]],
        ])]);
        $payload = [
            'priority' => 'normal',
            'message' => 'Maak geen dubbele alarmering.',
            'target_team_id' => $team->id,
        ];

        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', $payload)
            ->assertCreated();
        $response = $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertNotEmpty($response->json('error.details.target_team_id'));
        $this->assertSame(1, DispatchRequest::query()
            ->where('incident_id', $incident->id)
            ->where('target_team_id', $team->id)
            ->where('status', '!=', 'cancelled')
            ->count());
    }

    public function test_dispatch_push_outbox_survives_queue_failure_and_stops_requeueing_after_database_acknowledgement(): void
    {
        $dispatcher = $this->user('outbox-dispatcher@example.test', 'Outbox Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-OUTBOX');
        $pilot = $this->eligiblePilot($team, 'outbox-pilot@example.test', 'Outbox Pilot', 52.100000, 5.100000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-OUTBOX-001');
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[300]],
            'distances' => [[5_000]],
        ])]);
        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Bewaar deze alarmering duurzaam.',
                'target_team_id' => $team->id,
            ])
            ->assertCreated();
        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();

        $failingQueue = new class implements DispatchNotificationQueue
        {
            public int $attempts = 0;

            public function enqueue(DispatchPushOutbox $notification): void
            {
                $this->attempts++;

                throw new \RuntimeException('Simulated queue outage.');
            }
        };
        $this->app->instance(DispatchNotificationQueue::class, $failingQueue);

        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertOk();

        $outbox = DispatchPushOutbox::query()->sole();
        $this->assertSame('sent', $dispatch->refresh()->status);
        $this->assertSame(1, $failingQueue->attempts);
        $this->assertSame(1, $outbox->attempts);
        $this->assertNull($outbox->queued_at);
        $this->assertSame('queue_unavailable', $outbox->last_error_code);
        $this->assertSame($pilot->fcmTokens()->sole()->id, $outbox->fcm_token_id);

        $this->travel(6)->seconds();
        $recordingQueue = new class implements DispatchNotificationQueue
        {
            /** @var list<string> */
            public array $outboxIds = [];

            public function enqueue(DispatchPushOutbox $notification): void
            {
                $this->outboxIds[] = (string) $notification->id;
            }
        };
        $this->app->instance(DispatchNotificationQueue::class, $recordingQueue);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->artisan('dis:flush-dispatch-push-outbox')
            ->expectsOutput('{"queued":1,"failed":0,"cancelled":0}')
            ->assertSuccessful();
        $lockingTables = $this->lockingTablesFromQueryLog();
        DB::disableQueryLog();
        $secondRetry = $this->app->make(DispatchPushOutboxService::class)->flushPending();

        $this->assertSame(['queued' => 0, 'failed' => 0, 'cancelled' => 0], $secondRetry);
        $this->assertSame([(string) $outbox->id], $recordingQueue->outboxIds);
        $this->assertNotNull($outbox->refresh()->queued_at);
        $this->assertNull($outbox->last_error_code);

        // A repeated send request remains idempotent after recovery.
        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertOk();
        $this->assertSame([(string) $outbox->id], $recordingQueue->outboxIds);
        $this->assertSame(1, DispatchPushOutbox::query()->count());
        $this->assertSame(
            ['incidents', 'dispatch_requests', 'dispatch_push_outbox'],
            array_values(array_unique($lockingTables)),
            'Outbox writes must lock incident -> dispatch -> outbox.',
        );
    }

    public function test_dispatch_push_outbox_flush_runs_every_second_without_overlap(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => str_contains(
                $candidate->command ?? '',
                'dis:flush-dispatch-push-outbox',
            ));

        $this->assertInstanceOf(Event::class, $event);
        $this->assertSame(1, $event->repeatSeconds);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_declined_and_no_response_dispatch_reactions_revoke_live_location_consent(): void
    {
        $dispatcher = $this->user('consent-dispatcher@example.test', 'Consent Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $pilot = $this->user('consent-pilot@example.test', 'Consent Pilot');
        $this->grant($pilot, ['incidents.assigned.view'], operator: true);
        $overriddenPilot = $this->user('consent-override@example.test', 'Consent Override Pilot');
        $team = $this->team('CONSENT-RESPONSE');
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'CONSENT-RESPONSE-001');
        $dispatch = $this->sentDispatch($incident, $dispatcher);
        $this->acceptedRecipient($dispatch, $pilot);
        $this->acceptedRecipient($dispatch, $overriddenPilot);
        foreach ([$pilot, $overriddenPilot] as $recipient) {
            LocationSharingConsent::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $recipient->id,
                'is_active' => true,
                'state_version' => 1,
                'consented_at' => now(),
            ]);
        }

        $this->asOperatorClient($pilot)
            ->postJson('/api/dispatches/'.$dispatch->id.'/respond', ['response' => 'declined'])
            ->assertNoContent();
        $overriddenRecipient = $dispatch->recipients()->where('user_id', $overriddenPilot->id)->sole();
        $this->asWebClient($dispatcher)
            ->patchJson('/api/dispatches/'.$dispatch->id.'/recipients/'.$overriddenRecipient->id.'/response', [
                'response' => 'no_response',
                'note' => 'Geen reactie ontvangen.',
            ])
            ->assertOk();

        foreach ([$pilot, $overriddenPilot] as $recipient) {
            $consent = LocationSharingConsent::query()
                ->where('incident_id', $incident->id)
                ->where('user_id', $recipient->id)
                ->sole();
            $this->assertFalse($consent->is_active);
            $this->assertSame(2, $consent->state_version);
            $this->assertNotNull($consent->revoked_at);
        }
    }

    public function test_actual_alarm_excludes_a_declined_preannouncement_and_backfills_the_slot(): void
    {
        $dispatcher = $this->user('declined-dispatcher@example.test', 'Declined Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-DECLINED');
        $declinedPilot = $this->eligiblePilot($team, 'declined-pilot@example.test', 'A Declined Pilot', 52.100000, 5.100000);
        $backupPilot = $this->eligiblePilot($team, 'declined-backup@example.test', 'B Backup Pilot', 52.200000, 5.200000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-DECLINED-001');
        Http::fake(function (HttpRequest $request) {
            preg_match('#/table/v1/driving/([^?]+)#', urldecode($request->url()), $matches);
            $coordinates = explode(';', $matches[1] ?? '');
            array_pop($coordinates);
            $routes = collect($coordinates)->map(fn (string $coordinate): array => $coordinate === '5.100000,52.100000'
                ? ['duration' => 300, 'distance' => 5_000]
                : ['duration' => 600, 'distance' => 10_000]);

            return Http::response([
                'code' => 'Ok',
                'durations' => $routes->map(fn (array $route): array => [$route['duration']])->all(),
                'distances' => $routes->map(fn (array $route): array => [$route['distance']])->all(),
            ]);
        });

        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Vervang een weigering.',
                'target_team_id' => $team->id,
                'dispatch_recipient_count' => 1,
            ])
            ->assertCreated();
        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        $this->assertSame($declinedPilot->id, $dispatch->recipients()->sole()->user_id);
        $dispatch->recipients()->update([
            'response_status' => 'declined',
            'response_note' => 'Niet beschikbaar.',
            'responded_at' => now(),
            'notified_at' => now()->subMinutes(10),
        ]);
        Queue::fake();

        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertOk();

        Queue::assertPushed(SendFcmNotification::class, 1);
        $recipient = $dispatch->recipients()->sole();
        $this->assertSame($backupPilot->id, $recipient->user_id);
        $this->assertSame('pending', $recipient->response_status);
        $this->assertTrue($recipient->notified_at->equalTo($dispatch->refresh()->sent_at));
    }

    public function test_actual_alarm_fails_closed_when_every_preannouncement_recipient_declined(): void
    {
        $dispatcher = $this->user('all-declined-dispatcher@example.test', 'All Declined Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-ALL-DECLINED');
        $this->eligiblePilot($team, 'all-declined-pilot@example.test', 'Only Declined Pilot', 52.100000, 5.100000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-ALL-DECLINED-001');
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[300]],
            'distances' => [[5_000]],
        ])]);
        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Niemand beschikbaar.',
                'target_team_id' => $team->id,
                'dispatch_recipient_count' => 1,
            ])
            ->assertCreated();
        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        $dispatch->recipients()->update([
            'response_status' => 'declined',
            'responded_at' => now(),
            'notified_at' => now(),
        ]);
        Queue::fake();

        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertUnprocessable();

        Queue::assertNothingPushed();
        $this->assertSame('draft', $dispatch->refresh()->status);
        $this->assertNull($dispatch->sent_at);
    }

    public function test_actual_alarm_revalidates_changed_eligibility_and_backfills_the_slot(): void
    {
        $dispatcher = $this->user('send-revalidate-dispatcher@example.test', 'Send Revalidate Dispatcher');
        $this->grant($dispatcher, ['incidents.dispatch.manage']);
        $team = $this->team('ROUTE-SEND-REVALIDATE');
        $changedPilot = $this->eligiblePilot($team, 'send-changed@example.test', 'A Changed Pilot', 52.100000, 5.100000);
        $backupPilot = $this->eligiblePilot($team, 'send-backup@example.test', 'B Backup Pilot', 52.200000, 5.200000);
        $incident = $this->incident($dispatcher, $team, 52.300000, 5.300000, 'ROUTE-SEND-REVALIDATE-001');
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[300], [600]],
            'distances' => [[5_000], [10_000]],
        ])]);
        $this->asWebClient($dispatcher)
            ->postJson('/api/incidents/'.$incident->id.'/dispatches', [
                'priority' => 'normal',
                'message' => 'Hercontroleer bij verzending.',
                'target_team_id' => $team->id,
                'dispatch_recipient_count' => 1,
            ])
            ->assertCreated();
        $dispatch = DispatchRequest::query()->where('incident_id', $incident->id)->sole();
        $selectedUserId = (string) $dispatch->recipients()->sole()->user_id;
        $expectedBackupUserId = $selectedUserId === (string) $changedPilot->id
            ? (string) $backupPilot->id
            : (string) $changedPilot->id;
        User::query()->findOrFail($selectedUserId)->forceFill(['push_enabled' => false])->save();
        Queue::fake();

        $this->asWebClient($dispatcher)
            ->postJson('/api/dispatches/'.$dispatch->id.'/send')
            ->assertOk();

        Queue::assertPushed(SendFcmNotification::class, 1);
        $this->assertSame([$expectedBackupUserId], $dispatch->recipients()->pluck('user_id')->all());
    }

    public function test_live_locations_exposes_whole_navigation_minutes_only_for_current_locations(): void
    {
        $this->travelTo(now()->startOfSecond());
        $viewer = $this->user('live-routing-viewer@example.test', 'Live Routing Viewer');
        $this->grant($viewer, ['incidents.view']);
        $currentPilot = $this->user('current-location@example.test', 'Actuele Pilot');
        $stalePilot = $this->user('stale-location@example.test', 'Verlopen Pilot');
        $team = $this->team('LIVE-ROUTING');
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'LIVE-ROUTING-001');
        $dispatch = $this->sentDispatch($incident, $viewer);
        $this->acceptedRecipient($dispatch, $currentPilot);
        $this->acceptedRecipient($dispatch, $stalePilot);
        foreach ([$currentPilot, $stalePilot] as $pilot) {
            LocationSharingConsent::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $pilot->id,
                'is_active' => true,
                'consented_at' => now()->subMinutes(10),
            ]);
        }
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $currentPilot->id,
            'latitude' => 52.100000,
            'longitude' => 5.100000,
            'accuracy_meters' => 8,
            'recorded_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
        ]);
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $stalePilot->id,
            'latitude' => 52.200000,
            'longitude' => 5.200000,
            'accuracy_meters' => 10,
            'recorded_at' => now()->subMinutes(5)->subSecond(),
            'created_at' => now()->subMinutes(5)->subSecond(),
        ]);

        Http::fake([
            '*' => Http::response([
                'code' => 'Ok',
                'durations' => [[900.1]],
                'distances' => [[12_000]],
            ]),
        ]);

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/table/v1/driving/5.100000,52.100000;5.300000,52.300000')
                && ! str_contains($url, '5.200000,52.200000')
                && str_contains($url, 'sources=0')
                && str_contains($url, 'destinations=1');
        });

        $locations = collect($response->json('data'))->keyBy('user_id');
        $this->assertSame(true, $locations[$currentPilot->id]['location_is_current']);
        $this->assertSame('shared', $locations[$currentPilot->id]['sharing_status']);
        $this->assertSame(16, $locations[$currentPilot->id]['eta_minutes']);
        $this->assertSame('navigation', $locations[$currentPilot->id]['eta_source']);
        $this->assertSame(false, $locations[$stalePilot->id]['location_is_current']);
        $this->assertSame('stale', $locations[$stalePilot->id]['sharing_status']);
        $this->assertNull($locations[$stalePilot->id]['eta_minutes']);
        $this->assertSame('unknown', $locations[$stalePilot->id]['eta_source']);
    }

    public function test_live_locations_without_route_opt_in_preserves_contract_and_never_calls_route_endpoint(): void
    {
        $viewer = $this->user('no-route-opt-in@example.test', 'No Route Opt In');
        $this->grant($viewer, ['incidents.view', 'operational-map.view']);
        $pilot = $this->user('no-route-pilot@example.test', 'No Route Pilot');
        $team = $this->team('NO-ROUTE-OPT-IN');
        $incident = $this->incident($viewer, $team, 52.3, 5.3, 'NO-ROUTE-OPT-IN-001');
        $dispatch = $this->sentDispatch($incident, $viewer);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinute(),
        ])->refresh();
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $consent->state_version,
            'latitude' => 52.1,
            'longitude' => 5.1,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[600]],
            'distances' => [[8000]],
        ])]);

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonMissingPath('data.0.route')
            ->assertJsonPath('data.0.eta_source', 'navigation');
        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations?include_routes=0')
            ->assertOk()
            ->assertJsonMissingPath('data.0.route')
            ->assertJsonPath('data.0.eta_source', 'navigation');

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/table/v1/'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/route/v1/'));
    }

    public function test_route_opt_in_returns_only_current_authorized_geojson_and_reuses_route_eta(): void
    {
        $this->travelTo(now()->startOfSecond());
        $viewer = $this->user('route-map-viewer@example.test', 'Route Map Viewer');
        $this->grant($viewer, ['incidents.view', 'operational-map.view']);
        $currentPilot = $this->user('route-current@example.test', 'Route Current');
        $stalePilot = $this->user('route-stale@example.test', 'Route Stale');
        $onScenePilot = $this->user('route-on-scene@example.test', 'Route On Scene');
        $team = $this->team('ROUTE-MAP');
        $incident = $this->incident($viewer, $team, 52.3, 5.3, 'ROUTE-MAP-001');
        $dispatch = $this->sentDispatch($incident, $viewer);

        foreach ([$currentPilot, $stalePilot, $onScenePilot] as $pilot) {
            $this->acceptedRecipient($dispatch, $pilot);
            $consent = LocationSharingConsent::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $pilot->id,
                'is_active' => true,
                'consented_at' => now()->subMinutes(10),
            ])->refresh();
            $isStale = $pilot->is($stalePilot);
            LocationUpdate::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $pilot->id,
                'consent_state_version' => $consent->state_version,
                'latitude' => $pilot->is($currentPilot) ? 52.1 : ($isStale ? 52.2 : 52.25),
                'longitude' => $pilot->is($currentPilot) ? 5.1 : ($isStale ? 5.2 : 5.25),
                'recorded_at' => $isStale ? now()->subMinutes(6) : now(),
                'created_at' => $isStale ? now()->subMinutes(6) : now(),
            ]);
        }
        AvailabilityStatus::query()->create([
            'user_id' => $onScenePilot->id,
            'user_name' => $onScenePilot->name,
            'user_email' => $onScenePilot->email,
            'status' => 'on_scene',
            'is_available' => true,
            'is_system_applied' => false,
            'effective_at' => now(),
        ]);
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'routes' => [[
                'duration' => 600.2,
                'distance' => 8000.4,
                'geometry' => [
                    'type' => 'LineString',
                    'coordinates' => [[5.1, 52.1], [5.2, 52.2], [5.3, 52.3]],
                ],
            ]],
        ])]);

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations?include_routes=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $locations = collect($response->json('data'))->keyBy('user_id');
        $this->assertSame('navigation', $locations[$currentPilot->id]['route']['source']);
        $this->assertSame(601, $locations[$currentPilot->id]['route']['duration_seconds']);
        $this->assertSame(8001, $locations[$currentPilot->id]['route']['distance_meters']);
        $this->assertSame('LineString', $locations[$currentPilot->id]['route']['geometry']['type']);
        $this->assertSame([[5.1, 52.1], [5.2, 52.2], [5.3, 52.3]], $locations[$currentPilot->id]['route']['geometry']['coordinates']);
        $this->assertSame(11, $locations[$currentPilot->id]['eta_minutes']);
        $this->assertSame('navigation', $locations[$currentPilot->id]['eta_source']);
        $this->assertNull($locations[$stalePilot->id]['route']);
        $this->assertFalse($locations->has($onScenePilot->id));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/route/v1/driving/5.100000,52.100000;5.300000,52.300000')
                && ! str_contains($url, '5.200000,52.200000')
                && ! str_contains($url, '5.250000,52.250000');
        });
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/table/v1/'));
    }

    public function test_route_opt_in_degrades_to_null_geometry_and_fallback_eta_after_osrm_failure(): void
    {
        $viewer = $this->user('route-failure-viewer@example.test', 'Route Failure Viewer');
        $this->grant($viewer, ['incidents.view', 'operational-map.view']);
        $pilot = $this->user('route-failure-pilot@example.test', 'Route Failure Pilot');
        $team = $this->team('ROUTE-FAILURE');
        $incident = $this->incident($viewer, $team, 52.3, 5.3, 'ROUTE-FAILURE-001');
        $dispatch = $this->sentDispatch($incident, $viewer);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinute(),
        ])->refresh();
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $consent->state_version,
            'latitude' => 52.1,
            'longitude' => 5.1,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
        Http::fake(['*' => Http::response([], 503)]);

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations?include_routes=1')
            ->assertOk()
            ->assertJsonPath('data.0.route', null)
            ->assertJsonPath('data.0.eta_source', 'fallback');

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/route/v1/'));
        Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/table/v1/'));

        config()->set('dis.routing.enabled', false);
        $this->forgetRoutingSingletons();
        Http::fake();

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations?include_routes=1')
            ->assertOk()
            ->assertJsonPath('data.0.route', null)
            ->assertJsonPath('data.0.eta_source', 'fallback');
        // Http::fake() replaces the responder but deliberately keeps recorded
        // history; the count remaining at one proves this disabled poll added
        // no provider call.
        Http::assertSentCount(1);
    }

    public function test_route_opt_in_requires_operational_map_permission_before_contacting_osrm(): void
    {
        $viewer = $this->user('route-forbidden-viewer@example.test', 'Route Forbidden Viewer');
        $this->grant($viewer, ['incidents.view']);
        $team = $this->team('ROUTE-FORBIDDEN');
        $incident = $this->incident($viewer, $team, 52.3, 5.3, 'ROUTE-FORBIDDEN-001');
        Http::fake();

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations?include_routes=1')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_live_locations_selects_only_the_latest_received_position_in_the_database(): void
    {
        $this->travelTo(now()->startOfSecond());
        $viewer = $this->user('location-history-viewer@example.test', 'Location History Viewer');
        $this->grant($viewer, ['incidents.view']);
        $pilot = $this->user('location-history-pilot@example.test', 'Location History Pilot');
        $team = $this->team('LOCATION-HISTORY');
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'LOCATION-HISTORY-001');
        $dispatch = $this->sentDispatch($incident, $viewer);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinutes(10),
        ])->refresh();

        foreach (range(1, 200) as $offset) {
            $receivedAt = now()->subSeconds(240 - $offset);
            LocationUpdate::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $pilot->id,
                'consent_state_version' => $consent->state_version,
                'latitude' => 51.900000 + ($offset / 100_000),
                'longitude' => 4.900000 + ($offset / 100_000),
                'recorded_at' => $receivedAt,
                'created_at' => $receivedAt,
            ]);
        }
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $consent->state_version,
            'latitude' => 52.123456,
            'longitude' => 5.654321,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[420]],
            'distances' => [[6_000]],
        ])]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.latitude', '52.1234560')
            ->assertJsonPath('data.0.longitude', '5.6543210')
            ->assertJsonPath('data.0.eta_minutes', 7)
            ->assertJsonPath('data.0.eta_source', 'navigation');

        $locationQuery = collect(DB::getQueryLog())
            ->pluck('query')
            ->first(fn (string $query): bool => str_contains(strtolower($query), 'location_updates')
                && str_contains(strtolower($query), 'not exists'));
        DB::disableQueryLog();

        $this->assertNotNull($locationQuery, 'Live location polling must select the latest row per user in SQL.');
        Http::assertSent(function (HttpRequest $request): bool {
            return str_contains(urldecode($request->url()), '5.654321,52.123456;5.300000,52.300000');
        });
    }

    public function test_location_updates_reject_future_timestamps_and_legacy_future_rows_never_shadow_fresh_data(): void
    {
        $this->travelTo(now()->startOfSecond());
        $pilot = $this->user('future-location@example.test', 'Future Location Pilot');
        $this->grant($pilot, ['incidents.view']);
        $team = $this->team('FUTURE-LOCATION');
        $incident = $this->incident($pilot, $team, 52.300000, 5.300000, 'FUTURE-LOCATION-001');
        $dispatch = $this->sentDispatch($incident, $pilot);
        $this->acceptedRecipient($dispatch, $pilot);
        LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now(),
        ]);

        $futureResponse = $this->asWebClient($pilot)
            ->postJson('/api/incidents/'.$incident->id.'/location', [
                'latitude' => 52.100000,
                'longitude' => 5.100000,
                'recorded_at' => now()->addMinutes(3)->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertNotEmpty($futureResponse->json('error.details.recorded_at'));

        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'latitude' => 52.100000,
            'longitude' => 5.100000,
            'recorded_at' => now()->addHour(),
            'created_at' => now(),
        ]);
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'latitude' => 52.200000,
            'longitude' => 5.200000,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[600]],
            'distances' => [[8_000]],
        ])]);

        $response = $this->asWebClient($pilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk();

        $response
            ->assertJsonPath('data.0.location_is_current', true)
            ->assertJsonPath('data.0.sharing_status', 'shared')
            ->assertJsonPath('data.0.latitude', '52.2000000')
            ->assertJsonPath('data.0.eta_minutes', 10)
            ->assertJsonPath('data.0.eta_source', 'navigation');

        // When the poisoned timestamp eventually falls inside the wall-clock
        // window it must still be rejected against its server receipt time.
        $this->travel(58)->minutes();
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'latitude' => 52.250000,
            'longitude' => 5.250000,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->asWebClient($pilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonPath('data.0.location_is_current', true)
            ->assertJsonPath('data.0.latitude', '52.2500000')
            ->assertJsonPath('data.0.eta_source', 'navigation');
    }

    public function test_revoking_location_consent_immediately_hides_the_last_coordinate_and_eta(): void
    {
        $pilot = $this->user('revoked-location@example.test', 'Revoked Location Pilot');
        $this->grant($pilot, ['incidents.view']);
        $team = $this->team('REVOKED-LOCATION');
        $incident = $this->incident($pilot, $team, 52.300000, 5.300000, 'REVOKED-LOCATION-001');
        $dispatch = $this->sentDispatch($incident, $pilot);
        $this->acceptedRecipient($dispatch, $pilot);
        LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now(),
        ]);
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'latitude' => 52.100000,
            'longitude' => 5.100000,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->asWebClient($pilot)
            ->deleteJson('/api/incidents/'.$incident->id.'/location/consent')
            ->assertNoContent();
        Http::fake();

        $response = $this->asWebClient($pilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk();

        $response
            ->assertJsonPath('data.0.location_is_current', false)
            ->assertJsonPath('data.0.latitude', null)
            ->assertJsonPath('data.0.longitude', null)
            ->assertJsonPath('data.0.eta_minutes', null)
            ->assertJsonPath('data.0.eta_source', 'unknown');
    }

    public function test_reconsent_does_not_revive_a_coordinate_received_under_the_old_consent(): void
    {
        $this->travelTo(now()->startOfSecond());
        $pilot = $this->user('reconsent-location@example.test', 'Reconsent Location Pilot');
        $this->grant($pilot, ['incidents.view']);
        $team = $this->team('RECONSENT-LOCATION');
        $incident = $this->incident($pilot, $team, 52.300000, 5.300000, 'RECONSENT-LOCATION-001');
        $dispatch = $this->sentDispatch($incident, $pilot);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinute(),
        ]);
        $oldConsentStateVersion = (int) $consent->refresh()->state_version;
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'latitude' => 52.100000,
            'longitude' => 5.100000,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->asWebClient($pilot)
            ->deleteJson('/api/incidents/'.$incident->id.'/location/consent')
            ->assertNoContent();
        $this->travel(1)->seconds();
        $this->asWebClient($pilot)
            ->postJson('/api/incidents/'.$incident->id.'/location/consent')
            ->assertCreated();
        $newConsentStateVersion = (int) $consent->refresh()->state_version;
        $this->assertGreaterThan($oldConsentStateVersion, $newConsentStateVersion);

        // Simulate a request that began under the old consent but only reached
        // persistence after re-consent. Receipt timestamps alone would make it
        // look current; the consent generation must still reject it.
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $oldConsentStateVersion,
            'latitude' => 52.200000,
            'longitude' => 5.200000,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
        Http::fake();

        $this->asWebClient($pilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonPath('data.0.location_is_current', false)
            ->assertJsonPath('data.0.sharing_status', 'consented')
            ->assertJsonPath('data.0.latitude', null)
            ->assertJsonPath('data.0.longitude', null)
            ->assertJsonPath('data.0.eta_minutes', null)
            ->assertJsonPath('data.0.eta_source', 'unknown');
        Http::assertNothingSent();
    }

    public function test_location_update_normalizes_null_recorded_at_and_mobile_identity_stays_legacy_compatible(): void
    {
        $this->travelTo(now()->startOfSecond());
        $pilot = $this->user('legacy-mobile-location@example.test', 'Legacy Mobile Pilot');
        $this->grant($pilot, ['incidents.assigned.view'], operator: true);
        $team = $this->team('LEGACY-MOBILE-LOCATION');
        $incident = $this->incident($pilot, $team, 52.300000, 5.300000, 'LEGACY-MOBILE-LOCATION-001');
        $dispatch = $this->sentDispatch($incident, $pilot);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinute(),
        ])->refresh();

        $this->asOperatorClient($pilot)
            ->postJson('/api/incidents/'.$incident->id.'/location', [
                'latitude' => 52.100000,
                'longitude' => 5.100000,
                'recorded_at' => null,
            ])
            ->assertNoContent();

        $location = LocationUpdate::query()->sole();
        $this->assertNotNull($location->recorded_at);
        $this->assertSame((int) $consent->state_version, (int) $location->consent_state_version);
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[600]],
            'distances' => [[8_000]],
        ])]);

        $this->asOperatorClient($pilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $pilot->id)
            ->assertJsonPath('data.0.user.name', $pilot->name)
            ->assertJsonPath('data.0.user.email', $pilot->email)
            ->assertJsonPath('data.0.user.roles', [])
            ->assertJsonPath('data.0.user.teams', [])
            ->assertJsonPath('data.0.location_is_current', true);

        $this->grant($pilot, ['incidents.view']);
        $this->asWebClient($pilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonMissingPath('data.0.user.email')
            ->assertJsonMissingPath('data.0.user.roles')
            ->assertJsonMissingPath('data.0.user.teams');
    }

    public function test_repeated_location_share_request_keeps_current_active_consent_without_duplicate_push(): void
    {
        Queue::fake();
        $coordinator = $this->user('location-idempotency-coordinator@example.test', 'Location Idempotency Coordinator');
        $team = $this->team('LOCATION-IDEMPOTENCY');
        $pilot = $this->eligiblePilot($team, 'location-idempotency-pilot@example.test', 'Location Idempotency Pilot', 52.100000, 5.100000);
        $incident = $this->incident($coordinator, $team, 52.300000, 5.300000, 'LOCATION-IDEMPOTENCY-001');
        $dispatch = $this->sentDispatch($incident, $coordinator);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinute(),
        ])->refresh();
        $stateVersion = (int) $consent->state_version;
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $stateVersion,
            'latitude' => 52.100000,
            'longitude' => 5.100000,
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $result = app(LocationService::class)->requestSharing($incident, $pilot, $coordinator);

        $this->assertSame(['queued_tokens' => 0, 'user_id' => (string) $pilot->id], $result);
        $this->assertTrue($consent->refresh()->is_active);
        $this->assertSame($stateVersion, (int) $consent->state_version);
        Queue::assertNothingPushed();
    }

    public function test_repeated_location_share_request_wakes_device_when_active_location_is_stale(): void
    {
        Queue::fake();
        $coordinator = $this->user('location-refresh-coordinator@example.test', 'Location Refresh Coordinator');
        $team = $this->team('LOCATION-REFRESH');
        $pilot = $this->eligiblePilot($team, 'location-refresh-pilot@example.test', 'Location Refresh Pilot', 52.100000, 5.100000);
        $incident = $this->incident($coordinator, $team, 52.300000, 5.300000, 'LOCATION-REFRESH-001');
        $dispatch = $this->sentDispatch($incident, $coordinator);
        $this->acceptedRecipient($dispatch, $pilot);
        $consent = LocationSharingConsent::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'is_active' => true,
            'consented_at' => now()->subMinutes(10),
        ])->refresh();
        $stateVersion = (int) $consent->state_version;
        LocationUpdate::query()->create([
            'incident_id' => $incident->id,
            'user_id' => $pilot->id,
            'consent_state_version' => $stateVersion,
            'latitude' => 52.100000,
            'longitude' => 5.100000,
            'recorded_at' => now()->subMinutes(6),
            'created_at' => now()->subMinutes(6),
        ]);

        $result = app(LocationService::class)->requestSharing($incident, $pilot, $coordinator);

        $this->assertSame(['queued_tokens' => 1, 'user_id' => (string) $pilot->id], $result);
        $this->assertTrue($consent->refresh()->is_active);
        $this->assertSame($stateVersion, (int) $consent->state_version);
        Queue::assertPushed(SendFcmNotification::class, function (SendFcmNotification $job) use ($incident, $pilot): bool {
            return $job->messageType === 'location_share_request'
                && ($job->data['incident_id'] ?? null) === $incident->id
                && $job->fcmTokenId === $pilot->fcmTokens()->sole()->id;
        });
    }

    public function test_live_location_access_enforces_operator_self_scope_assignment_and_permission(): void
    {
        $viewer = $this->user('scope-viewer@example.test', 'Scope Viewer');
        $this->grant($viewer, ['incidents.view']);
        $firstPilot = $this->user('scope-first@example.test', 'Scope First Pilot');
        $secondPilot = $this->user('scope-second@example.test', 'Scope Second Pilot');
        $this->grant($firstPilot, ['incidents.assigned.view'], operator: true);
        $this->grant($secondPilot, ['incidents.assigned.view'], operator: true);
        $team = $this->team('LIVE-SCOPE');
        $incident = $this->incident($viewer, $team, 52.300000, 5.300000, 'LIVE-SCOPE-001');
        $dispatch = $this->sentDispatch($incident, $viewer);
        $this->acceptedRecipient($dispatch, $firstPilot);
        $this->acceptedRecipient($dispatch, $secondPilot);
        foreach ([$firstPilot, $secondPilot] as $index => $pilot) {
            LocationSharingConsent::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $pilot->id,
                'is_active' => true,
                'consented_at' => now()->subMinute(),
            ]);
            LocationUpdate::query()->create([
                'incident_id' => $incident->id,
                'user_id' => $pilot->id,
                'latitude' => 52.100000 + ($index / 100),
                'longitude' => 5.100000 + ($index / 100),
                'recorded_at' => now(),
                'created_at' => now(),
            ]);
        }
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'durations' => [[300]],
            'distances' => [[5_000]],
        ])]);

        $this->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertUnauthorized();
        $this->asOperatorClient($firstPilot)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $firstPilot->id);

        $unassigned = $this->incident($viewer, $team, 52.400000, 5.400000, 'LIVE-SCOPE-UNASSIGNED');
        $this->asOperatorClient($firstPilot)
            ->getJson('/api/incidents/'.$unassigned->id.'/live-locations')
            ->assertForbidden();

        $unauthorizedViewer = $this->user('scope-unauthorized@example.test', 'Scope Unauthorized');
        $this->asWebClient($unauthorizedViewer)
            ->getJson('/api/incidents/'.$incident->id.'/live-locations')
            ->assertForbidden();
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

    /** @param list<string> $permissionNames */
    private function grant(User $user, array $permissionNames, bool $operator = false): void
    {
        $role = Role::query()->create([
            'name' => 'routing-role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Routing endpoint test role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => ! $operator,
        ]);
        $permissions = collect($permissionNames)->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            ['category' => 'test', 'display_name' => $name, 'description' => 'Routing endpoint test permission'],
        ));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id, ['created_at' => now()]);
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

    private function eligiblePilot(
        Team $team,
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
        $team->users()->attach($pilot->id, ['created_at' => now()]);
        $token = 'routing-token-'.$pilot->id;
        FcmToken::query()->create([
            'user_id' => $pilot->id,
            'device_id' => 'routing-device-'.$pilot->id,
            'token' => $token,
            'token_hash' => hash('sha256', $token),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        return $pilot;
    }

    private function incident(
        User $creator,
        Team $team,
        float $latitude,
        float $longitude,
        string $reference,
    ): Incident {
        $incident = Incident::query()->create([
            'reference' => $reference,
            'title' => 'Routing endpoint testincident',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'team_id' => $team->id,
            'opened_at' => now(),
        ]);
        $incident->teams()->attach($team->id, ['created_at' => now()]);

        return $incident;
    }

    private function sentDispatch(Incident $incident, User $creator): DispatchRequest
    {
        return DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $creator->id,
            'requested_by_name' => $creator->name,
            'requested_by_email' => $creator->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Routing endpoint testmelding',
            'sent_at' => now(),
        ]);
    }

    private function acceptedRecipient(DispatchRequest $dispatch, User $pilot): void
    {
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'response_status' => 'accepted',
            'responded_at' => now(),
            'notified_at' => now(),
        ]);
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Routing endpoint test client', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function asOperatorClient(User $user): static
    {
        $token = $user->createToken('Routing operator test client', ['*', 'client:operator'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function forgetRoutingSingletons(): void
    {
        app()->forgetInstance(RouteGeometryService::class);
        app()->forgetInstance(RouteGeometryProvider::class);
        app()->forgetInstance(RoutingService::class);
        app()->forgetInstance(RoutingProvider::class);
    }

    /** @return list<string> */
    private function lockingTablesFromQueryLog(): array
    {
        $tables = ['incidents', 'dispatch_requests', 'dispatch_recipients', 'dispatch_push_outbox'];

        return collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains(strtolower($query), 'for update'))
            ->map(function (string $query) use ($tables): ?string {
                foreach ($tables as $table) {
                    if (preg_match('/\bfrom\s+["`]?'.preg_quote($table, '/').'["`]?\b/i', $query) === 1) {
                        return $table;
                    }
                }

                return null;
            })
            ->filter()
            ->values()
            ->all();
    }
}
