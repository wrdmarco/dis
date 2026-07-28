<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\CalendarEventRegistration;
use App\Models\CalendarGroup;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Repositories\CalendarEventRepository;
use App\Services\UserService;
use App\Services\WebSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CalendarGroupRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_ORIGIN = 'https://dis.example.test';

    public function test_migration_backfills_everyone_and_dynamic_legacy_team_groups(): void
    {
        $migration = require database_path(
            'migrations/2026_07_28_000004_create_calendar_groups_and_registrations.php',
        );
        $migration->down();

        $team = $this->team('LEG', 'Bestaand team');
        $everyoneEvent = CalendarEvent::query()->create([
            'title' => 'Bestaand algemeen agenda-item',
            'type' => 'meeting',
            'starts_at' => now()->addDay(),
        ]);
        $teamEvent = CalendarEvent::query()->create([
            'title' => 'Bestaand teamagenda-item',
            'type' => 'meeting',
            'starts_at' => now()->addDays(2),
            'team_id' => $team->id,
        ]);

        $migration->up();

        $everyone = CalendarGroup::query()->where('is_everyone', true)->sole();
        $this->assertSame('Iedereen', $everyone->name);
        $this->assertDatabaseHas('calendar_event_group', [
            'calendar_event_id' => $everyoneEvent->id,
            'calendar_group_id' => $everyone->id,
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $everyoneEvent->id,
            'audience_scope' => CalendarEvent::AUDIENCE_SCOPE_EVERYONE,
            'registration_enabled' => false,
            'max_participants' => null,
        ]);

        $legacyGroup = CalendarGroup::query()
            ->where('legacy_team_id', $team->id)
            ->sole();
        $this->assertFalse($legacyGroup->is_everyone);
        $this->assertDatabaseHas('calendar_group_team', [
            'calendar_group_id' => $legacyGroup->id,
            'team_id' => $team->id,
        ]);
        $this->assertDatabaseHas('calendar_event_group', [
            'calendar_event_id' => $teamEvent->id,
            'calendar_group_id' => $legacyGroup->id,
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $teamEvent->id,
            'audience_scope' => CalendarEvent::AUDIENCE_SCOPE_GROUPS,
        ]);
    }

    public function test_group_management_requires_both_permissions_and_a_stateful_web_session(): void
    {
        $everyone = $this->everyoneGroup();
        $missingView = $this->user('calendar-groups-no-view@example.test');
        $this->grant($missingView, ['calendar.groups.manage']);
        $deletedUser = $this->user('calendar-groups-deleted@example.test');
        $deletedUser->delete();

        $this->asStatefulWebClient($missingView)
            ->getJson('/api/calendar-groups')
            ->assertForbidden();

        $manager = $this->user('calendar-groups-manager@example.test');
        $this->grant($manager, ['calendar.view', 'calendar.groups.manage']);

        foreach (['client:web', 'client:operator'] as $ability) {
            $this->asBearerClient($manager, $ability)
                ->getJson('/api/calendar-groups')
                ->assertForbidden()
                ->assertJsonPath('error.code', 'stateful_web_session_required');
        }

        $response = $this->asStatefulWebClient($manager)
            ->getJson('/api/calendar-groups')
            ->assertOk();
        $everyonePayload = collect($response->json('data'))
            ->firstWhere('id', (string) $everyone->id);
        $this->assertIsArray($everyonePayload);
        $this->assertTrue($everyonePayload['is_everyone']);
        $this->assertSame(
            User::query()->count(),
            $everyonePayload['effective_member_count'],
        );
        $team = $this->team('SYS', 'Systeemgroepmutatietest');

        $this->asStatefulWebClient($manager)
            ->patchJson('/api/calendar-groups/'.$everyone->id, [
                'name' => 'Gewijzigd',
                'description' => null,
                'user_ids' => [$manager->id],
                'team_ids' => [$team->id],
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'calendar_group_system_protected');
        $this->asStatefulWebClient($manager)
            ->deleteJson('/api/calendar-groups/'.$everyone->id)
            ->assertConflict()
            ->assertJsonPath('error.code', 'calendar_group_system_protected');

        $this->assertDatabaseHas('calendar_groups', [
            'id' => $everyone->id,
            'name' => 'Iedereen',
            'is_everyone' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_group_membership_unions_direct_and_current_team_members_without_duplicates(): void
    {
        $manager = $this->user('calendar-union-manager@example.test', 'Agenda beheerder');
        $this->grant($manager, [
            'calendar.view',
            'calendar.manage',
            'calendar.groups.manage',
        ]);
        $directAndTeamMember = $this->user(
            'calendar-union-direct@example.test',
            'Direct en teamlid',
        );
        $teamOnlyMember = $this->user(
            'calendar-union-team@example.test',
            'Alleen teamlid',
        );
        $outsider = $this->user('calendar-union-outsider@example.test', 'Buitenstaander');
        foreach ([$directAndTeamMember, $teamOnlyMember, $outsider] as $viewer) {
            $this->grant($viewer, ['calendar.view']);
        }

        $team = $this->team('UNI', 'Unietest');
        $team->users()->attach([
            $directAndTeamMember->id => ['created_at' => now()],
            $teamOnlyMember->id => ['created_at' => now()],
        ]);

        $createdGroup = $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-groups', [
                'name' => 'Operationele groep',
                'description' => 'Directe gebruikers plus actuele teamleden.',
                'user_ids' => [$directAndTeamMember->id],
                'team_ids' => [$team->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.direct_user_count', 1)
            ->assertJsonPath('data.team_count', 1)
            ->assertJsonPath('data.effective_member_count', 2);
        $groupId = (string) $createdGroup->json('data.id');

        $eventResponse = $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events', $this->eventPayload([$groupId]))
            ->assertCreated()
            ->assertJsonPath('data.audience_scope', 'groups')
            ->assertJsonPath('data.group_ids.0', $groupId);
        $eventId = (string) $eventResponse->json('data.id');

        foreach ([$directAndTeamMember, $teamOnlyMember] as $viewer) {
            $ids = collect(
                $this->asBearerClient($viewer, 'client:operator')
                    ->getJson('/api/calendar-events')
                    ->assertOk()
                    ->json('data'),
            )->pluck('id');
            $this->assertTrue($ids->contains($eventId));
        }

        $this->asBearerClient($outsider, 'client:operator')
            ->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonPath('data', []);

        $team->users()->detach($teamOnlyMember->id);
        $this->asBearerClient($teamOnlyMember, 'client:operator')
            ->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_suspended_group_members_remain_selectable_and_editable(): void
    {
        $manager = $this->user(
            'calendar-suspended-manager@example.test',
            'Agenda beheerder',
        );
        $this->grant($manager, ['calendar.view', 'calendar.groups.manage']);
        $suspended = $this->user(
            'calendar-suspended-member@example.test',
            'Tijdelijk geschorst groepslid',
        );
        $suspended->forceFill(['account_status' => 'suspended'])->save();
        $group = $this->group('Groep met geschorst lid', directUsers: [$suspended]);

        $this->asStatefulWebClient($manager)
            ->getJson('/api/calendar-groups/member-options?search=geschorst')
            ->assertOk()
            ->assertJsonPath('data.users.0.id', $suspended->id);

        $this->asStatefulWebClient($manager)
            ->patchJson('/api/calendar-groups/'.$group->id, [
                'name' => 'Bijgewerkte groep met geschorst lid',
                'description' => null,
                'user_ids' => [$suspended->id],
                'team_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.direct_users.0.id', $suspended->id);
    }

    public function test_event_payload_exposes_only_registration_summary_to_ordinary_viewers(): void
    {
        $participant = $this->user(
            'calendar-private-participant@example.test',
            'Privé Deelnemer',
        );
        $this->grant($participant, ['calendar.view', 'calendar.register']);
        $event = $this->event([
            'title' => 'Privacytest',
            'registration_enabled' => true,
            'max_participants' => 5,
        ]);

        $this->asStatefulWebClient($participant)
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/me')
            ->assertOk();

        $response = $this->asBearerClient($participant, 'client:operator')
            ->getJson('/api/calendar-events')
            ->assertOk();
        $payload = collect($response->json('data'))->firstWhere('id', (string) $event->id);
        $this->assertIsArray($payload);
        $this->assertSame(
            [
                'enabled',
                'status',
                'max_participants',
                'participant_count',
                'current_user_registered',
                'can_register',
                'can_unregister',
                'can_view_participants',
                'can_manage_participants',
                'unavailable_reason',
            ],
            array_keys($payload['registration']),
        );
        $this->assertSame(1, $payload['registration']['participant_count']);
        $this->assertTrue($payload['registration']['current_user_registered']);
        $this->assertFalse($payload['registration']['can_view_participants']);
        $this->assertArrayNotHasKey('participants', $payload);
        $this->assertStringNotContainsString($participant->email, $response->getContent());

        $this->asBearerClient($participant, 'client:operator')
            ->getJson('/api/calendar-events/'.$event->id.'/registrations')
            ->assertForbidden();

        $rosterViewer = $this->user(
            'calendar-private-roster@example.test',
            'Deelnemerslezer',
        );
        $this->grant($rosterViewer, [
            'calendar.view',
            'calendar.registrations.view',
        ]);
        $this->asBearerClient($rosterViewer, 'client:web')
            ->getJson('/api/calendar-events/'.$event->id.'/registrations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');
        $this->asStatefulWebClient($rosterViewer)
            ->getJson('/api/calendar-events/'.$event->id.'/registrations')
            ->assertOk()
            ->assertJsonPath('data.0.user.id', $participant->id)
            ->assertJsonPath('data.0.user.email', $participant->email)
            ->assertJsonPath('data.0.registered_by_name', $participant->name);
    }

    public function test_self_registration_is_idempotent_capacity_safe_and_cancellation_reopens_a_seat(): void
    {
        $first = $this->registrant('calendar-capacity-first@example.test', 'Eerste deelnemer');
        $second = $this->registrant('calendar-capacity-second@example.test', 'Tweede deelnemer');
        $third = $this->registrant('calendar-capacity-third@example.test', 'Derde deelnemer');
        $event = $this->event([
            'title' => 'Beperkte capaciteit',
            'registration_enabled' => true,
            'max_participants' => 2,
        ]);
        $registrationUri = '/api/calendar-events/'.$event->id.'/registrations/me';

        $this->asBearerClient($first, 'client:operator')
            ->postJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 1)
            ->assertJsonPath('data.registration.current_user_registered', true);
        $this->asBearerClient($first, 'client:operator')
            ->postJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 1);
        $this->assertDatabaseCount('calendar_event_registrations', 1);

        $this->asBearerClient($second, 'client:operator')
            ->postJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.status', 'full')
            ->assertJsonPath('data.registration.participant_count', 2);
        $this->asBearerClient($third, 'client:operator')
            ->postJson($registrationUri)
            ->assertConflict()
            ->assertJsonPath('error.code', 'calendar_event_full')
            ->assertJsonPath('error.details.registration.participant_count', 2);

        $this->assertSame(
            2,
            CalendarEventRegistration::query()
                ->where('calendar_event_id', $event->id)
                ->where('status', CalendarEventRegistration::STATUS_REGISTERED)
                ->count(),
        );

        $this->asBearerClient($first, 'client:operator')
            ->deleteJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 1)
            ->assertJsonPath('data.registration.current_user_registered', false);
        $this->asBearerClient($first, 'client:operator')
            ->deleteJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 1);
        $this->asBearerClient($third, 'client:operator')
            ->postJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.status', 'full')
            ->assertJsonPath('data.registration.participant_count', 2);

        $this->assertDatabaseCount('calendar_event_registrations', 3);
        $this->assertDatabaseHas('calendar_event_registrations', [
            'calendar_event_id' => $event->id,
            'user_id' => $first->id,
            'status' => CalendarEventRegistration::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $first->id,
            'target_id' => $event->id,
            'action' => 'calendar_registrations.registered',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $first->id,
            'target_id' => $event->id,
            'action' => 'calendar_registrations.cancelled',
        ]);
    }

    public function test_registration_remains_visible_and_cancellable_after_group_loss_and_closure(): void
    {
        $participant = $this->registrant(
            'calendar-former-member@example.test',
            'Voormalig groepslid',
        );
        $group = $this->group('Tijdelijke groep', directUsers: [$participant]);
        $event = $this->event([
            'title' => 'Besloten inschrijving',
            'registration_enabled' => true,
        ], [$group->id]);
        $registrationUri = '/api/calendar-events/'.$event->id.'/registrations/me';

        $this->asBearerClient($participant, 'client:operator')
            ->postJson($registrationUri)
            ->assertOk();

        $group->directUsers()->detach($participant->id);
        $event->forceFill(['registration_enabled' => false])->save();

        $response = $this->asBearerClient($participant, 'client:operator')
            ->getJson('/api/calendar-events')
            ->assertOk();
        $payload = collect($response->json('data'))->firstWhere('id', (string) $event->id);
        $this->assertIsArray($payload);
        $this->assertSame('closed', $payload['registration']['status']);
        $this->assertTrue($payload['registration']['current_user_registered']);
        $this->assertTrue($payload['registration']['can_unregister']);

        $this->asBearerClient($participant, 'client:operator')
            ->deleteJson($registrationUri)
            ->assertOk()
            ->assertJsonPath('data.registration.current_user_registered', false);
        $this->asBearerClient($participant, 'client:operator')
            ->getJson('/api/calendar-events')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_deleting_a_registered_user_cancels_the_registration_and_frees_capacity(): void
    {
        $actor = $this->user(
            'calendar-delete-actor@example.test',
            'Gebruikersbeheerder',
        );
        $this->grant($actor, ['users.delete']);
        $target = $this->user(
            'calendar-delete-target@example.test',
            'Te verwijderen deelnemer',
        );
        $event = $this->event([
            'title' => 'Capaciteit na gebruikersverwijdering',
            'registration_enabled' => true,
            'max_participants' => 1,
        ]);
        $registration = CalendarEventRegistration::query()->create([
            'calendar_event_id' => $event->id,
            'user_id' => $target->id,
            'user_name' => $target->name,
            'status' => CalendarEventRegistration::STATUS_REGISTERED,
            'registered_by' => $target->id,
            'registered_by_name' => $target->name,
            'registered_at' => now(),
        ]);

        app(UserService::class)->delete($target, $actor);

        $registration->refresh();
        $this->assertNull($registration->user_id);
        $this->assertSame(CalendarEventRegistration::STATUS_CANCELLED, $registration->status);
        $this->assertSame($actor->id, $registration->cancelled_by);
        $this->assertSame(0, app(CalendarEventRepository::class)->participantCount($event));

        $replacement = $this->registrant(
            'calendar-delete-replacement@example.test',
            'Vervangende deelnemer',
        );
        $this->asBearerClient($replacement, 'client:operator')
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/me')
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 1)
            ->assertJsonPath('data.registration.status', 'full');
    }

    public function test_admin_registration_requires_exact_permission_current_audience_and_stateful_web(): void
    {
        $target = $this->user('calendar-admin-target@example.test', 'Doelgebruiker');
        $otherMember = $this->user('calendar-admin-second@example.test', 'Tweede groepslid');
        $outsider = $this->user('calendar-admin-outsider@example.test', 'Niet in groep');
        $inactiveMember = $this->user(
            'calendar-admin-inactive@example.test',
            'Inactief groepslid',
        );
        $inactiveMember->forceFill(['account_status' => 'suspended'])->save();
        $group = $this->group(
            'Beheerbare groep',
            directUsers: [$target, $otherMember, $inactiveMember],
        );
        $event = $this->event([
            'title' => 'Beheerde inschrijving',
            'registration_enabled' => true,
            'max_participants' => 1,
        ], [$group->id]);

        $withoutView = $this->user('calendar-admin-no-view@example.test', 'Beheer zonder lezen');
        $this->grant($withoutView, ['calendar.registrations.manage']);
        $this->asStatefulWebClient($withoutView)
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/'.$target->id)
            ->assertForbidden();

        $manager = $this->user('calendar-admin-manager@example.test', 'Inschrijfbeheerder');
        $this->grant($manager, [
            'calendar.view',
            'calendar.registrations.manage',
        ]);
        $this->asBearerClient($manager, 'client:web')
            ->getJson('/api/calendar-events/'.$event->id.'/registration-options?search=Doel')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'stateful_web_session_required');

        $this->asStatefulWebClient($manager)
            ->getJson('/api/calendar-events/'.$event->id.'/registration-options?search=Doel')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $target->id)
            ->assertJsonPath('data.0.email', $target->email);

        $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/'.$target->id)
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 1);
        $this->assertDatabaseHas('calendar_event_registrations', [
            'calendar_event_id' => $event->id,
            'user_id' => $target->id,
            'registered_by' => $manager->id,
            'registered_by_name' => $manager->name,
            'status' => CalendarEventRegistration::STATUS_REGISTERED,
        ]);

        $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/'.$otherMember->id)
            ->assertConflict()
            ->assertJsonPath('error.code', 'calendar_event_full');
        $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/'.$outsider->id)
            ->assertForbidden();
        $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events/'.$event->id.'/registrations/'.$inactiveMember->id)
            ->assertForbidden();

        $this->asStatefulWebClient($manager)
            ->getJson('/api/calendar-events/'.$event->id.'/registrations')
            ->assertForbidden();

        $event->forceFill(['registration_enabled' => false])->save();
        $group->directUsers()->detach($target->id);
        $this->asStatefulWebClient($manager)
            ->deleteJson('/api/calendar-events/'.$event->id.'/registrations/'.$target->id)
            ->assertOk()
            ->assertJsonPath('data.registration.participant_count', 0);
    }

    public function test_event_group_and_capacity_invariants_fail_without_mutating_state(): void
    {
        $manager = $this->user('calendar-invariant-manager@example.test', 'Agenda beheerder');
        $this->grant($manager, [
            'calendar.view',
            'calendar.manage',
            'calendar.groups.manage',
        ]);
        $group = $this->group('Vaste doelgroep');

        $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events', $this->eventPayload([]))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events', [
                ...$this->eventPayload([$group->id]),
                'team_id' => $this->team('IGN', 'Verboden legacyteam')->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');

        $eventResponse = $this->asStatefulWebClient($manager)
            ->postJson('/api/calendar-events', [
                ...$this->eventPayload([$group->id]),
                'registration_enabled' => true,
                'max_participants' => 2,
            ])
            ->assertCreated()
            ->assertJsonPath('data.team_id', null)
            ->assertJsonPath('data.group_ids.0', $group->id);
        $eventId = (string) $eventResponse->json('data.id');

        $first = $this->user('calendar-invariant-first@example.test', 'Eerste capaciteit');
        $second = $this->user('calendar-invariant-second@example.test', 'Tweede capaciteit');
        $group->directUsers()->attach([
            $first->id => ['created_at' => now()],
            $second->id => ['created_at' => now()],
        ]);
        CalendarEventRegistration::query()->create([
            'calendar_event_id' => $eventId,
            'user_id' => $first->id,
            'user_name' => $first->name,
            'status' => CalendarEventRegistration::STATUS_REGISTERED,
            'registered_by' => $first->id,
            'registered_by_name' => $first->name,
            'registered_at' => now(),
        ]);
        CalendarEventRegistration::query()->create([
            'calendar_event_id' => $eventId,
            'user_id' => $second->id,
            'user_name' => $second->name,
            'status' => CalendarEventRegistration::STATUS_REGISTERED,
            'registered_by' => $second->id,
            'registered_by_name' => $second->name,
            'registered_at' => now(),
        ]);

        $this->asStatefulWebClient($manager)
            ->patchJson('/api/calendar-events/'.$eventId, [
                ...$this->eventPayload([$group->id]),
                'title' => 'Mag niet wijzigen',
                'registration_enabled' => true,
                'max_participants' => 1,
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'calendar_capacity_below_participant_count');
        $this->assertDatabaseHas('calendar_events', [
            'id' => $eventId,
            'title' => 'Kalendercontracttest',
            'max_participants' => 2,
        ]);

        $this->asStatefulWebClient($manager)
            ->deleteJson('/api/calendar-groups/'.$group->id)
            ->assertConflict()
            ->assertJsonPath('error.code', 'calendar_group_in_use');
        $this->assertDatabaseHas('calendar_groups', [
            'id' => $group->id,
            'deleted_at' => null,
        ]);
    }

    public function test_wallboard_calendar_repository_returns_only_everyone_events(): void
    {
        $everyoneEvent = $this->event([
            'title' => 'Iedereen op wallboard',
            'starts_at' => now()->addHour(),
        ]);
        $limitedGroup = $this->group('Niet op wallboard');
        $limitedEvent = $this->event([
            'title' => 'Beperkt agenda-item',
            'starts_at' => now()->addHours(2),
        ], [$limitedGroup->id]);

        $events = app(CalendarEventRepository::class)
            ->currentAndUpcoming(now(), 12);
        $ids = $events->pluck('id')->map(static fn ($id): string => (string) $id);

        $this->assertTrue($ids->contains((string) $everyoneEvent->id));
        $this->assertFalse($ids->contains((string) $limitedEvent->id));
    }

    private function user(string $email, string $name = 'Kalender Testgebruiker'): User
    {
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'Testgebruiker');

        return User::query()->create([
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function registrant(string $email, string $name): User
    {
        $user = $this->user($email, $name);
        $this->grant(
            $user,
            ['calendar.view', 'calendar.register'],
            canUseOperatorApp: true,
            canUseAdminApp: false,
        );

        return $user;
    }

    private function team(string $code, string $name): Team
    {
        return Team::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => 'operational',
            'is_operational' => true,
        ]);
    }

    /**
     * @param  list<User>  $directUsers
     * @param  list<Team>  $teams
     */
    private function group(
        string $name,
        array $directUsers = [],
        array $teams = [],
    ): CalendarGroup {
        $group = CalendarGroup::query()->create([
            'name' => $name,
            'description' => null,
        ]);
        if ($directUsers !== []) {
            $group->directUsers()->attach(collect($directUsers)->mapWithKeys(
                static fn (User $user): array => [
                    $user->id => ['created_at' => now()],
                ],
            )->all());
        }
        if ($teams !== []) {
            $group->teams()->attach(collect($teams)->mapWithKeys(
                static fn (Team $team): array => [
                    $team->id => ['created_at' => now()],
                ],
            )->all());
        }

        return $group;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $groupIds
     */
    private function event(array $attributes = [], array $groupIds = []): CalendarEvent
    {
        if ($groupIds === []) {
            $groupIds = [(string) $this->everyoneGroup()->id];
        }
        $audienceScope = CalendarGroup::query()
            ->whereIn('id', $groupIds)
            ->where('is_everyone', true)
            ->exists()
                ? CalendarEvent::AUDIENCE_SCOPE_EVERYONE
                : CalendarEvent::AUDIENCE_SCOPE_GROUPS;
        $event = CalendarEvent::query()->create([
            'title' => 'Kalendercontracttest',
            'type' => 'meeting',
            'starts_at' => now()->addDay(),
            'ends_at' => null,
            'location_label' => null,
            'description' => null,
            'team_id' => null,
            'audience_scope' => $audienceScope,
            'registration_enabled' => false,
            'max_participants' => null,
            ...$attributes,
        ]);
        $event->audienceGroups()->attach(collect($groupIds)->mapWithKeys(
            static fn (string $groupId): array => [
                $groupId => ['created_at' => now()],
            ],
        )->all());

        return $event;
    }

    private function everyoneGroup(): CalendarGroup
    {
        return CalendarGroup::query()->where('is_everyone', true)->sole();
    }

    /**
     * @param  list<string>  $groupIds
     * @return array<string, mixed>
     */
    private function eventPayload(array $groupIds): array
    {
        return [
            'title' => 'Kalendercontracttest',
            'type' => 'meeting',
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => null,
            'location_label' => 'Utrecht',
            'description' => 'Gerichte backend-contracttest.',
            'group_ids' => $groupIds,
            'registration_enabled' => false,
            'max_participants' => null,
        ];
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(
        User $user,
        array $permissionNames,
        bool $canUseOperatorApp = true,
        bool $canUseAdminApp = true,
    ): void {
        $role = Role::query()->create([
            'name' => 'calendar-contract-'.strtolower((string) Str::ulid()),
            'display_name' => 'Kalender contracttest',
            'can_use_operator_app' => $canUseOperatorApp,
            'can_use_admin_app' => $canUseAdminApp,
        ]);

        foreach ($permissionNames as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'category' => 'calendar_management',
                    'display_name' => $permissionName,
                    'description' => 'Kalender contracttest',
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);
        $user->unsetRelation('roles');
    }

    private function asStatefulWebClient(User $user): static
    {
        config([
            'app.url' => self::WEB_ORIGIN,
            'session.trusted_origins' => [self::WEB_ORIGIN],
            'sanctum.stateful' => ['dis.example.test'],
        ]);

        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        $timestamp = now()->getTimestamp();
        $csrfToken = hash('sha256', 'calendar-browser-session-'.$user->id);

        return $this->actingAs($user, 'web')
            ->withSession([
                '_token' => $csrfToken,
                WebSessionService::KEY_AUTHENTICATED_AT => $timestamp,
                WebSessionService::KEY_LAST_ACTIVITY_AT => $timestamp,
                WebSessionService::KEY_AUTH_VERSION => (int) $user->auth_session_version,
            ])
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => self::WEB_ORIGIN,
                'Referer' => self::WEB_ORIGIN.'/',
                'Sec-Fetch-Site' => 'same-origin',
                'X-CSRF-TOKEN' => $csrfToken,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'HTTPS' => 'on',
            ]);
    }

    private function asBearerClient(User $user, string $ability): static
    {
        $token = $user->createToken(
            'Calendar contract test',
            ['*', $ability],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
