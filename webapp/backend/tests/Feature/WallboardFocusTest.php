<?php

namespace Tests\Feature;

use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardSession;
use App\Services\WallboardFocusService;
use App\Services\WallboardSessionService;
use App\Services\WallboardStateService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class WallboardFocusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_focus_configuration_has_safe_defaults_and_strict_duration_and_key_validation(): void
    {
        $defaults = WallboardConfiguration::defaults();

        $this->assertSame([
            'preannouncement' => [
                'enabled' => true,
                'duration_seconds' => 120,
                'show_response_feed' => true,
            ],
            'real_alarm' => [
                'enabled' => true,
                'duration_seconds' => 30,
                'show_response_feed' => true,
            ],
            'test_alarm' => [
                'enabled' => true,
                'duration_seconds' => 300,
                'show_response_feed' => true,
            ],
        ], $defaults['focus']);

        $normalized = WallboardConfiguration::normalize([
            'focus' => [
                'preannouncement' => ['duration_seconds' => 5],
                'real_alarm' => ['duration_seconds' => 3600],
                'test_alarm' => ['enabled' => false, 'show_response_feed' => false],
            ],
        ]);

        $this->assertSame(5, $normalized['focus']['preannouncement']['duration_seconds']);
        $this->assertSame(3600, $normalized['focus']['real_alarm']['duration_seconds']);
        $this->assertFalse($normalized['focus']['test_alarm']['enabled']);
        $this->assertFalse($normalized['focus']['test_alarm']['show_response_feed']);

        foreach ([
            ['focus' => ['preannouncement' => ['duration_seconds' => 4]]],
            ['focus' => ['real_alarm' => ['duration_seconds' => 3601]]],
            ['focus' => ['test_alarm' => ['duration_seconds' => 0]]],
            ['focus' => ['unexpected' => []]],
            ['focus' => ['real_alarm' => ['unexpected' => true]]],
        ] as $invalidConfiguration) {
            $this->assertConfigurationIsRejected($invalidConfiguration);
        }
    }

    public function test_only_an_explicit_preannouncement_creates_focus_and_it_expires_at_the_configured_boundary(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        $dispatcher = $this->user('focus-pre-dispatcher@example.test', 'Focus dispatcher');
        $wallboard = $this->wallboard();
        $incident = $this->incident($dispatcher, 'FOCUS-PRE', 'active', false);
        $dispatch = $this->dispatch($incident, $dispatcher, 'draft');
        $service = app(WallboardStateService::class);

        $this->assertNull($service->state($wallboard)['operational_summary']['focus']);
        $this->assertNull($service->control($wallboard)['focus']);

        $dispatch->forceFill(['preannounced_at' => now()])->save();
        $stateFocus = $service->state($wallboard)['operational_summary']['focus'];
        $controlFocus = $service->control($wallboard)['focus'];

        $this->assertIsArray($stateFocus);
        $this->assertSame('preannouncement', $stateFocus['kind']);
        $this->assertSame($dispatch->id, $stateFocus['dispatch_id']);
        $this->assertSame($incident->id, $stateFocus['incident_id']);
        $this->assertSame('2026-07-20T10:00:00+02:00', $stateFocus['started_at']);
        $this->assertSame('2026-07-20T10:02:00+02:00', $stateFocus['expires_at']);
        $this->assertTrue($stateFocus['visible']);
        $this->assertIsString($stateFocus['focus_id']);
        $this->assertNotSame('', $stateFocus['focus_id']);
        $this->assertSame($stateFocus['focus_id'], $controlFocus['focus_id']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:01:59', 'Europe/Amsterdam'));
        $this->assertSame(
            $stateFocus['focus_id'],
            $service->state($wallboard)['operational_summary']['focus']['focus_id'],
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:02:00', 'Europe/Amsterdam'));
        $this->assertNull($service->state($wallboard)['operational_summary']['focus']);
        $this->assertNull($service->control($wallboard)['focus']);
    }

    public function test_test_alarm_focus_expires_after_its_own_playlist_duration(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 11:00:00', 'Europe/Amsterdam'));
        $dispatcher = $this->user('focus-test-dispatcher@example.test', 'Test dispatcher');
        $wallboard = $this->wallboard();
        $incident = $this->incident($dispatcher, 'FOCUS-TEST', 'active', true);
        $dispatch = $this->dispatch($incident, $dispatcher, 'sent', now());
        $confirmed = $this->user('focus-test-confirmed@example.test', 'Test bevestigd');
        $missed = $this->user('focus-test-missed@example.test', 'Test gemist');
        $this->recipient($dispatch, $confirmed, 'accepted', now());
        $this->recipient($dispatch, $missed, 'no_response', now());
        $service = app(WallboardStateService::class);

        $focus = $service->state($wallboard)['operational_summary']['focus'];

        $this->assertSame('test_alarm', $focus['kind']);
        $this->assertSame($dispatch->id, $focus['dispatch_id']);
        $this->assertNull($focus['location_label']);
        $this->assertNull($focus['pilot_counts']);
        $this->assertSame(1, $focus['responses']['counts']['accepted']);
        $this->assertSame(1, $focus['responses']['counts']['no_response']);
        $this->assertSame(
            ['accepted', 'no_response'],
            collect($focus['responses']['items'])->pluck('response_status')->sort()->values()->all(),
        );
        $this->assertSame('2026-07-20T11:05:00+02:00', $focus['expires_at']);
        $this->assertTrue($focus['visible']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 11:04:59', 'Europe/Amsterdam'));
        $this->assertSame($focus['focus_id'], $service->control($wallboard)['focus']['focus_id']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 11:05:00', 'Europe/Amsterdam'));
        $this->assertNull($service->state($wallboard)['operational_summary']['focus']);
    }

    public function test_real_alarm_focus_alternates_with_every_complete_playlist_page_deterministically(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', 'Europe/Amsterdam'));
        $dispatcher = $this->user('focus-real-dispatcher@example.test', 'Real dispatcher');
        $wallboard = $this->wallboard();
        $incident = $this->incident($dispatcher, 'FOCUS-REAL', 'dispatching', false);
        $dispatch = $this->dispatch($incident, $dispatcher, 'sent', now());
        $service = app(WallboardStateService::class);

        $firstFocus = $service->state($wallboard)['operational_summary']['focus'];
        $this->assertSame('real_alarm', $firstFocus['kind']);
        $this->assertSame($dispatch->id, $firstFocus['dispatch_id']);
        $this->assertTrue($firstFocus['visible']);
        $this->assertNull($firstFocus['expires_at']);
        $this->assertNull($firstFocus['playlist_page_id']);
        $this->assertSame('2026-07-20T12:00:30+02:00', $firstFocus['next_change_at']);

        $configurationWithoutPages = WallboardConfiguration::defaults();
        $configurationWithoutPages['pages'] = [];
        $focusWithoutPlaylist = app(WallboardFocusService::class)->resolve($configurationWithoutPages);
        $this->assertTrue($focusWithoutPlaylist['visible']);
        $this->assertNull($focusWithoutPlaylist['playlist_page_id']);
        $this->assertNull($focusWithoutPlaylist['next_change_at']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:29', 'Europe/Amsterdam'));
        $duringFocus = $service->control($wallboard)['focus'];
        $this->assertTrue($duringFocus['visible']);
        $this->assertSame($firstFocus['focus_id'], $duringFocus['focus_id']);
        $this->assertSame('2026-07-20T12:00:30+02:00', $duringFocus['next_change_at']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:30', 'Europe/Amsterdam'));
        $map = $service->control($wallboard)['focus'];
        $this->assertFalse($map['visible']);
        $this->assertSame('map', $map['playlist_page_id']);
        $this->assertSame('2026-07-20T12:00:50+02:00', $map['next_change_at']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:49', 'Europe/Amsterdam'));
        $this->assertSame('map', $service->control($wallboard)['focus']['playlist_page_id']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:50', 'Europe/Amsterdam'));
        $summary = $service->control($wallboard)['focus'];
        $this->assertFalse($summary['visible']);
        $this->assertSame('summary', $summary['playlist_page_id']);
        $this->assertSame('2026-07-20T12:01:00+02:00', $summary['next_change_at']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:01:00', 'Europe/Amsterdam'));
        $nextFocus = $service->control($wallboard)['focus'];
        $this->assertTrue($nextFocus['visible']);
        $this->assertNull($nextFocus['playlist_page_id']);
        $this->assertSame($firstFocus['focus_id'], $nextFocus['focus_id']);
        $this->assertSame('2026-07-20T12:01:30+02:00', $nextFocus['next_change_at']);
    }

    public function test_an_active_real_alarm_is_never_covered_by_a_newer_preannouncement_or_test_alarm(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 13:00:00', 'Europe/Amsterdam'));
        $dispatcher = $this->user('focus-priority-dispatcher@example.test', 'Priority dispatcher');
        $wallboard = $this->wallboard();
        $realIncident = $this->incident($dispatcher, 'FOCUS-PRIORITY-REAL', 'dispatching', false);
        $realDispatch = $this->dispatch($realIncident, $dispatcher, 'sent', now());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 13:00:01', 'Europe/Amsterdam'));
        $preIncident = $this->incident($dispatcher, 'FOCUS-PRIORITY-PRE', 'active', false);
        $this->dispatch($preIncident, $dispatcher, 'draft', preannouncedAt: now());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 13:00:02', 'Europe/Amsterdam'));
        $testIncident = $this->incident($dispatcher, 'FOCUS-PRIORITY-TEST', 'active', true);
        $testDispatch = $this->dispatch($testIncident, $dispatcher, 'sent', now());
        $service = app(WallboardStateService::class);

        $focus = $service->state($wallboard)['operational_summary']['focus'];
        $this->assertSame('real_alarm', $focus['kind']);
        $this->assertSame($realDispatch->id, $focus['dispatch_id']);

        $realIncident->forceFill(['status' => 'resolved', 'closed_at' => now()])->save();
        $afterRealAlarm = $service->state($wallboard)['operational_summary']['focus'];
        $this->assertSame('test_alarm', $afterRealAlarm['kind']);
        $this->assertSame($testDispatch->id, $afterRealAlarm['dispatch_id']);
    }

    public function test_response_feed_is_safe_deduplicated_bounded_and_can_be_disabled_without_leaking_names(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 14:00:00', 'Europe/Amsterdam'));
        $dispatcher = $this->user('focus-feed-dispatcher@example.test', 'Feed dispatcher');
        $incident = $this->incident($dispatcher, 'FOCUS-FEED', 'dispatching', false);
        $firstDispatch = $this->dispatch($incident, $dispatcher, 'sent', now());
        $secondDispatch = $this->dispatch($incident, $dispatcher, 'escalated', now());

        $users = [];
        for ($index = 0; $index < 26; $index++) {
            $number = str_pad((string) $index, 2, '0', STR_PAD_LEFT);
            $users[] = $this->user("focus-feed-{$number}@example.test", "Focus pilot {$number}");
        }

        foreach ($users as $index => $user) {
            $status = match (true) {
                $index < 12 => 'accepted',
                $index < 24 => 'declined',
                $index === 24 => 'no_response',
                default => 'pending',
            };
            $respondedAt = in_array($status, ['accepted', 'declined'], true) ? now() : null;
            $this->recipient($firstDispatch, $user, $status, $respondedAt);
        }
        $this->recipient($secondDispatch, $users[0], 'accepted', now());

        $service = app(WallboardStateService::class);
        $wallboard = $this->wallboard();
        $focus = $service->state($wallboard)['operational_summary']['focus'];
        $responses = $focus['responses'];

        $this->assertSame(26, $responses['counts']['targeted']);
        $this->assertSame(26, $responses['counts']['contacted']);
        $this->assertSame(1, $responses['counts']['pending']);
        $this->assertSame(12, $responses['counts']['accepted']);
        $this->assertSame(12, $responses['counts']['declined']);
        $this->assertSame(1, $responses['counts']['no_response']);
        $this->assertCount(24, $responses['items']);
        $this->assertCount(24, collect($responses['items'])->pluck('name')->unique()->all());

        foreach ($responses['items'] as $item) {
            $keys = array_keys($item);
            sort($keys);
            $this->assertSame(['name', 'responded_at', 'response_status'], $keys);
        }

        $serializedResponses = json_encode($responses, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('@example.test', $serializedResponses);
        $this->assertStringNotContainsString('FEED-RESPONSE-NOTE-SECRET', $serializedResponses);
        $this->assertStringNotContainsString((string) $users[0]->id, $serializedResponses);

        $feedOffWallboard = $this->wallboard(showRealResponses: false);
        $stateFocus = $service->state($feedOffWallboard)['operational_summary']['focus'];
        $controlFocus = $service->control($feedOffWallboard)['focus'];

        $this->assertNull($stateFocus['responses']);
        $this->assertNull($controlFocus['responses']);
        $serializedFocus = json_encode([$stateFocus, $controlFocus], JSON_THROW_ON_ERROR);
        foreach ($users as $user) {
            $this->assertStringNotContainsString($user->name, $serializedFocus);
        }
    }

    public function test_preannouncement_exposes_live_selected_pilot_counts_and_current_privacy_limited_responses(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 15:00:00', 'Europe/Amsterdam'));
        $dispatcher = $this->user('focus-pre-count-dispatcher@example.test', 'Pre count dispatcher');
        $incident = $this->incident($dispatcher, 'FOCUS-PRE-COUNTS', 'active', false);
        $dispatch = $this->dispatch($incident, $dispatcher, 'draft', preannouncedAt: now());
        $available = $this->user('focus-pre-available@example.test', 'Beschikbare piloot');
        $unavailable = $this->user('focus-pre-unavailable@example.test', 'Niet beschikbare piloot');
        $pending = $this->user('focus-pre-pending@example.test', 'Wachtende piloot');
        $noResponse = $this->user('focus-pre-no-response@example.test', 'Niet reagerende piloot');

        $this->recipient($dispatch, $available, 'accepted', now());
        $this->recipient($dispatch, $unavailable, 'declined', now());
        $pendingRecipient = $this->recipient($dispatch, $pending, 'pending', null);
        $this->recipient($dispatch, $noResponse, 'no_response', now());

        $wallboard = $this->wallboard();
        $cookie = $this->wallboardCredential($wallboard);
        $focusResponse = $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.focus.kind', 'preannouncement')
            ->assertJsonPath('data.focus.pilot_counts.available', 1)
            ->assertJsonPath('data.focus.pilot_counts.relevant', 4)
            ->assertJsonPath('data.focus.pilot_counts.contacted', 4)
            ->assertJsonPath('data.focus.responses.counts.accepted', 1);
        $focus = $focusResponse->json('data.focus');

        $this->assertSame('preannouncement', $focus['kind']);
        $this->assertSame('Utrecht', $focus['location_label']);
        $this->assertSame([
            'available' => 1,
            'relevant' => 4,
            'contacted' => 4,
        ], $focus['pilot_counts']);
        $this->assertSame(4, $focus['responses']['counts']['targeted']);
        $this->assertSame(4, $focus['responses']['counts']['contacted']);
        $this->assertSame(
            ['accepted', 'declined', 'no_response'],
            collect($focus['responses']['items'])->pluck('response_status')->sort()->values()->all(),
        );

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 15:00:02', 'Europe/Amsterdam'));
        $pendingRecipient->forceFill([
            'response_status' => 'accepted',
            'responded_at' => now(),
        ])->save();
        $updatedResponse = $this->wallboardGet('/api/wallboard/control', $cookie)
            ->assertOk()
            ->assertJsonPath('data.focus.pilot_counts.available', 2)
            ->assertJsonPath('data.focus.responses.counts.accepted', 2)
            ->assertJsonCount(4, 'data.focus.responses.items');
        $updated = $updatedResponse->json('data.focus');

        $this->assertSame(2, $updated['pilot_counts']['available']);
        $this->assertSame(2, $updated['responses']['counts']['accepted']);
        $this->assertCount(4, $updated['responses']['items']);
        foreach ($updated['responses']['items'] as $item) {
            $keys = array_keys($item);
            sort($keys);
            $this->assertSame(['name', 'responded_at', 'response_status'], $keys);
        }
        $serialized = $updatedResponse->getContent();
        foreach (['@example.test', 'FEED-RESPONSE-NOTE-SECRET', (string) $available->id] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $serialized);
        }
    }

    /** @param array<string, mixed> $configuration */
    private function assertConfigurationIsRejected(array $configuration): void
    {
        try {
            WallboardConfiguration::normalize($configuration);
        } catch (ValidationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('De ongeldige focusconfiguratie is niet afgewezen.');
    }

    private function wallboard(bool $showRealResponses = true): Wallboard
    {
        return Wallboard::query()->create([
            'name' => 'Focus wallboard',
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'configuration' => WallboardConfiguration::normalize([
                'rotation_enabled' => true,
                'pages' => [
                    [
                        'id' => 'map',
                        'name' => 'Kaart',
                        'type' => 'map',
                        'duration_seconds' => 20,
                        'options' => [],
                    ],
                    [
                        'id' => 'summary',
                        'name' => 'Samenvatting',
                        'type' => 'summary',
                        'duration_seconds' => 10,
                        'options' => [],
                    ],
                ],
                'focus' => [
                    'real_alarm' => ['show_response_feed' => $showRealResponses],
                ],
            ]),
            'is_enabled' => true,
            'rotation_started_at' => now(),
        ]);
    }

    private function wallboardCredential(Wallboard $wallboard): string
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $session = WallboardSession::query()->create([
            'wallboard_id' => $wallboard->id,
            'token_hash' => hash_hmac('sha256', $secret, (string) config('app.key')),
            'last_seen_at' => now(),
            'last_rotated_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        return $session->id.'.'.$secret;
    }

    private function wallboardGet(string $uri, string $cookie): TestResponse
    {
        Auth::forgetGuards();
        $this->withoutMiddleware(EncryptCookies::class);

        return $this->disableCookieEncryption()
            ->withUnencryptedCookie(WallboardSessionService::COOKIE_NAME, $cookie)
            ->withCredentials()
            ->withHeaders(['Origin' => 'https://dis.example.test'])
            ->getJson($uri);
    }

    private function user(string $email, string $name): User
    {
        return User::query()->create([
            'name' => $name,
            'first_name' => strtok($name, ' ') ?: $name,
            'last_name' => 'Focus',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
        ]);
    }

    private function incident(
        User $creator,
        string $reference,
        string $status,
        bool $isTest,
    ): Incident {
        return Incident::query()->create([
            'reference' => $reference,
            'title' => 'Wallboardfocus '.$reference,
            'description' => 'FOCUS-INCIDENT-DESCRIPTION-SECRET',
            'internal_notes' => 'FOCUS-INCIDENT-NOTES-SECRET',
            'priority' => 'high',
            'status' => $status,
            'is_test' => $isTest,
            'location_label' => 'Utrecht',
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    private function dispatch(
        Incident $incident,
        User $dispatcher,
        string $status,
        ?\DateTimeInterface $sentAt = null,
        ?\DateTimeInterface $preannouncedAt = null,
    ): DispatchRequest {
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $dispatcher->id,
            'requested_by_name' => $dispatcher->name,
            'requested_by_email' => $dispatcher->email,
            'status' => $status,
            'priority' => $incident->priority,
            'message' => 'FOCUS-DISPATCH-MESSAGE-SECRET',
            'sent_at' => $sentAt,
        ]);

        if ($preannouncedAt !== null) {
            $dispatch->forceFill(['preannounced_at' => $preannouncedAt])->save();
        }

        return $dispatch;
    }

    private function recipient(
        DispatchRequest $dispatch,
        User $user,
        string $responseStatus,
        ?\DateTimeInterface $respondedAt,
    ): DispatchRecipient {
        return DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'response_status' => $responseStatus,
            'response_note' => 'FEED-RESPONSE-NOTE-SECRET',
            'notified_at' => now(),
            'responded_at' => $respondedAt,
        ]);
    }
}
