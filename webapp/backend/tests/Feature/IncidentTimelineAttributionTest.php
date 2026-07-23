<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\AvailabilityStatus;
use App\Models\DispatchMessage;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\IncidentReportService;
use App\Services\UserService;
use App\Support\ApiDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class IncidentTimelineAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_kladblokregel_keeps_and_returns_the_recorded_author_name(): void
    {
        $author = $this->user('centralist@example.test', 'Sanne de Vries');
        $viewer = $this->user('viewer@example.test', 'Logboeklezer');
        $this->grant($author, ['incidents.manage', 'incidents.view']);
        $this->grant($viewer, ['incidents.manage', 'incidents.view']);
        $incident = $this->incident($viewer, 'ATTR-NOTE-001');

        $this->asWebClient($author)
            ->patchJson('/api/incidents/'.$incident->id.'/internal-notes', [
                'internal_notes' => 'Brandweer vraagt om een tweede drone.',
            ])
            ->assertOk();

        $audit = AuditLog::query()
            ->where('action', 'incidents.internal_note_added')
            ->where('target_id', $incident->id)
            ->sole();
        $this->assertSame($author->id, $audit->actor_id);
        $this->assertSame('Sanne de Vries', $audit->actor_name);

        $author->forceDelete();
        $this->assertNull($audit->refresh()->actor_id);
        $this->assertSame('Sanne de Vries', $audit->actor_name);
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        $response = $this->asWebClient($viewer)
            ->getJson('/api/incidents/'.$incident->id.'/timeline')
            ->assertOk();

        $note = collect($response->json('data'))
            ->first(fn (array $item): bool => $item['type'] === 'internal_notes'
                && $item['message'] === 'Brandweer vraagt om een tweede drone.');

        $this->assertIsArray($note);
        $this->assertNull($note['actor']);
        $this->assertSame('Sanne de Vries', $note['actor_name']);
        $this->assertSame('Kladblokregel toegevoegd door Sanne de Vries', $note['description']);
        $this->assertArrayNotHasKey('actor_email', $note);
    }

    public function test_operator_without_manage_permission_never_receives_kladblok_entries(): void
    {
        $manager = $this->user('note-manager@example.test', 'Centralist');
        $operator = $this->user('note-operator@example.test', 'Operator');
        $this->grant($operator, ['incidents.assigned.view'], operator: true, admin: false);
        $incident = $this->incident($manager, 'ATTR-NOTE-HIDDEN-001');
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Zichtbare melding',
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
        AuditLog::query()->create([
            'actor_id' => $manager->id,
            'actor_name' => $manager->name,
            'action' => 'incidents.internal_note_added',
            'target_type' => Incident::class,
            'target_id' => $incident->id,
            'reason' => 'KLADBLOK-GEHEIM-VOOR-OPERATOR',
            'created_at' => now(),
        ]);

        $token = $operator->createToken('Operator timeline test', ['*', 'client:operator'], now()->addHour())->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/incidents/'.$incident->id.'/timeline')
            ->assertOk();

        $this->assertNotContains('internal_notes', collect($response->json('data'))->pluck('type')->all());
        $this->assertStringNotContainsString('KLADBLOK-GEHEIM-VOOR-OPERATOR', $response->getContent());
    }

    public function test_operator_app_receives_only_incident_wide_and_own_timeline_rules_even_with_manage_permission(): void
    {
        $manager = $this->user('scope-manager@example.test', 'Scope Centralist');
        $operator = $this->user('scope-operator@example.test', 'Eigen Scope Operator');
        $otherOperator = $this->user('scope-other@example.test', 'Andere Scope Operator');
        $this->grant(
            $operator,
            ['incidents.assigned.view', 'incidents.manage'],
            operator: true,
            admin: false,
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => 'incident.timeline.app_visible_types'],
            [
                'value' => ['status', 'dispatch', 'dispatch_response', 'dispatch_message', 'operator_status', 'audit'],
                'is_sensitive' => false,
            ],
        );

        $incident = $this->incident($manager, 'ATTR-APP-SCOPE-001');
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'INCIDENTBREDE-ALARMEERREGEL',
            'sent_at' => now()->subDays(2),
        ]);
        $ownRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $operator->id,
            'user_name' => $operator->name,
            'user_email' => $operator->email,
            'response_status' => 'accepted',
            'response_note' => 'EIGEN-REACTIEREGEL',
            'notified_at' => now()->subDays(2),
            'responded_at' => now()->subDays(2),
        ]);
        $otherRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $otherOperator->id,
            'user_name' => $otherOperator->name,
            'user_email' => $otherOperator->email,
            'response_status' => 'accepted',
            'response_note' => 'ANDERE-REACTIEREGEL-GEHEIM',
            'notified_at' => now()->subDays(2),
            'responded_at' => now()->subDays(2),
        ]);

        $this->audit(
            'dispatch.sent',
            $dispatch,
            $manager,
            'ALGEMENE-AUDITREGEL',
            createdAt: now()->subDays(2),
        );
        $this->audit(
            'dispatch.responded',
            $dispatch,
            $operator,
            'EIGEN-AUDITREACTIE',
            [
                'recipient_id' => $ownRecipient->id,
                'user_id' => $operator->id,
                'response' => 'accepted',
            ],
            now()->subDays(2),
        );
        $this->audit(
            'dispatch.recipient_response_overridden',
            $dispatch,
            $manager,
            'EIGEN-OVERRIDE-DOOR-CENTRALIST',
            [
                'recipient_id' => $ownRecipient->id,
                'user_id' => $operator->id,
                'response' => 'accepted',
            ],
            now()->subDays(2),
        );
        $this->audit(
            'dispatch.responded',
            $dispatch,
            $operator,
            'LEGACY-EIGEN-REACTIE-ZONDER-METADATA',
            createdAt: now()->subDays(2),
        );
        $this->audit(
            'location.share_requested',
            $incident,
            $manager,
            'EIGEN-LOCATIEVERZOEK',
            ['user_id' => $operator->id],
            now()->subDays(2),
        );
        $this->audit(
            'pilot_incident_report.submitted_by_admin',
            'pilot-report',
            $manager,
            'EIGEN-INZETRAPPORT',
            ['incident_id' => $incident->id, 'user_id' => $operator->id],
            now()->subDays(2),
        );

        foreach (range(1, 205) as $index) {
            $this->audit(
                'dispatch.responded',
                $dispatch,
                $otherOperator,
                'ANDERE-AUDITREGEL-'.$index,
                [
                    'recipient_id' => $otherRecipient->id,
                    'user_id' => $otherOperator->id,
                    'response' => 'accepted',
                ],
                now()->subMinute(),
            );
        }
        $this->audit(
            'location.share_requested',
            $incident,
            $manager,
            'ANDER-LOCATIEVERZOEK-GEHEIM',
            ['user_id' => $otherOperator->id],
        );
        $this->audit(
            'pilot_incident_report.submitted_by_admin',
            'pilot-report',
            $manager,
            'ANDER-INZETRAPPORT-GEHEIM',
            ['incident_id' => $incident->id, 'user_id' => $otherOperator->id],
        );
        $this->audit(
            'incidents.internal_note_added',
            $incident,
            $manager,
            'KLADBLOK-GEHEIM-ONDANKS-MANAGE',
        );
        $this->audit(
            'incidents.internal_notes_updated',
            $incident,
            $manager,
            'LEGACY-KLADBLOK-GEHEIM',
        );
        $this->audit(
            'incident.unknown_personal_action',
            $incident,
            $otherOperator,
            'ONBEKENDE-ACTIE-GEHEIM',
            ['user_id' => $otherOperator->id],
        );
        $this->audit(
            'location.share_requested',
            $incident,
            $manager,
            'PERSOONSREGEL-ZONDER-ONDERWERP-GEHEIM',
        );

        $token = $operator->createToken('Operator scoped timeline test', ['*', 'client:operator'], now()->addHour())->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/incidents/'.$incident->id.'/timeline')
            ->assertOk();
        $content = $response->getContent();

        foreach ([
            'INCIDENTBREDE-ALARMEERREGEL',
            'ALGEMENE-AUDITREGEL',
            'EIGEN-REACTIEREGEL',
            'EIGEN-AUDITREACTIE',
            'EIGEN-OVERRIDE-DOOR-CENTRALIST',
            'LEGACY-EIGEN-REACTIE-ZONDER-METADATA',
            'EIGEN-LOCATIEVERZOEK',
            'EIGEN-INZETRAPPORT',
        ] as $visibleMarker) {
            $this->assertStringContainsString($visibleMarker, $content);
        }
        foreach ([
            'Andere Scope Operator',
            'ANDERE-REACTIEREGEL-GEHEIM',
            'ANDERE-AUDITREGEL-',
            'ANDER-LOCATIEVERZOEK-GEHEIM',
            'ANDER-INZETRAPPORT-GEHEIM',
            'KLADBLOK-GEHEIM-ONDANKS-MANAGE',
            'LEGACY-KLADBLOK-GEHEIM',
            'ONBEKENDE-ACTIE-GEHEIM',
            'PERSOONSREGEL-ZONDER-ONDERWERP-GEHEIM',
            '_app_audience',
            '_app_user_id',
        ] as $hiddenMarker) {
            $this->assertStringNotContainsString($hiddenMarker, $content);
        }
    }

    public function test_timeline_separates_current_recipient_state_from_self_response_and_centralist_override(): void
    {
        $manager = $this->user('manager@example.test', 'Mila Jansen');
        $pilot = $this->user('pilot@example.test', 'Pieter Piloot');
        $pendingPilot = $this->user('pending@example.test', 'Pien Piloot');
        $noResponsePilot = $this->user('no-response@example.test', 'Nora Piloot');
        $this->grant($manager, ['incidents.manage', 'incidents.view', 'incidents.dispatch.view']);
        $incident = $this->incident($manager, 'ATTR-LIVE-001');
        $statusHistory = $incident->statusHistory()->create([
            'from_status' => 'draft',
            'to_status' => 'active',
            'changed_by' => $manager->id,
            'changed_by_name' => $manager->name,
            'changed_by_email' => $manager->email,
            'reason' => 'Melding bevestigd.',
            'created_at' => now()->subMinutes(5),
        ]);

        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Ga naar de opgegeven locatie.',
            'sent_at' => now()->subMinutes(4),
        ]);
        $recipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'response_status' => 'accepted',
            'response_note' => 'Ik ben onderweg.',
            'notified_at' => now()->subMinutes(4),
            'responded_at' => now()->subMinutes(3),
        ]);
        $pendingRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pendingPilot->id,
            'user_name' => $pendingPilot->name,
            'user_email' => $pendingPilot->email,
            'response_status' => 'pending',
            'notified_at' => now()->subMinutes(4),
            'responded_at' => null,
        ]);
        $pendingOverrideAt = now()->subSeconds(45)->startOfSecond();
        DB::table('dispatch_recipients')
            ->where('id', $pendingRecipient->id)
            ->update(['updated_at' => $pendingOverrideAt]);
        $pendingRecipient->refresh();
        $noResponseRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $noResponsePilot->id,
            'user_name' => $noResponsePilot->name,
            'user_email' => $noResponsePilot->email,
            'response_status' => 'no_response',
            'notified_at' => now()->subMinutes(4),
            'responded_at' => now()->subMinute(),
        ]);
        $message = DispatchMessage::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'sent_by' => $manager->id,
            'sent_by_name' => $manager->name,
            'sent_by_email' => $manager->email,
            'body' => 'Aanrijden via de noordzijde.',
            'created_at' => now()->subMinutes(2),
        ]);
        $operatorStatus = AvailabilityStatus::query()->create([
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'status' => 'en_route',
            'is_available' => false,
            'is_system_applied' => false,
            'changed_by' => $manager->id,
            'changed_by_name' => $manager->name,
            'changed_by_email' => $manager->email,
            'effective_at' => now()->subMinute(),
        ]);
        $selfResponseAudit = AuditLog::query()->create([
            'actor_id' => $pilot->id,
            'actor_name' => $pilot->name,
            'action' => 'dispatch.responded',
            'target_type' => DispatchRequest::class,
            'target_id' => $dispatch->id,
            'metadata' => [
                'recipient_id' => $recipient->id,
                'user_id' => $pilot->id,
                'response' => 'accepted',
            ],
            'created_at' => now()->subMinutes(3),
        ]);
        $overrideAudit = AuditLog::query()->create([
            'actor_id' => $manager->id,
            'actor_name' => $manager->name,
            'action' => 'dispatch.recipient_response_overridden',
            'target_type' => DispatchRequest::class,
            'target_id' => $dispatch->id,
            'metadata' => [
                'recipient_id' => $pendingRecipient->id,
                'user_id' => $pendingPilot->id,
                'response' => 'pending',
            ],
            'created_at' => $pendingOverrideAt,
        ]);
        $duplicateCreatedAudit = AuditLog::query()->create([
            'actor_id' => $manager->id,
            'actor_name' => $manager->name,
            'action' => 'dispatch.created',
            'target_type' => DispatchRequest::class,
            'target_id' => $dispatch->id,
            'created_at' => now()->subMinutes(4),
        ]);
        $unknownActorAudit = AuditLog::query()->create([
            'actor_id' => null,
            'actor_name' => null,
            'action' => 'location.share_requested',
            'target_type' => Incident::class,
            'target_id' => $incident->id,
            'created_at' => now()->subSeconds(20),
        ]);
        AuditLog::query()->create([
            'actor_id' => null,
            'actor_name' => null,
            'action' => 'incidents.status_auto_updated',
            'target_type' => Incident::class,
            'target_id' => $incident->id,
            'created_at' => now(),
        ]);

        $response = $this->asWebClient($manager)
            ->getJson('/api/incidents/'.$incident->id.'/timeline')
            ->assertOk();
        $items = collect($response->json('data'))->keyBy('id');

        $this->assertSame('Incidentstatus gewijzigd: draft -> active door Mila Jansen', $items[$statusHistory->id]['description']);
        $this->assertSame('Alarmering aangemaakt door Mila Jansen', $items[$dispatch->id]['description']);
        $this->assertSame('Pieter Piloot - Komt', $items[$recipient->id]['label']);
        $this->assertNull($items[$recipient->id]['actor']);
        $this->assertNull($items[$recipient->id]['actor_name']);
        $this->assertSame('Actuele reactiestatus van Pieter Piloot: Komt', $items[$recipient->id]['description']);

        $this->assertSame($pilot->id, $items[$selfResponseAudit->id]['actor']['id']);
        $this->assertSame('Reactie van Pieter Piloot vastgelegd: Komt door Pieter Piloot', $items[$selfResponseAudit->id]['description']);

        $this->assertNull($items[$pendingRecipient->id]['actor']);
        $this->assertNull($items[$pendingRecipient->id]['actor_name']);
        $this->assertSame('Actuele reactiestatus van Pien Piloot: Wacht op reactie', $items[$pendingRecipient->id]['description']);
        $this->assertSame(ApiDateTime::dateTime($pendingOverrideAt), $items[$pendingRecipient->id]['created_at']);
        $this->assertSame($manager->id, $items[$overrideAudit->id]['actor']['id']);
        $this->assertSame('Reactie van Pien Piloot teruggezet naar Wacht op reactie door Mila Jansen', $items[$overrideAudit->id]['description']);
        $this->assertSame(ApiDateTime::dateTime($pendingOverrideAt), $items[$overrideAudit->id]['created_at']);

        $this->assertNull($items[$noResponseRecipient->id]['actor']);
        $this->assertNull($items[$noResponseRecipient->id]['actor_name']);
        $this->assertSame('Actuele reactiestatus van Nora Piloot: Geen reactie', $items[$noResponseRecipient->id]['description']);
        $this->assertSame('Nadere informatie toegevoegd door Mila Jansen', $items[$message->id]['description']);
        $this->assertSame('Operationele status van Pieter Piloot gewijzigd naar Onderweg door Mila Jansen', $items[$operatorStatus->id]['description']);
        $this->assertNull($items[$unknownActorAudit->id]['actor']);
        $this->assertSame('Niet vastgelegd', $items[$unknownActorAudit->id]['actor_name']);
        $this->assertSame('Live locatie gevraagd (uitvoerder niet vastgelegd)', $items[$unknownActorAudit->id]['description']);
        $this->assertFalse($items->has($duplicateCreatedAudit->id));
        $this->assertSame(1, $items->where('description', 'Alarmering aangemaakt door Mila Jansen')->count());
        $this->assertSame(1, $items->where('type', 'status')->count());
        $this->assertStringNotContainsString('@example.test', $response->getContent());
    }

    public function test_incident_report_log_renders_the_same_actor_attribution(): void
    {
        $manager = $this->user('report-manager@example.test', 'Robin de Boer');
        $pilot = $this->user('report-pilot@example.test', 'Alex Piloot');
        $incident = $this->incident($manager, 'ATTR-REPORT-001', [
            'drone_flight_context' => [
                'map' => ['status' => 'unavailable'],
                'weather' => ['status' => 'unavailable'],
                'airspace' => ['status' => 'unavailable'],
                'checklist' => [],
            ],
        ]);
        $incident->statusHistory()->create([
            'from_status' => 'draft',
            'to_status' => 'active',
            'changed_by' => $manager->id,
            'changed_by_name' => $manager->name,
            'changed_by_email' => $manager->email,
            'created_at' => now()->subMinutes(2),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $manager->id,
            'requested_by_name' => $manager->name,
            'requested_by_email' => $manager->email,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Rapporttest',
            'sent_at' => now()->subMinute(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'user_name' => $pilot->name,
            'user_email' => $pilot->email,
            'response_status' => 'accepted',
            'responded_at' => now(),
        ]);

        $data = $this->app->make(IncidentReportService::class)->data($incident->fresh());
        $statusItem = collect($data['timeline'])->firstWhere('type', 'Incidentstatus');
        $dispatchItem = collect($data['timeline'])->firstWhere('type', 'Alarmering');

        $this->assertSame('Incidentstatus gewijzigd: draft -> active door Robin de Boer', $statusItem['description']);
        $this->assertSame('Alarmering aangemaakt door Robin de Boer', $dispatchItem['description']);

        $html = view('reports.incident', $data)->render();
        $this->assertStringContainsString('Operationeel verloop', $html);
        $this->assertStringContainsString('Incidentstatus gewijzigd: draft -&gt; active door Robin de Boer', $html);
        $this->assertStringContainsString('Actuele reactiestatus van Alex Piloot: Komt', $html);
        $this->assertStringNotContainsString('Volledige log', $html);
    }

    public function test_user_service_preserves_empty_legacy_audit_actor_snapshots_before_hard_delete(): void
    {
        $manager = $this->user('delete-manager@example.test', 'Beheerder');
        $target = $this->user('legacy-actor@example.test', 'Historische Centralist');
        $this->grant($manager, ['users.delete']);
        $nullSnapshot = AuditLog::query()->create([
            'actor_id' => $target->id,
            'actor_name' => null,
            'action' => 'legacy.null_snapshot',
            'target_type' => 'legacy',
            'created_at' => now()->subDay(),
        ]);
        $emptySnapshot = AuditLog::query()->create([
            'actor_id' => $target->id,
            'actor_name' => '',
            'action' => 'legacy.empty_snapshot',
            'target_type' => 'legacy',
            'created_at' => now()->subDay(),
        ]);

        $this->app->make(UserService::class)->delete($target, $manager);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
        foreach ([$nullSnapshot, $emptySnapshot] as $audit) {
            $audit->refresh();
            $this->assertNull($audit->actor_id);
            $this->assertSame('Historische Centralist', $audit->actor_name);
        }
    }

    public function test_actor_name_migration_backfills_existing_audits_set_based(): void
    {
        $actor = $this->user('migration-actor@example.test', 'Bestaande Gebruiker');
        $audit = AuditLog::query()->create([
            'actor_id' => $actor->id,
            'actor_name' => null,
            'action' => 'legacy.before_actor_snapshot',
            'target_type' => 'legacy',
            'created_at' => now()->subDay(),
        ]);
        $migration = require database_path('migrations/2026_07_23_000004_add_actor_name_to_audit_logs.php');

        $migration->down();
        $migration->up();

        $this->assertSame('Bestaande Gebruiker', $audit->refresh()->actor_name);
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
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $permissionNames
     */
    private function grant(User $user, array $permissionNames, bool $operator = false, bool $admin = true): void
    {
        $role = Role::query()->create([
            'name' => 'role-'.strtolower((string) str()->ulid()),
            'display_name' => 'Attributietest',
            'can_use_operator_app' => $operator,
            'can_use_admin_app' => $admin,
        ]);
        $permissions = collect($permissionNames)->map(fn (string $name): Permission => Permission::query()->firstOrCreate(
            ['name' => $name],
            [
                'category' => 'test',
                'display_name' => $name,
                'description' => 'Test permission',
            ],
        ));
        $role->permissions()->attach($permissions->pluck('id')->all());
        $user->roles()->attach($role->id, ['created_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function incident(User $creator, string $reference, array $overrides = []): Incident
    {
        return Incident::query()->create($overrides + [
            'reference' => $reference,
            'title' => 'Attributietestincident',
            'priority' => 'normal',
            'status' => 'active',
            'is_test' => false,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(
        string $action,
        Model|string $target,
        ?User $actor,
        string $reason,
        array $metadata = [],
        ?\DateTimeInterface $createdAt = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'target_type' => is_string($target) ? $target : $target::class,
            'target_id' => is_string($target) ? null : (string) $target->getKey(),
            'metadata' => $metadata,
            'reason' => $reason,
            'created_at' => $createdAt ?? now(),
        ]);
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Incident attribution test', ['*', 'client:web'], now()->addHour())->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
