<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Services\DeploymentFormService;
use App\Services\DeploymentReportService;
use App\Services\DeploymentRequestService;
use App\Services\DeploymentRequestWorkflowService;
use App\Services\DispatchService;
use App\Services\PilotDeploymentReportFormService;
use App\Support\FormFieldType;
use App\Support\FormFieldValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

final class ConfigurableFormFieldTypesTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_and_deployment_forms_accept_the_shared_field_type_union_in_order(): void
    {
        $fields = array_map(
            fn (string $type, int $index): array => $this->standardField($type, $index),
            FormFieldType::ALL,
            array_keys(FormFieldType::ALL),
        );

        $pilotFields = app(PilotDeploymentReportFormService::class)->validateFields($fields);
        $deploymentFields = app(DeploymentFormService::class)->validateFields($fields);

        $this->assertSame(FormFieldType::ALL, array_column($pilotFields, 'type'));
        $this->assertSame(
            FormFieldType::ALL,
            array_column(array_slice($deploymentFields, 0, count(FormFieldType::ALL)), 'type'),
        );
        $this->assertSame(array_column($fields, 'key'), array_column($pilotFields, 'key'));
        $this->assertSame(
            array_column($fields, 'key'),
            array_column(array_slice($deploymentFields, 0, count(FormFieldType::ALL)), 'key'),
        );
    }

    public function test_score_date_and_datetime_values_validate_and_normalize_for_both_stored_forms(): void
    {
        $configured = [
            $this->standardField('score', 1, required: true),
            $this->standardField('date', 2, required: true),
            $this->standardField('datetime', 3, required: true),
            $this->standardField('address', 4, required: true),
        ];
        $input = ['custom_fields' => [
            'field_score_1' => '4',
            'field_date_2' => '2026-08-04',
            'field_datetime_3' => '2026-08-04T12:30:00+02:00',
            'field_address_4' => '  Dam 1, Amsterdam  ',
            'requesting_organization' => 'Politie',
        ]];

        $pilot = app(PilotDeploymentReportFormService::class);
        $pilotFields = $pilot->validateFields($configured);
        SystemSetting::query()->updateOrCreate(
            ['key' => PilotDeploymentReportFormService::SETTING_KEY],
            ['value' => $pilotFields, 'is_sensitive' => false],
        );
        Validator::make($input, $pilot->validationRules())->validate();
        $this->assertSame([
            'field_score_1' => 4,
            'field_date_2' => '2026-08-04',
            'field_datetime_3' => '2026-08-04T10:30:00+00:00',
            'field_address_4' => 'Dam 1, Amsterdam',
        ], $pilot->normalizeCustomValues($input));

        $deployment = app(DeploymentFormService::class);
        $deploymentFields = $deployment->validateFields($configured);
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentFields, 'is_sensitive' => false],
        );
        Validator::make($input, $deployment->validationRules())->validate();
        $this->assertSame([
            'field_score_1' => 4,
            'field_date_2' => '2026-08-04',
            'field_datetime_3' => '2026-08-04T10:30:00+00:00',
            'field_address_4' => 'Dam 1, Amsterdam',
            'requesting_organization' => 'Politie',
        ], $deployment->normalizeCustomValues($input));

        foreach ([0, 6] as $invalidScore) {
            $invalid = $input;
            $invalid['custom_fields']['field_score_1'] = $invalidScore;
            $validator = Validator::make($invalid, $deployment->validationRules());
            $this->assertTrue($validator->fails());
            $this->assertArrayHasKey('custom_fields.field_score_1', $validator->errors()->toArray());
        }
    }

    public function test_flight_time_rejects_hour_24_across_stored_forms_and_workflow_answers(): void
    {
        $this->assertSame('23:59', FormFieldValue::normalizeTime(' 23:59 '));
        foreach (['24:00', '24:59', '23:60'] as $invalidTime) {
            $this->assertNull(FormFieldValue::normalizeTime($invalidTime));
        }

        $field = $this->standardField('flight_time', 5, required: true);
        $validInput = ['custom_fields' => [
            'field_flight_time_5' => ['start' => '23:59', 'end' => '00:00'],
            'requesting_organization' => 'Politie',
        ]];
        $invalidInputs = [
            ['custom_fields' => [
                'field_flight_time_5' => ['start' => '24:00', 'end' => '01:00'],
                'requesting_organization' => 'Politie',
            ]],
            ['custom_fields' => [
                'field_flight_time_5' => ['start' => '23:00', 'end' => '24:59'],
                'requesting_organization' => 'Politie',
            ]],
        ];

        $pilot = app(PilotDeploymentReportFormService::class);
        SystemSetting::query()->updateOrCreate(
            ['key' => PilotDeploymentReportFormService::SETTING_KEY],
            ['value' => $pilot->validateFields([$field]), 'is_sensitive' => false],
        );
        $this->assertFalse(Validator::make($validInput, $pilot->validationRules())->fails());
        foreach ($invalidInputs as $invalidInput) {
            $validator = Validator::make($invalidInput, $pilot->validationRules());
            $this->assertTrue($validator->fails());
            $this->assertNotEmpty(array_intersect(
                [
                    'custom_fields.field_flight_time_5.start',
                    'custom_fields.field_flight_time_5.end',
                ],
                array_keys($validator->errors()->toArray()),
            ));
        }

        $deployment = app(DeploymentFormService::class);
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deployment->validateFields([$field]), 'is_sensitive' => false],
        );
        $this->assertFalse(Validator::make($validInput, $deployment->validationRules())->fails());
        foreach ($invalidInputs as $invalidInput) {
            $validator = Validator::make($invalidInput, $deployment->validationRules());
            $this->assertTrue($validator->fails());
            $this->assertNotEmpty(array_intersect(
                [
                    'custom_fields.field_flight_time_5.start',
                    'custom_fields.field_flight_time_5.end',
                ],
                array_keys($validator->errors()->toArray()),
            ));
        }

        $workflow = app(DeploymentRequestWorkflowService::class);
        $configuration = [
            'fields' => [[
                ...$this->workflowField('flight_time', 5),
                'key' => 'flight_window',
            ]],
            'bindings' => [],
        ];
        $this->assertSame([
            'flight_window' => [
                'start' => '23:59',
                'end' => '00:00',
                'duration_minutes' => 1,
            ],
        ], $workflow->normalizeAnswers($configuration, 'person', [
            'flight_window' => '23:59-00:00',
        ], patch: true));

        foreach ([
            '24:00-01:00',
            ['start' => '23:00', 'end' => '24:59', 'duration_minutes' => 119],
        ] as $invalidValue) {
            try {
                $workflow->normalizeAnswers($configuration, 'person', [
                    'flight_window' => $invalidValue,
                ], patch: true);
                $this->fail('Vluchttijd met uur 24 moet worden geweigerd.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('answers.flight_window', $exception->errors());
            }
        }
    }

    public function test_workflow_supports_the_union_score_rules_and_strict_score_bindings(): void
    {
        $deploymentForm = app(DeploymentFormService::class);
        $deploymentFields = $deploymentForm->fields();
        $deploymentFields[] = [
            'key' => 'satisfaction',
            'label' => 'Tevredenheid',
            'type' => 'score',
            'visible' => true,
            'required' => false,
            'expose_to_push' => true,
        ];
        SystemSetting::query()->updateOrCreate(
            ['key' => DeploymentFormService::SETTING_KEY],
            ['value' => $deploymentForm->validateFields($deploymentFields), 'is_sensitive' => false],
        );

        $workflow = app(DeploymentRequestWorkflowService::class);
        $configuration = $workflow->defaultConfiguration();
        $existingTypes = array_column($configuration['fields'], 'type');
        foreach (FormFieldType::ALL as $index => $type) {
            if (in_array($type, $existingTypes, true)) {
                continue;
            }
            $configuration['fields'][] = $this->workflowField($type, $index);
        }
        $configuration['fields'][] = [
            ...$this->workflowField('phone', 90),
            'key' => 'test_phone',
        ];
        $configuration['fields'][] = [
            ...$this->workflowField('datetime', 91),
            'key' => 'test_datetime',
        ];
        $configuration['fields'][] = [
            'key' => 'satisfaction_score',
            'label' => 'Tevredenheid',
            'type' => 'score',
            'scope' => 'common',
            'required' => false,
            'operator_visible' => true,
            'help_text' => null,
            'options' => [],
        ];
        $configuration['bindings'][] = [
            'field_key' => 'satisfaction_score',
            'target' => 'custom_fields.satisfaction',
        ];
        array_unshift($configuration['priority_rules'], [
            'id' => 'good_satisfaction',
            'label' => 'Goede score',
            'subject_types' => ['person'],
            'match' => 'all',
            'conditions' => [[
                'field_key' => 'satisfaction_score',
                'operator' => 'greater_than_or_equal',
                'value' => 4,
            ]],
            'priority' => 'high',
            'explanation' => 'De score is minimaal goed.',
            'deployment_profile_id' => 'high_response',
        ]);

        $validated = $workflow->validateConfiguration($configuration);
        $this->assertEqualsCanonicalizing(
            FormFieldType::ALL,
            array_values(array_unique(array_column($validated['fields'], 'type'))),
        );

        $flightField = collect($validated['fields'])->firstWhere('type', 'flight_time');
        $phoneField = collect($validated['fields'])->firstWhere('key', 'test_phone');
        $dateField = collect($validated['fields'])->firstWhere('type', 'date');
        $dateTimeField = collect($validated['fields'])->firstWhere('key', 'test_datetime');
        $answers = $workflow->normalizeAnswers($validated, 'person', [
            'last_seen_at' => '2026-08-04T12:30:00+02:00',
            'last_seen_location' => 'Utrecht Centraal',
            'deployment_location' => 'Kazerne Utrecht',
            'circumstances' => 'Volledige testaanvraag',
            'requesting_organization' => 'Politie',
            'immediate_danger' => false,
            'person_name' => 'Testpersoon',
            'person_age' => 42,
            'person_vulnerable' => false,
            'satisfaction_score' => 4,
            $flightField['key'] => ['start' => '23:30', 'end' => '00:15', 'duration_minutes' => null],
            $phoneField['key'] => '+31612345678',
            $dateField['key'] => '2026-08-04',
            $dateTimeField['key'] => '2026-08-04T12:30:00Z',
        ]);

        $this->assertSame(4, $answers['satisfaction_score']);
        $this->assertSame([
            'start' => '23:30',
            'end' => '00:15',
            'duration_minutes' => 45,
        ], $answers[$flightField['key']]);
        $this->assertSame('+31612345678', $answers[$phoneField['key']]);
        $this->assertSame('2026-08-04', $answers[$dateField['key']]);
        $this->assertSame('2026-08-04T10:30:00+00:00', $answers['last_seen_at']);
        $this->assertSame('2026-08-04T12:30:00+00:00', $answers[$dateTimeField['key']]);
        $this->assertSame('high', $workflow->evaluate($validated, 'person', $answers)['triage']['recommended_priority']);

        $operators = collect($workflow->catalogs()['operators'])->keyBy('key');
        foreach (['equals', 'not_equals', 'greater_than_or_equal', 'less_than_or_equal', 'is_present'] as $operator) {
            $this->assertContains('score', $operators[$operator]['field_types']);
        }
        foreach (['contains', 'is_true', 'is_false'] as $operator) {
            $this->assertNotContains('score', $operators[$operator]['field_types']);
        }

        $numberBoundToScore = $validated;
        foreach ($numberBoundToScore['fields'] as &$field) {
            if ($field['key'] === 'satisfaction_score') {
                $field['type'] = 'number';
            }
        }
        unset($field);
        $this->expectException(ValidationException::class);
        $workflow->validateConfiguration($numberBoundToScore);
    }

    public function test_score_display_is_consistent_in_request_report_and_push_presenters(): void
    {
        $this->assertSame('4/5 – Goed', FormFieldType::scoreDisplay(4));

        $requestDisplay = new ReflectionMethod(DeploymentRequestService::class, 'displayValue');
        $this->assertSame(
            '4/5 – Goed',
            $requestDisplay->invoke(app(DeploymentRequestService::class), ['type' => 'score'], 4),
        );

        $reportDisplay = new ReflectionMethod(DeploymentReportService::class, 'pilotCustomDisplayValue');
        $this->assertSame(
            '4/5 – Goed',
            $reportDisplay->invoke(app(DeploymentReportService::class), ['type' => 'score'], 4),
        );

        $pushDisplay = new ReflectionMethod(DispatchService::class, 'stringifyCustomFieldValue');
        $this->assertSame(
            '4/5 – Goed',
            $pushDisplay->invoke(app(DispatchService::class), 4, ['type' => 'score']),
        );
        $this->assertSame(
            '04-08-2026',
            $pushDisplay->invoke(app(DispatchService::class), '2026-08-04', ['type' => 'date']),
        );
        $this->assertSame(
            '04-08-2026 12:30',
            $pushDisplay->invoke(app(DispatchService::class), '2026-08-04T10:30:00Z', ['type' => 'datetime']),
        );
        $this->assertSame(
            '04-01-2026 12:30',
            $pushDisplay->invoke(app(DispatchService::class), '2026-01-04T11:30:00+00:00', ['type' => 'datetime']),
        );
        $this->assertSame(
            '04-08-2026 12:30',
            $pushDisplay->invoke(app(DispatchService::class), '2026-08-04T12:30:00+02:00', ['type' => 'datetime']),
        );
        $this->assertSame(
            'onverwachte historische waarde',
            $pushDisplay->invoke(app(DispatchService::class), ' onverwachte historische waarde ', ['type' => 'datetime']),
        );
    }

    /** @return array<string, mixed> */
    private function standardField(string $type, int $index, bool $required = false): array
    {
        return [
            'key' => "field_{$type}_{$index}",
            'label' => "Veld $type",
            'type' => $type,
            'visible' => true,
            'required' => $required,
            'options' => in_array($type, ['select', 'radio'], true)
                ? [['value' => 'one', 'label' => 'Een'], ['value' => 'two', 'label' => 'Twee']]
                : [],
            'phone_countries' => $type === 'phone' ? ['31', '32'] : [],
        ];
    }

    /** @return array<string, mixed> */
    private function workflowField(string $type, int $index): array
    {
        return [
            'key' => "workflow_{$type}_{$index}",
            'label' => "Workflowveld $type",
            'type' => $type,
            'scope' => 'common',
            'required' => false,
            'operator_visible' => true,
            'help_text' => null,
            'options' => in_array($type, ['select', 'radio'], true)
                ? [['value' => 'one', 'label' => 'Een'], ['value' => 'two', 'label' => 'Twee']]
                : [],
        ];
    }
}
