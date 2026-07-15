<?php

namespace Tests\Feature;

use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\TransientToken;
use Tests\TestCase;

final class OperationalAlarmRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_authenticated_reads_and_writes_have_independent_counters(): void
    {
        $resolve = RateLimiter::limiter('authenticated');
        $this->assertNotNull($resolve);

        $readLimits = $resolve(Request::create('/api/auth/me', 'GET', server: ['REMOTE_ADDR' => '198.51.100.20']));
        $writeLimits = $resolve(Request::create('/api/status/me', 'PATCH', server: ['REMOTE_ADDR' => '198.51.100.20']));

        $this->assertSame([600, 1200], array_column($readLimits, 'maxAttempts'));
        $this->assertSame([120, 240], array_column($writeLimits, 'maxAttempts'));
        $this->assertSame([], array_intersect(array_column($readLimits, 'key'), array_column($writeLimits, 'key')));
        $this->assertStringContainsString('authenticated:read:', $readLimits[0]->key);
        $this->assertStringContainsString('authenticated:write:', $writeLimits[0]->key);
    }

    public function test_alarm_limits_are_isolated_per_device_and_shared_per_user(): void
    {
        $user = $this->user('device-limits@example.test');
        $firstToken = $this->accessToken($user, 'First operator device');
        $secondToken = $this->accessToken($user, 'Second operator device');

        $firstLimits = $this->resolveLimits('alarm-read', $user->fresh(), $firstToken);
        $secondLimits = $this->resolveLimits('alarm-read', $user->fresh(), $secondToken);

        $this->assertSame([1200, 3600], array_column($firstLimits, 'maxAttempts'));
        $this->assertNotSame($firstLimits[0]->key, $secondLimits[0]->key);
        $this->assertSame($firstLimits[1]->key, $secondLimits[1]->key);
        $this->assertCount(2, $firstLimits, 'Operational traffic must not have a shared carrier-NAT counter.');
    }

    public function test_general_authenticated_limits_are_not_shared_by_users_behind_one_nat(): void
    {
        $firstUser = $this->user('first-nat-user@example.test');
        $secondUser = $this->user('second-nat-user@example.test');
        $firstLimits = $this->resolveLimits('authenticated', $firstUser, $this->accessToken($firstUser, 'First NAT device'));
        $secondLimits = $this->resolveLimits('authenticated', $secondUser, $this->accessToken($secondUser, 'Second NAT device'));

        $this->assertCount(2, $firstLimits);
        $this->assertSame([], array_intersect(array_column($firstLimits, 'key'), array_column($secondLimits, 'key')));
    }

    public function test_alarm_limiter_supports_stateful_web_sessions_without_a_persisted_token(): void
    {
        $user = $this->user('web-session-limits@example.test');
        $user->withAccessToken(new TransientToken);
        $request = Request::create('/api/incidents', 'GET', server: ['REMOTE_ADDR' => '203.0.113.30']);
        $session = app('session')->driver();
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(static fn (): User => $user);

        $resolve = RateLimiter::limiter('alarm-read');
        $this->assertNotNull($resolve);
        $limits = $resolve($request);

        $this->assertCount(2, $limits);
        $this->assertStringStartsWith('alarm-read:client:', $limits[0]->key);
        $this->assertStringNotContainsString($session->getId(), $limits[0]->key);
    }

    public function test_critical_alarm_routes_do_not_use_the_general_authenticated_bucket(): void
    {
        $routes = [
            ['GET', '/api/incidents', 'throttle:alarm-read'],
            ['GET', '/api/incidents/01J00000000000000000000000', 'throttle:alarm-read'],
            ['POST', '/api/dispatches/01J00000000000000000000000/respond', 'throttle:alarm-response'],
            ['POST', '/api/dispatches/01J00000000000000000000000/send', 'throttle:alarm-dispatch'],
            ['POST', '/api/test-alert', 'throttle:reachability-test'],
            ['PATCH', '/api/status/me', 'throttle:operational-action'],
            ['POST', '/api/devices/heartbeat', 'throttle:operational-telemetry'],
            ['POST', '/api/incidents/01J00000000000000000000000/location', 'throttle:operational-telemetry'],
        ];

        foreach ($routes as [$method, $uri, $expectedLimiter]) {
            $route = app('router')->getRoutes()->match(Request::create($uri, $method));
            $middleware = $route->gatherMiddleware();
            $resolvedThrottles = array_values(array_filter(
                app('router')->gatherRouteMiddleware($route),
                static fn (string $name): bool => str_starts_with($name, ThrottleRequests::class.':'),
            ));

            $this->assertContains($expectedLimiter, $middleware, $method.' '.$uri.' must use its operational limiter.');
            $this->assertSame(
                [ThrottleRequests::class.':'.str($expectedLimiter)->after('throttle:')],
                $resolvedThrottles,
                $method.' '.$uri.' must resolve to exactly one operational throttle.',
            );
            $this->assertContains(
                'throttle:authenticated',
                $route->excludedMiddleware(),
                $method.' '.$uri.' must exclude the general limiter inherited from its route group.',
            );
        }
    }

    public function test_exhausted_general_bucket_does_not_block_alarm_read_or_acknowledgement(): void
    {
        Queue::fake();
        $operator = $this->user('alarm-response@example.test');
        $this->grant($operator, ['incidents.assigned.view'], operator: true, admin: false);
        $coordinator = $this->user('alarm-coordinator@example.test');
        [$incident, $dispatch] = $this->dispatch($coordinator, $operator);
        $token = $operator->createToken('Alarm operator', ['*', 'client:operator'], now()->addHour())->plainTextToken;

        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute(1)
            ->by('exhausted-general:'.$request->user()?->getAuthIdentifier()));

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(429);

        $this->withToken($token)
            ->getJson('/api/incidents?active_alarms=true')
            ->assertOk()
            ->assertJsonPath('data.0.id', $incident->id);

        $this->withToken($token)
            ->postJson('/api/dispatches/'.$dispatch->id.'/respond', ['response' => 'accepted'])
            ->assertNoContent();

        $this->assertDatabaseHas('dispatch_recipients', [
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $operator->id,
            'response_status' => 'accepted',
        ]);
    }

    public function test_exhausted_general_bucket_does_not_block_sending_a_test_alarm(): void
    {
        Queue::fake();
        $dispatcher = $this->user('alarm-dispatch@example.test', pushEnabled: true);
        $this->grant($dispatcher, ['incidents.dispatch.manage'], operator: false, admin: true);
        $this->fcmToken($dispatcher);
        $token = $dispatcher->createToken('Alarm dispatcher', ['*', 'client:web'], now()->addHour())->plainTextToken;

        RateLimiter::for('authenticated', fn (Request $request) => Limit::perMinute(1)
            ->by('exhausted-general:'.$request->user()?->getAuthIdentifier()));

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(429);

        $this->withToken($token)
            ->postJson('/api/test-alert', ['scope' => 'self'])
            ->assertCreated()
            ->assertJsonPath('meta.scope', 'self')
            ->assertJsonPath('meta.recipient_count', 1);
    }

    private function user(string $email, bool $pushEnabled = false): User
    {
        return User::query()->create([
            'name' => 'Alarm Rate Limit User',
            'first_name' => 'Alarm',
            'last_name' => 'Operator',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => $pushEnabled,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @param list<string> $permissionNames */
    private function grant(User $user, array $permissionNames, bool $operator, bool $admin): void
    {
        $role = Role::query()->create([
            'name' => 'alarm-rate-'.strtolower((string) str()->ulid()),
            'display_name' => 'Alarm rate test role',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
        $permissions = collect($permissionNames)->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            ['category' => 'test', 'display_name' => $name, 'description' => 'Test permission'],
        ));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    private function accessToken(User $user, string $name): PersonalAccessToken
    {
        $plainTextToken = $user->createToken($name, ['*', 'client:operator'], now()->addHour())->plainTextToken;
        $token = PersonalAccessToken::findToken($plainTextToken);
        $this->assertInstanceOf(PersonalAccessToken::class, $token);

        return $token;
    }

    /** @return array<int, Limit> */
    private function resolveLimits(string $name, User $user, PersonalAccessToken $token): array
    {
        $user->withAccessToken($token);
        $request = Request::create('/api/incidents', 'GET', server: ['REMOTE_ADDR' => '192.0.2.40']);
        $request->setUserResolver(static fn (): User => $user);
        $resolve = RateLimiter::limiter($name);
        $this->assertNotNull($resolve);

        return $resolve($request);
    }

    /** @return array{Incident, DispatchRequest} */
    private function dispatch(User $coordinator, User $operator): array
    {
        $incident = Incident::query()->create([
            'reference' => 'RATE-'.strtoupper(substr((string) str()->ulid(), -8)),
            'title' => 'Operationeel alarm',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'created_by' => $coordinator->id,
            'created_by_name' => $coordinator->name,
            'created_by_email' => $coordinator->email,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $coordinator->id,
            'requested_by_name' => $coordinator->name,
            'requested_by_email' => $coordinator->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Operationele melding',
            'sent_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $operator->id,
            'user_name' => $operator->name,
            'user_email' => $operator->email,
            'response_status' => 'pending',
            'notified_at' => now(),
        ]);

        return [$incident, $dispatch];
    }

    private function fcmToken(User $user): void
    {
        $value = 'alarm-rate-fcm-'.strtolower((string) str()->ulid());
        FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'alarm-rate-device',
            'token' => $value,
            'token_hash' => hash('sha256', $value),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        Auth::forgetGuards();
    }
}
