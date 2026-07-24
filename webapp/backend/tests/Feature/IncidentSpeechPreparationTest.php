<?php

namespace Tests\Feature;

use App\Events\IncidentChanged;
use App\Jobs\PrewarmIncidentSpeechPhase;
use App\Models\Incident;
use App\Models\IncidentSpeechPreparation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\SpeechAudioPipeline;
use App\Services\SpeechPrewarmService;
use App\Services\SpeechSettingsService;
use App\Services\SpeechTemplateService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class IncidentSpeechPreparationTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_incident_create_and_update_track_both_phases_and_only_expose_them_on_web_detail(): void
    {
        Queue::fake();
        Event::fake([IncidentChanged::class]);
        Carbon::setTestNow('2026-07-24 10:00:00');
        $actor = $this->staff('incident-speech-status@example.test');
        $this->speechSetting('speech.enabled', true, $actor);
        $this->speechSetting('speech.pre_generate_on_save', true, $actor);

        $created = $this->asWebClient($actor)->postJson('/api/incidents', [
            'title' => 'Brandmelding distributiecentrum',
            'description' => 'Controleer de afzonderlijke spraakvoorbereidingen.',
            'priority' => 'normal',
            'custom_fields' => [
                'requesting_organization' => 'Testorganisatie',
            ],
        ])->assertCreated();
        $this->assertArrayNotHasKey('speech_preparations', $created->json('data'));

        $incident = Incident::query()->findOrFail((string) $created->json('data.id'));
        $preparations = IncidentSpeechPreparation::query()
            ->where('incident_id', $incident->id)
            ->orderBy('phase')
            ->get()
            ->keyBy('phase');
        $this->assertSame([
            SpeechTemplateService::PHASE_ATTENDANCE,
            SpeechTemplateService::PHASE_AVAILABILITY,
        ], $preparations->keys()->all());
        foreach ($preparations as $preparation) {
            $this->assertSame(IncidentSpeechPreparation::STATUS_QUEUED, $preparation->status);
            $this->assertSame(0, $preparation->progress_percent);
            $this->assertNull($preparation->error_code);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $preparation->source_fingerprint_hmac);
            Queue::assertPushed(
                PrewarmIncidentSpeechPhase::class,
                fn (PrewarmIncidentSpeechPhase $job): bool => $job->incidentSpeechPreparationId === $preparation->id
                    && $job->sourceFingerprint === $preparation->source_fingerprint_hmac,
            );
        }
        Queue::assertPushed(PrewarmIncidentSpeechPhase::class, 2);

        $detail = $this->asWebClient($actor)
            ->getJson('/api/incidents/'.$incident->id)
            ->assertOk();
        $speechPayload = $detail->json('data.speech_preparations');
        $this->assertSame(['availability', 'attendance'], array_keys($speechPayload));
        foreach (['availability', 'attendance'] as $phase) {
            $this->assertSame(
                ['phase', 'status', 'progress_percent', 'error_code', 'updated_at'],
                array_keys($speechPayload[$phase]),
            );
            $this->assertSame($phase, $speechPayload[$phase]['phase']);
            $this->assertSame('queued', $speechPayload[$phase]['status']);
            $this->assertSame(0, $speechPayload[$phase]['progress_percent']);
            $this->assertNull($speechPayload[$phase]['error_code']);
            $this->assertIsString($speechPayload[$phase]['updated_at']);
        }

        $index = $this->asWebClient($actor)->getJson('/api/incidents')->assertOk();
        $this->assertArrayNotHasKey('speech_preparations', $index->json('data.0'));

        $beforeUpdate = $preparations->map(fn (IncidentSpeechPreparation $preparation): array => [
            'id' => (string) $preparation->id,
            'source_fingerprint_hmac' => (string) $preparation->source_fingerprint_hmac,
        ]);
        Carbon::setTestNow('2026-07-24 10:00:01');
        $updated = $this->asWebClient($actor)->patchJson('/api/incidents/'.$incident->id, [
            'title' => 'Gewijzigde brandmelding distributiecentrum',
        ])->assertOk();
        $this->assertArrayNotHasKey('speech_preparations', $updated->json('data'));

        $service = app(SpeechPrewarmService::class);
        foreach (['availability', 'attendance'] as $phase) {
            $current = IncidentSpeechPreparation::query()
                ->where('incident_id', $incident->id)
                ->where('phase', $phase)
                ->sole();
            $old = $beforeUpdate->get($phase);
            $this->assertSame($old['id'], $current->id);
            $this->assertNotSame($old['source_fingerprint_hmac'], $current->source_fingerprint_hmac);
            $this->assertSame(IncidentSpeechPreparation::STATUS_QUEUED, $current->status);
            $this->assertNull($service->claim($old['id'], $old['source_fingerprint_hmac']));
            $this->assertFalse($service->fail(
                $old['id'],
                $old['source_fingerprint_hmac'],
                'stale_job_must_not_win',
            ));
        }
        Queue::assertPushed(PrewarmIncidentSpeechPhase::class, 4);

        $this->speechSetting('speech.enabled', false, $actor);
        Carbon::setTestNow('2026-07-24 10:00:02');
        $this->asWebClient($actor)->patchJson('/api/incidents/'.$incident->id, [
            'description' => 'Serverspraak staat nu uit.',
        ])->assertOk();
        $this->assertPhaseStatuses($incident, IncidentSpeechPreparation::STATUS_DISABLED);

        $this->speechSetting('speech.enabled', true, $actor);
        $this->speechSetting('speech.pre_generate_on_save', false, $actor);
        Carbon::setTestNow('2026-07-24 10:00:03');
        $this->asWebClient($actor)->patchJson('/api/incidents/'.$incident->id, [
            'description' => 'Vooraf genereren staat nu uit.',
        ])->assertOk();
        $this->assertPhaseStatuses($incident, IncidentSpeechPreparation::STATUS_NOT_SCHEDULED);

        $this->speechSetting('speech.pre_generate_on_save', true, $actor);
        Carbon::setTestNow('2026-07-24 10:00:04');
        $this->asWebClient($actor)->patchJson('/api/incidents/'.$incident->id, [
            'description' => 'Vooraf genereren staat weer aan.',
        ])->assertOk();
        $this->assertPhaseStatuses($incident, IncidentSpeechPreparation::STATUS_QUEUED);
        Queue::assertPushed(PrewarmIncidentSpeechPhase::class, 6);

        Carbon::setTestNow('2026-07-24 10:00:05');
        $this->asWebClient($actor)
            ->postJson('/api/incidents/'.$incident->id.'/cancel', ['reason' => 'Test afgerond.'])
            ->assertOk();
        $this->assertPhaseStatuses($incident, IncidentSpeechPreparation::STATUS_CANCELLED);
    }

    public function test_phase_progress_errors_and_completion_are_independent_and_broadcast_realtime(): void
    {
        Queue::fake();
        Event::fake([IncidentChanged::class]);
        $actor = $this->staff('incident-speech-progress@example.test');
        $this->speechSetting('speech.enabled', true, $actor);
        $this->speechSetting('speech.pre_generate_on_save', true, $actor);
        $incident = $this->incident(
            $actor,
            'INC-SPEECH-TRACKING',
            'Zoekactie geheim object',
            'Testlaan 12, 1234 AB Utrecht',
        );
        $service = app(SpeechPrewarmService::class);
        $service->queueAfterCommit((string) $incident->id);

        $availability = IncidentSpeechPreparation::query()
            ->where('incident_id', $incident->id)
            ->where('phase', SpeechTemplateService::PHASE_AVAILABILITY)
            ->sole();
        $attendance = IncidentSpeechPreparation::query()
            ->where('incident_id', $incident->id)
            ->where('phase', SpeechTemplateService::PHASE_ATTENDANCE)
            ->sole();

        $this->assertNotNull($service->claim(
            (string) $availability->id,
            (string) $availability->source_fingerprint_hmac,
        ));
        $this->assertTrue($service->progress(
            (string) $availability->id,
            (string) $availability->source_fingerprint_hmac,
            58,
        ));
        $this->assertSame(58, $availability->refresh()->progress_percent);
        $this->assertTrue($service->fail(
            (string) $availability->id,
            (string) $availability->source_fingerprint_hmac,
            'INTERNAL Testlaan 12',
        ));

        $this->assertNotNull($service->claim(
            (string) $attendance->id,
            (string) $attendance->source_fingerprint_hmac,
        ));
        $this->assertTrue($service->complete(
            (string) $attendance->id,
            (string) $attendance->source_fingerprint_hmac,
        ));

        $payload = $service->payload($incident);
        $this->assertSame('failed', $payload['availability']['status']);
        $this->assertSame(58, $payload['availability']['progress_percent']);
        $this->assertSame(
            'incident_speech_preparation_failed',
            $payload['availability']['error_code'],
        );
        $this->assertSame('ready', $payload['attendance']['status']);
        $this->assertSame(100, $payload['attendance']['progress_percent']);
        $this->assertNull($payload['attendance']['error_code']);

        Event::assertDispatched(
            IncidentChanged::class,
            fn (IncidentChanged $event): bool => $event->incident->is($incident)
                && $event->action === 'speech_preparation_changed',
        );

        $trackingBytes = json_encode(
            DB::table('incident_speech_preparations')
                ->where('incident_id', $incident->id)
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString('Zoekactie', $trackingBytes);
        $this->assertStringNotContainsString('Testlaan', $trackingBytes);
        $this->assertStringNotContainsString('1234 AB', $trackingBytes);
        $this->assertStringNotContainsString('Utrecht', $trackingBytes);
    }

    public function test_a_phase_job_failure_does_not_change_the_other_phase(): void
    {
        Queue::fake();
        Event::fake([IncidentChanged::class]);
        $actor = $this->staff('incident-speech-job@example.test');
        $this->speechSetting('speech.enabled', true, $actor);
        $this->speechSetting('speech.pre_generate_on_save', true, $actor);
        $incident = $this->incident(
            $actor,
            'INC-SPEECH-JOB',
            'Proef met ontbrekend spraakmodel',
            null,
        );
        $service = app(SpeechPrewarmService::class);
        $service->queueAfterCommit((string) $incident->id);

        $availability = IncidentSpeechPreparation::query()
            ->where('incident_id', $incident->id)
            ->where('phase', SpeechTemplateService::PHASE_AVAILABILITY)
            ->sole();
        $job = new PrewarmIncidentSpeechPhase(
            (string) $availability->id,
            (string) $availability->source_fingerprint_hmac,
        );
        $job->handle(
            $service,
            app(SpeechSettingsService::class),
            app(SpeechTemplateService::class),
            app(SpeechAudioPipeline::class),
        );

        $this->assertDatabaseHas('incident_speech_preparations', [
            'id' => $availability->id,
            'phase' => SpeechTemplateService::PHASE_AVAILABILITY,
            'status' => IncidentSpeechPreparation::STATUS_FAILED,
            'error_code' => 'speech_configuration_invalid',
        ]);
        $this->assertDatabaseHas('incident_speech_preparations', [
            'incident_id' => $incident->id,
            'phase' => SpeechTemplateService::PHASE_ATTENDANCE,
            'status' => IncidentSpeechPreparation::STATUS_QUEUED,
            'progress_percent' => 0,
            'error_code' => null,
        ]);
    }

    private function assertPhaseStatuses(Incident $incident, string $status): void
    {
        $this->assertSame(
            [
                SpeechTemplateService::PHASE_ATTENDANCE => $status,
                SpeechTemplateService::PHASE_AVAILABILITY => $status,
            ],
            IncidentSpeechPreparation::query()
                ->where('incident_id', $incident->id)
                ->orderBy('phase')
                ->pluck('status', 'phase')
                ->all(),
        );
    }

    private function staff(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Spraakcentralist',
            'first_name' => 'Spraak',
            'last_name' => 'Centralist',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'incident-speech-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Incident spraakbeheer',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach (['incidents.view', 'incidents.manage'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $permissionName],
                [
                    'display_name' => $permissionName,
                    'category' => 'incidents',
                    'description' => $permissionName,
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function incident(
        User $creator,
        string $reference,
        string $title,
        ?string $locationLabel,
    ): Incident {
        return Incident::query()->create([
            'reference' => $reference,
            'title' => $title,
            'description' => 'Gerichte test van afzonderlijke spraakvoorbereiding.',
            'priority' => 'normal',
            'status' => 'draft',
            'is_test' => false,
            'location_label' => $locationLabel,
            'created_by' => $creator->id,
            'created_by_name' => $creator->name,
            'created_by_email' => $creator->email,
            'opened_at' => now(),
        ]);
    }

    private function speechSetting(string $key, mixed $value, User $actor): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'is_sensitive' => false,
                'updated_by' => $actor->id,
            ],
        );
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken(
            'Incident speech preparation test',
            ['*', 'client:web'],
            now()->addHour(),
        )->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
