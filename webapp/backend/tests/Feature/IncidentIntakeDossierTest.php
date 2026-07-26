<?php

namespace Tests\Feature;

use App\Events\DispatchChanged;
use App\Events\IncidentIntakeChanged;
use App\Exceptions\IncidentIntakeConflictException;
use App\Jobs\SendFcmNotification;
use App\Models\Certification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\IncidentIntakeDossier;
use App\Models\IncidentIntakeMutation;
use App\Models\IncidentIntakeWorkflowRevision;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\DispatchService;
use App\Services\IncidentFormService;
use App\Services\IncidentIntakeDossierService;
use App\Services\IncidentIntakeWorkflowService;
use App\Services\IncidentService;
use App\Support\MobileApiPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class IncidentIntakeDossierTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_workflow_is_initialized_once_and_published_revisions_are_immutable(): void
    {
        $actor = $this->user('workflow@example.test');
        $this->grant($actor, ['forms.manage']);
        $service = app(IncidentIntakeWorkflowService::class);

        $first = $service->adminEnvelope();
        $second = $service->adminEnvelope();

        $this->assertSame(1, $first['published']['version']);
        $this->assertSame($first['published']['id'], $second['published']['id']);
        $this->assertDatabaseCount('incident_intake_workflow_revisions', 2);
        $this->assertSame('active', IncidentIntakeWorkflowRevision::query()->where('status', 'draft')->value('draft_marker'));

        $configuration = $first['draft']['configuration'];
        $configuration['subject_types'][0]['label'] = 'Persoon';
        $updated = $service->updateDraft($first['draft']['lock_version'], $configuration, $actor);
        $published = $service->publishDraft($updated['draft']['lock_version'], $actor);

        $this->assertSame(2, $published['published']['version']);
        $this->assertSame('Persoon', $published['published']['configuration']['subject_types'][0]['label']);
        $this->assertSame('Mens', IncidentIntakeWorkflowRevision::query()->where('version', 1)->firstOrFail()->configuration['subject_types'][0]['label']);
        $this->assertGreaterThan($updated['draft']['lock_version'], $published['draft']['lock_version']);
        $this->assertDatabaseCount('incident_intake_workflow_revisions', 3);

        $this->expectException(IncidentIntakeConflictException::class);
        $service->updateDraft($updated['draft']['lock_version'], $configuration, $actor);
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
        $service = app(IncidentIntakeWorkflowService::class);
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
        $service = app(IncidentIntakeWorkflowService::class);
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
            $this->fail('Twee gelijktijdige velden mogen hetzelfde incidentdoel niet vullen.');
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
            $this->fail('Vaste en configureerbare aliassen moeten als hetzelfde incidentdoel gelden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.bindings', $exception->errors());
        }

        foreach (['last_seen_location', 'circumstances', 'person_name'] as $hiddenCoreField) {
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
                $this->assertArrayHasKey('configuration.bindings', $exception->errors());
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

        $catalogTargets = array_column($service->catalogs()['incident_fields'], 'target');
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

    public function test_bound_answers_enforce_target_lengths_and_select_options_before_promotion(): void
    {
        $service = app(IncidentIntakeWorkflowService::class);
        $configuration = $service->defaultConfiguration();

        foreach ([
            ['person_name', str_repeat('a', 181)],
            ['last_seen_location', str_repeat('a', 256)],
            ['reporter_phone', str_repeat('1', 41)],
        ] as [$fieldKey, $value]) {
            try {
                $service->normalizeAnswers($configuration, 'person', [$fieldKey => $value], patch: true);
                $this->fail("Een te lang gebonden antwoord voor $fieldKey moet direct worden geweigerd.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey("answers.$fieldKey", $exception->errors());
            }
        }

        $incidentFields = app(IncidentFormService::class)->fields();
        $incidentFields[] = [
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
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $incidentFields, 'is_sensitive' => false],
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
            $this->fail('Een intakekeuze buiten de incidentveldopties moet worden geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'configuration.bindings.'.(count($configuration['bindings']) - 1),
                $exception->errors(),
            );
        }
    }

    public function test_first_workflow_initialization_adopts_existing_required_flight_time_field_and_promotes_it(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('required-upgrade-field@example.test');
        $this->grant($actor, ['incidents.manage']);
        $incidentFields = app(IncidentFormService::class)->fields();
        $incidentFields[] = [
            'key' => 'search_window',
            'label' => 'Zoekvenster',
            'type' => 'flight_time',
            'visible' => true,
            'required' => true,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $incidentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $workflow = app(IncidentIntakeWorkflowService::class);
        $published = $workflow->published();
        $binding = collect($published->configuration['bindings'])
            ->firstWhere('target', 'custom_fields.search_window');
        $this->assertIsArray($binding);

        $answers = $this->personAnswers();
        $answers[$binding['field_key']] = '23:30-00:15';
        $dossiers = app(IncidentIntakeDossierService::class);
        $created = $dossiers->create([
            'subject_type' => 'person',
            'answers' => $answers,
            'client_mutation_id' => 'required-upgrade-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $dossiers->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'required-upgrade-decide',
            'priority' => 'low',
        ], $actor);
        $incident = $dossiers->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'required-upgrade-promote',
        ], $actor)['incident'];

        $this->assertSame([
            'start' => '23:30',
            'end' => '00:15',
            'duration_minutes' => 45,
        ], $incident->custom_fields['search_window']);
        $lockVersion = $incident->intakeDossier()->firstOrFail()->lock_version;
        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", [
                'custom_fields' => $incident->custom_fields,
            ])
            ->assertOk();
        $this->assertSame(
            $lockVersion,
            $incident->intakeDossier()->firstOrFail()->lock_version,
        );
        $this->assertSame('low', $incident->intakeDossier()->firstOrFail()->decided_priority);
    }

    public function test_first_workflow_initialization_aligns_prebound_legacy_incident_field_types(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('prebound-upgrade-fields@example.test');
        $this->grant($actor, ['forms.manage', 'incidents.manage']);
        $incidentFields = array_values(array_filter(
            app(IncidentFormService::class)->fields(),
            fn (array $field): bool => $field['key'] !== 'on_scene_contact_role',
        ));

        foreach ($incidentFields as &$incidentField) {
            if ($incidentField['key'] === 'requesting_organization') {
                $incidentField['type'] = 'select';
                $incidentField['options'] = [
                    ['value' => 'police', 'label' => 'Politie'],
                    ['value' => 'fire_service', 'label' => 'Brandweer'],
                ];
            }
            if ($incidentField['key'] === 'requesting_unit') {
                $incidentField['type'] = 'number';
                $incidentField['required'] = true;
            }
        }
        unset($incidentField);

        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $incidentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $configuration = $this->asWebClient($actor)
            ->getJson('/api/admin/intake-workflow/config')
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
        $dossiers = app(IncidentIntakeDossierService::class);
        $created = $dossiers->create([
            'subject_type' => 'person',
            'answers' => $answers,
            'client_mutation_id' => 'prebound-upgrade-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $dossiers->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'prebound-upgrade-decide',
            'priority' => 'low',
        ], $actor);
        $incident = $dossiers->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'prebound-upgrade-promote',
        ], $actor)['incident'];

        $this->assertSame('police', $incident->requesting_organization);
        $this->assertSame('police', $incident->custom_fields['requesting_organization']);
        $this->assertSame('112', $incident->requesting_unit);
        $this->assertSame(112, $incident->custom_fields['requesting_unit']);
    }

    public function test_promoted_bound_number_keeps_current_incident_form_range(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('bound-number-range@example.test');
        $this->grant($actor, ['forms.manage', 'incidents.manage']);
        $incidentFields = app(IncidentFormService::class)->fields();
        $incidentFields[] = [
            'key' => 'search_radius',
            'label' => 'Zoekstraal',
            'type' => 'number',
            'visible' => true,
            'required' => false,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $incidentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );
        $workflow = app(IncidentIntakeWorkflowService::class);
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

        $dossiers = app(IncidentIntakeDossierService::class);
        $created = $dossiers->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + ['search_radius_answer' => 10],
            'client_mutation_id' => 'bound-number-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $dossiers->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'bound-number-decide',
            'priority' => 'low',
        ], $actor);
        $promoted = $dossiers->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'bound-number-promote',
        ], $actor);

        try {
            $dossiers->patch($dossier, [
                'lock_version' => $promoted['dossier']['lock_version'],
                'client_mutation_id' => 'bound-number-invalid',
                'changes' => ['answers' => ['search_radius_answer' => 1000000]],
            ], $actor);
            $this->fail('Een gebonden getal buiten het incidentformulierbereik moet worden geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.search_radius_answer', $exception->errors());
        }
        $this->assertSame(10, $promoted['incident']->refresh()->custom_fields['search_radius']);
    }

    public function test_unknown_higher_priority_information_never_falls_back_to_low(): void
    {
        $service = app(IncidentIntakeWorkflowService::class);
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
        $service = app(IncidentIntakeWorkflowService::class);
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
        $service = app(IncidentIntakeWorkflowService::class);
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
        $service = app(IncidentIntakeWorkflowService::class);
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

    public function test_dossier_autosave_is_idempotent_conflict_safe_and_preserves_inactive_subject_answers(): void
    {
        Event::fake([IncidentIntakeChanged::class]);
        $actor = $this->user('intake@example.test');
        $this->grant($actor, ['incidents.manage', 'intakes.priority.override']);
        $service = app(IncidentIntakeDossierService::class);

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
        $this->assertDatabaseCount('incident_intake_dossiers', 1);
        $this->assertSame('determined', $created['triage']['state']);
        $this->assertSame('low', $created['triage']['recommended_priority']);
        $storedMutationPayload = IncidentIntakeMutation::query()
            ->where('client_mutation_id', 'create-1')
            ->firstOrFail()
            ->response_payload;
        $this->assertCount(3, $storedMutationPayload);
        $this->assertArrayHasKey('dossier_id', $storedMutationPayload);
        $this->assertArrayHasKey('lock_version', $storedMutationPayload);
        $this->assertArrayHasKey('incident_id', $storedMutationPayload);
        $this->assertArrayNotHasKey('answers', $storedMutationPayload);

        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $switched = $service->patch($dossier, [
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
            $service->patch($dossier, [
                'lock_version' => $created['lock_version'],
                'client_mutation_id' => 'stale-patch',
                'changes' => ['answers' => ['animal_name' => 'Max']],
            ], $actor);
            $this->fail('Een verouderde lock_version moet conflicteren.');
        } catch (IncidentIntakeConflictException $exception) {
            $this->assertSame('intake_version_conflict', $exception->errorCode);
            $this->assertSame($switched['lock_version'], $exception->current['lock_version']);
        }

        Event::assertDispatched(IncidentIntakeChanged::class);
    }

    public function test_closed_dossier_metadata_is_immutable_for_new_mutations(): void
    {
        $actor = $this->user('close-intake@example.test');
        $this->grant($actor, ['incidents.manage']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'close-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $closed = $service->close($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'close-once',
            'reason' => 'Geen inzet nodig.',
        ], $actor);
        $firstClosedAt = $dossier->refresh()->closed_at;

        try {
            $service->close($dossier, [
                'lock_version' => $closed['lock_version'],
                'client_mutation_id' => 'close-again',
                'reason' => 'Overschreven reden.',
            ], $actor);
            $this->fail('Een afgesloten dossier mag niet opnieuw worden afgesloten.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $dossier->refresh();
        $this->assertSame('Geen inzet nodig.', $dossier->close_reason);
        $this->assertTrue($firstClosedAt->equalTo($dossier->closed_at));
    }

    public function test_decision_override_requires_permission_and_reason_and_content_changes_reset_decision(): void
    {
        $actor = $this->user('decision@example.test');
        $this->grant($actor, ['incidents.manage']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'decision-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);

        try {
            $service->decidePriority($dossier, [
                'lock_version' => $created['lock_version'],
                'client_mutation_id' => 'override-denied',
                'priority' => 'urgent',
                'reason' => 'Acuut telefoongesprek.',
            ], $actor);
            $this->fail('Afwijken zonder recht moet worden geweigerd.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('priority', $exception->errors());
        }

        $this->grant($actor, ['intakes.priority.override']);
        $actor->unsetRelation('roles');
        try {
            $service->decidePriority($dossier, [
                'lock_version' => $created['lock_version'],
                'client_mutation_id' => 'override-no-reason',
                'priority' => 'urgent',
            ], $actor);
            $this->fail('Een gemotiveerde afwijking heeft een reden nodig.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'override-ok',
            'priority' => 'urgent',
            'reason' => 'Nieuwe informatie van de melder.',
        ], $actor);
        $this->assertSame('urgent', $decided['decided_priority']);
        $this->assertSame('urgent_response', $decided['selected_deployment_proposal']['profile_id']);

        $patched = $service->patch($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'content-after-decision',
            'changes' => ['answers' => ['last_seen_direction' => 'Noord']],
        ], $actor);
        $this->assertNull($patched['decided_priority']);
        $this->assertNull($patched['selected_deployment_proposal']);

        $service->decidePriority($dossier, [
            'lock_version' => $patched['lock_version'],
            'client_mutation_id' => 'override-second',
            'priority' => 'urgent',
            'reason' => 'Tweede gemotiveerde beoordeling.',
        ], $actor);
        $reasons = DB::table('audit_logs')
            ->where('target_id', $dossier->id)
            ->where('action', 'intake_dossiers.priority')
            ->orderBy('created_at')
            ->pluck('reason')
            ->all();
        $this->assertContains('Nieuwe informatie van de melder.', $reasons);
        $this->assertContains('Tweede gemotiveerde beoordeling.', $reasons);
    }

    public function test_incomplete_dossier_cannot_promote_or_create_dispatch_side_effects(): void
    {
        Queue::fake();
        Event::fake([DispatchChanged::class]);
        $actor = $this->user('incomplete-promote@example.test');
        $this->grant($actor, ['incidents.manage', 'intakes.priority.override']);
        $service = app(IncidentIntakeDossierService::class);
        $answers = $this->personAnswers();
        unset($answers['person_age']);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $answers,
            'client_mutation_id' => 'incomplete-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'incomplete-decision',
            'priority' => 'low',
            'reason' => 'Handmatig beoordeeld, maar dossier blijft onvolledig.',
        ], $actor);

        try {
            $service->promote($dossier, [
                'lock_version' => $decided['lock_version'],
                'client_mutation_id' => 'incomplete-promote',
            ], $actor);
            $this->fail('Een onvolledig dossier mag geen incident worden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('triage', $exception->errors());
        }

        $this->assertDatabaseCount('incidents', 0);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);
        Queue::assertNotPushed(SendFcmNotification::class);
        Event::assertNotDispatched(DispatchChanged::class);
    }

    public function test_promotion_rejects_a_missing_bound_title_before_database_write(): void
    {
        $actor = $this->user('missing-title-promote@example.test');
        $this->grant($actor, ['incidents.manage', 'intakes.priority.override']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'missing-title-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'missing-title-decision',
            'priority' => 'low',
        ], $actor);

        // Simulate a historical/corrupted frozen row that predates strict core
        // binding validation. Promotion must fail as validation, never as a
        // database NOT NULL exception.
        $answers = $dossier->refresh()->answers;
        unset($answers['person_name']);
        $dossier->forceFill(['answers' => $answers])->save();

        try {
            $service->promote($dossier, [
                'lock_version' => $decided['lock_version'],
                'client_mutation_id' => 'missing-title-promote',
            ], $actor);
            $this->fail('Een dossier zonder gekoppelde incidenttitel mag niet promoveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bindings', $exception->errors());
        }

        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_realert_is_blocked_after_linked_intake_content_invalidates_the_decision(): void
    {
        Queue::fake();
        $actor = $this->user('stale-realert-manager@example.test');
        $this->grant($actor, ['incidents.manage', 'intakes.priority.override']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'stale-realert-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'stale-realert-decision',
            'priority' => 'low',
        ], $actor);
        $promoted = $service->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'stale-realert-promote',
        ], $actor);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $promoted['incident']->id,
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
        $patched = $service->patch($dossier, [
            'lock_version' => $promoted['dossier']['lock_version'],
            'client_mutation_id' => 'stale-realert-content-change',
            'changes' => ['answers' => ['circumstances' => 'Nieuwe inhoud vereist een nieuw besluit.']],
        ], $actor);
        $this->assertNull($patched['decided_priority']);
        $this->assertFalse($promoted['incident']->refresh()->intake_decision_valid);
        $this->assertSame('sent', $dispatch->refresh()->status);

        try {
            app(DispatchService::class)->reAlert($dispatch, $actor);
            $this->fail('Een heralarmering mag een ongeldig geworden intakebesluit niet omzeilen.');
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

    public function test_promote_creates_exactly_one_draft_incident_and_linked_edits_refresh_payload(): void
    {
        Queue::fake();
        Event::fake([IncidentIntakeChanged::class, DispatchChanged::class]);
        $actor = $this->user('promote@example.test');
        $this->grant($actor, ['incidents.manage', 'intakes.priority.override']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + [
                'requesting_unit' => 'Eenheid Midden-Nederland',
                'on_scene_contact_name' => 'Piet',
                'on_scene_contact_phone' => '+31 6 12345678',
            ],
            'client_mutation_id' => 'promote-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'promote-decision',
            'priority' => 'low',
        ], $actor);
        $promoted = $service->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'promote-once',
        ], $actor);
        $replayed = $service->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'promote-once',
        ], $actor);

        $this->assertSame($promoted['incident']->id, $replayed['incident']->id);
        $this->assertDatabaseCount('incidents', 1);
        $this->assertSame('draft', $promoted['incident']->status);
        $this->assertSame('low', $promoted['incident']->priority);
        $this->assertSame('Zoekactie in Utrecht', $promoted['incident']->description);
        $this->assertSame('Utrecht Centraal', $promoted['incident']->location_label);
        $this->assertSame('Politie', $promoted['incident']->requesting_organization);
        $this->assertSame('Politie', $promoted['incident']->custom_fields['requesting_organization']);
        $this->assertSame('Operationele droneploeg', $promoted['incident']->required_resources);
        $this->assertSame('Operationele droneploeg', $promoted['incident']->custom_fields['required_resources']);
        $this->assertSame('+31612345678', $promoted['incident']->on_scene_contact_phone);
        $this->assertSame('+31612345678', $promoted['incident']->custom_fields['on_scene_contact_phone']);
        $this->assertDatabaseCount('dispatch_requests', 0);
        $this->assertDatabaseCount('dispatch_push_outbox', 0);
        Queue::assertNotPushed(SendFcmNotification::class);
        Event::assertNotDispatched(DispatchChanged::class);

        $staleDraft = DispatchRequest::query()->create([
            'incident_id' => $promoted['incident']->id,
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
        $linked = $service->patch($dossier, [
            'lock_version' => $promoted['dossier']['lock_version'],
            'client_mutation_id' => 'linked-update',
            'changes' => ['answers' => [
                'circumstances' => 'Aanvullende informatie ontvangen',
                'requesting_organization' => 'Brandweer',
            ]],
        ], $actor);
        $incident = $promoted['incident']->refresh();
        $this->assertSame('Aanvullende informatie ontvangen', $incident->description);
        $this->assertSame('Brandweer', $incident->requesting_organization);
        $this->assertSame('Brandweer', $incident->custom_fields['requesting_organization']);
        $this->assertNotNull($incident->updated_at);
        $this->assertSame($linked['lock_version'], $incident->intakeDossier()->firstOrFail()->lock_version);
        $this->assertFalse($incident->intake_decision_valid);
        $this->assertNull($incident->required_resources);
        $this->assertSame([], $incident->teams()->pluck('teams.id')->all());
        $this->assertSame('cancelled', $staleDraft->refresh()->status);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->fcmTokenId === $staleToken->id
            && $job->messageType === 'incident_preannouncement_cancelled'
            && ($job->data['action_mode'] ?? null) === 'availability_cancelled');
        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", [
                'status' => 'active',
                'status_reason' => 'Mag niet zonder herbeoordeling.',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['status']]]);

        $redecided = $service->decidePriority($dossier, [
            'lock_version' => $linked['lock_version'],
            'client_mutation_id' => 'linked-redecision',
            'priority' => 'low',
        ], $actor);
        $this->assertTrue($incident->refresh()->intake_decision_valid);
        $this->assertSame('Operationele droneploeg', $incident->required_resources);
        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", [
                'requesting_organization' => 'Reddingsbrigade',
                'on_scene_contact_phone' => '+31 6 87654321',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.intake_dossier.0', 'Wijzig gekoppelde uitvraagvelden via het meldingsdossier.');
        $incident->refresh();
        $unchangedDossier = $incident->intakeDossier()->firstOrFail();
        $this->assertSame('Brandweer', $incident->requesting_organization);
        $this->assertSame('+31612345678', $incident->on_scene_contact_phone);
        $this->assertSame('Brandweer', $unchangedDossier->answers['requesting_organization']);
        $this->assertSame('+31 6 12345678', $unchangedDossier->answers['on_scene_contact_phone']);
        $this->assertSame('low', $unchangedDossier->decided_priority);
        $this->assertSame($redecided['lock_version'], $unchangedDossier->lock_version);
        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", ['priority' => 'high'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['priority']]]);
        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", [
                'custom_fields' => ['required_resources' => 'Omzeilde inzet'],
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['required_resources']]]);
        $this->assertSame('low', $incident->refresh()->priority);
        $this->assertSame('Operationele droneploeg', $incident->required_resources);
        Event::assertDispatched(IncidentIntakeChanged::class, fn (IncidentIntakeChanged $event): bool => $event->dossier->incident_id === $incident->id);
    }

    public function test_active_incident_keeps_deployed_team_snapshot_when_intake_content_changes(): void
    {
        $actor = $this->user('active-intake-change@example.test');
        $this->grant($actor, ['incidents.manage']);
        $team = Team::query()->create([
            'code' => 'ACTIVE-SEARCH',
            'name' => 'Actief zoekteam',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'active-change-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'active-change-decide',
            'priority' => 'low',
        ], $actor);
        $promoted = $service->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'active-change-promote',
        ], $actor);
        $incident = $promoted['incident'];
        $incident->teams()->sync([$team->id]);
        $incident->forceFill([
            'team_id' => $team->id,
            'status' => 'active',
            'required_resources' => 'Bestaande actieve inzet',
        ])->save();

        $service->patch($dossier, [
            'lock_version' => $promoted['dossier']['lock_version'],
            'client_mutation_id' => 'active-change-patch',
            'changes' => ['answers' => ['person_clothing' => 'Nu een groene jas']],
        ], $actor);

        $incident->refresh();
        $this->assertFalse($incident->intake_decision_valid);
        $this->assertSame('Bestaande actieve inzet', $incident->required_resources);
        $this->assertSame([$team->id], $incident->teams()->pluck('teams.id')->all());
    }

    public function test_linked_dossier_rejects_missing_core_binding_and_switches_subject_atomically(): void
    {
        $actor = $this->user('linked-core-binding@example.test');
        $this->grant($actor, ['incidents.manage']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'linked-core-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'linked-core-decide',
            'priority' => 'low',
        ], $actor);
        $promoted = $service->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'linked-core-promote',
        ], $actor);
        $lockVersion = $promoted['dossier']['lock_version'];

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
                $service->patch($dossier, [
                    'lock_version' => $lockVersion,
                    ...$attempt,
                ], $actor);
                $this->fail('Een gekoppeld incident mag zijn verplichte kernbinding niet verliezen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('answers', $exception->errors());
            }
            $this->assertSame('person', $dossier->refresh()->subject_type);
            $this->assertSame('Jan Jansen', $promoted['incident']->refresh()->title);
        }

        $switched = $service->patch($dossier, [
            'lock_version' => $lockVersion,
            'client_mutation_id' => 'linked-core-valid-animal',
            'changes' => [
                'subject_type' => 'animal',
                'answers' => ['animal_species' => 'Hond'],
            ],
        ], $actor);
        $this->assertSame('animal', $switched['subject_type']);
        $this->assertSame('Hond', $promoted['incident']->refresh()->title);
    }

    public function test_later_required_incident_field_does_not_block_frozen_dossier_promotion(): void
    {
        $actor = $this->user('frozen-promotion@example.test');
        $this->grant($actor, ['incidents.manage']);
        $service = app(IncidentIntakeDossierService::class);
        $created = $service->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'frozen-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $service->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'frozen-decision',
            'priority' => 'low',
        ], $actor);

        $fields = app(IncidentFormService::class)->fields();
        $fields[] = [
            'key' => 'new_required_after_intake',
            'label' => 'Later verplicht veld',
            'type' => 'text',
            'visible' => true,
            'required' => true,
            'width' => 'full',
            'expose_to_push' => false,
            'available_in_operator_app' => false,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $fields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );

        $promoted = $service->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'frozen-promote',
        ], $actor);

        $this->assertSame('draft', $promoted['incident']->status);
        $this->assertArrayNotHasKey('new_required_after_intake', $promoted['incident']->custom_fields);
    }

    public function test_removed_bound_custom_field_blocks_frozen_promotion_instead_of_dropping_data(): void
    {
        $actor = $this->user('removed-bound-field@example.test');
        $this->grant($actor, ['forms.manage', 'incidents.manage']);
        $incidentFields = app(IncidentFormService::class)->fields();
        $incidentFields[] = [
            'key' => 'legacy_detail',
            'label' => 'Historisch detail',
            'type' => 'text',
            'visible' => true,
            'required' => false,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            ['value' => $incidentFields, 'is_sensitive' => false, 'updated_by' => $actor->id],
        );
        $workflow = app(IncidentIntakeWorkflowService::class);
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

        $dossiers = app(IncidentIntakeDossierService::class);
        $created = $dossiers->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers() + ['legacy_detail_answer' => 'Niet verliezen'],
            'client_mutation_id' => 'removed-bound-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $dossiers->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'removed-bound-decision',
            'priority' => 'low',
        ], $actor);
        SystemSetting::query()->updateOrCreate(
            ['key' => IncidentFormService::SETTING_KEY],
            [
                'value' => collect(app(IncidentFormService::class)->fields())
                    ->reject(fn (array $field): bool => $field['key'] === 'legacy_detail')
                    ->values()
                    ->all(),
                'is_sensitive' => false,
                'updated_by' => $actor->id,
            ],
        );

        try {
            $dossiers->promote($dossier, [
                'lock_version' => $decided['lock_version'],
                'client_mutation_id' => 'removed-bound-promote',
            ], $actor);
            $this->fail('Een verwijderd gebonden doel mag niet stilzwijgend uit het incident verdwijnen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('answers.legacy_detail_answer', $exception->errors());
        }
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_incident_custom_field_patch_merges_changed_keys_and_null_removes_one_key(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('custom-field-merge@example.test');
        $this->grant($actor, ['incidents.manage']);
        $incident = app(IncidentService::class)->create([
            'title' => 'Los incident',
            'description' => 'Zonder gekoppeld dossier voor regressietest.',
            'priority' => 'normal',
            'location_label' => 'Utrecht',
            'custom_fields' => [
                'requesting_organization' => 'Politie',
                'requesting_unit' => 'Eenheid Midden',
            ],
        ], $actor);

        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", [
                'custom_fields' => ['requesting_unit' => 'Eenheid Oost'],
            ])
            ->assertOk();
        $incident->refresh();
        $this->assertSame('Politie', $incident->custom_fields['requesting_organization']);
        $this->assertSame('Eenheid Oost', $incident->custom_fields['requesting_unit']);

        $this->asWebClient($actor)
            ->patchJson("/api/incidents/{$incident->id}", [
                'custom_fields' => ['requesting_unit' => null],
            ])
            ->assertOk();
        $this->assertArrayNotHasKey('requesting_unit', $incident->refresh()->custom_fields);
        $this->assertSame('Politie', $incident->custom_fields['requesting_organization']);
    }

    public function test_removed_profile_targets_block_new_decision_and_existing_decision_promotion(): void
    {
        $actor = $this->user('stale-profile@example.test');
        $this->grant($actor, ['forms.manage', 'incidents.manage']);
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
        $workflow = app(IncidentIntakeWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['deployment_profiles'][0]['team_ids'] = [$team->id];
        $configuration['deployment_profiles'][0]['required_certification_type_ids'] = [$certification->id];
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $workflow->publishDraft($updated['draft']['lock_version'], $actor);

        $service = app(IncidentIntakeDossierService::class);
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
        $firstDossier = IncidentIntakeDossier::query()->findOrFail($first['id']);
        $firstDecision = $service->decidePriority($firstDossier, [
            'lock_version' => $first['lock_version'],
            'client_mutation_id' => 'stale-first-decision',
            'priority' => 'low',
        ], $actor);
        $team->delete();
        $certification->delete();

        try {
            $service->decidePriority(IncidentIntakeDossier::query()->findOrFail($second['id']), [
                'lock_version' => $second['lock_version'],
                'client_mutation_id' => 'stale-second-decision',
                'priority' => 'low',
            ], $actor);
            $this->fail('Een nieuw besluit mag geen verwijderde inzetdoelen selecteren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deployment_adjustments.team_ids', $exception->errors());
        }

        try {
            $service->promote($firstDossier, [
                'lock_version' => $firstDecision['lock_version'],
                'client_mutation_id' => 'stale-first-promote',
            ], $actor);
            $this->fail('Een bestaand besluit met verwijderde inzetdoelen mag niet promoveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected_deployment_profile_id', $exception->errors());
        }
        $this->assertDatabaseCount('incidents', 0);
    }

    public function test_non_operational_teams_are_hidden_and_block_new_decisions_and_promotion(): void
    {
        $actor = $this->user('inactive-profile-team@example.test');
        $this->grant($actor, ['forms.manage', 'incidents.manage']);
        $team = Team::query()->create([
            'code' => 'INACTIVE-LATER',
            'name' => 'Later gedeactiveerd team',
            'type' => 'operational',
            'is_operational' => true,
        ]);
        $workflow = app(IncidentIntakeWorkflowService::class);
        $admin = $workflow->adminEnvelope();
        $configuration = $admin['draft']['configuration'];
        $configuration['deployment_profiles'][0]['team_ids'] = [$team->id];
        $updated = $workflow->updateDraft($admin['draft']['lock_version'], $configuration, $actor);
        $published = $workflow->publishDraft($updated['draft']['lock_version'], $actor);
        $this->assertContains($team->id, array_column($published['catalogs']['teams'], 'id'));

        $service = app(IncidentIntakeDossierService::class);
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
        $firstDossier = IncidentIntakeDossier::query()->findOrFail($first['id']);
        $firstDecision = $service->decidePriority($firstDossier, [
            'lock_version' => $first['lock_version'],
            'client_mutation_id' => 'inactive-team-first-decision',
            'priority' => 'low',
        ], $actor);
        $team->update(['is_operational' => false]);

        $this->assertNotContains($team->id, array_column($workflow->catalogs()['teams'], 'id'));
        try {
            $service->decidePriority(IncidentIntakeDossier::query()->findOrFail($second['id']), [
                'lock_version' => $second['lock_version'],
                'client_mutation_id' => 'inactive-team-second-decision',
                'priority' => 'low',
            ], $actor);
            $this->fail('Een nieuw besluit mag geen niet-operationeel team selecteren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('deployment_adjustments.team_ids', $exception->errors());
        }

        try {
            $service->promote($firstDossier, [
                'lock_version' => $firstDecision['lock_version'],
                'client_mutation_id' => 'inactive-team-first-promote',
            ], $actor);
            $this->fail('Een bestaand besluit met een gedeactiveerd team mag niet promoveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('selected_deployment_profile_id', $exception->errors());
        }

        $this->assertDatabaseCount('incidents', 0);

        $standalone = app(IncidentService::class)->create([
            'title' => 'Losstaand dispatchveiligheidsincident',
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

    public function test_operator_projection_is_double_filtered_and_full_dossier_routes_are_manage_only(): void
    {
        $manager = $this->user('privacy-manager@example.test');
        $operator = $this->user('privacy-operator@example.test');
        $this->grant($manager, ['incidents.manage', 'forms.manage']);
        $this->grant($operator, ['incidents.view']);
        $dossiers = app(IncidentIntakeDossierService::class);
        $created = $dossiers->create([
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
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $dossiers->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'privacy-decision',
            'priority' => 'low',
        ], $manager);
        $incident = $dossiers->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'privacy-promote',
        ], $manager)['incident'];

        $operatorToken = $operator->createToken('Operator Android', ['*', 'client:operator'], now()->addHour());
        $operator->withAccessToken($operatorToken->accessToken);
        $legacyIncident = app(IncidentService::class)->create([
            'title' => 'Bestaand incident',
            'description' => 'Aangemaakt vóór de intakeworkflow.',
            'priority' => 'normal',
            'location_label' => 'Amersfoort',
            'custom_fields' => [
                'requesting_organization' => 'Legacy organisatie',
                'requesting_unit' => 'Legacy eenheid',
            ],
        ], $manager);
        $legacyPayload = MobileApiPayload::incident($legacyIncident, $operator);
        $this->assertSame(
            'Legacy organisatie',
            ((array) $legacyPayload['custom_fields'])['requesting_organization'],
        );
        $this->assertNull($legacyPayload['intake']);

        $initial = MobileApiPayload::incident($incident, $operator);
        $this->assertContains('person_age', array_column($initial['intake']['answers'], 'key'));
        $this->assertNotContains('medical_details', array_column($initial['intake']['answers'], 'key'));
        $this->assertSame('Vertrouwelijke melder', $incident->reporter_name);
        $this->assertSame('Politie', $incident->requesting_organization);
        $this->assertNull($initial['reporter_name']);
        $this->assertNull($initial['reporter_phone']);
        $this->assertNull($initial['requesting_organization']);
        $this->assertNull($initial['requesting_unit']);
        $this->assertNull($initial['on_scene_contact_name']);
        $this->assertSame([], (array) $initial['custom_fields']);
        $tokenMethod = new \ReflectionMethod(DispatchService::class, 'pushTemplateTokens');
        $tokens = $tokenMethod->invoke(app(DispatchService::class), $incident);
        $this->assertSame('', $tokens['reporter_name']);
        $this->assertSame('', $tokens['reporter_phone']);
        $this->assertSame('', $tokens['requesting_organization']);
        $this->assertSame('', $tokens['field_requesting_organization']);
        $this->assertSame('', $tokens['on_scene_contact_name']);

        $workflow = app(IncidentIntakeWorkflowService::class);
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

        $filtered = MobileApiPayload::incident($incident->refresh(), $operator);
        $keys = array_column($filtered['intake']['answers'], 'key');
        $this->assertNotContains('person_age', $keys);
        $this->assertNotContains('medical_details', $keys);
        $this->assertNull($filtered['reporter_name']);

        $this->asWebClient($operator)
            ->getJson('/api/intake-dossiers')
            ->assertForbidden();
        $this->asWebClient($operator)
            ->getJson("/api/incidents/{$incident->id}/intake-dossier")
            ->assertForbidden();
    }

    public function test_push_transport_defensively_redacts_historical_hidden_core_bindings(): void
    {
        Http::fake(['*' => Http::response([], 200)]);
        $actor = $this->user('push-privacy@example.test');
        $this->grant($actor, ['incidents.manage']);
        $dossiers = app(IncidentIntakeDossierService::class);
        $created = $dossiers->create([
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'push-privacy-create',
        ], $actor);
        $dossier = IncidentIntakeDossier::query()->findOrFail($created['id']);
        $decided = $dossiers->decidePriority($dossier, [
            'lock_version' => $created['lock_version'],
            'client_mutation_id' => 'push-privacy-decide',
            'priority' => 'low',
        ], $actor);
        $incident = $dossiers->promote($dossier, [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'push-privacy-promote',
        ], $actor)['incident'];

        $revision = $dossier->workflowRevision()->firstOrFail();
        $configuration = $revision->configuration;
        foreach ($configuration['fields'] as &$field) {
            if (in_array($field['key'], ['person_name', 'circumstances', 'last_seen_location'], true)) {
                $field['operator_visible'] = false;
            }
        }
        unset($field);
        $revision->forceFill(['configuration' => $configuration])->save();

        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $actor->id,
            'status' => 'draft',
            'priority' => 'normal',
            'message' => implode(' - ', [
                $incident->reference,
                $incident->title,
                $incident->location_label,
            ]),
        ])->load('incident');
        $dispatchService = app(DispatchService::class);

        $preannouncement = (new \ReflectionMethod(DispatchService::class, 'preannouncementNotification'))
            ->invoke($dispatchService, $incident->refresh());
        $cancellation = (new \ReflectionMethod(DispatchService::class, 'cancellationNotification'))
            ->invoke($dispatchService, $incident);
        $body = (new \ReflectionMethod(DispatchService::class, 'notificationBody'))
            ->invoke($dispatchService, $dispatch);
        $data = (new \ReflectionMethod(DispatchService::class, 'notificationData'))
            ->invoke($dispatchService, $dispatch);

        foreach ([$preannouncement['body'], $cancellation['body'], $body, ...array_values($data)] as $value) {
            $this->assertStringNotContainsString('Jan Jansen', (string) $value);
            $this->assertStringNotContainsString('Utrecht Centraal', (string) $value);
            $this->assertStringNotContainsString('Zoekactie in Utrecht', (string) $value);
        }
        $this->assertSame('', $data['incident_title']);
        $this->assertSame('', $data['incident_location']);
        $this->assertSame($incident->reference, $data['dispatch_message']);
        $this->assertSame('Ben je beschikbaar voor een melding?', $preannouncement['body']);
        $this->assertSame('De vooraankondiging is geannuleerd.', $cancellation['body']);
    }

    public function test_api_contract_supports_dirty_patches_conflicts_decision_and_promote(): void
    {
        $actor = $this->user('api-intake@example.test');
        $this->grant($actor, ['incidents.manage', 'incidents.view', 'intakes.priority.override']);
        $client = $this->asWebClient($actor);
        $createPayload = [
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
            'client_mutation_id' => 'api-create',
        ];

        $created = $client->postJson('/api/intake-dossiers', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.subject_type', 'person')
            ->assertJsonPath('data.triage.state', 'determined')
            ->assertJsonPath('data.triage.recommended_priority', 'low')
            ->assertJsonPath('data.lock_version', 1)
            ->json('data');
        $client->postJson('/api/intake-dossiers', $createPayload)
            ->assertCreated()
            ->assertJsonPath('data.id', $created['id']);
        $this->assertDatabaseCount('incident_intake_dossiers', 1);

        $patched = $client->patchJson("/api/intake-dossiers/{$created['id']}", [
            'lock_version' => 1,
            'client_mutation_id' => 'api-patch',
            'changes' => ['answers' => ['person_clothing' => 'Groene jas']],
        ])->assertOk()
            ->assertJsonPath('data.lock_version', 2)
            ->json('data');

        $client->patchJson("/api/intake-dossiers/{$created['id']}", [
            'lock_version' => 1,
            'client_mutation_id' => 'api-stale',
            'changes' => ['answers' => ['person_clothing' => 'Rode jas']],
        ])->assertConflict()
            ->assertJsonPath('error.code', 'intake_version_conflict')
            ->assertJsonPath('error.details.current.lock_version', 2);

        $decided = $client->patchJson("/api/intake-dossiers/{$created['id']}/priority", [
            'lock_version' => $patched['lock_version'],
            'client_mutation_id' => 'api-decision',
            'priority' => 'low',
        ])->assertOk()
            ->assertJsonPath('data.decided_priority', 'low')
            ->json('data');

        $promoted = $client->postJson("/api/intake-dossiers/{$created['id']}/promote", [
            'lock_version' => $decided['lock_version'],
            'client_mutation_id' => 'api-promote',
        ])->assertCreated()
            ->assertJsonPath('data.dossier.status', 'promoted')
            ->assertJsonPath('data.incident.status', 'draft')
            ->json('data');

        $client->getJson("/api/incidents/{$promoted['incident']['id']}/intake-dossier")
            ->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.incident_id', $promoted['incident']['id']);
    }

    public function test_override_only_custom_role_can_load_team_catalog_without_incident_view(): void
    {
        $actor = $this->user('override-team-catalog@example.test');
        $this->grant($actor, ['intakes.priority.override']);

        $this->asWebClient($actor)
            ->getJson('/api/teams')
            ->assertOk();
    }

    public function test_admin_api_returns_full_envelopes_and_simulates_only_server_validated_drafts(): void
    {
        $actor = $this->user('api-workflow@example.test');
        $this->grant($actor, ['forms.manage']);
        $client = $this->asWebClient($actor);

        $config = $client->getJson('/api/admin/intake-workflow/config')
            ->assertOk()
            ->assertJsonPath('data.published.version', 1)
            ->assertJsonStructure(['data' => ['draft', 'published', 'history', 'catalogs' => ['incident_fields', 'teams', 'certification_types', 'operators']]])
            ->json('data');
        $configuration = $config['draft']['configuration'];
        $configuration['subject_types'][0]['label'] = 'Persoon';

        $updated = $client->patchJson('/api/admin/intake-workflow/draft', [
            'expected_revision' => $config['draft']['lock_version'],
            'configuration' => $configuration,
        ])->assertOk()
            ->assertJsonPath('data.draft.configuration.subject_types.0.label', 'Persoon')
            ->json('data');

        $client->postJson('/api/admin/intake-workflow/simulate', [
            'expected_revision' => $updated['draft']['lock_version'],
            'subject_type' => 'person',
            'answers' => $this->personAnswers(),
        ])->assertOk()
            ->assertJsonPath('data.triage.state', 'determined')
            ->assertJsonPath('data.deployment_proposal.recommended_dispatch_mode', 'preannouncement');

        $published = $client->postJson('/api/admin/intake-workflow/publish', [
            'expected_revision' => $updated['draft']['lock_version'],
        ])->assertOk()
            ->assertJsonPath('data.published.version', 2)
            ->assertJsonPath('data.published.configuration.subject_types.0.label', 'Persoon')
            ->json('data');

        $client->postJson('/api/admin/intake-workflow/restore', [
            'published_revision_id' => $config['published']['id'],
            'expected_revision' => $published['draft']['lock_version'],
        ])->assertOk()
            ->assertJsonPath('data.draft.configuration.subject_types.0.label', 'Mens')
            ->assertJsonPath('data.published.version', 2);
    }

    public function test_incident_form_change_cannot_invalidate_published_intake_bindings(): void
    {
        $actor = $this->user('cross-form-contract@example.test');
        $this->grant($actor, ['forms.manage']);
        app(IncidentIntakeWorkflowService::class)->published();
        $incidentForm = app(IncidentFormService::class);
        $fields = $incidentForm->fields();
        $fields[] = [
            'key' => 'new_required_without_intake_binding',
            'label' => 'Nieuw verplicht hoofdveld',
            'type' => 'text',
            'visible' => true,
            'required' => true,
            'width' => 'full',
            'expose_to_push' => false,
            'available_in_operator_app' => false,
        ];

        $this->asWebClient($actor)
            ->patchJson('/api/admin/incident-form/config', [
                'fields' => $fields,
                'layout' => $incidentForm->layout(),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['configuration.bindings']]]);

        $stored = SystemSetting::value(IncidentFormService::SETTING_KEY, []);
        $this->assertNotContains(
            'new_required_without_intake_binding',
            array_column(is_array($stored) ? $stored : [], 'key'),
        );
    }

    public function test_override_permission_migration_only_grants_canonical_coordinator_roles(): void
    {
        $existing = Permission::query()->where('name', 'intakes.priority.override')->first();
        if ($existing !== null) {
            DB::table('permission_role')->where('permission_id', $existing->id)->delete();
            $existing->delete();
        }
        $manage = Permission::query()->firstOrCreate(
            ['name' => 'incidents.manage'],
            ['category' => 'test', 'display_name' => 'Incidenten beheren', 'description' => 'Test'],
        );
        $roles = collect([
            'system-administrator',
            'national-coordinator',
            'incident-coordinator',
            'custom-incident-manager',
        ])->mapWithKeys(function (string $name): array {
            $role = Role::query()->create([
                'name' => $name,
                'display_name' => $name,
                'can_use_operator_app' => false,
                'can_use_admin_app' => true,
            ]);
            $role->permissions()->attach(Permission::query()->where('name', 'incidents.manage')->value('id'), ['created_at' => now()]);

            return [$name => $role];
        });

        $migration = require database_path('migrations/2026_07_25_000004_add_incident_intake_permissions.php');
        $migration->up();
        $overrideId = Permission::query()->where('name', 'intakes.priority.override')->value('id');

        foreach (['system-administrator', 'national-coordinator', 'incident-coordinator'] as $canonicalRole) {
            $this->assertDatabaseHas('permission_role', [
                'role_id' => $roles[$canonicalRole]->id,
                'permission_id' => $overrideId,
            ]);
        }
        $this->assertDatabaseMissing('permission_role', [
            'role_id' => $roles['custom-incident-manager']->id,
            'permission_id' => $overrideId,
        ]);
        $this->assertTrue($roles['custom-incident-manager']->permissions()->whereKey($manage->id)->exists());
    }

    /** @return array<string, mixed> */
    private function personAnswers(): array
    {
        return [
            'last_seen_at' => '2026-07-26T12:30:00+02:00',
            'last_seen_location' => 'Utrecht Centraal',
            'last_seen_direction' => 'Onbekend',
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
            'name' => 'intake-test-'.str()->ulid(),
            'display_name' => 'Intake test',
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
        $token = $user->createToken('Incident intake test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
