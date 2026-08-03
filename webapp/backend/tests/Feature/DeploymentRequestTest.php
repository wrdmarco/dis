<?php

namespace Tests\Feature;

use App\Events\DeploymentRequestChanged;
use App\Events\DeploymentRequestDeleted;
use App\Events\DispatchChanged;
use App\Exceptions\DeploymentRequestConflictException;
use App\Jobs\SendFcmNotification;
use App\Models\AuditLog;
use App\Models\Certification;
use App\Models\DeploymentRequest;
use App\Models\DeploymentRequestMutation;
use App\Models\DeploymentRequestWorkflowRevision;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\DeploymentFormService;
use App\Services\DeploymentRequestService;
use App\Services\DeploymentRequestWorkflowService;
use App\Services\DeploymentService;
use App\Services\DispatchService;
use App\Support\MobileApiPayload;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DeploymentRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_workflow_is_initialized_once_and_published_revisions_are_immutable(): void
    {
        $actor = $this->user('workflow@example.test');
        $this->grant($actor, ['forms.manage']);
        $service = app(DeploymentRequestWorkflowService::class);

        $first = $service->adminEnvelope();
        $second = $service->adminEnvelope();

        $this->assertSame(1, $first['published']['version']);
        $this->assertSame($first['published']['id'], $second['published']['id']);
        $this->assertDatabaseCount('deployment_request_workflow_revisions', 2);
        $this->assertSame('active', DeploymentRequestWorkflowRevision::query()->where('status', 'draft')->value('draft_marker'));

        $configuration = $first['draft']['configuration'];
        $configuration['subject_types'][0]['label'] = 'Persoon';
        $updated = $service->updateDraft($first['draft']['lock_version'], $configuration, $actor);
        $published = $service->publishDraft($updated['draft']['lock_version'], $actor);

        $this->assertSame(2, $published['published']['version']);
        $this->assertSame('Persoon', $published['published']['configuration']['subject_types'][0]['label']);
        $this->assertSame('Mens', DeploymentRequestWorkflowRevision::query()->where('version', 1)->firstOrFail()->configuration['subject_types'][0]['label']);
        $this->assertGreaterThan($updated['draft']['lock_version'], $published['draft']['lock_version']);
        $this->assertDatabaseCount('deployment_request_workflow_revisions', 3);

        $this->expectException(DeploymentRequestConflictException::class);
        $service->updateDraft($updated['draft']['lock_version'], $configuration, $actor);
    }

    public function test_default_workflow_keeps_last_seen_and_deployment_locations_distinct(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();
        $fields = collect($configuration['fields'])->keyBy('key');
        $bindings = collect($configuration['bindings'])->keyBy('field_key');

        $this->assertSame('datetime', $fields->get('last_seen_at')['type']);
        $this->assertSame('Laatst gezien locatie', $fields->get('last_seen_location')['label']);
        $this->assertSame('address', $fields->get('last_seen_location')['type']);
        $this->assertTrue($fields->get('last_seen_location')['required']);
        $this->assertTrue($fields->get('last_seen_location')['operator_visible']);
        $this->assertSame('section', $fields->get('deployment_location_section')['type']);
        $this->assertSame('Opkomstlocatie', $fields->get('deployment_location')['label']);
        $this->assertSame('address', $fields->get('deployment_location')['type']);
        $this->assertTrue($fields->get('deployment_location')['required']);
        $this->assertTrue($fields->get('deployment_location')['operator_visible']);
        $this->assertFalse($bindings->has('last_seen_location'));
        $this->assertSame('location_label', $bindings->get('deployment_location')['target']);

        $configuration['priority_rules'][0]['conditions'][0] = [
            'field_key' => 'last_seen_location',
            'operator' => 'contains',
            'value' => 'Utrecht',
        ];
        $service->validateConfiguration($configuration);

        $normalized = $service->normalizeAnswers($configuration, 'person', [
            'last_seen_at' => '2026-07-26T12:30:00+02:00',
            'last_seen_location' => 'Utrecht Centraal',
            'deployment_location' => 'Kazerne Utrecht',
        ], patch: true);
        $this->assertSame('2026-07-26T10:30:00+00:00', $normalized['last_seen_at']);
        $this->assertSame('Utrecht Centraal', $normalized['last_seen_location']);
        $this->assertSame('Kazerne Utrecht', $normalized['deployment_location']);
    }

    public function test_location_split_migration_publishes_once_and_keeps_existing_requests_frozen(): void
    {
        $actor = $this->user('location-split-migration@example.test');
        $configuration = app(DeploymentRequestWorkflowService::class)->defaultConfiguration();
        $configuration['fields'] = array_values(array_filter(
            $configuration['fields'],
            fn (array $field): bool => ! in_array(
                $field['key'],
                ['deployment_location_section', 'deployment_location'],
                true,
            ),
        ));
        foreach ($configuration['fields'] as &$field) {
            if ($field['key'] === 'last_seen_location') {
                $field['label'] = 'Plaats laatst gezien';
                $field['type'] = 'text';
            }
            if ($field['key'] === 'circumstances') {
                $field['help_text'] = 'Bestaande gepubliceerde toelichting';
            }
        }
        unset($field);
        foreach ($configuration['bindings'] as &$binding) {
            if ($binding['field_key'] === 'deployment_location') {
                $binding['field_key'] = 'last_seen_location';
            }
        }
        unset($binding);

        $published = DeploymentRequestWorkflowRevision::query()->create([
            'version' => 7,
            'status' => 'published',
            'lock_version' => 4,
            'configuration' => $configuration,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'published_by' => $actor->id,
            'published_at' => now()->subMinute(),
        ]);
        $draftConfiguration = $configuration;
        $draftConfiguration['subject_types'][0]['label'] = 'Persoon in concept';
        $draftConfiguration['fields'][] = [
            'key' => 'deployment_location_section',
            'label' => 'Bestaande opkomstsectie',
            'type' => 'section',
            'scope' => 'person',
            'required' => false,
            'operator_visible' => false,
            'help_text' => null,
            'options' => [],
        ];
        $draftConfiguration['fields'][] = [
            'key' => 'deployment_location',
            'label' => 'Bestaande opkomstplek',
            'type' => 'text',
            'scope' => 'person',
            'required' => false,
            'operator_visible' => false,
            'help_text' => null,
            'options' => [],
        ];
        $draftConfiguration['bindings'][] = [
            'field_key' => 'deployment_location',
            'target' => 'location_label',
        ];
        DeploymentRequestWorkflowRevision::query()->create([
            'status' => 'draft',
            'draft_marker' => 'active',
            'lock_version' => 9,
            'configuration' => $draftConfiguration,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        $request = DeploymentRequest::query()->create([
            'workflow_revision_id' => $published->id,
            'status' => 'open',
            'subject_type' => 'person',
            'answers' => ['last_seen_location' => 'Utrecht Centraal'],
            'triage' => ['state' => 'incomplete'],
            'lock_version' => 1,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $migration = require database_path('migrations/2026_07_27_000003_split_last_seen_and_deployment_locations.php');
        $migration->up();
        $firstMigratedId = DeploymentRequestWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->value('id');
        $firstMigratedDraftLock = DeploymentRequestWorkflowRevision::query()
            ->where('draft_marker', 'active')
            ->value('lock_version');
        $migration->up();

        $this->assertDatabaseCount('deployment_request_workflow_revisions', 3);
        $this->assertSame(
            $firstMigratedId,
            DeploymentRequestWorkflowRevision::query()
                ->where('status', 'published')
                ->latest('version')
                ->value('id'),
        );
        $this->assertSame(
            $firstMigratedDraftLock,
            DeploymentRequestWorkflowRevision::query()
                ->where('draft_marker', 'active')
                ->value('lock_version'),
        );
        $this->assertSame($published->id, $request->refresh()->workflow_revision_id);
        $this->assertSame('text', collect($published->refresh()->configuration['fields'])->firstWhere('key', 'last_seen_location')['type']);
        $this->assertSame(
            'last_seen_location',
            collect($published->configuration['bindings'])->firstWhere('target', 'location_label')['field_key'],
        );

        $migrated = DeploymentRequestWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->firstOrFail();
        $migratedFields = collect($migrated->configuration['fields'])->keyBy('key');
        $migratedBindings = collect($migrated->configuration['bindings'])->keyBy('field_key');
        $this->assertSame(8, $migrated->version);
        $this->assertNull($migrated->published_by);
        $this->assertSame('datetime', $migratedFields->get('last_seen_at')['type']);
        $this->assertSame('address', $migratedFields->get('last_seen_location')['type']);
        $this->assertSame('address', $migratedFields->get('deployment_location')['type']);
        $this->assertFalse($migratedBindings->has('last_seen_location'));
        $this->assertSame('location_label', $migratedBindings->get('deployment_location')['target']);
        $this->assertSame(
            'Bestaande gepubliceerde toelichting',
            $migratedFields->get('circumstances')['help_text'],
        );
        app(DeploymentRequestWorkflowService::class)->validateConfiguration($migrated->configuration);

        $draft = DeploymentRequestWorkflowRevision::query()
            ->where('draft_marker', 'active')
            ->firstOrFail();
        $draftFields = collect($draft->configuration['fields'])->keyBy('key');
        $draftBindings = collect($draft->configuration['bindings'])->keyBy('field_key');
        $this->assertSame(10, $draft->lock_version);
        $this->assertNull($draft->updated_by);
        $this->assertSame('Persoon in concept', $draft->configuration['subject_types'][0]['label']);
        $this->assertCount(1, collect($draft->configuration['fields'])->where('key', 'deployment_location_section'));
        $this->assertCount(1, collect($draft->configuration['fields'])->where('key', 'deployment_location'));
        $this->assertSame('common', $draftFields->get('deployment_location_section')['scope']);
        $this->assertSame('Opkomstlocatie', $draftFields->get('deployment_location')['label']);
        $this->assertSame('address', $draftFields->get('last_seen_location')['type']);
        $this->assertSame('address', $draftFields->get('deployment_location')['type']);
        $this->assertSame('common', $draftFields->get('deployment_location')['scope']);
        $this->assertTrue($draftFields->get('deployment_location')['required']);
        $this->assertTrue($draftFields->get('deployment_location')['operator_visible']);
        $this->assertFalse($draftBindings->has('last_seen_location'));
        $this->assertCount(1, collect($draft->configuration['bindings'])->where('target', 'location_label'));
        $this->assertSame('location_label', $draftBindings->get('deployment_location')['target']);
        app(DeploymentRequestWorkflowService::class)->validateConfiguration($draft->configuration);

        $customLegacyConfiguration = $configuration;
        foreach ($customLegacyConfiguration['bindings'] as &$binding) {
            if (($binding['target'] ?? null) === 'location_label') {
                $binding['field_key'] = 'last_seen_direction';
            }
        }
        unset($binding);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        $deploymentFields[] = [
            'key' => 'location_label',
            'label' => 'Aparte custom locatie',
            'type' => 'text',
            'visible' => true,
            'required' => false,
            'options' => [],
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false],
        );
        $customLegacyConfiguration['fields'][] = [
            'key' => 'custom_location_label',
            'label' => 'Aparte custom locatie',
            'type' => 'text',
            'scope' => 'common',
            'required' => false,
            'operator_visible' => false,
            'help_text' => null,
            'options' => [],
        ];
        $customLegacyConfiguration['bindings'][] = [
            'field_key' => 'custom_location_label',
            'target' => 'custom_fields.location_label',
        ];
        DeploymentRequestWorkflowRevision::query()->create([
            'version' => 9,
            'status' => 'published',
            'lock_version' => 1,
            'configuration' => $customLegacyConfiguration,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);
        $migration->up();
        $customMigrated = DeploymentRequestWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->firstOrFail();
        $this->assertSame(10, $customMigrated->version);
        $this->assertSame(
            'deployment_location',
            collect($customMigrated->configuration['bindings'])
                ->firstWhere('target', 'location_label')['field_key'],
        );
        $this->assertSame(
            'custom_location_label',
            collect($customMigrated->configuration['bindings'])
                ->firstWhere('target', 'custom_fields.location_label')['field_key'],
        );
        app(DeploymentRequestWorkflowService::class)->validateConfiguration(
            $customMigrated->configuration,
        );
        $countAfterCustomMigration = DeploymentRequestWorkflowRevision::query()->count();
        $migration->up();
        $this->assertSame(
            $countAfterCustomMigration,
            DeploymentRequestWorkflowRevision::query()->count(),
        );
    }

    public function test_linked_deployment_team_backfill_reconciles_current_and_protected_teams(): void
    {
        $actor = $this->user('linked-team-backfill@example.test');
        $this->grant($actor, ['deployments.manage']);
        $staleTeam = Team::query()->create([
            'code' => 'BACKFILL-STALE',
            'name' => 'Niet meer gekoppeld team',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $currentTeam = Team::query()->create([
            'code' => 'BACKFILL-TEAM',
            'name' => 'Bestaand gekoppeld team',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'linked-team-backfill-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'linked-team-backfill-decision',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'linked-team-backfill-prepare',
        ], $actor);
        $prepared['deployment']->teams()->sync([$currentTeam->id]);
        $prepared['deployment']->forceFill(['team_id' => $currentTeam->id])->save();
        $staleProposal = $deploymentRequest->refresh()->selected_deployment_proposal;
        $staleProposal['team_ids'] = [$staleTeam->id];
        $staleProposal['teams'] = [[
            'id' => $staleTeam->id,
            'code' => $staleTeam->code,
            'name' => $staleTeam->name,
        ]];
        $deploymentRequest->forceFill([
            'selected_deployment_proposal' => $staleProposal,
            'updated_by' => $actor->id,
        ])->save();
        $lockVersionBeforeBackfill = $deploymentRequest->refresh()->lock_version;

        $migration = require database_path('migrations/2026_07_27_000004_backfill_linked_deployment_request_teams.php');
        $migration->up();

        $backfilled = $deploymentRequest->refresh();
        $this->assertSame(
            [$currentTeam->id],
            $backfilled->selected_deployment_proposal['team_ids'],
        );
        $this->assertSame(
            [[
                'id' => $currentTeam->id,
                'code' => $currentTeam->code,
                'name' => $currentTeam->name,
            ]],
            $backfilled->selected_deployment_proposal['teams'],
        );
        $this->assertSame($lockVersionBeforeBackfill + 1, $backfilled->lock_version);
        $this->assertNull($backfilled->updated_by);
        $backfilledLockVersion = $backfilled->lock_version;

        DispatchRequest::query()->create([
            'deployment_id' => $prepared['deployment']->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'target_team_id' => $staleTeam->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Historische actieve alarmering',
            'sent_at' => now(),
        ]);
        $migration->up();
        $protected = $deploymentRequest->refresh();
        $this->assertSame(
            [$currentTeam->id, $staleTeam->id],
            $protected->selected_deployment_proposal['team_ids'],
        );
        $this->assertSame($backfilledLockVersion + 1, $protected->lock_version);
        $protectedLockVersion = $protected->lock_version;

        $migration->up();
        $this->assertSame($protectedLockVersion, $deploymentRequest->refresh()->lock_version);
    }

    public function test_restored_historical_workflow_cannot_rejoin_last_seen_and_deployment_locations(): void
    {
        $actor = $this->user('location-restore-guard@example.test');
        $this->grant($actor, ['forms.manage']);
        $service = app(DeploymentRequestWorkflowService::class);
        $admin = $service->adminEnvelope();
        $legacy = $service->defaultConfiguration();
        $legacy['fields'] = array_values(array_filter(
            $legacy['fields'],
            fn (array $field): bool => ! in_array(
                $field['key'],
                ['deployment_location_section', 'deployment_location'],
                true,
            ),
        ));
        foreach ($legacy['fields'] as &$field) {
            if ($field['key'] === 'last_seen_location') {
                $field['type'] = 'text';
            }
        }
        unset($field);
        foreach ($legacy['bindings'] as &$binding) {
            if ($binding['target'] === 'location_label') {
                $binding['field_key'] = 'last_seen_location';
            }
        }
        unset($binding);
        $source = DeploymentRequestWorkflowRevision::query()->create([
            'version' => ((int) DeploymentRequestWorkflowRevision::query()->max('version')) + 1,
            'status' => 'published',
            'lock_version' => 1,
            'configuration' => $legacy,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'published_by' => $actor->id,
            'published_at' => now(),
        ]);

        $restored = $service->restore(
            $source->id,
            $admin['draft']['lock_version'],
            $actor,
        );
        try {
            $service->publishDraft($restored['draft']['lock_version'], $actor);
            $this->fail('Een herstelde workflow mag laatst gezien niet opnieuw aan de inzetlocatie koppelen.');
        } catch (ValidationException $exception) {
            $this->assertTrue(
                array_key_exists('configuration.fields', $exception->errors())
                || array_key_exists('configuration.bindings', $exception->errors()),
            );
        }
    }

    public function test_restore_keeps_stale_profile_references_editable_but_publish_rejects_them(): void
    {
        $actor = $this->user('restore-stale@example.test');
        $this->grant($actor, ['forms.manage']);
        $team = Team::query()->create([
            'code' => 'RESTORE',
            'name' => 'Te herstellen team',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $certification = Certification::query()->create([
            'code' => 'RESTORE-CERT',
            'name' => 'Te herstellen certificaat',
            'description' => null,
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ]);
        $service = app(DeploymentRequestWorkflowService::class);
        $admin = $service->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['deployment_profiles'][0]['team_ids'] = [$team->id];
        $configuration['deployment_profiles'][0]['required_certification_type_ids'] = [$certification->id];
        $updated = $service->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $published = $service->publishDraft($updated['draft']['lock_version'], $actor);
        $sourceId = $published['published']['id'];
        $team->delete();
        $certification->delete();

        $restored = $service->restore($sourceId, $published['draft']['lock_version'], $actor);

        $this->assertSame([$team->id], $restored['draft']['configuration']['deployment_profiles'][0]['team_ids']);
        $this->assertSame(
            [$certification->id],
            $restored['draft']['configuration']['deployment_profiles'][0]['required_certification_type_ids'],
        );
        $this->expectException(ValidationException::class);
        $service->publishDraft($restored['draft']['lock_version'], $actor);
    }

    public function test_configuration_validation_is_strict_and_fail_safe(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();
        $configuration['fields'][1]['required'] = 'false';

        try {
            $service->validateConfiguration($configuration);
            $this->fail('Stringbooleans mogen niet worden geaccepteerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.fields.1.required', $exception->errors());
        }

        $configuration = $service->defaultConfiguration();
        $configuration['priority_rules'][0]['conditions'][0] = [
            'field_key' => 'immediate_danger',
            'operator' => 'contains',
            'value' => 'ja',
        ];
        try {
            $service->validateConfiguration($configuration);
            $this->fail('Een tekstoperator mag niet op een checkbox worden gebruikt.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.priority_rules.0.conditions.0.operator', $exception->errors());
        }

        $configuration = $service->defaultConfiguration();
        $configuration['bindings'][] = ['field_key' => 'last_seen_direction', 'target' => 'location_label'];
        try {
            $service->validateConfiguration($configuration);
            $this->fail('Twee gelijktijdige velden mogen hetzelfde inzetdoel niet vullen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.bindings', $exception->errors());
        }

        $configuration = $service->defaultConfiguration();
        foreach ($configuration['bindings'] as &$binding) {
            if ($binding['field_key'] === 'requesting_unit') {
                $binding['target'] = 'requesting_organization';
            }
        }
        unset($binding);
        try {
            $service->validateConfiguration($configuration);
            $this->fail('Vaste en configureerbare aliassen moeten als hetzelfde inzetdoel gelden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.bindings', $exception->errors());
        }

        foreach (['deployment_location', 'circumstances', 'person_name'] as $hiddenCoreField) {
            $configuration = $service->defaultConfiguration();
            foreach ($configuration['fields'] as &$field) {
                if ($field['key'] === $hiddenCoreField) {
                    $field['operator_visible'] = false;
                }
            }
            unset($field);
            try {
                $service->validateConfiguration($configuration);
                $this->fail('Operationele kernvelden moeten altijd met operators worden gedeeld.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(
                    $hiddenCoreField === 'deployment_location'
                        ? 'configuration.fields'
                        : 'configuration.bindings',
                    $exception->errors(),
                );
            }
        }

        $configuration = $service->defaultConfiguration();
        $answers = $this->personAnswers();
        unset($answers['immediate_danger']);
        $result = $service->evaluate($configuration, 'person', $answers);
        $this->assertSame('incomplete', $result['triage']['state']);
        $this->assertNull($result['triage']['recommended_priority']);

        try {
            $service->normalizeAnswers($service->defaultConfiguration(), 'person', ['immediate_danger' => 'false']);
            $this->fail('Checkboxantwoorden moeten echte booleans zijn.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.immediate_danger', $exception->errors());
        }

        foreach ([['geen', 'tekst'], false, 42] as $invalidText) {
            try {
                $service->normalizeAnswers(
                    $service->defaultConfiguration(),
                    'person',
                    ['circumstances' => $invalidText],
                );
                $this->fail('Tekstvelden mogen geen impliciet gecaste waarden accepteren.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('answers.circumstances', $exception->errors());
            }
        }

        $configuration = $service->defaultConfiguration();
        $configuration['deployment_profiles'][0]['summary'] = ['geen tekst'];
        try {
            $service->validateConfiguration($configuration);
            $this->fail('Configuratieteksten moeten type-strict zijn.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.deployment_profiles.0.summary', $exception->errors());
        }

        $configuration = $service->defaultConfiguration();
        $configuration['priority_rules'][0]['label'] = str_repeat('a', 161);
        try {
            $service->validateConfiguration($configuration);
            $this->fail('Namen van prioriteitsregels moeten begrensd zijn.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.priority_rules.0', $exception->errors());
        }

        $certification = Certification::query()->create([
            'code' => 'UAS-A1',
            'name' => 'UAS A1/A3',
            'description' => null,
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ]);
        $team = Team::query()->create([
            'code' => 'SEARCH',
            'name' => 'Zoekteam',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $configuration = $service->defaultConfiguration();
        $configuration['deployment_profiles'][0]['recommended_recipient_count'] = 4;
        $configuration['deployment_profiles'][0]['team_ids'] = [$team->id];
        $configuration['deployment_profiles'][0]['required_certification_type_ids'] = [$certification->id];
        $configuration = $service->validateConfiguration($configuration);
        $result = $service->evaluate($configuration, 'person', $this->personAnswers());
        $this->assertSame(4, $result['deployment_proposal']['recommended_recipient_count']);
        $this->assertSame('preannouncement', $result['deployment_proposal']['recommended_dispatch_mode']);
        $this->assertSame('Zoekteam', $result['deployment_proposal']['teams'][0]['name']);
        $this->assertSame('UAS A1/A3', $result['deployment_proposal']['required_certification_types'][0]['name']);
        $team->update(['name' => 'Hernoemd team']);
        $certification->update(['name' => 'Hernoemd certificaat']);
        $frozen = $service->evaluate($configuration, 'person', $this->personAnswers());
        $this->assertSame('Zoekteam', $frozen['deployment_proposal']['teams'][0]['name']);
        $this->assertSame('UAS A1/A3', $frozen['deployment_proposal']['required_certification_types'][0]['name']);

        $catalogTargets = array_column($service->catalogs()['deployment_fields'], 'target');
        $this->assertNotContains('required_resources', $catalogTargets);
        $this->assertNotContains('custom_fields.required_resources', $catalogTargets);
        foreach (['required_resources', 'custom_fields.required_resources'] as $forbiddenTarget) {
            $configuration = $service->defaultConfiguration();
            $configuration['bindings'][] = [
                'field_key' => 'last_seen_direction',
                'target' => $forbiddenTarget,
            ];
            try {
                $service->validateConfiguration($configuration);
                $this->fail('Benodigde middelen moeten uitsluitend uit het gekozen inzetvoorstel komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(
                    'configuration.bindings.'.(count($configuration['bindings']) - 1),
                    $exception->errors(),
                );
            }
        }
    }

    public function test_empty_deployment_profile_uses_operational_base_team_in_recommended_and_selected_proposals(): void
    {
        config()->set('dis.teams.base_team_code', 'BASE-CUSTOM');
        $baseTeam = Team::query()->create([
            'code' => 'BASE-CUSTOM',
            'name' => 'Operationeel basisteam',
            'type' => 'base',
            'is_operational' => true,
        ]);
        $workflow = app(DeploymentRequestWorkflowService::class);
        $configuration = $workflow->validateConfiguration($workflow->defaultConfiguration());

        $evaluation = $workflow->evaluate($configuration, 'person', $this->personAnswers());

        $this->assertSame([$baseTeam->id], $evaluation['deployment_proposal']['team_ids']);
        $this->assertSame('BASE-CUSTOM', $evaluation['deployment_proposal']['teams'][0]['code']);
        $this->assertSame('Operationeel basisteam', $evaluation['deployment_proposal']['teams'][0]['name']);

        $actor = $this->user('base-team-proposal@example.test');
        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'base-team-proposal-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'base-team-proposal-decision',
            'priority' => 'low',
        ], $actor);

        $this->assertSame([$baseTeam->id], $created['deployment_proposal']['team_ids']);
        $this->assertSame([$baseTeam->id], $decided['selected_deployment_proposal']['team_ids']);
        $this->assertSame('BASE-CUSTOM', $decided['selected_deployment_proposal']['teams'][0]['code']);

        Http::fake(['*' => Http::response([], 200)]);
        $prepared = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'base-team-proposal-prepare',
        ], $actor);
        $this->assertSame(
            [$baseTeam->id],
            $prepared['deployment']->teams()->pluck('teams.id')->all(),
        );
    }

    public function test_empty_deployment_profile_remains_teamless_when_base_team_is_missing_or_not_operational(): void
    {
        config()->set('dis.teams.base_team_code', 'UNAVAILABLE-BASE');
        $workflow = app(DeploymentRequestWorkflowService::class);
        $configuration = $workflow->validateConfiguration($workflow->defaultConfiguration());
        $actor = $this->user('unavailable-base-team@example.test');
        $deploymentRequests = app(DeploymentRequestService::class);

        $missingEvaluation = $workflow->evaluate($configuration, 'person', $this->personAnswers());
        $missing = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'missing-base-team-create',
        ], $actor);
        $missingDecision = $deploymentRequests->decidePriority(
            DeploymentRequest::query()->findOrFail($missing['id']),
            [
                'lock_version' => $missing['lock_version'],
                'client_mutation_id' => 'missing-base-team-decision',
                'priority' => 'low',
            ],
            $actor,
        );

        $this->assertSame([], $missingEvaluation['deployment_proposal']['team_ids']);
        $this->assertSame([], $missingEvaluation['deployment_proposal']['teams']);
        $this->assertSame([], $missing['deployment_proposal']['team_ids']);
        $this->assertSame([], $missingDecision['selected_deployment_proposal']['team_ids']);
        $this->assertSame([], $missingDecision['selected_deployment_proposal']['teams']);

        Team::query()->create([
            'code' => 'UNAVAILABLE-BASE',
            'name' => 'Niet-operationeel basisteam',
            'type' => 'base',
            'is_operational' => false,
        ]);
        $inactiveEvaluation = $workflow->evaluate($configuration, 'person', $this->personAnswers());
        $inactive = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'inactive-base-team-create',
        ], $actor);
        $inactiveDecision = $deploymentRequests->decidePriority(
            DeploymentRequest::query()->findOrFail($inactive['id']),
            [
                'lock_version' => $inactive['lock_version'],
                'client_mutation_id' => 'inactive-base-team-decision',
                'priority' => 'low',
            ],
            $actor,
        );

        $this->assertSame([], $inactiveEvaluation['deployment_proposal']['team_ids']);
        $this->assertSame([], $inactiveEvaluation['deployment_proposal']['teams']);
        $this->assertSame([], $inactive['deployment_proposal']['team_ids']);
        $this->assertSame([], $inactiveDecision['selected_deployment_proposal']['team_ids']);
        $this->assertSame([], $inactiveDecision['selected_deployment_proposal']['teams']);
    }

    public function test_address_and_bound_answers_enforce_lengths_and_select_options_before_preparation(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();

        foreach ([
            ['person_name', str_repeat('a', 181)],
            ['last_seen_location', str_repeat('a', 256)],
            ['deployment_location', str_repeat('a', 256)],
            ['reporter_phone', str_repeat('1', 41)],
        ] as [$fieldKey, $value]) {
            try {
                $service->normalizeAnswers($configuration, 'person', [$fieldKey => $value], patch: true);
                $this->fail("Een te lang gebonden antwoord voor $fieldKey moet direct worden geweigerd.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey("answers.$fieldKey", $exception->errors());
            }
        }

        $deploymentFields = app(DeploymentFormService::class)->fields();
        $deploymentFields[] = [
            'key' => 'search_method',
            'label' => 'Zoekmethode',
            'type' => 'select',
            'visible' => true,
            'required' => false,
            'options' => [
                ['value' => 'air', 'label' => 'Lucht'],
                ['value' => 'ground', 'label' => 'Grond'],
            ],
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false],
        );
        $configuration = $service->defaultConfiguration();
        $configuration['fields'][] = [
            'key' => 'search_method_answer',
            'label' => 'Zoekmethode',
            'type' => 'select',
            'scope' => 'common',
            'required' => false,
            'operator_visible' => true,
            'help_text' => null,
            'options' => [['value' => 'air', 'label' => 'Lucht']],
        ];
        $configuration['bindings'][] = [
            'field_key' => 'search_method_answer',
            'target' => 'custom_fields.search_method',
        ];
        $service->validateConfiguration($configuration);

        $configuration['fields'][array_key_last($configuration['fields'])]['options'][] = [
            'value' => 'water',
            'label' => 'Water',
        ];
        try {
            $service->validateConfiguration($configuration);
            $this->fail('Een uitvraagkeuze buiten de inzetveldopties moet worden geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'configuration.bindings.'.(count($configuration['bindings']) - 1),
                $exception->errors(),
            );
        }
    }

    public function test_first_workflow_initialization_adopts_existing_required_flight_time_field_and_prepares_it(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('required-upgrade-field@example.test');
        $this->grant($actor, ['deployments.manage']);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        $deploymentFields[] = [
            'key' => 'search_window',
            'label' => 'Zoekvenster',
            'type' => 'flight_time',
            'visible' => true,
            'required' => true,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $workflow = app(DeploymentRequestWorkflowService::class);
        $published = $workflow->published();
        $binding = collect($published->configuration['bindings'])
            ->firstWhere('target', 'custom_fields.search_window');
        $this->assertIsArray($binding);

        $answers = $this->personAnswers();
        $answers[$binding['field_key']] = '23:30-00:15';
        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $answers,
            'client_mutation_id' => 'required-upgrade-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'required-upgrade-decide',
            'priority' => 'low',
        ], $actor);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'required-upgrade-prepare',
        ], $actor)['deployment'];

        $this->assertSame([
            'start' => '23:30',
            'end' => '00:15',
            'duration_minutes' => 45,
        ], $deployment->custom_fields['search_window']);
        $lockVersion = $deployment->deploymentRequest()->firstOrFail()->lock_version;
        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", [
                'custom_fields' => $deployment->custom_fields,
            ])
            ->assertOk();
        $this->assertSame(
            $lockVersion,
            $deployment->deploymentRequest()->firstOrFail()->lock_version,
        );
        $this->assertSame('low', $deployment->deploymentRequest()->firstOrFail()->decided_priority);
    }

    public function test_first_workflow_initialization_aligns_prebound_legacy_deployment_field_types(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('prebound-upgrade-fields@example.test');
        $this->grant($actor, ['forms.manage', 'deployments.manage']);
        $deploymentFields = array_values(array_filter(
            app(DeploymentFormService::class)->fields(),
            fn (array $field): bool => $field['key'] !== 'on_scene_contact_role',
        ));

        foreach ($deploymentFields as &$deploymentField) {
            if ($deploymentField['key'] === 'requesting_organization') {
                $deploymentField['type'] = 'select';
                $deploymentField['options'] = [
                    ['value' => 'police', 'label' => 'Politie'],
                    ['value' => 'fire_service', 'label' => 'Brandweer'],
                ];
            }
            if ($deploymentField['key'] === 'requesting_unit') {
                $deploymentField['type'] = 'number';
                $deploymentField['required'] = true;
            }
        }
        unset($deploymentField);

        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $configuration = $this->asWebClient($actor)
            ->getJson('/api/admin/deployment-request-workflow/config')
            ->assertOk()
            ->json('data.published.configuration');
        $fields = collect($configuration['fields'])->keyBy('key');
        $bindings = collect($configuration['bindings'])->keyBy('field_key');

        $this->assertSame('select', $fields['requesting_organization']['type']);
        $this->assertSame(
            ['police', 'fire_service'],
            array_column($fields['requesting_organization']['options'], 'value'),
        );
        $this->assertSame('number', $fields['requesting_unit']['type']);
        $this->assertTrue($fields['requesting_unit']['required']);
        $this->assertSame('on_scene_contact_role', $bindings['on_scene_contact_role']['target']);

        $answers = [
            ...$this->personAnswers(),
            'requesting_organization' => 'police',
            'requesting_unit' => 112,
        ];
        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $answers,
            'client_mutation_id' => 'prebound-upgrade-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'prebound-upgrade-decide',
            'priority' => 'low',
        ], $actor);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'prebound-upgrade-prepare',
        ], $actor)['deployment'];

        $this->assertSame('police', $deployment->requesting_organization);
        $this->assertSame('police', $deployment->custom_fields['requesting_organization']);
        $this->assertSame('112', $deployment->requesting_unit);
        $this->assertSame(112, $deployment->custom_fields['requesting_unit']);
    }

    public function test_historical_incompatible_on_scene_phone_type_repairs_before_workflow_initialization_and_prepares(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('repaired-on-scene-phone@example.test');
        $this->grant($actor, ['deployments.manage']);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        foreach ($deploymentFields as &$deploymentField) {
            if ($deploymentField['key'] === 'on_scene_contact_phone') {
                $deploymentField['type'] = 'number';
                $deploymentField['options'] = [];
                $deploymentField['phone_countries'] = [];
            }
        }
        unset($deploymentField);
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $repairedDeploymentField = collect(app(DeploymentFormService::class)->fields())
            ->firstWhere('key', 'on_scene_contact_phone');
        $this->assertIsArray($repairedDeploymentField);
        $this->assertSame('phone', $repairedDeploymentField['type']);
        $this->assertSame(['31', '32'], $repairedDeploymentField['phone_countries']);

        $workflow = app(DeploymentRequestWorkflowService::class)->published();
        $workflowField = collect($workflow->configuration['fields'])
            ->firstWhere('key', 'on_scene_contact_phone');
        $this->assertIsArray($workflowField);
        $this->assertSame('text', $workflowField['type']);

        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + [
                'on_scene_contact_phone' => '+31 6 12345678',
            ],
            'client_mutation_id' => 'repaired-phone-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'repaired-phone-decide',
            'priority' => 'low',
        ], $actor);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'repaired-phone-prepare',
        ], $actor)['deployment'];

        $this->assertSame('+31612345678', $deployment->on_scene_contact_phone);
        $this->assertSame('+31612345678', $deployment->custom_fields['on_scene_contact_phone']);
    }

    public function test_frozen_numeric_on_scene_phone_answer_prepares_after_live_form_repair(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('frozen-numeric-on-scene-phone@example.test');
        $this->grant($actor, ['forms.manage', 'deployments.manage']);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        foreach ($deploymentFields as &$deploymentField) {
            if ($deploymentField['key'] === 'on_scene_contact_phone') {
                $deploymentField['type'] = 'number';
                $deploymentField['options'] = [];
                $deploymentField['phone_countries'] = [];
            }
        }
        unset($deploymentField);
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $workflowService = app(DeploymentRequestWorkflowService::class);
        $historicalConfiguration = $workflowService->defaultConfiguration();
        foreach ($historicalConfiguration['fields'] as &$workflowField) {
            if ($workflowField['key'] === 'on_scene_contact_phone') {
                $workflowField['type'] = 'number';
            }
        }
        unset($workflowField);
        DeploymentRequestWorkflowRevision::query()->create([
            'version' => 1,
            'status' => 'published',
            'lock_version' => 1,
            'configuration' => $historicalConfiguration,
            'published_by' => $actor->id,
            'published_at' => now(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $form = app(DeploymentFormService::class);
        $this->asWebClient($actor)
            ->patchJson('/api/admin/deployment-form/config', [
                'fields' => $form->fields(),
                'layout' => $form->layout(),
            ])
            ->assertOk();
        $storedPhoneField = collect(SystemSetting::value(DeploymentFormService::SETTING_KEY, []))
            ->firstWhere('key', 'on_scene_contact_phone');
        $this->assertIsArray($storedPhoneField);
        $this->assertSame('phone', $storedPhoneField['type']);

        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => [
                ...$this->personAnswers(),
                'last_seen_location' => 'Utrecht, Nederland',
                'deployment_location' => 'Utrecht, Nederland',
                'on_scene_contact_phone' => 612345678,
            ],
            'client_mutation_id' => 'frozen-numeric-phone-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'frozen-numeric-phone-decide',
            'priority' => 'low',
        ], $actor);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'frozen-numeric-phone-prepare',
        ], $actor)['deployment'];

        $this->assertSame(612345678, $deploymentRequest->refresh()->answers['on_scene_contact_phone']);
        $this->assertSame('+31612345678', $deployment->on_scene_contact_phone);
        $this->assertSame('+31612345678', $deployment->custom_fields['on_scene_contact_phone']);
    }

    public function test_historical_incompatible_required_resources_type_repairs_and_prepares_proposal_text(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('repaired-required-resources@example.test');
        $this->grant($actor, ['deployments.manage']);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        foreach ($deploymentFields as &$deploymentField) {
            if ($deploymentField['key'] === 'required_resources') {
                $deploymentField['type'] = 'number';
                $deploymentField['options'] = [];
            }
        }
        unset($deploymentField);
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $repairedDeploymentField = collect(app(DeploymentFormService::class)->fields())
            ->firstWhere('key', 'required_resources');
        $this->assertIsArray($repairedDeploymentField);
        $this->assertSame('textarea', $repairedDeploymentField['type']);

        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'repaired-resources-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'repaired-resources-decide',
            'priority' => 'low',
        ], $actor);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'repaired-resources-prepare',
        ], $actor)['deployment'];

        $this->assertSame('Operationele droneploeg', $deployment->required_resources);
        $this->assertSame('Operationele droneploeg', $deployment->custom_fields['required_resources']);
    }

    public function test_deployment_form_rejects_new_incompatible_mirrored_types_and_preserves_compatible_types(): void
    {
        $actor = $this->user('strict-mirrored-types@example.test');
        $this->grant($actor, ['forms.manage']);
        $form = app(DeploymentFormService::class);
        $fields = $form->fields();
        $phoneIndex = collect($fields)->search(
            fn (array $field): bool => $field['key'] === 'on_scene_contact_phone',
        );
        $resourcesIndex = collect($fields)->search(
            fn (array $field): bool => $field['key'] === 'required_resources',
        );
        $this->assertIsInt($phoneIndex);
        $this->assertIsInt($resourcesIndex);

        foreach (['text', 'select', 'radio'] as $compatibleType) {
            $candidate = $fields;
            $candidate[$phoneIndex]['type'] = $compatibleType;
            $candidate[$phoneIndex]['options'] = in_array($compatibleType, ['select', 'radio'], true)
                ? [
                    ['value' => '+31611111111', 'label' => 'Meldkamer'],
                    ['value' => '+31622222222', 'label' => 'Leidinggevende'],
                ]
                : [];
            $validated = $form->validateFields($candidate);
            $this->assertSame(
                $compatibleType,
                collect($validated)->firstWhere('key', 'on_scene_contact_phone')['type'],
            );
        }

        $invalidPhoneOptions = $fields;
        $invalidPhoneOptions[$phoneIndex]['type'] = 'select';
        $invalidPhoneOptions[$phoneIndex]['options'] = [
            ['value' => 'meldkamer', 'label' => 'Meldkamer'],
            ['value' => 'leidinggevende', 'label' => 'Leidinggevende'],
        ];
        $invalidPhoneOptionsResponse = $this->asWebClient($actor)
            ->patchJson('/api/admin/deployment-form/config', [
                'fields' => $invalidPhoneOptions,
                'layout' => $form->layout(),
            ])
            ->assertUnprocessable();
        $this->assertSame(
            'Gebruik voor iedere telefoonkeuze een internationaal nummer, bijvoorbeeld +31612345678.',
            $invalidPhoneOptionsResponse->json('error.details')["fields.$phoneIndex.options"][0] ?? null,
        );

        $invalidPhoneFields = $fields;
        $invalidPhoneFields[$phoneIndex]['type'] = 'number';
        $invalidPhoneResponse = $this->asWebClient($actor)
            ->patchJson('/api/admin/deployment-form/config', [
                'fields' => $invalidPhoneFields,
                'layout' => $form->layout(),
            ])
            ->assertUnprocessable();
        $this->assertSame(
            'Telefoon ter plaatse ondersteunt alleen telefoon, tekst, dropdown of radio.',
            $invalidPhoneResponse->json('error.details')["fields.$phoneIndex.type"][0] ?? null,
        );

        $invalidResourcesFields = $fields;
        $invalidResourcesFields[$resourcesIndex]['type'] = 'select';
        $invalidResourcesFields[$resourcesIndex]['options'] = [
            ['value' => 'drone', 'label' => 'Drone'],
            ['value' => 'coordination', 'label' => 'Coördinatie'],
        ];
        $invalidResourcesResponse = $this->asWebClient($actor)
            ->patchJson('/api/admin/deployment-form/config', [
                'fields' => $invalidResourcesFields,
                'layout' => $form->layout(),
            ])
            ->assertUnprocessable();
        $this->assertSame(
            'Benodigde middelen ondersteunt alleen tekst of tekstvak.',
            $invalidResourcesResponse->json('error.details')["fields.$resourcesIndex.type"][0] ?? null,
        );
    }

    public function test_prepared_bound_number_keeps_current_deployment_form_range(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('bound-number-range@example.test');
        $this->grant($actor, ['forms.manage', 'deployments.manage']);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        $deploymentFields[] = [
            'key' => 'search_radius',
            'label' => 'Zoekstraal',
            'type' => 'number',
            'visible' => true,
            'required' => false,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );
        $workflow = app(DeploymentRequestWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['fields'][] = [
            'key' => 'search_radius_answer',
            'label' => 'Zoekstraal',
            'type' => 'number',
            'scope' => 'common',
            'required' => false,
            'operator_visible' => true,
            'help_text' => null,
            'options' => [],
        ];
        $configuration['bindings'][] = [
            'field_key' => 'search_radius_answer',
            'target' => 'custom_fields.search_radius',
        ];
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $workflow->publishDraft($updated['draft']['lock_version'], $actor);

        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + ['search_radius_answer' => 10],
            'client_mutation_id' => 'bound-number-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'bound-number-decide',
            'priority' => 'low',
        ], $actor);
        $prepared = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'bound-number-prepare',
        ], $actor);

        try {
            $deploymentRequests->patch($deploymentRequest, [
                'lock_version' => $prepared['deployment_request']['lock_version'],
                'client_mutation_id' => 'bound-number-invalid',
                'changes' => ['answers' => ['search_radius_answer' => 1000000]],
            ], $actor);
            $this->fail('Een gebonden getal buiten het deploymentformulierbereik moet worden geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.search_radius_answer', $exception->errors());
        }
        $this->assertSame(10, $prepared['deployment']->refresh()->custom_fields['search_radius']);
    }

    public function test_unknown_higher_priority_information_never_falls_back_to_low(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();
        foreach ($configuration['fields'] as &$field) {
            if ($field['key'] === 'immediate_danger') {
                $field['required'] = false;
            }
        }
        unset($field);
        $configuration = $service->validateConfiguration($configuration);
        $answers = $this->personAnswers();
        unset($answers['immediate_danger']);

        $result = $service->evaluate($configuration, 'person', $answers);

        $this->assertSame('unknown', $result['triage']['state']);
        $this->assertNull($result['triage']['recommended_priority']);
        $this->assertNull($result['deployment_proposal']);
    }

    public function test_unknown_equal_priority_rule_with_another_profile_is_fail_safe(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();
        $alternateProfile = $configuration['deployment_profiles'][2];
        $alternateProfile['id'] = 'alternate_urgent_response';
        $alternateProfile['label'] = 'Alternatieve urgente inzet';
        $configuration['deployment_profiles'][] = $alternateProfile;
        array_splice($configuration['priority_rules'], 1, 0, [[
            'id' => 'urgent_unknown_alternative',
            'label' => 'Urgente alternatieve inzet',
            'subject_types' => ['person'],
            'match' => 'all',
            'conditions' => [[
                'field_key' => 'reporter_name',
                'operator' => 'equals',
                'value' => 'Bevestigd',
            ]],
            'priority' => 'urgent',
            'explanation' => 'Alternatieve urgente inzet kan nodig zijn.',
            'deployment_profile_id' => 'alternate_urgent_response',
        ]]);
        $configuration = $service->validateConfiguration($configuration);
        $answers = $this->personAnswers();
        $answers['immediate_danger'] = true;

        $result = $service->evaluate($configuration, 'person', $answers);

        $this->assertSame('unknown', $result['triage']['state']);
        $this->assertNull($result['triage']['recommended_priority']);
        $this->assertNull($result['deployment_proposal']);
    }

    public function test_equal_highest_priority_rules_follow_configuration_order_deterministically(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();
        $equalPriorityRules = [
            [
                'id' => 'medium_first',
                'label' => 'Eerste middelregel',
                'subject_types' => ['person'],
                'match' => 'all',
                'conditions' => [[
                    'field_key' => 'person_age',
                    'operator' => 'greater_than_or_equal',
                    'value' => 1,
                ]],
                'priority' => 'medium',
                'explanation' => 'Eerste regel in de configuratie.',
                'deployment_profile_id' => 'standard_response',
            ],
            [
                'id' => 'medium_second',
                'label' => 'Tweede middelregel',
                'subject_types' => ['person'],
                'match' => 'all',
                'conditions' => [[
                    'field_key' => 'person_age',
                    'operator' => 'greater_than_or_equal',
                    'value' => 1,
                ]],
                'priority' => 'medium',
                'explanation' => 'Tweede regel in de configuratie.',
                'deployment_profile_id' => 'standard_response',
            ],
        ];
        array_splice($configuration['priority_rules'], 2, 0, $equalPriorityRules);
        $configuration = $service->validateConfiguration($configuration);

        $result = $service->evaluate($configuration, 'person', $this->personAnswers());

        $this->assertSame('determined', $result['triage']['state']);
        $this->assertSame('medium', $result['triage']['recommended_priority']);
        $this->assertSame(
            ['Eerste regel in de configuratie.', 'Tweede regel in de configuratie.'],
            $result['triage']['reasons'],
        );
    }

    public function test_text_rule_equality_is_type_and_value_strict(): void
    {
        $service = app(DeploymentRequestWorkflowService::class);
        $configuration = $service->defaultConfiguration();
        array_splice($configuration['priority_rules'], 2, 0, [[
            'id' => 'medium_numeric_looking_text',
            'label' => 'Exacte tekstvergelijking',
            'subject_types' => ['person'],
            'match' => 'all',
            'conditions' => [[
                'field_key' => 'circumstances',
                'operator' => 'equals',
                'value' => '0e456',
            ]],
            'priority' => 'medium',
            'explanation' => 'Exacte tekst kwam overeen.',
            'deployment_profile_id' => 'standard_response',
        ]]);
        $configuration = $service->validateConfiguration($configuration);
        $answers = $this->personAnswers();
        $answers['circumstances'] = '0e123';

        $result = $service->evaluate($configuration, 'person', $answers);

        $this->assertSame('low', $result['triage']['recommended_priority']);
    }

    public function test_deployment_request_autosave_is_idempotent_conflict_safe_and_preserves_inactive_subject_answers(): void
    {
        Event::fake([DeploymentRequestChanged::class]);
        $actor = $this->user('deployment-request@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.priority.override']);
        $service = app(DeploymentRequestService::class);

        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + ['medical_details' => 'Alleen meldkamer'],
            'client_mutation_id' => 'create-1',
        ], $actor);
        $replayed = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + ['medical_details' => 'Alleen meldkamer'],
            'client_mutation_id' => 'create-1',
        ], $actor);

        $this->assertSame($created['id'], $replayed['id']);
        $this->assertDatabaseCount('deployment_requests', 1);
        $this->assertSame('determined', $created['triage']['state']);
        $this->assertSame('low', $created['triage']['recommended_priority']);
        $storedMutationPayload = DeploymentRequestMutation::query()
            ->where('client_mutation_id', 'create-1')
            ->firstOrFail()
            ->response_payload;
        $this->assertCount(4, $storedMutationPayload);
        $this->assertSame(2, $storedMutationPayload['request_hash_version']);
        $this->assertArrayHasKey('deployment_request_id', $storedMutationPayload);
        $this->assertArrayHasKey('lock_version', $storedMutationPayload);
        $this->assertArrayHasKey('deployment_id', $storedMutationPayload);
        $this->assertArrayNotHasKey('answers', $storedMutationPayload);

        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $switched = $service->patch($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'patch-animal',
            'changes' => [
                'subject_type' => 'animal',
                'answers' => [
                    'animal_name' => 'Bello',
                    'animal_species' => 'Hond',
                ],
            ],
        ], $actor);
        $this->assertArrayHasKey('person_age', (array) $switched['answers']);
        $this->assertNotContains('person_age', array_column($switched['answer_rows'], 'key'));
        $this->assertContains('animal_species', array_column($switched['answer_rows'], 'key'));

        try {
            $service->patch($deploymentRequest, [
                'lock_version' => $created['lock_version'],
                'client_mutation_id' => 'stale-patch',
                'changes' => ['answers' => ['animal_name' => 'Max']],
            ], $actor);
            $this->fail('Een verouderde lock_version moet conflicteren.');
        } catch (DeploymentRequestConflictException $exception) {
            $this->assertSame('deployment_request_version_conflict', $exception->errorCode);
            $this->assertSame($switched['lock_version'], $exception->current['lock_version']);
        }

        Event::assertDispatched(DeploymentRequestChanged::class);
    }

    public function test_deployment_request_title_is_additive_editable_and_uses_a_neutral_legacy_fallback(): void
    {
        $actor = $this->user('deployment-request-title@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);

        $created = $service->create([
            'title' => 'Zoekactie Utrecht Centraal',
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'title-create',
        ], $actor);
        $this->assertSame('Zoekactie Utrecht Centraal', $created['title']);
        $this->assertDatabaseHas('deployment_requests', [
            'id' => $created['id'],
            'title' => 'Zoekactie Utrecht Centraal',
        ]);

        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'title-decision',
            'priority' => 'low',
        ], $actor);
        $selectedProfileId = $decided['selected_deployment_proposal']['profile_id'];
        $triage = $decided['triage'];

        $renamed = $service->patch($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'title-patch',
            'changes' => ['title' => 'Vermist persoon Utrecht'],
        ], $actor);

        $this->assertSame('Vermist persoon Utrecht', $renamed['title']);
        $this->assertSame('low', $renamed['decided_priority']);
        $this->assertSame($selectedProfileId, $renamed['selected_deployment_proposal']['profile_id']);
        $this->assertSame($triage, $renamed['triage']);
        $titleAudit = AuditLog::query()
            ->where('target_id', $deploymentRequest->id)
            ->where('action', 'deployment_requests.patch')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame('Zoekactie Utrecht Centraal', $titleAudit->metadata['title_from']);
        $this->assertSame('Vermist persoon Utrecht', $titleAudit->metadata['title_to']);

        $invalidCreate = $this->asWebClient($actor)->postJson('/api/deployment-requests', [
            'title' => '   ',
            'subject_type' => 'person',
            'client_mutation_id' => 'title-blank-create',
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertArrayHasKey('title', $invalidCreate->json('error.details'));

        $invalidPatch = $this->asWebClient($actor)->patchJson("/api/deployment-requests/{$deploymentRequest->id}", [
            'lock_version' => $renamed['lock_version'],
            'client_mutation_id' => 'title-blank-route-patch',
            'changes' => ['title' => '   '],
        ])->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed');
        $this->assertArrayHasKey('changes.title', $invalidPatch->json('error.details'));

        try {
            $service->patch($deploymentRequest, [
                'lock_version' => $renamed['lock_version'],
                'client_mutation_id' => 'title-blank-service-patch',
                'changes' => ['title' => '   '],
            ], $actor);
            $this->fail('Een expliciet lege titel mag ook buiten de HTTP-validatie niet worden opgeslagen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('changes.title', $exception->errors());
            $this->assertSame('Vermist persoon Utrecht', $deploymentRequest->refresh()->title);
            $this->assertSame($renamed['lock_version'], $deploymentRequest->lock_version);
        }

        $legacy = $service->create([
            'subject_type' => 'animal',
            'client_mutation_id' => 'title-legacy-create',
        ], $actor);
        $this->assertSame('Aanvraag', $legacy['title']);
        $this->assertNull(DeploymentRequest::query()->findOrFail($legacy['id'])->title);
        $this->assertStringNotContainsString('Uitvraag', $legacy['title']);
        $this->assertStringNotContainsString('dier', mb_strtolower($legacy['title']));
    }

    public function test_deployment_request_delete_requires_the_dedicated_permission_at_route_and_service_layers(): void
    {
        $actor = $this->user('deployment-request-delete-denied@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'title' => 'Niet verwijderbaar',
            'subject_type' => 'object',
            'client_mutation_id' => 'delete-denied-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);

        $this->asWebClient($actor)
            ->deleteJson("/api/deployment-requests/{$deploymentRequest->id}", [
                'lock_version' => $created['lock_version'],
            ])
            ->assertForbidden();

        try {
            $service->delete($deploymentRequest, $created['lock_version'], $actor);
            $this->fail('Verwijderen zonder het afzonderlijke aanvraagrecht moet worden geweigerd.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('deployment_requests', ['id' => $deploymentRequest->id]);
        }

        $deleteOnlyActor = $this->user('deployment-request-delete-only@example.test');
        $this->grant($deleteOnlyActor, ['deployment-requests.delete']);
        $this->asWebClient($deleteOnlyActor)
            ->deleteJson("/api/deployment-requests/{$deploymentRequest->id}", [
                'lock_version' => $created['lock_version'],
            ])
            ->assertForbidden();

        try {
            $service->delete($deploymentRequest, $created['lock_version'], $deleteOnlyActor);
            $this->fail('Het verwijderrecht zonder aanvraagbeheer mag geen dossier verwijderen.');
        } catch (AuthorizationException) {
            $this->assertDatabaseHas('deployment_requests', ['id' => $deploymentRequest->id]);
        }
    }

    public function test_authorized_deployment_request_delete_cascades_mutations_audits_safely_and_broadcasts_after_commit(): void
    {
        Event::fake([DeploymentRequestDeleted::class]);
        $actor = $this->user('deployment-request-delete@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.delete']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'title' => 'Foutief aangemaakte aanvraag',
            'subject_type' => 'person',
            'answers' => ['person_name' => 'Niet in audit opnemen'],
            'client_mutation_id' => 'delete-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $patched = $service->patch($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'delete-patch',
            'changes' => ['answers' => ['person_clothing' => 'Evenmin in audit']],
        ], $actor);
        $this->assertDatabaseCount('deployment_request_mutations', 2);

        $this->asWebClient($actor)
            ->deleteJson("/api/deployment-requests/{$deploymentRequest->id}", [
                'lock_version' => $patched['lock_version'],
            ])
            ->assertNoContent();

        $this->assertDatabaseMissing('deployment_requests', ['id' => $deploymentRequest->id]);
        $this->assertDatabaseMissing('deployment_request_mutations', [
            'deployment_request_id' => $deploymentRequest->id,
        ]);
        $audit = AuditLog::query()
            ->where('target_id', $deploymentRequest->id)
            ->where('action', 'deployment_requests.deleted')
            ->firstOrFail();
        $this->assertSame('Foutief aangemaakte aanvraag', $audit->metadata['title']);
        $this->assertSame('open', $audit->metadata['status']);
        $this->assertSame('person', $audit->metadata['subject_type']);
        $this->assertSame(2, $audit->metadata['mutation_count']);
        $this->assertArrayNotHasKey('answers', $audit->metadata);
        $this->assertStringNotContainsString('Niet in audit opnemen', json_encode($audit->metadata, JSON_THROW_ON_ERROR));
        Event::assertDispatched(
            DeploymentRequestDeleted::class,
            function (DeploymentRequestDeleted $event) use ($deploymentRequest): bool {
                $payload = $event->broadcastWith();

                return $event->deploymentRequestId === (string) $deploymentRequest->id
                    && $event->broadcastAs() === 'deployment-request.changed'
                    && $payload['action'] === 'deleted'
                    && $payload['deleted'] === true
                    && $payload['deployment_request_id'] === (string) $deploymentRequest->id;
            },
        );
    }

    public function test_deployment_request_delete_rejects_stale_versions_and_linked_deployments(): void
    {
        $actor = $this->user('deployment-request-delete-guard@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.delete']);
        $client = $this->asWebClient($actor);
        $created = $client->postJson('/api/deployment-requests', [
            'title' => 'Te koppelen aanvraag',
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'delete-guard-create',
        ])->assertCreated()->json('data');
        $patched = $client->patchJson("/api/deployment-requests/{$created['id']}", [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'delete-guard-patch',
            'changes' => ['title' => 'Actuele titel'],
        ])->assertOk()->json('data');

        $client->deleteJson("/api/deployment-requests/{$created['id']}", [
            'lock_version' => $created['lock_version'],
        ])->assertConflict()
            ->assertJsonPath('error.code', 'deployment_request_version_conflict')
            ->assertJsonPath('error.details.current.lock_version', $patched['lock_version'])
            ->assertJsonPath('error.details.current.id', $created['id'])
            ->assertJsonMissingPath('error.details.current.title')
            ->assertJsonMissingPath('error.details.current.answers')
            ->assertJsonMissingPath('error.details.current.answer_rows')
            ->assertJsonMissingPath('error.details.current.triage');

        $decided = $client->patchJson("/api/deployment-requests/{$created['id']}/priority", [
            'lock_version' => $patched['lock_version'],
            'client_mutation_id' => 'delete-guard-decision',
            'priority' => 'low',
        ])->assertOk()->json('data');
        $prepared = $client->postJson("/api/deployment-requests/{$created['id']}/prepare-deployment", [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'delete-guard-prepare',
        ])->assertCreated()->json('data.deployment_request');

        $client->deleteJson("/api/deployment-requests/{$created['id']}", [
            'lock_version' => $prepared['lock_version'],
        ])->assertUnprocessable()
            ->assertJsonPath(
                'error.details.deployment_request.0',
                'Een aanvraag met een gekoppelde inzet kan niet afzonderlijk worden verwijderd. Verwijder de inzet via inzetbeheer.',
            );
        $this->assertDatabaseHas('deployment_requests', [
            'id' => $created['id'],
            'deployment_id' => $prepared['deployment_id'],
            'status' => 'prepared',
        ]);
    }

    public function test_closed_deployment_request_metadata_is_immutable_for_new_mutations(): void
    {
        $actor = $this->user('close-deployment-request@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'close-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $closed = $service->close($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'close-once',
            'reason' => 'Geen inzet nodig.',
        ], $actor);
        $firstClosedAt = $deploymentRequest->refresh()->closed_at;

        try {
            $service->close($deploymentRequest, [
                'lock_version' => $closed['lock_version'],
                'client_mutation_id' => 'close-again',
                'reason' => 'Overschreven reden.',
            ], $actor);
            $this->fail('Een afgesloten dossier mag niet opnieuw worden afgesloten.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $deploymentRequest->refresh();
        $this->assertSame('Geen inzet nodig.', $deploymentRequest->close_reason);
        $this->assertTrue($firstClosedAt->equalTo($deploymentRequest->closed_at));
    }

    public function test_decision_override_requires_permission_and_reason_and_content_changes_reset_decision(): void
    {
        $actor = $this->user('decision@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'decision-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);

        try {
            $service->decidePriority($deploymentRequest, [
                'lock_version' => $created['lock_version'],
                'client_mutation_id' => 'override-denied',
                'priority' => 'urgent',
                'reason' => 'Acuut telefoongesprek.',
            ], $actor);
            $this->fail('Afwijken zonder recht moet worden geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('priority', $exception->errors());
        }

        $this->grant($actor, ['deployment-requests.priority.override']);
        $actor->unsetRelation('roles');
        try {
            $service->decidePriority($deploymentRequest, [
                'lock_version' => $created['lock_version'],
                'client_mutation_id' => 'override-no-reason',
                'priority' => 'urgent',
            ], $actor);
            $this->fail('Een gemotiveerde afwijking heeft een reden nodig.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'override-ok',
            'priority' => 'urgent',
            'reason' => 'Nieuwe informatie van de melder.',
        ], $actor);
        $this->assertSame('urgent', $decided['decided_priority']);
        $this->assertSame('urgent_response', $decided['selected_deployment_proposal']['profile_id']);

        $patched = $service->patch($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'content-after-decision',
            'changes' => ['answers' => ['last_seen_direction' => 'Noord']],
        ], $actor);
        $this->assertNull($patched['decided_priority']);
        $this->assertNull($patched['selected_deployment_proposal']);

        $service->decidePriority($deploymentRequest, [
            'lock_version' => $patched['lock_version'],
            'client_mutation_id' => 'override-second',
            'priority' => 'urgent',
            'reason' => 'Tweede gemotiveerde beoordeling.',
        ], $actor);
        $reasons = DB::table('audit_logs')
            ->where('target_id', $deploymentRequest->id)
            ->where('action', 'deployment_requests.priority')
            ->orderBy('created_at')
            ->pluck('reason')
            ->all();
        $this->assertContains('Nieuwe informatie van de melder.', $reasons);
        $this->assertContains('Tweede gemotiveerde beoordeling.', $reasons);
    }

    public function test_incomplete_deployment_request_cannot_prepare_a_deployment_or_create_dispatch_side_effects(): void
    {
        Queue::fake();
        Event::fake([DispatchChanged::class]);
        $actor = $this->user('incomplete-prepare@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.priority.override']);
        $service = app(DeploymentRequestService::class);
        $answers = $this->personAnswers();
        unset($answers['person_age']);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $answers,
            'client_mutation_id' => 'incomplete-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'incomplete-decision',
            'priority' => 'low',
            'reason' => 'Handmatig beoordeeld, maar dossier blijft onvolledig.',
        ], $actor);

        try {
            $service->prepareDeployment($deploymentRequest, [
                'lock_version' => $decided['lock_version'],
                'client_mutation_id' => 'incomplete-prepare',
            ], $actor);
            $this->fail('Een onvolledig dossier mag geen deployment worden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('triage', $exception->errors());
        }

        $this->assertDatabaseCount('deployments', 0);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);
        Queue::assertNotPushed(SendFcmNotification::class);
        Event::assertNotDispatched(DispatchChanged::class);
    }

    public function test_preparation_rejects_a_missing_bound_title_before_database_write(): void
    {
        $actor = $this->user('missing-title-prepare@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.priority.override']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'missing-title-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'missing-title-decision',
            'priority' => 'low',
        ], $actor);

        // Simulate a historical/corrupted frozen row that predates strict core
        // binding validation. Promotion must fail as validation, never as a
        // database NOT NULL exception.
        $answers = $deploymentRequest->refresh()->answers;
        unset($answers['person_name']);
        $deploymentRequest->forceFill(['answers' => $answers])->save();

        try {
            $service->prepareDeployment($deploymentRequest, [
                'lock_version' => $decided['lock_version'],
                'client_mutation_id' => 'missing-title-prepare',
            ], $actor);
            $this->fail('Een dossier zonder gekoppelde deploymenttitel mag niet promoveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bindings', $exception->errors());
        }

        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_realert_is_blocked_after_linked_deployment_request_content_invalidates_the_decision(): void
    {
        Queue::fake();
        $actor = $this->user('stale-realert-manager@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.priority.override']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'stale-realert-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'stale-realert-decision',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'stale-realert-prepare',
        ], $actor);
        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $prepared['deployment']->id,
            'requested_by' => $actor->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Bestaande definitieve alarmering',
            'sent_at' => now(),
        ]);
        $pilot = $this->user('stale-realert-pilot@example.test');
        $pilot->forceFill(['push_enabled' => true])->save();
        $operatorSession = $pilot->createToken(
            'Stale re-alert operator',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $operatorSession->id,
            'device_id' => 'stale-realert-device',
            'token' => 'stale-realert-token',
            'token_hash' => hash('sha256', 'stale-realert-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        $recipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id,
            'user_id' => $pilot->id,
            'response_status' => 'pending',
        ]);
        $patched = $service->patch($deploymentRequest, [
            'lock_version' => $prepared['deployment_request']['lock_version'],
            'client_mutation_id' => 'stale-realert-content-change',
            'changes' => ['answers' => ['circumstances' => 'Nieuwe inhoud vereist een nieuw besluit.']],
        ], $actor);
        $this->assertNull($patched['decided_priority']);
        $this->assertFalse($prepared['deployment']->refresh()->deployment_request_decision_valid);
        $this->assertSame('sent', $dispatch->refresh()->status);

        try {
            app(DispatchService::class)->reAlert($dispatch, $actor);
            $this->fail('Een heralarmering mag een ongeldig geworden aanvraagbesluit niet omzeilen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }

        Queue::assertNotPushed(SendFcmNotification::class);
        $this->assertNull($recipient->refresh()->notified_at);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'dispatch.realerted',
            'target_id' => $dispatch->id,
        ]);
    }

    public function test_prepare_deployment_creates_exactly_one_draft_deployment_and_linked_edits_refresh_payload(): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://nominatim.openstreetmap.org/search')) {
                return Http::response([], 503);
            }
            if (str_starts_with($request->url(), 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free')) {
                $point = $request['q'] === '"Brandweerkazerne Utrecht"'
                    ? 'POINT(5.1224000 52.0917000)'
                    : 'POINT(5.1214000 52.0907000)';

                return Http::response([
                    'response' => ['docs' => [[
                        'centroide_ll' => $point,
                        'weergavenaam' => $request['q'] === '"Brandweerkazerne Utrecht"'
                            ? 'Brandweerkazerne Utrecht'
                            : 'Kazerne Utrecht',
                    ]]],
                ]);
            }

            return Http::response([], 503);
        });
        Queue::fake();
        Event::fake([DeploymentRequestChanged::class, DispatchChanged::class]);
        $actor = $this->user('prepare@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.priority.override']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + [
                'requesting_unit' => 'Eenheid Midden-Nederland',
                'on_scene_contact_name' => 'Piet',
                'on_scene_contact_phone' => '+31 6 12345678',
            ],
            'client_mutation_id' => 'prepare-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'prepare-decision',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'prepare-once',
        ], $actor);
        $replayed = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'prepare-once',
        ], $actor);

        $this->assertSame($prepared['deployment']->id, $replayed['deployment']->id);
        $this->assertDatabaseCount('deployments', 1);
        $this->assertSame('draft', $prepared['deployment']->status);
        $this->assertSame('low', $prepared['deployment']->priority);
        $this->assertSame('Zoekactie in Utrecht', $prepared['deployment']->description);
        $this->assertSame('Kazerne Utrecht', $prepared['deployment']->location_label);
        $this->assertSame('52.0907000', $prepared['deployment']->latitude);
        $this->assertSame('5.1214000', $prepared['deployment']->longitude);
        $this->assertSame('52.0907', (string) data_get($prepared['deployment']->drone_flight_context, 'location.latitude'));
        $this->assertSame('5.1214', (string) data_get($prepared['deployment']->drone_flight_context, 'location.longitude'));
        $this->assertSame('Politie', $prepared['deployment']->requesting_organization);
        $this->assertSame('Politie', $prepared['deployment']->custom_fields['requesting_organization']);
        $this->assertSame('Operationele droneploeg', $prepared['deployment']->required_resources);
        $this->assertSame('Operationele droneploeg', $prepared['deployment']->custom_fields['required_resources']);
        $this->assertSame('+31612345678', $prepared['deployment']->on_scene_contact_phone);
        $this->assertSame('+31612345678', $prepared['deployment']->custom_fields['on_scene_contact_phone']);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);
        Queue::assertNotPushed(SendFcmNotification::class);
        Event::assertNotDispatched(DispatchChanged::class);

        $staleDraft = DispatchRequest::query()->create([
            'deployment_id' => $prepared['deployment']->id,
            'requested_by' => $actor->id,
            'status' => 'draft',
            'priority' => 'normal',
            'message' => 'Oud inzetvoorstel',
        ]);
        $notifiedPilot = $this->user('stale-preannouncement-pilot@example.test');
        $notifiedPilot->forceFill(['push_enabled' => true])->save();
        $operatorSession = $notifiedPilot->createToken(
            'Stale preannouncement operator',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        $staleToken = FcmToken::query()->create([
            'user_id' => $notifiedPilot->id,
            'personal_access_token_id' => $operatorSession->id,
            'device_id' => 'stale-preannouncement-device',
            'token' => 'stale-preannouncement-token',
            'token_hash' => hash('sha256', 'stale-preannouncement-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $staleDraft->id,
            'user_id' => $notifiedPilot->id,
            'response_status' => 'pending',
            'notified_at' => now(),
        ]);
        $linked = $service->patch($deploymentRequest, [
            'lock_version' => $prepared['deployment_request']['lock_version'],
            'client_mutation_id' => 'linked-update',
            'changes' => ['answers' => [
                'last_seen_location' => 'Utrecht Overvecht',
                'circumstances' => 'Aanvullende informatie ontvangen',
                'requesting_organization' => 'Brandweer',
            ]],
        ], $actor);
        $deployment = $prepared['deployment']->refresh();
        $this->assertSame('Aanvullende informatie ontvangen', $deployment->description);
        $this->assertSame('Brandweer', $deployment->requesting_organization);
        $this->assertSame('Brandweer', $deployment->custom_fields['requesting_organization']);
        $this->assertSame('Kazerne Utrecht', $deployment->location_label);
        $this->assertNotNull($deployment->updated_at);
        $this->assertSame($linked['lock_version'], $deployment->deploymentRequest()->firstOrFail()->lock_version);
        $this->assertFalse($deployment->deployment_request_decision_valid);
        $this->assertSame('Operationele droneploeg', $deployment->required_resources);
        $this->assertSame(
            $prepared['deployment_request']['selected_deployment_proposal'],
            $linked['selected_deployment_proposal'],
        );
        $this->assertEqualsCanonicalizing(
            $prepared['deployment']->teams()->pluck('teams.id')->all(),
            $deployment->teams()->pluck('teams.id')->all(),
        );
        $this->assertSame('cancelled', $staleDraft->refresh()->status);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $staleToken->id
            && $job->messageType === 'deployment_preannouncement_cancelled'
            && ($job->data['type'] ?? null) === 'deployment_preannouncement_cancelled'
            && ($job->data['action_mode'] ?? null) === 'availability_cancelled');
        $locationPatched = $service->patch($deploymentRequest, [
            'lock_version' => $linked['lock_version'],
            'client_mutation_id' => 'linked-deployment-location-update',
            'changes' => ['answers' => [
                'deployment_location' => 'Brandweerkazerne Utrecht',
            ]],
        ], $actor);
        $deployment->refresh();
        $this->assertSame('Brandweerkazerne Utrecht', $deployment->location_label);
        $this->assertSame('52.0917000', $deployment->latitude);
        $this->assertSame('5.1224000', $deployment->longitude);
        $this->assertSame('52.0917', (string) data_get($deployment->drone_flight_context, 'location.latitude'));
        $this->assertSame('5.1224', (string) data_get($deployment->drone_flight_context, 'location.longitude'));
        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", [
                'status' => 'active',
                'status_reason' => 'Mag niet zonder herbeoordeling.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $redecided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $locationPatched['lock_version'],
            'client_mutation_id' => 'linked-redecision',
            'priority' => 'low',
        ], $actor);
        $this->assertTrue($deployment->refresh()->deployment_request_decision_valid);
        $this->assertSame('Operationele droneploeg', $deployment->required_resources);
        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", [
                'requesting_organization' => 'Reddingsbrigade',
                'on_scene_contact_phone' => '+31 6 87654321',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.deployment_request.0', 'Wijzig gekoppelde uitvraagvelden via het aanvraagdossier.');
        $deployment->refresh();
        $unchangedDeploymentRequest = $deployment->deploymentRequest()->firstOrFail();
        $this->assertSame('Brandweer', $deployment->requesting_organization);
        $this->assertSame('+31612345678', $deployment->on_scene_contact_phone);
        $this->assertSame('Brandweer', $unchangedDeploymentRequest->answers['requesting_organization']);
        $this->assertSame('+31 6 12345678', $unchangedDeploymentRequest->answers['on_scene_contact_phone']);
        $this->assertSame('low', $unchangedDeploymentRequest->decided_priority);
        $this->assertSame($redecided['lock_version'], $unchangedDeploymentRequest->lock_version);
        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", ['priority' => 'high'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['priority']]]);
        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", [
                'custom_fields' => ['required_resources' => 'Omzeilde inzet'],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['required_resources']]]);
        $this->assertSame('low', $deployment->refresh()->priority);
        $this->assertSame('Operationele droneploeg', $deployment->required_resources);
        Event::assertDispatched(DeploymentRequestChanged::class, fn (DeploymentRequestChanged $event): bool => $event->deploymentRequest->deployment_id === $deployment->id);
    }

    public function test_linked_request_partial_patch_preserves_an_existing_deployment_description_when_the_frozen_answer_is_missing(): void
    {
        $actor = $this->user('linked-request-partial-patch@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'linked-partial-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'linked-partial-decision',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'linked-partial-prepare',
        ], $actor);

        $linkedRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $legacyAnswers = $linkedRequest->answers;
        unset($legacyAnswers['circumstances']);
        $linkedRequest->forceFill(['answers' => $legacyAnswers])->save();
        $deployment = $prepared['deployment']->refresh();
        $this->assertSame('Zoekactie in Utrecht', $deployment->description);

        $client = $this->asWebClient($actor);
        $partiallyPatched = $client
            ->patchJson("/api/deployments/{$deployment->id}/deployment-request", [
                'lock_version' => $linkedRequest->lock_version,
                'client_mutation_id' => 'linked-partial-unrelated-answer',
                'changes' => [
                    'answers' => ['last_seen_direction' => 'Noord'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.answers.last_seen_direction', 'Noord')
            ->json('data');

        $this->assertSame('Zoekactie in Utrecht', $deployment->refresh()->description);

        $restored = $client
            ->patchJson("/api/deployments/{$deployment->id}/deployment-request", [
                'lock_version' => $partiallyPatched['lock_version'],
                'client_mutation_id' => 'linked-partial-restore-description',
                'changes' => [
                    'answers' => ['circumstances' => 'Nieuwe inzetomschrijving'],
                ],
            ])
            ->assertOk()
            ->json('data');
        $this->assertSame('Nieuwe inzetomschrijving', $deployment->refresh()->description);

        $client
            ->patchJson("/api/deployments/{$deployment->id}/deployment-request", [
                'lock_version' => $restored['lock_version'],
                'client_mutation_id' => 'linked-partial-clear-description',
                'changes' => [
                    'answers' => ['circumstances' => null],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.details.answers.0',
                'Een gekoppelde inzet moet altijd een ingevuld veld voor description behouden.',
            );
        $this->assertSame('Nieuwe inzetomschrijving', $deployment->refresh()->description);
    }

    public function test_migrated_legacy_prepare_mutation_replays_only_with_the_legacy_hash_marker(): void
    {
        $actor = $this->user('legacy-prepare-replay@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'legacy-prepare-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'legacy-prepare-decision',
            'priority' => 'low',
        ], $actor);
        $input = [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'legacy-prepare-once',
        ];
        $prepared = $service->prepareDeployment($deploymentRequest, $input, $actor);
        $mutation = DeploymentRequestMutation::query()
            ->where('client_mutation_id', $input['client_mutation_id'])
            ->firstOrFail();
        $requestHash = new \ReflectionMethod(DeploymentRequestService::class, 'requestHash');
        $legacyHash = $requestHash->invoke($service, 'promote', $input);
        $canonicalHash = $requestHash->invoke($service, 'prepare_deployment', $input);
        $this->assertNotSame($canonicalHash, $legacyHash);

        $responsePayload = $mutation->response_payload;
        $responsePayload['request_hash_version'] = 1;
        $mutation->forceFill([
            'request_hash' => $legacyHash,
            'response_payload' => $responsePayload,
        ])->save();

        $replayed = $service->prepareDeployment($deploymentRequest, $input, $actor);
        $this->assertSame($prepared['deployment']->id, $replayed['deployment']->id);
        $this->assertSame('prepared', $replayed['deployment_request']['status']);
        $this->assertArrayNotHasKey('request_hash_version', $replayed['deployment_request']);
        $this->assertDatabaseCount('deployments', 1);

        $responsePayload['request_hash_version'] = 2;
        $mutation->forceFill(['response_payload' => $responsePayload])->save();
        try {
            $service->prepareDeployment($deploymentRequest, $input, $actor);
            $this->fail('Een legacy hash zonder expliciete v1-markering mag niet worden afgespeeld.');
        } catch (DeploymentRequestConflictException $exception) {
            $this->assertSame('deployment_request_mutation_conflict', $exception->errorCode);
        }

        $responsePayload['request_hash_version'] = 1;
        $mutation->forceFill([
            'request_hash' => $canonicalHash,
            'response_payload' => $responsePayload,
        ])->save();
        $canonicalReplay = $service->prepareDeployment($deploymentRequest, $input, $actor);
        $this->assertSame($prepared['deployment']->id, $canonicalReplay['deployment']->id);
    }

    public function test_active_deployment_keeps_deployed_team_snapshot_when_deployment_request_content_changes(): void
    {
        $actor = $this->user('active-request-change@example.test');
        $this->grant($actor, ['deployments.manage']);
        $team = Team::query()->create([
            'code' => 'ACTIVE-SEARCH',
            'name' => 'Actief zoekteam',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'active-change-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'active-change-decide',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'active-change-prepare',
        ], $actor);
        $deployment = $prepared['deployment'];
        $deployment->teams()->sync([$team->id]);
        $deployment->forceFill([
            'team_id' => $team->id,
            'status' => 'active',
            'required_resources' => 'Bestaande actieve inzet',
        ])->save();

        $service->patch($deploymentRequest, [
            'lock_version' => $prepared['deployment_request']['lock_version'],
            'client_mutation_id' => 'active-change-patch',
            'changes' => ['answers' => ['person_clothing' => 'Nu een groene jas']],
        ], $actor);

        $deployment->refresh();
        $this->assertFalse($deployment->deployment_request_decision_valid);
        $this->assertSame('Bestaande actieve inzet', $deployment->required_resources);
        $this->assertSame([$team->id], $deployment->teams()->pluck('teams.id')->all());
    }

    public function test_linked_plan_survives_answer_edits_and_escalated_teams_round_trip_through_redecision(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        Queue::fake();
        Event::fake([DeploymentRequestChanged::class, DispatchChanged::class]);
        $actor = $this->user('linked-plan-sync@example.test');
        $this->grant($actor, ['deployments.manage', 'deployment-requests.priority.override']);
        $initialTeam = Team::query()->create([
            'code' => 'PLAN-INITIAL',
            'name' => 'Initieel inzetteam',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $escalationTeam = Team::query()->create([
            'code' => 'PLAN-EXTRA',
            'name' => 'Extra inzetteam',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'linked-plan-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'linked-plan-decision',
            'priority' => 'low',
            'deployment_adjustments' => [
                'team_ids' => [$initialTeam->id],
                'resources' => ['Warmtebeeldcamera'],
                'notes' => 'Behoud het actuele operationele plan.',
                'recommended_recipient_count' => 2,
                'recommended_dispatch_mode' => 'preannouncement',
            ],
            'reason' => 'Operationeel inzetplan vastgesteld.',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'linked-plan-prepare',
        ], $actor);
        $deployment = $prepared['deployment'];

        $firstPatch = $service->patch($deploymentRequest, [
            'lock_version' => $prepared['deployment_request']['lock_version'],
            'client_mutation_id' => 'linked-plan-first-answer-update',
            'changes' => ['answers' => ['person_clothing' => 'Groene jas']],
        ], $actor);
        $this->assertNull($firstPatch['decided_priority']);
        $this->assertSame([$initialTeam->id], $firstPatch['selected_deployment_proposal']['team_ids']);
        $this->assertSame(['Warmtebeeldcamera'], $firstPatch['selected_deployment_proposal']['resources']);
        $this->assertSame([$initialTeam->id], $deployment->refresh()->teams()->pluck('teams.id')->all());
        $this->assertSame('Warmtebeeldcamera', $deployment->required_resources);

        $firstRedecision = $service->decidePriority($deploymentRequest, [
            'lock_version' => $firstPatch['lock_version'],
            'client_mutation_id' => 'linked-plan-first-redecision',
            'priority' => 'low',
            'reason' => 'Het handmatig vastgestelde inzetplan blijft van kracht.',
        ], $actor);
        $this->assertSame([$initialTeam->id], $firstRedecision['selected_deployment_proposal']['team_ids']);
        $this->assertSame([$initialTeam->id], $deployment->refresh()->teams()->pluck('teams.id')->all());

        $deployment->forceFill(['status' => 'dispatching'])->save();
        $existingDispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $actor->id,
            'requested_by_name' => $actor->name,
            'requested_by_email' => $actor->email,
            'target_team_id' => $initialTeam->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Bestaande alarmering',
            'sent_at' => now(),
        ]);
        $existingRecipient = DispatchRecipient::query()->create([
            'dispatch_request_id' => $existingDispatch->id,
            'user_id' => $actor->id,
            'user_name' => $actor->name,
            'user_email' => $actor->email,
            'response_status' => 'accepted',
            'response_note' => 'Bestaande reactie',
            'notified_at' => now()->subMinute(),
            'responded_at' => now(),
        ]);
        $pilot = $this->user('linked-plan-extra-pilot@example.test');
        $pilot->forceFill([
            'push_enabled' => true,
            'home_city' => 'Utrecht',
            'home_latitude' => 52.0907,
            'home_longitude' => 5.1214,
        ])->save();
        $escalationTeam->users()->attach($pilot->id, ['created_at' => now()]);
        $operatorSession = $pilot->createToken(
            'Linked plan operator',
            ['*', 'client:operator'],
            now()->addHour(),
        )->accessToken;
        FcmToken::query()->create([
            'user_id' => $pilot->id,
            'personal_access_token_id' => $operatorSession->id,
            'device_id' => 'linked-plan-extra-device',
            'token' => 'linked-plan-extra-token',
            'token_hash' => hash('sha256', 'linked-plan-extra-token'),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);

        try {
            app(DispatchService::class)->create($deployment, [
                'priority' => 'normal',
                'message' => 'Mag gekoppeld plan niet omzeilen',
                'target_team_id' => $escalationTeam->id,
            ], $actor);
            $this->fail('Een directe alarmering mag geen nieuw team buiten het gekoppelde inzetplan introduceren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_team_id', $exception->errors());
        }
        $this->assertDatabaseMissing('dispatch_requests', [
            'deployment_id' => $deployment->id,
            'target_team_id' => $escalationTeam->id,
        ]);

        app(DispatchService::class)->escalate($existingDispatch, $actor, [$escalationTeam->id]);

        $synced = $deploymentRequest->refresh();
        $expectedTeamIds = [$initialTeam->id, $escalationTeam->id];
        $this->assertEqualsCanonicalizing(
            $expectedTeamIds,
            $synced->selected_deployment_proposal['team_ids'],
        );
        $this->assertEqualsCanonicalizing(
            $expectedTeamIds,
            collect($synced->selected_deployment_proposal['teams'])->pluck('id')->all(),
        );
        $this->assertSame($firstRedecision['lock_version'] + 1, $synced->lock_version);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'deployment_requests.operational_plan_synced',
            'target_id' => $deploymentRequest->id,
        ]);
        Event::assertDispatched(
            DeploymentRequestChanged::class,
            fn (DeploymentRequestChanged $event): bool => $event->deploymentRequest->is($deploymentRequest),
        );

        $secondPatch = $service->patch($deploymentRequest, [
            'lock_version' => $synced->lock_version,
            'client_mutation_id' => 'linked-plan-second-answer-update',
            'changes' => ['answers' => ['circumstances' => 'Nieuwe informatie na opschaling']],
        ], $actor);
        $this->assertEqualsCanonicalizing(
            $expectedTeamIds,
            $secondPatch['selected_deployment_proposal']['team_ids'],
        );
        $secondRedecision = $service->decidePriority($deploymentRequest, [
            'lock_version' => $secondPatch['lock_version'],
            'client_mutation_id' => 'linked-plan-second-redecision',
            'priority' => 'urgent',
            'deployment_adjustments' => [
                // Simulate a conflict retry from a client that still has the
                // pre-escalation team list. Already alerted teams must remain.
                'team_ids' => [$initialTeam->id],
                'resources' => ['Warmtebeeldcamera'],
                'notes' => 'Behoud het actuele operationele plan.',
                'recommended_recipient_count' => 2,
                'recommended_dispatch_mode' => 'preannouncement',
            ],
            'reason' => 'Nieuwe informatie maakt de inzet kritiek.',
        ], $actor);
        $this->assertSame('urgent', $secondRedecision['decided_priority']);
        $this->assertEqualsCanonicalizing(
            $expectedTeamIds,
            $secondRedecision['selected_deployment_proposal']['team_ids'],
        );
        $this->assertEqualsCanonicalizing(
            $expectedTeamIds,
            $deployment->refresh()->teams()->pluck('teams.id')->all(),
        );
        $this->assertSame('critical', $deployment->priority);
        $this->assertSame('Warmtebeeldcamera', $deployment->required_resources);

        $existingDispatch->refresh();
        $existingRecipient->refresh();
        $this->assertSame('escalated', $existingDispatch->status);
        $this->assertSame('normal', $existingDispatch->priority);
        $this->assertSame($initialTeam->id, $existingDispatch->target_team_id);
        $this->assertSame('Bestaande alarmering', $existingDispatch->message);
        $this->assertSame('accepted', $existingRecipient->response_status);
        $this->assertSame('Bestaande reactie', $existingRecipient->response_note);

        $staleDispatch = DispatchRequest::query()->findOrFail($existingDispatch->id);
        app(DispatchService::class)->cancel($existingDispatch, $actor);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'dispatch.cancelled',
            'target_id' => $existingDispatch->id,
        ]);
        $blockedEscalationTeam = Team::query()->create([
            'code' => 'PLAN-AFTER-CANCEL',
            'name' => 'Team na annulering',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $blockedEscalationTeam->users()->attach($pilot->id, ['created_at' => now()]);
        try {
            app(DispatchService::class)->escalate(
                $staleDispatch,
                $actor,
                [$blockedEscalationTeam->id],
            );
            $this->fail('Een stale alarmeringsinstantie mag een gelijktijdige annulering niet overschrijven.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('dispatch', $exception->errors());
        }
        $this->assertSame('cancelled', $existingDispatch->refresh()->status);
        $this->assertNotNull($existingDispatch->cancelled_at);
        $this->assertDatabaseMissing('dispatch_requests', [
            'deployment_id' => $deployment->id,
            'target_team_id' => $blockedEscalationTeam->id,
        ]);
        $this->assertFalse(
            $deployment->refresh()->teams()->whereKey($blockedEscalationTeam->id)->exists(),
        );
    }

    public function test_preserved_linked_plan_requires_override_rights_when_the_recommendation_changes(): void
    {
        $actor = $this->user('linked-plan-override-guard@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'linked-plan-guard-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'linked-plan-guard-decision',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'linked-plan-guard-prepare',
        ], $actor);
        $patched = $service->patch($deploymentRequest, [
            'lock_version' => $prepared['deployment_request']['lock_version'],
            'client_mutation_id' => 'linked-plan-guard-answer-update',
            'changes' => ['answers' => ['immediate_danger' => true]],
        ], $actor);
        $this->assertSame('urgent', $patched['triage']['recommended_priority']);
        $this->assertSame(
            ['Operationele droneploeg'],
            $patched['selected_deployment_proposal']['resources'],
        );

        try {
            $service->decidePriority($deploymentRequest, [
                'lock_version' => $patched['lock_version'],
                'client_mutation_id' => 'linked-plan-guard-no-permission',
                'priority' => 'urgent',
            ], $actor);
            $this->fail('Een bewaard plan dat afwijkt van het nieuwe advies vereist override-rechten.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('priority', $exception->errors());
        }

        $this->grant($actor, ['deployment-requests.priority.override']);
        try {
            $service->decidePriority($deploymentRequest, [
                'lock_version' => $patched['lock_version'],
                'client_mutation_id' => 'linked-plan-guard-no-reason',
                'priority' => 'urgent',
            ], $actor);
            $this->fail('Een bewaard afwijkend plan vereist ook een vastgelegde reden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $redecided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $patched['lock_version'],
            'client_mutation_id' => 'linked-plan-guard-with-reason',
            'priority' => 'urgent',
            'reason' => 'Het ingezette operationele plan blijft bewust behouden.',
        ], $actor);
        $this->assertSame('urgent', $redecided['decided_priority']);
        $this->assertSame(
            ['Operationele droneploeg'],
            $redecided['selected_deployment_proposal']['resources'],
        );
        $this->assertSame('critical', $prepared['deployment']->refresh()->priority);
    }

    public function test_linked_deployment_request_rejects_missing_core_binding_and_switches_subject_atomically(): void
    {
        $actor = $this->user('linked-core-binding@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'linked-core-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'linked-core-decide',
            'priority' => 'low',
        ], $actor);
        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'linked-core-prepare',
        ], $actor);
        $lockVersion = $prepared['deployment_request']['lock_version'];

        foreach ([
            [
                'client_mutation_id' => 'linked-core-missing-animal',
                'changes' => ['subject_type' => 'animal'],
            ],
            [
                'client_mutation_id' => 'linked-core-clear-title',
                'changes' => ['answers' => ['person_name' => null]],
            ],
        ] as $attempt) {
            try {
                $service->patch($deploymentRequest, [
                    'lock_version' => $lockVersion,
                    ...$attempt,
                ], $actor);
                $this->fail('Een gekoppeld deployment mag zijn verplichte kernbinding niet verliezen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('answers', $exception->errors());
            }
            $this->assertSame('person', $deploymentRequest->refresh()->subject_type);
            $this->assertSame('Jan Jansen', $prepared['deployment']->refresh()->title);
        }

        $switched = $service->patch($deploymentRequest, [
            'lock_version' => $lockVersion,
            'client_mutation_id' => 'linked-core-valid-animal',
            'changes' => [
                'subject_type' => 'animal',
                'answers' => ['animal_species' => 'Hond'],
            ],
        ], $actor);
        $this->assertSame('animal', $switched['subject_type']);
        $this->assertSame('Hond', $prepared['deployment']->refresh()->title);
    }

    public function test_later_required_deployment_field_does_not_block_frozen_deployment_request_preparation(): void
    {
        $actor = $this->user('frozen-preparation@example.test');
        $this->grant($actor, ['deployments.manage']);
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'frozen-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'frozen-decision',
            'priority' => 'low',
        ], $actor);

        $fields = app(DeploymentFormService::class)->fields();
        $fields[] = [
            'key' => 'new_required_after_request',
            'label' => 'Later verplicht veld',
            'type' => 'text',
            'visible' => true,
            'required' => true,
            'width' => 'full',
            'expose_to_push' => false,
            'available_in_operator_app' => false,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $prepared = $service->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'frozen-prepare',
        ], $actor);

        $this->assertSame('draft', $prepared['deployment']->status);
        $this->assertArrayNotHasKey('new_required_after_request', $prepared['deployment']->custom_fields);
    }

    public function test_removed_bound_custom_field_blocks_frozen_preparation_instead_of_dropping_data(): void
    {
        $actor = $this->user('removed-bound-field@example.test');
        $this->grant($actor, ['forms.manage', 'deployments.manage']);
        $deploymentFields = app(DeploymentFormService::class)->fields();
        $deploymentFields[] = [
            'key' => 'legacy_detail',
            'label' => 'Historisch detail',
            'type' => 'text',
            'visible' => true,
            'required' => false,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );
        $workflow = app(DeploymentRequestWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['fields'][] = [
            'key' => 'legacy_detail_answer',
            'label' => 'Historisch detail',
            'type' => 'text',
            'scope' => 'common',
            'required' => false,
            'operator_visible' => false,
            'help_text' => null,
            'options' => [],
        ];
        $configuration['bindings'][] = [
            'field_key' => 'legacy_detail_answer',
            'target' => 'custom_fields.legacy_detail',
        ];
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $workflow->publishDraft($updated['draft']['lock_version'], $actor);

        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + ['legacy_detail_answer' => 'Niet verliezen'],
            'client_mutation_id' => 'removed-bound-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'removed-bound-decision',
            'priority' => 'low',
        ], $actor);
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            [
                'value' => collect(app(DeploymentFormService::class)->fields())
                    ->reject(fn (array $field): bool => $field['key'] === 'legacy_detail')
                    ->values()
                    ->all(),
                'is_sensitive' => false,
                'updated_by' => $actor->id,
            ],
        );

        try {
            $deploymentRequests->prepareDeployment($deploymentRequest, [
                'lock_version' => $decided['lock_version'],
                'client_mutation_id' => 'removed-bound-prepare',
            ], $actor);
            $this->fail('Een verwijderd gebonden doel mag niet stilzwijgend uit het deployment verdwijnen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.legacy_detail_answer', $exception->errors());
        }
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_deployment_custom_field_patch_merges_changed_keys_and_null_removes_one_key(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('custom-field-merge@example.test');
        $this->grant($actor, ['deployments.manage']);
        $deployment = app(DeploymentService::class)->create([
            'title' => 'Los deployment',
            'description' => 'Zonder gekoppeld dossier voor regressietest.',
            'priority' => 'normal',
            'location_label' => 'Utrecht',
            'custom_fields' => [
                'requesting_organization' => 'Politie',
                'requesting_unit' => 'Eenheid Midden',
            ],
        ], $actor);

        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", [
                'custom_fields' => ['requesting_unit' => 'Eenheid Oost'],
            ])
            ->assertOk();
        $deployment->refresh();
        $this->assertSame('Politie', $deployment->custom_fields['requesting_organization']);
        $this->assertSame('Eenheid Oost', $deployment->custom_fields['requesting_unit']);

        $this->asWebClient($actor)
            ->patchJson("/api/deployments/{$deployment->id}", [
                'custom_fields' => ['requesting_unit' => null],
            ])
            ->assertOk();
        $this->assertArrayNotHasKey('requesting_unit', $deployment->refresh()->custom_fields);
        $this->assertSame('Politie', $deployment->custom_fields['requesting_organization']);
    }

    public function test_removed_profile_targets_block_new_decision_and_existing_decision_preparation(): void
    {
        $actor = $this->user('stale-profile@example.test');
        $this->grant($actor, ['forms.manage', 'deployments.manage']);
        $team = Team::query()->create([
            'code' => 'STALE',
            'name' => 'Tijdelijk inzetteam',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $certification = Certification::query()->create([
            'code' => 'STALE-CERT',
            'name' => 'Tijdelijk certificaat',
            'description' => null,
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ]);
        $workflow = app(DeploymentRequestWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['deployment_profiles'][0]['team_ids'] = [$team->id];
        $configuration['deployment_profiles'][0]['required_certification_type_ids'] = [$certification->id];
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $workflow->publishDraft($updated['draft']['lock_version'], $actor);

        $service = app(DeploymentRequestService::class);
        $first = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'stale-first',
        ], $actor);
        $second = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'stale-second',
        ], $actor);
        $firstDeploymentRequest = DeploymentRequest::query()->findOrFail($first['id']);
        $firstDecision = $service->decidePriority($firstDeploymentRequest, [
            'lock_version' => $first['lock_version'],
            'client_mutation_id' => 'stale-first-decision',
            'priority' => 'low',
        ], $actor);
        $team->delete();
        $certification->delete();

        try {
            $service->decidePriority(DeploymentRequest::query()->findOrFail($second['id']), [
                'lock_version' => $second['lock_version'],
                'client_mutation_id' => 'stale-second-decision',
                'priority' => 'low',
            ], $actor);
            $this->fail('Een nieuw besluit mag geen verwijderde inzetdoelen selecteren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deployment_adjustments.team_ids', $exception->errors());
        }

        try {
            $service->prepareDeployment($firstDeploymentRequest, [
                'lock_version' => $firstDecision['lock_version'],
                'client_mutation_id' => 'stale-first-prepare',
            ], $actor);
            $this->fail('Een bestaand besluit met verwijderde inzetdoelen mag niet promoveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected_deployment_profile_id', $exception->errors());
        }
        $this->assertDatabaseCount('deployments', 0);
    }

    public function test_non_operational_teams_are_hidden_and_block_new_decisions_and_preparation(): void
    {
        $actor = $this->user('inactive-profile-team@example.test');
        $this->grant($actor, ['forms.manage', 'deployments.manage']);
        $team = Team::query()->create([
            'code' => 'INACTIVE-LATER',
            'name' => 'Later gedeactiveerd team',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $workflow = app(DeploymentRequestWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['deployment_profiles'][0]['team_ids'] = [$team->id];
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $published = $workflow->publishDraft($updated['draft']['lock_version'], $actor);
        $this->assertContains($team->id, array_column($published['catalogs']['teams'], 'id'));

        $service = app(DeploymentRequestService::class);
        $first = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'inactive-team-first',
        ], $actor);
        $second = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'inactive-team-second',
        ], $actor);
        $firstDeploymentRequest = DeploymentRequest::query()->findOrFail($first['id']);
        $firstDecision = $service->decidePriority($firstDeploymentRequest, [
            'lock_version' => $first['lock_version'],
            'client_mutation_id' => 'inactive-team-first-decision',
            'priority' => 'low',
        ], $actor);
        $team->update(['is_operational' => false]);

        $this->assertNotContains($team->id, array_column($workflow->catalogs()['teams'], 'id'));
        try {
            $service->decidePriority(DeploymentRequest::query()->findOrFail($second['id']), [
                'lock_version' => $second['lock_version'],
                'client_mutation_id' => 'inactive-team-second-decision',
                'priority' => 'low',
            ], $actor);
            $this->fail('Een nieuw besluit mag geen niet-operationeel team selecteren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deployment_adjustments.team_ids', $exception->errors());
        }

        try {
            $service->prepareDeployment($firstDeploymentRequest, [
                'lock_version' => $firstDecision['lock_version'],
                'client_mutation_id' => 'inactive-team-first-prepare',
            ], $actor);
            $this->fail('Een bestaand besluit met een gedeactiveerd team mag niet promoveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected_deployment_profile_id', $exception->errors());
        }

        $this->assertDatabaseCount('deployments', 0);

        $standalone = app(DeploymentService::class)->create([
            'title' => 'Losstaand dispatchveiligheidsdeployment',
            'description' => 'Controleert dat dispatch zelf niet-operationele teams weigert.',
            'priority' => 'normal',
            'location_label' => 'Utrecht',
        ], $actor);
        try {
            app(DispatchService::class)->create($standalone, [
                'priority' => 'normal',
                'message' => 'Mag niet worden verstuurd',
                'target_team_id' => $team->id,
            ], $actor);
            $this->fail('Dispatch mag een niet-operationeel team niet accepteren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('team_code', $exception->errors());
        }
        $this->assertDatabaseCount('dispatch_requests', 0);
    }

    public function test_operator_projection_is_double_filtered_and_full_deployment_request_routes_are_manage_only(): void
    {
        $manager = $this->user('privacy-manager@example.test');
        $operator = $this->user('privacy-operator@example.test');
        $this->grant($manager, ['deployments.manage', 'forms.manage']);
        $this->grant($operator, ['deployments.view']);
        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + [
                'medical_details' => 'Niet delen',
                'reporter_name' => 'Vertrouwelijke melder',
                'reporter_phone' => '+31611111111',
                'requesting_unit' => 'Vertrouwelijke eenheid',
                'on_scene_contact_name' => 'Vertrouwelijk contact',
            ],
            'client_mutation_id' => 'privacy-create',
        ], $manager);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'privacy-decision',
            'priority' => 'low',
        ], $manager);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'privacy-prepare',
        ], $manager)['deployment'];

        $operatorToken = $operator->createToken('Operator Android', ['*', 'client:operator'], now()->addHour());
        $operator->withAccessToken($operatorToken->accessToken);
        $legacyDeployment = app(DeploymentService::class)->create([
            'title' => 'Bestaand deployment',
            'description' => 'Aangemaakt vóór de aanvraagworkflow.',
            'priority' => 'normal',
            'location_label' => 'Amersfoort',
            'custom_fields' => [
                'requesting_organization' => 'Legacy organisatie',
                'requesting_unit' => 'Legacy eenheid',
            ],
        ], $manager);
        $legacyPayload = MobileApiPayload::deployment($legacyDeployment, $operator);
        $this->assertSame(
            'Legacy organisatie',
            ((array) $legacyPayload['custom_fields'])['requesting_organization'],
        );
        $this->assertNull($legacyPayload['deployment_request']);

        $initial = MobileApiPayload::deployment($deployment, $operator);
        $this->assertContains('person_age', array_column($initial['deployment_request']['answers'], 'key'));
        $this->assertNotContains('medical_details', array_column($initial['deployment_request']['answers'], 'key'));
        $this->assertSame('Vertrouwelijke melder', $deployment->reporter_name);
        $this->assertSame('Politie', $deployment->requesting_organization);
        $this->assertNull($initial['reporter_name']);
        $this->assertNull($initial['reporter_phone']);
        $this->assertNull($initial['requesting_organization']);
        $this->assertNull($initial['requesting_unit']);
        $this->assertNull($initial['on_scene_contact_name']);
        $this->assertSame([], (array) $initial['custom_fields']);
        $tokenMethod = new \ReflectionMethod(DispatchService::class, 'pushTemplateTokens');
        $tokens = $tokenMethod->invoke(app(DispatchService::class), $deployment);
        $this->assertSame('', $tokens['reporter_name']);
        $this->assertSame('', $tokens['reporter_phone']);
        $this->assertSame('', $tokens['requesting_organization']);
        $this->assertSame('', $tokens['field_requesting_organization']);
        $this->assertSame('', $tokens['on_scene_contact_name']);

        $workflow = app(DeploymentRequestWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        foreach ($configuration['fields'] as &$field) {
            if ($field['key'] === 'person_age') {
                $field['operator_visible'] = false;
            }
            if ($field['key'] === 'medical_details') {
                $field['operator_visible'] = true;
            }
            if ($field['key'] === 'reporter_name') {
                $field['operator_visible'] = true;
            }
        }
        unset($field);
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $manager);
        $workflow->publishDraft($updated['draft']['lock_version'], $manager);

        $filtered = MobileApiPayload::deployment($deployment->refresh(), $operator);
        $keys = array_column($filtered['deployment_request']['answers'], 'key');
        $this->assertNotContains('person_age', $keys);
        $this->assertNotContains('medical_details', $keys);
        $this->assertNull($filtered['reporter_name']);

        $managerRows = collect(
            $this->asWebClient($manager)
                ->getJson("/api/deployments/{$deployment->id}/deployment-request")
                ->assertOk()
                ->json('data.answer_rows'),
        )->keyBy('key');
        $this->assertFalse($managerRows->get('person_age')['operator_visible']);
        $this->assertFalse($managerRows->get('medical_details')['operator_visible']);
        $this->assertTrue($managerRows->get('last_seen_location')['operator_visible']);

        $this->asWebClient($operator)
            ->getJson('/api/deployment-requests')
            ->assertForbidden();
        $this->asWebClient($operator)
            ->getJson("/api/deployments/{$deployment->id}/deployment-request")
            ->assertForbidden();
    }

    public function test_push_transport_defensively_redacts_historical_hidden_core_bindings(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('push-privacy@example.test');
        $this->grant($actor, ['deployments.manage']);
        $deploymentRequests = app(DeploymentRequestService::class);
        $created = $deploymentRequests->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'push-privacy-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $decided = $deploymentRequests->decidePriority($deploymentRequest, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'push-privacy-decide',
            'priority' => 'low',
        ], $actor);
        $deployment = $deploymentRequests->prepareDeployment($deploymentRequest, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'push-privacy-prepare',
        ], $actor)['deployment'];

        $revision = $deploymentRequest->workflowRevision()->firstOrFail();
        $configuration = $revision->configuration;
        foreach ($configuration['fields'] as &$field) {
            if (in_array($field['key'], ['person_name', 'circumstances', 'deployment_location'], true)) {
                $field['operator_visible'] = false;
            }
        }
        unset($field);
        $revision->forceFill(['configuration' => $configuration])->save();

        $dispatch = DispatchRequest::query()->create([
            'deployment_id' => $deployment->id,
            'requested_by' => $actor->id,
            'status' => 'draft',
            'priority' => 'normal',
            'message' => implode(' - ', [
                $deployment->reference,
                $deployment->title,
                $deployment->location_label,
            ]),
        ])->load('deployment');
        $dispatchService = app(DispatchService::class);

        $preannouncement = (new \ReflectionMethod(DispatchService::class, 'preannouncementNotification'))
            ->invoke($dispatchService, $deployment->refresh());
        $cancellation = (new \ReflectionMethod(DispatchService::class, 'cancellationNotification'))
            ->invoke($dispatchService, $deployment);
        $body = (new \ReflectionMethod(DispatchService::class, 'notificationBody'))
            ->invoke($dispatchService, $dispatch);
        $data = (new \ReflectionMethod(DispatchService::class, 'notificationData'))
            ->invoke($dispatchService, $dispatch);

        foreach ([$preannouncement['body'], $cancellation['body'], $body, ...array_values($data)] as $value) {
            $this->assertStringNotContainsString('Jan Jansen', (string) $value);
            $this->assertStringNotContainsString('Kazerne Utrecht', (string) $value);
            $this->assertStringNotContainsString('Zoekactie in Utrecht', (string) $value);
        }
        $this->assertSame('', $data['deployment_title']);
        $this->assertSame('', $data['deployment_location']);
        $this->assertSame($deployment->reference, $data['dispatch_message']);
        $this->assertSame('Ben je beschikbaar voor een mogelijke inzet?', $preannouncement['body']);
        $this->assertSame('De vooraankondiging is geannuleerd.', $cancellation['body']);
    }

    public function test_api_contract_supports_dirty_patches_conflicts_decision_and_deployment_preparation(): void
    {
        $actor = $this->user('api-deployment-request@example.test');
        $this->grant($actor, ['deployments.manage', 'deployments.view', 'deployment-requests.priority.override']);
        $client = $this->asWebClient($actor);
        $createPayload = [
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'api-create',
        ];

        $created = $client->postJson('/api/deployment-requests', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.subject_type', 'person')
            ->assertJsonPath('data.triage.state', 'determined')
            ->assertJsonPath('data.triage.recommended_priority', 'low')
            ->assertJsonPath('data.lock_version', 1)
            ->json('data');
        $client->postJson('/api/deployment-requests', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.id', $created['id']);
        $this->assertDatabaseCount('deployment_requests', 1);

        $patched = $client->patchJson("/api/deployment-requests/{$created['id']}", [
            'lock_version' => 1,
            'client_mutation_id' => 'api-patch',
            'changes' => ['answers' => ['person_clothing' => 'Groene jas']],
        ])->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->json('data');

        $client->patchJson("/api/deployment-requests/{$created['id']}", [
            'lock_version' => 1,
            'client_mutation_id' => 'api-stale',
            'changes' => ['answers' => ['person_clothing' => 'Rode jas']],
        ])->assertConflict()
            ->assertJsonPath('error.code', 'deployment_request_version_conflict')
            ->assertJsonPath('error.details.current.lock_version', 2);

        $decided = $client->patchJson("/api/deployment-requests/{$created['id']}/priority", [
            'lock_version' => $patched['lock_version'],
            'client_mutation_id' => 'api-decision',
            'priority' => 'low',
        ])->assertOk()
            ->assertJsonPath('data.decided_priority', 'low')
            ->json('data');

        $prepared = $client->postJson("/api/deployment-requests/{$created['id']}/prepare-deployment", [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'api-prepare',
        ])->assertCreated()
            ->assertJsonPath('data.deployment_request.status', 'prepared')
            ->assertJsonPath('data.deployment.status', 'draft')
            ->assertJsonMissingPath('data.dossier')
            ->assertJsonMissingPath('data.incident')
            ->json('data');

        $client->getJson("/api/deployments/{$prepared['deployment']['id']}/deployment-request")
            ->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.deployment_id', $prepared['deployment']['id']);

        $client->getJson('/api/intake-dossiers')->assertNotFound();
        $client->getJson('/api/incidents')
            ->assertOk()
            ->assertJsonPath('data.0.id', $prepared['deployment']['id']);
    }

    public function test_manage_only_web_actor_can_open_the_deployment_immediately_after_preparation(): void
    {
        $actor = $this->user('manage-only-preparation@example.test');
        $this->grant($actor, ['deployments.manage']);
        $client = $this->asWebClient($actor);

        $created = $client->postJson('/api/deployment-requests', [
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'manage-only-create',
        ])->assertCreated()->json('data');
        $decided = $client->patchJson("/api/deployment-requests/{$created['id']}/priority", [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'manage-only-decision',
            'priority' => 'low',
        ])->assertOk()->json('data');
        $prepared = $client->postJson("/api/deployment-requests/{$created['id']}/prepare-deployment", [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'manage-only-prepare',
        ])->assertCreated()->json('data');
        $deploymentId = $prepared['deployment']['id'];

        $client->getJson("/api/deployments/{$deploymentId}")
            ->assertOk()
            ->assertJsonPath('data.id', $deploymentId)
            ->assertJsonPath('data.deployment_request_id', $created['id']);
        $client->getJson("/api/deployments/{$deploymentId}/timeline")
            ->assertOk();
        $client->getJson("/api/deployments/{$deploymentId}/live-locations")
            ->assertOk();
        $client->getJson('/api/reports/deployments?limit=100')
            ->assertOk();
        $client->getJson('/api/teams')
            ->assertOk();
        $client->getJson('/api/deployment-form/config')
            ->assertOk();
        $listedDeploymentIds = collect(
            $client->getJson('/api/deployments')->assertOk()->json('data'),
        )->pluck('id')->all();
        $this->assertContains($deploymentId, $listedDeploymentIds);
    }

    public function test_override_only_custom_role_can_load_team_catalog_without_deployment_view(): void
    {
        $actor = $this->user('override-team-catalog@example.test');
        $this->grant($actor, ['deployment-requests.priority.override']);

        $this->asWebClient($actor)
            ->getJson('/api/teams')
            ->assertOk();
    }

    public function test_admin_api_returns_full_envelopes_and_simulates_only_server_validated_drafts(): void
    {
        $actor = $this->user('api-workflow@example.test');
        $this->grant($actor, ['forms.manage']);
        $client = $this->asWebClient($actor);

        $config = $client->getJson('/api/admin/deployment-request-workflow/config')
            ->assertOk()
            ->assertJsonPath('data.published.version', 1)
            ->assertJsonStructure(['data' => ['draft', 'published', 'history', 'catalogs' => ['deployment_fields', 'teams', 'certification_types', 'operators']]])
            ->json('data');
        $configuration = $config['draft']['configuration'];
        $configuration['subject_types'][0]['label'] = 'Persoon';

        $updated = $client->patchJson('/api/admin/deployment-request-workflow/draft', [
            'expected_revision' => $config['draft']['lock_version'],
            'configuration' => $configuration,
        ])->assertOk()
            ->assertJsonPath('data.draft.configuration.subject_types.0.label', 'Persoon')
            ->json('data');

        $client->postJson('/api/admin/deployment-request-workflow/simulate', [
            'expected_revision' => $updated['draft']['lock_version'],
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
        ])->assertOk()
            ->assertJsonPath('data.triage.state', 'determined')
            ->assertJsonPath('data.deployment_proposal.recommended_dispatch_mode', 'preannouncement');

        $published = $client->postJson('/api/admin/deployment-request-workflow/publish', [
            'expected_revision' => $updated['draft']['lock_version'],
        ])->assertOk()
            ->assertJsonPath('data.published.version', 2)
            ->assertJsonPath('data.published.configuration.subject_types.0.label', 'Persoon')
            ->json('data');

        $client->postJson('/api/admin/deployment-request-workflow/restore', [
            'published_revision_id' => $config['published']['id'],
            'expected_revision' => $published['draft']['lock_version'],
        ])->assertOk()
            ->assertJsonPath('data.draft.configuration.subject_types.0.label', 'Mens')
            ->assertJsonPath('data.published.version', 2);
    }

    public function test_deployment_form_change_cannot_invalidate_published_deployment_request_bindings(): void
    {
        $actor = $this->user('cross-form-contract@example.test');
        $this->grant($actor, ['forms.manage']);
        app(DeploymentRequestWorkflowService::class)->published();
        $deploymentForm = app(DeploymentFormService::class);
        $fields = $deploymentForm->fields();
        $fields[] = [
            'key' => 'new_required_without_request_binding',
            'label' => 'Nieuw verplicht hoofdveld',
            'type' => 'text',
            'visible' => true,
            'required' => true,
            'width' => 'full',
            'expose_to_push' => false,
            'available_in_operator_app' => false,
        ];

        $this->asWebClient($actor)
            ->patchJson('/api/admin/deployment-form/config', [
                'fields' => $fields,
                'layout' => $deploymentForm->layout(),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['configuration.bindings']]]);

        $stored = SystemSetting::value(DeploymentFormService::SETTING_KEY, []);
        $this->assertNotContains(
            'new_required_without_request_binding',
            array_column(is_array($stored) ? $stored : [], 'key'),
        );
    }

    public function test_domain_cutover_renames_only_typed_deployment_form_layout_items(): void
    {
        SystemSetting::query()
            ->whereIn('key', ['incident.form_layout', DeploymentFormService::LAYOUT_SETTING_KEY])
            ->delete();
        SystemSetting::query()->create([
            'key' => 'incident.form_layout',
            'value' => [
                ['key' => 'section_incident', 'label' => 'Sectie: incident'],
                ['key' => 'incident_details', 'label' => 'Incident'],
                ['key' => 'custom_field:incident', 'label' => 'Incident'],
                ['key' => 'incidents_custom', 'label' => 'Sectie: incident'],
            ],
            'is_sensitive' => false,
        ]);

        $migration = require database_path('migrations/2026_07_27_000002_rename_incident_domain_to_deployments.php');
        $migration->up();
        $layout = SystemSetting::value(DeploymentFormService::LAYOUT_SETTING_KEY, []);

        $this->assertNull(SystemSetting::query()->find('incident.form_layout'));
        $this->assertSame('section_deployment', $layout[0]['key']);
        $this->assertSame('Sectie: inzet', $layout[0]['label']);
        $this->assertSame('deployment_details', $layout[1]['key']);
        $this->assertSame('Inzet', $layout[1]['label']);
        $this->assertSame('custom_field:incident', $layout[2]['key']);
        $this->assertSame('Incident', $layout[2]['label']);
        $this->assertSame('incidents_custom', $layout[3]['key']);
        $this->assertSame('Sectie: incident', $layout[3]['label']);
    }

    public function test_domain_cutover_preserves_submitted_answers_in_legacy_mutation_payloads(): void
    {
        $actor = $this->user('domain-cutover-mutation@example.test');
        $service = app(DeploymentRequestService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'domain-cutover-create',
        ], $actor);
        $deploymentRequest = DeploymentRequest::query()->findOrFail($created['id']);
        $deploymentRequest->forceFill(['status' => 'promoted'])->save();
        $mutation = DeploymentRequestMutation::query()->create([
            'deployment_request_id' => $deploymentRequest->id,
            'actor_id' => $actor->id,
            'client_mutation_id' => 'domain-cutover-promote',
            'operation' => 'promote',
            'request_hash' => hash('sha256', 'domain-cutover-promote'),
            'response_payload' => [
                'dossier_id' => $deploymentRequest->id,
                'incident_id' => null,
                'dossier' => [
                    'status' => 'promoted',
                    'incident_id' => null,
                    'answers' => [
                        'incident' => 'incident',
                        'incidents_custom' => 'incidents_custom',
                    ],
                    'answer_rows' => [[
                        'key' => 'incident',
                        'label' => 'Incident',
                        'value' => 'incident',
                    ]],
                ],
            ],
            'created_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_07_27_000002_rename_incident_domain_to_deployments.php');
        $migration->up();
        $payload = $mutation->refresh()->response_payload;

        $this->assertSame('prepared', $deploymentRequest->refresh()->status);
        $this->assertSame('prepare_deployment', $mutation->operation);
        $this->assertSame(1, $payload['request_hash_version']);
        $this->assertSame($deploymentRequest->id, $payload['deployment_request_id']);
        $this->assertArrayNotHasKey('dossier_id', $payload);
        $this->assertArrayNotHasKey('dossier', $payload);
        $this->assertSame('prepared', $payload['deployment_request']['status']);
        $this->assertArrayHasKey('deployment_id', $payload['deployment_request']);
        $this->assertSame([
            'incident' => 'incident',
            'incidents_custom' => 'incidents_custom',
        ], $payload['deployment_request']['answers']);
        $this->assertSame('incident', $payload['deployment_request']['answer_rows'][0]['key']);
        $this->assertSame('Incident', $payload['deployment_request']['answer_rows'][0]['label']);
        $this->assertSame('incident', $payload['deployment_request']['answer_rows'][0]['value']);
    }

    public function test_override_permission_migration_only_grants_canonical_coordinator_roles(): void
    {
        foreach (['intakes.priority.override', 'deployment-requests.priority.override'] as $permissionName) {
            $existing = Permission::query()->where('name', $permissionName)->first();
            if ($existing !== null) {
                DB::table('permission_role')->where('permission_id', $existing->id)->delete();
                $existing->delete();
            }
        }
        $manage = Permission::query()->firstOrCreate(
            ['name' => 'deployments.manage'],
            ['category' => 'test', 'display_name' => 'Deploymenten beheren', 'description' => 'Test'],
        );
        $roles = collect([
            'system-administrator',
            'national-coordinator',
            'incident-coordinator',
            'custom-deployment-manager',
        ])->mapWithKeys(function (string $name): array {
            $role = Role::query()->create([
                'name' => $name,
                'display_name' => $name,
                'can_use_operator_app' => false,
                'can_use_admin_app' => true,
            ]);
            $role->permissions()->attach(Permission::query()->where('name', 'deployments.manage')->value('id'), ['created_at' => now()]);

            return [$name => $role];
        });

        $historicalMigration = require database_path('migrations/2026_07_25_000004_add_incident_intake_permissions.php');
        $historicalMigration->up();
        $cutoverMigration = require database_path('migrations/2026_07_27_000002_rename_incident_domain_to_deployments.php');
        $cutoverMigration->up();
        $overrideId = Permission::query()->where('name', 'deployment-requests.priority.override')->value('id');

        foreach (['system-administrator', 'national-coordinator', 'deployment-coordinator'] as $canonicalRole) {
            $roleId = Role::query()->where('name', $canonicalRole)->value('id');
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $roleId,
                'permission_id' => $overrideId,
            ]);
        }
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $roles['custom-deployment-manager']->id,
            'permission_id' => $overrideId,
        ]);
        $this->assertTrue($roles['custom-deployment-manager']->permissions()->whereKey($manage->id)->exists());
    }

    /** @return array<string, mixed> */
    private function personAnswers(): array
    {
        return [
            'last_seen_at' => '2026-07-26T12:30:00+02:00',
            'last_seen_location' => 'Utrecht Centraal',
            'last_seen_direction' => 'Onbekend',
            'deployment_location' => 'Kazerne Utrecht',
            'circumstances' => 'Zoekactie in Utrecht',
            'requesting_organization' => 'Politie',
            'immediate_danger' => false,
            'person_name' => 'Jan Jansen',
            'person_age' => 76,
            'person_clothing' => 'Blauwe jas',
            'person_vulnerable' => false,
        ];
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'name' => 'Testgebruiker',
            'first_name' => 'Test',
            'last_name' => 'Gebruiker',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @param list<string> $names */
    private function grant(User $user, array $names): void
    {
        $role = $user->roles()->first() ?? Role::query()->create([
            'name' => 'deployment-request-test-'.str()->ulid(),
            'display_name' => 'Deployment request test',
            'can_use_operator_app' => true,
            'can_use_admin_app' => true,
        ]);
        foreach ($names as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                ['category' => 'test', 'display_name' => $name, 'description' => $name],
            );
            $role->permissions()->syncWithoutDetaching([$permission->id => ['created_at' => now()]]);
        }
        $user->roles()->syncWithoutDetaching([$role->id => ['created_at' => now()]]);
        $user->unsetRelation('roles');
    }

    private function asWebClient(User $user): static
    {
        $token = $user->createToken('Deployment request test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
