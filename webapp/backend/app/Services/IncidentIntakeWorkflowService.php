<?php

namespace App\Services;

use App\Exceptions\IncidentIntakeConflictException;
use App\Models\Certification;
use App\Models\Incident;
use App\Models\IncidentIntakeWorkflowRevision;
use App\Models\Team;
use App\Models\User;
use App\Repositories\IncidentIntakeWorkflowRepository;
use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IncidentIntakeWorkflowService
{
    private const FORM_CONTRACT_ADVISORY_LOCK = 4920552417250622275;

    private ?IncidentIntakeWorkflowRevision $publishedCache = null;

    /** @var list<string> */
    public const SUBJECT_TYPES = ['person', 'animal', 'object'];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /** @var list<string> */
    public const LEGACY_MIRRORED_FIELD_KEYS = [
        'requesting_organization',
        'requesting_unit',
        'on_scene_contact_name',
        'on_scene_contact_phone',
        'on_scene_contact_role',
        'required_resources',
    ];

    /** @var list<string> */
    private const FIELD_TYPES = ['section', 'text', 'textarea', 'number', 'select', 'radio', 'checkbox', 'date', 'datetime'];

    /** @var list<string> */
    private const SCOPES = ['common', 'person', 'animal', 'object'];

    /** @var list<string> */
    private const OPERATORS = ['equals', 'not_equals', 'contains', 'greater_than_or_equal', 'less_than_or_equal', 'is_true', 'is_false', 'is_present'];

    /** @var list<string> */
    private const FIXED_BINDING_TARGETS = [
        'title',
        'description',
        'reporter_name',
        'reporter_phone',
        'requesting_organization',
        'requesting_unit',
        'on_scene_contact_name',
        'on_scene_contact_phone',
        'on_scene_contact_role',
        'location_label',
    ];

    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{1,60}$/';

    public function __construct(
        private readonly IncidentIntakeWorkflowRepository $repository,
        private readonly IncidentFormService $incidentFormService,
    ) {}

    public function published(): IncidentIntakeWorkflowRevision
    {
        if ($this->publishedCache !== null) {
            return $this->publishedCache;
        }
        $published = $this->repository->published();
        if ($published !== null) {
            return $this->publishedCache = $published;
        }

        return $this->publishedCache = DB::transaction(fn (): IncidentIntakeWorkflowRevision => IncidentIntakeWorkflowRevision::query()->firstOrCreate(
            ['version' => 1],
            [
                'status' => 'published',
                'lock_version' => 1,
                'configuration' => $this->validateConfiguration($this->defaultConfiguration()),
                'published_at' => now(),
            ],
        ));
    }

    /**
     * @return array{draft: array<string, mixed>, published: array<string, mixed>, history: list<array<string, mixed>>, catalogs: array<string, mixed>}
     */
    public function adminEnvelope(): array
    {
        $published = $this->published();
        $draft = $this->ensureDraft($published);

        return [
            'draft' => $this->revisionPayload($draft),
            'published' => $this->revisionPayload($published),
            'history' => $this->repository->history()
                ->map(fn (IncidentIntakeWorkflowRevision $revision): array => $this->revisionPayload($revision))
                ->values()
                ->all(),
            'catalogs' => $this->catalogs(),
        ];
    }

    public function updateDraft(int $expectedRevision, array $configuration, User $actor): array
    {
        $normalized = $this->validateConfiguration($configuration);

        DB::transaction(function () use ($expectedRevision, $normalized, $actor): void {
            $draft = $this->repository->draft(lock: true) ?? $this->ensureDraft($this->published());
            if ($draft->lock_version !== $expectedRevision) {
                throw new IncidentIntakeConflictException(
                    'intake_workflow_conflict',
                    'De formulierconfiguratie is intussen gewijzigd.',
                    $this->revisionPayload($draft),
                );
            }

            $draft->forceFill([
                'configuration' => $normalized,
                'lock_version' => $draft->lock_version + 1,
                'updated_by' => $actor->id,
            ])->save();
        });

        return $this->adminEnvelope();
    }

    public function publishDraft(int $expectedRevision, User $actor): array
    {
        DB::transaction(function () use ($expectedRevision, $actor): void {
            $this->acquireFormContractMutationLock();
            $draft = $this->repository->draft(lock: true);
            if ($draft === null) {
                throw ValidationException::withMessages(['draft' => ['Er is geen concept om te publiceren.']]);
            }
            if ($draft->lock_version !== $expectedRevision) {
                throw new IncidentIntakeConflictException(
                    'intake_workflow_conflict',
                    'De formulierconfiguratie is intussen gewijzigd.',
                    $this->revisionPayload($draft),
                );
            }

            $configuration = $this->validateConfiguration($draft->configuration ?? []);
            $nextVersion = ((int) (IncidentIntakeWorkflowRevision::query()->max('version') ?? 0)) + 1;
            $draft->forceFill([
                'version' => $nextVersion,
                'status' => 'published',
                'draft_marker' => null,
                'configuration' => $configuration,
                'published_by' => $actor->id,
                'published_at' => now(),
                'updated_by' => $actor->id,
            ])->save();

            IncidentIntakeWorkflowRevision::query()->create([
                'status' => 'draft',
                'draft_marker' => 'active',
                'lock_version' => $draft->lock_version + 1,
                'configuration' => $configuration,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->publishedCache = $draft->refresh();
        });

        return $this->adminEnvelope();
    }

    /**
     * The current incident form and published intake workflow are one
     * cross-table contract. Every mutation of either active half acquires this
     * lock before reading the other half, preventing a publish/form-update
     * time-of-check/time-of-use race.
     */
    public function acquireFormContractMutationLock(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('The incident form contract lock requires an active transaction.');
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'pgsql') {
            $connection->select(
                'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
                [self::FORM_CONTRACT_ADVISORY_LOCK],
            );

            return;
        }

        // Tests use SQLite, whose write transactions already serialize. This
        // row lock provides equivalent ordering for other supported drivers.
        IncidentIntakeWorkflowRevision::query()
            ->where('status', 'published')
            ->latest('version')
            ->lockForUpdate()
            ->first(['id']);
    }

    public function validatePublishedFormContract(): IncidentIntakeWorkflowRevision
    {
        $published = $this->repository->published() ?? $this->published();
        $this->validateConfiguration($published->configuration ?? []);

        return $published;
    }

    public function restore(string $publishedRevisionId, int $expectedRevision, User $actor): array
    {
        $source = IncidentIntakeWorkflowRevision::query()
            ->where('status', 'published')
            ->findOrFail($publishedRevisionId);

        DB::transaction(function () use ($expectedRevision, $source, $actor): void {
            $draft = $this->repository->draft(lock: true) ?? $this->ensureDraft($this->published());
            if ($draft->lock_version !== $expectedRevision) {
                throw new IncidentIntakeConflictException(
                    'intake_workflow_conflict',
                    'De formulierconfiguratie is intussen gewijzigd.',
                    $this->revisionPayload($draft),
                );
            }

            // Restoring is deliberately a raw historical copy. Stale teams or
            // certifications remain visible in the draft so an administrator
            // can repair them; update/publish still performs strict validation.
            $draft->forceFill([
                'configuration' => $source->configuration ?? [],
                'lock_version' => $draft->lock_version + 1,
                'updated_by' => $actor->id,
            ])->save();
        });

        return $this->adminEnvelope();
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function validateConfiguration(array $configuration): array
    {
        $allowedTopLevel = ['subject_types', 'fields', 'bindings', 'priority_rules', 'deployment_profiles'];
        if (array_diff(array_keys($configuration), $allowedTopLevel) !== []) {
            throw ValidationException::withMessages(['configuration' => ['De configuratie bevat onbekende eigenschappen.']]);
        }

        $subjectTypes = $this->normalizeSubjectTypes($configuration['subject_types'] ?? null);
        $fields = $this->normalizeFields($configuration['fields'] ?? null);
        $fieldMap = collect($fields)->keyBy('key');
        $bindings = $this->normalizeBindings($configuration['bindings'] ?? [], $fieldMap->all());
        $this->assertRequiredIncidentBindings($bindings, $fieldMap->all());
        $profiles = $this->normalizeDeploymentProfiles($configuration['deployment_profiles'] ?? []);
        $rules = $this->normalizePriorityRules(
            $configuration['priority_rules'] ?? [],
            $fieldMap->all(),
            collect($profiles)->keyBy('id')->all(),
        );

        return [
            'subject_types' => $subjectTypes,
            'fields' => $fields,
            'bindings' => $bindings,
            'priority_rules' => $rules,
            'deployment_profiles' => $profiles,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $answers
     * @return array{triage: array<string, mixed>, deployment_proposal: array<string, mixed>|null}
     */
    public function evaluate(array $configuration, string $subjectType, array $answers): array
    {
        $this->assertSubjectType($subjectType);
        $fields = collect($configuration['fields'] ?? [])
            ->filter(fn (mixed $field): bool => is_array($field))
            ->filter(fn (array $field): bool => $this->fieldApplies($field, $subjectType))
            ->values();

        $missing = $fields
            ->filter(fn (array $field): bool => ($field['type'] ?? null) !== 'section' && ($field['required'] ?? false) === true)
            ->filter(fn (array $field): bool => $this->isEmpty($answers[(string) $field['key']] ?? null))
            ->map(fn (array $field): array => ['key' => $field['key'], 'label' => $field['label']])
            ->values()
            ->all();

        if ($missing !== []) {
            return [
                'triage' => [
                    'state' => 'incomplete',
                    'recommended_priority' => null,
                    'reasons' => ['Vul eerst de verplichte beslisinformatie in.'],
                    'missing_fields' => $missing,
                ],
                'deployment_proposal' => null,
            ];
        }

        $matched = [];
        $unknownRules = [];
        foreach ($configuration['priority_rules'] ?? [] as $ruleOrder => $rule) {
            if (! is_array($rule) || ! in_array($subjectType, $rule['subject_types'] ?? [], true)) {
                continue;
            }

            $results = [];
            foreach ($rule['conditions'] ?? [] as $condition) {
                if (is_array($condition)) {
                    $result = $this->evaluateCondition($condition, $answers);
                    $results[] = $result;
                }
            }

            $state = $results === [] ? true : match ($rule['match'] ?? 'all') {
                'all' => in_array(false, $results, true)
                    ? false
                    : (in_array(null, $results, true) ? null : true),
                default => in_array(true, $results, true)
                    ? true
                    : (in_array(null, $results, true) ? null : false),
            };
            if ($state === true) {
                $rule['_order'] = $ruleOrder;
                $matched[] = $rule;
            } elseif ($state === null) {
                $unknownRules[] = $rule;
            }
        }

        if ($matched === []) {
            return [
                'triage' => [
                    'state' => 'unknown',
                    'recommended_priority' => null,
                    'reasons' => [$unknownRules !== []
                        ? 'Niet alle informatie voor een betrouwbaar prioriteitsadvies is beschikbaar.'
                        : 'Geen gepubliceerde prioriteitsregel past bij deze melding.'],
                    'missing_fields' => [],
                ],
                'deployment_proposal' => null,
            ];
        }

        usort($matched, function (array $left, array $right): int {
            $priorityComparison = $this->priorityRank((string) $right['priority']) <=> $this->priorityRank((string) $left['priority']);

            return $priorityComparison !== 0 ? $priorityComparison : ((int) $left['_order'] <=> (int) $right['_order']);
        });
        $winning = $matched[0];
        $priority = (string) $winning['priority'];
        $unknownCouldChangeOutcome = collect($unknownRules)
            ->contains(function (array $unknownRule) use ($winning, $priority): bool {
                $unknownPriority = (string) $unknownRule['priority'];

                return $this->priorityRank($unknownPriority) > $this->priorityRank($priority)
                    || ($unknownPriority === $priority
                        && ($unknownRule['deployment_profile_id'] ?? null) !== ($winning['deployment_profile_id'] ?? null));
            });
        if ($unknownCouldChangeOutcome) {
            return [
                'triage' => [
                    'state' => 'unknown',
                    'recommended_priority' => null,
                    'reasons' => ['Aanvullende informatie kan de prioriteit verhogen; het advies is nog niet betrouwbaar.'],
                    'missing_fields' => [],
                ],
                'deployment_proposal' => null,
            ];
        }
        $reasons = collect($matched)
            ->filter(fn (array $rule): bool => (string) $rule['priority'] === $priority)
            ->pluck('explanation')
            ->filter()
            ->values()
            ->all();
        $proposal = $this->deploymentProposal($configuration, $subjectType, $priority, $winning['deployment_profile_id'] ?? null);

        return [
            'triage' => [
                'state' => 'determined',
                'recommended_priority' => $priority,
                'reasons' => $reasons === [] ? ['Prioriteit bepaald door de gepubliceerde beslisregels.'] : $reasons,
                'missing_fields' => [],
            ],
            'deployment_proposal' => $proposal,
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function normalizeAnswers(array $configuration, string $subjectType, array $answers, bool $patch = false): array
    {
        $this->assertSubjectType($subjectType);
        $fieldMap = collect($configuration['fields'] ?? [])
            ->filter(fn (mixed $field): bool => is_array($field) && ($field['type'] ?? null) !== 'section')
            ->keyBy('key');
        $targetDefinitions = collect($this->incidentFieldCatalog())->keyBy('target')->all();
        $normalized = [];

        foreach ($answers as $key => $value) {
            if (! is_string($key) || ! $fieldMap->has($key)) {
                throw ValidationException::withMessages(["answers.$key" => ['Dit uitvraagveld bestaat niet in de gekoppelde workflowversie.']]);
            }
            $field = $fieldMap->get($key);
            if (! is_array($field) || ! $this->fieldApplies($field, $subjectType)) {
                throw ValidationException::withMessages(["answers.$key" => ['Dit veld hoort niet bij het gekozen meldingstype.']]);
            }
            $normalized[$key] = $value === null && $patch ? null : $this->normalizeAnswerValue($field, $value, "answers.$key");
            $this->assertBoundAnswerFitsCurrentIncidentTarget(
                $configuration,
                $key,
                $normalized[$key],
                "answers.$key",
                $targetDefinitions,
            );
        }

        return $normalized;
    }

    /**
     * Revalidates frozen bound values against the incident form that is
     * current at promotion time, so removed or narrowed targets never vanish
     * silently from the resulting incident.
     *
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $answers
     */
    public function assertCurrentBindingTargets(
        array $configuration,
        string $subjectType,
        array $answers,
    ): void {
        $this->assertSubjectType($subjectType);
        $fieldMap = collect($configuration['fields'] ?? [])->keyBy('key');
        $targetDefinitions = collect($this->incidentFieldCatalog())->keyBy('target')->all();
        foreach ($configuration['bindings'] ?? [] as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            $fieldKey = (string) ($binding['field_key'] ?? '');
            $field = $fieldMap->get($fieldKey);
            if (! is_array($field)
                || ! $this->fieldApplies($field, $subjectType)
                || ! array_key_exists($fieldKey, $answers)) {
                continue;
            }
            $this->assertBoundAnswerFitsCurrentIncidentTarget(
                $configuration,
                $fieldKey,
                $answers[$fieldKey],
                "answers.$fieldKey",
                $targetDefinitions,
            );
        }
    }

    /** @return array<string, mixed> */
    public function revisionPayload(IncidentIntakeWorkflowRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'version' => $revision->version,
            'status' => $revision->status,
            'lock_version' => $revision->lock_version,
            'configuration' => $revision->configuration,
            'published_at' => ApiDateTime::dateTime($revision->published_at),
            'created_at' => ApiDateTime::dateTime($revision->created_at),
            'updated_at' => ApiDateTime::dateTime($revision->updated_at),
        ];
    }

    /** @return list<string> */
    public function hiddenIncidentTargetsForOperator(Incident $incident): array
    {
        $incident->loadMissing('intakeDossier.workflowRevision');
        $dossier = $incident->intakeDossier;
        if ($dossier === null) {
            return [];
        }
        $frozenConfiguration = $dossier->workflowRevision->configuration ?? [];
        $currentConfiguration = $this->published()->configuration ?? [];
        $currentFields = collect($currentConfiguration['fields'] ?? [])->keyBy('key');
        $frozenFields = collect($frozenConfiguration['fields'] ?? [])->keyBy('key');
        $subjectType = (string) $dossier->subject_type;
        $hidden = [];

        foreach ($frozenConfiguration['bindings'] ?? [] as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            $field = $frozenFields->get($binding['field_key'] ?? null);
            $currentField = $currentFields->get($binding['field_key'] ?? null);
            if (! is_array($field) || ! $this->fieldApplies($field, $subjectType)) {
                continue;
            }
            $visible = ($field['operator_visible'] ?? false) === true
                && is_array($currentField)
                && ($currentField['operator_visible'] ?? false) === true
                && $this->fieldApplies($currentField, $subjectType);
            if (! $visible) {
                $hidden[] = self::canonicalBindingTarget((string) ($binding['target'] ?? ''));
            }
        }

        return array_values(array_unique($hidden));
    }

    /** @return array<string, mixed> */
    public function catalogs(): array
    {
        return [
            'incident_fields' => $this->incidentFieldCatalog(),
            'teams' => Team::query()
                ->where('is_operational', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map->only(['id', 'name'])
                ->values()
                ->all(),
            'certification_types' => Certification::query()->orderBy('name')->get(['id', 'code', 'name'])->map->only(['id', 'code', 'name'])->values()->all(),
            'operators' => [
                ['key' => 'equals', 'label' => 'is gelijk aan', 'field_types' => array_values(array_diff(self::FIELD_TYPES, ['section'])), 'needs_value' => true],
                ['key' => 'not_equals', 'label' => 'is niet gelijk aan', 'field_types' => array_values(array_diff(self::FIELD_TYPES, ['section'])), 'needs_value' => true],
                ['key' => 'contains', 'label' => 'bevat', 'field_types' => ['text', 'textarea', 'select', 'radio'], 'needs_value' => true],
                ['key' => 'greater_than_or_equal', 'label' => 'is minimaal', 'field_types' => ['number', 'date', 'datetime'], 'needs_value' => true],
                ['key' => 'less_than_or_equal', 'label' => 'is maximaal', 'field_types' => ['number', 'date', 'datetime'], 'needs_value' => true],
                ['key' => 'is_true', 'label' => 'is waar', 'field_types' => ['checkbox'], 'needs_value' => false],
                ['key' => 'is_false', 'label' => 'is onwaar', 'field_types' => ['checkbox'], 'needs_value' => false],
                ['key' => 'is_present', 'label' => 'is ingevuld', 'field_types' => array_values(array_diff(self::FIELD_TYPES, ['section'])), 'needs_value' => false],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function defaultConfiguration(): array
    {
        $configuration = [
            'subject_types' => [
                ['key' => 'person', 'label' => 'Mens'],
                ['key' => 'animal', 'label' => 'Dier'],
                ['key' => 'object', 'label' => 'Object'],
            ],
            'fields' => [
                ['key' => 'common_section', 'label' => 'Laatst gezien', 'type' => 'section', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'last_seen_at', 'label' => 'Laatst gezien op', 'type' => 'datetime', 'scope' => 'common', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'last_seen_location', 'label' => 'Plaats laatst gezien', 'type' => 'text', 'scope' => 'common', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'last_seen_direction', 'label' => 'Bewegingsrichting', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'circumstances', 'label' => 'Omstandigheden', 'type' => 'textarea', 'scope' => 'common', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'requesting_organization', 'label' => 'Aanvragende organisatie', 'type' => 'text', 'scope' => 'common', 'required' => true, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'requesting_unit', 'label' => 'Dienst of eenheid', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'reporter_name', 'label' => 'Naam melder', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'reporter_phone', 'label' => 'Telefoon melder', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'on_scene_contact_name', 'label' => 'Contactpersoon ter plaatse', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'on_scene_contact_phone', 'label' => 'Telefoon ter plaatse', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'on_scene_contact_role', 'label' => 'Rol contactpersoon', 'type' => 'text', 'scope' => 'common', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'immediate_danger', 'label' => 'Direct levensgevaar of acuut risico', 'type' => 'checkbox', 'scope' => 'common', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'person_section', 'label' => 'Gegevens vermiste persoon', 'type' => 'section', 'scope' => 'person', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'person_name', 'label' => 'Naam', 'type' => 'text', 'scope' => 'person', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'person_age', 'label' => 'Leeftijd', 'type' => 'number', 'scope' => 'person', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'person_gender', 'label' => 'Geslacht', 'type' => 'select', 'scope' => 'person', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => [['value' => 'male', 'label' => 'Man'], ['value' => 'female', 'label' => 'Vrouw'], ['value' => 'other', 'label' => 'Anders'], ['value' => 'unknown', 'label' => 'Onbekend']]],
                ['key' => 'person_appearance', 'label' => 'Signalement en uiterlijk', 'type' => 'textarea', 'scope' => 'person', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'person_clothing', 'label' => 'Kleding', 'type' => 'textarea', 'scope' => 'person', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'person_transport', 'label' => 'Verplaatsingsmiddel', 'type' => 'text', 'scope' => 'person', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'person_vulnerable', 'label' => 'Extra kwetsbaar', 'type' => 'checkbox', 'scope' => 'person', 'required' => true, 'operator_visible' => true, 'help_text' => 'Bijvoorbeeld door leeftijd, gezondheid of omstandigheden.', 'options' => []],
                ['key' => 'medical_details', 'label' => 'Medische bijzonderheden', 'type' => 'textarea', 'scope' => 'person', 'required' => false, 'operator_visible' => false, 'help_text' => 'Niet standaard delen met operators.', 'options' => []],
                ['key' => 'animal_section', 'label' => 'Gegevens dier', 'type' => 'section', 'scope' => 'animal', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'animal_name', 'label' => 'Naam dier', 'type' => 'text', 'scope' => 'animal', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'animal_species', 'label' => 'Diersoort', 'type' => 'text', 'scope' => 'animal', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'animal_breed', 'label' => 'Ras', 'type' => 'text', 'scope' => 'animal', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'animal_description', 'label' => 'Kleur en kenmerken', 'type' => 'textarea', 'scope' => 'animal', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'animal_identification', 'label' => 'Halsband, penning of chip', 'type' => 'text', 'scope' => 'animal', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'animal_behavior', 'label' => 'Gedrag en benaderingsadvies', 'type' => 'textarea', 'scope' => 'animal', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'object_section', 'label' => 'Gegevens object', 'type' => 'section', 'scope' => 'object', 'required' => false, 'operator_visible' => false, 'help_text' => null, 'options' => []],
                ['key' => 'object_category', 'label' => 'Soort object', 'type' => 'text', 'scope' => 'object', 'required' => true, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'object_brand_model', 'label' => 'Merk en model', 'type' => 'text', 'scope' => 'object', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'object_color', 'label' => 'Kleur', 'type' => 'text', 'scope' => 'object', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'object_identifier', 'label' => 'Kenteken, serienummer of uniek kenmerk', 'type' => 'text', 'scope' => 'object', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
                ['key' => 'object_description', 'label' => 'Aanvullende omschrijving', 'type' => 'textarea', 'scope' => 'object', 'required' => false, 'operator_visible' => true, 'help_text' => null, 'options' => []],
            ],
            'bindings' => [
                ['field_key' => 'last_seen_location', 'target' => 'location_label'],
                ['field_key' => 'circumstances', 'target' => 'description'],
                ['field_key' => 'requesting_organization', 'target' => 'custom_fields.requesting_organization'],
                ['field_key' => 'requesting_unit', 'target' => 'custom_fields.requesting_unit'],
                ['field_key' => 'reporter_name', 'target' => 'reporter_name'],
                ['field_key' => 'reporter_phone', 'target' => 'reporter_phone'],
                ['field_key' => 'on_scene_contact_name', 'target' => 'custom_fields.on_scene_contact_name'],
                ['field_key' => 'on_scene_contact_phone', 'target' => 'custom_fields.on_scene_contact_phone'],
                ['field_key' => 'on_scene_contact_role', 'target' => 'custom_fields.on_scene_contact_role'],
                ['field_key' => 'person_name', 'target' => 'title'],
                ['field_key' => 'animal_species', 'target' => 'title'],
                ['field_key' => 'object_category', 'target' => 'title'],
            ],
            'priority_rules' => [
                ['id' => 'urgent_immediate_danger', 'label' => 'Acuut gevaar', 'subject_types' => self::SUBJECT_TYPES, 'match' => 'all', 'conditions' => [['field_key' => 'immediate_danger', 'operator' => 'is_true', 'value' => null]], 'priority' => 'urgent', 'explanation' => 'Er is direct levensgevaar of een ander acuut risico gemeld.', 'deployment_profile_id' => 'urgent_response'],
                ['id' => 'high_vulnerable_person', 'label' => 'Kwetsbare persoon', 'subject_types' => ['person'], 'match' => 'all', 'conditions' => [['field_key' => 'person_vulnerable', 'operator' => 'is_true', 'value' => null]], 'priority' => 'high', 'explanation' => 'De vermiste persoon is als extra kwetsbaar aangemerkt.', 'deployment_profile_id' => 'high_response'],
                ['id' => 'baseline_complete', 'label' => 'Volledige standaardmelding', 'subject_types' => self::SUBJECT_TYPES, 'match' => 'all', 'conditions' => [], 'priority' => 'low', 'explanation' => 'De verplichte gegevens zijn compleet en er zijn geen hogere risicoregels geactiveerd.', 'deployment_profile_id' => 'standard_response'],
            ],
            'deployment_profiles' => [
                ['id' => 'standard_response', 'label' => 'Standaardinzet', 'subject_types' => self::SUBJECT_TYPES, 'priorities' => ['low', 'medium'], 'summary' => 'Beoordeel beschikbare teams en middelen voor een reguliere zoekinzet.', 'team_ids' => [], 'resources' => ['Operationele droneploeg'], 'recommended_recipient_count' => null, 'recommended_dispatch_mode' => 'preannouncement', 'required_certification_type_ids' => []],
                ['id' => 'high_response', 'label' => 'Versnelde inzet', 'subject_types' => self::SUBJECT_TYPES, 'priorities' => ['high'], 'summary' => 'Versnelde coördinatie met aanvullende capaciteit.', 'team_ids' => [], 'resources' => ['Operationele droneploeg', 'Extra zoekcapaciteit'], 'recommended_recipient_count' => null, 'recommended_dispatch_mode' => 'preannouncement', 'required_certification_type_ids' => []],
                ['id' => 'urgent_response', 'label' => 'Urgente inzet', 'subject_types' => self::SUBJECT_TYPES, 'priorities' => ['urgent'], 'summary' => 'Directe coördinatie van beschikbare capaciteit; alarmering blijft een aparte handmatige actie.', 'team_ids' => [], 'resources' => ['Operationele droneploeg', 'Extra zoekcapaciteit', 'Coördinatie'], 'recommended_recipient_count' => null, 'recommended_dispatch_mode' => 'direct_dispatch', 'required_certification_type_ids' => []],
            ],
        ];

        return $this->appendRequiredIncidentFormFields($configuration);
    }

    /**
     * Keeps first-time workflow initialization compatible with required
     * incident fields that already exist on an upgraded installation.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function appendRequiredIncidentFormFields(array $configuration): array
    {
        $boundTargets = collect($configuration['bindings'])
            ->map(fn (array $binding): string => self::canonicalBindingTarget((string) $binding['target']))
            ->all();
        $fieldKeys = array_fill_keys(array_column($configuration['fields'], 'key'), true);

        foreach ($this->incidentFormService->fields() as $incidentField) {
            if (($incidentField['visible'] ?? true) !== true
                || ($incidentField['required'] ?? false) !== true
                || ($incidentField['type'] ?? null) === 'section'
                || ($incidentField['key'] ?? null) === 'required_resources') {
                continue;
            }
            $target = 'custom_fields.'.$incidentField['key'];
            if (in_array(self::canonicalBindingTarget($target), $boundTargets, true)) {
                continue;
            }
            $hash = substr(hash('sha256', (string) $incidentField['key']), 0, 8);
            $base = substr((string) $incidentField['key'], 0, 36);
            $fieldKey = "required_{$base}_{$hash}";
            if (isset($fieldKeys[$fieldKey])) {
                continue;
            }
            $incidentType = (string) ($incidentField['type'] ?? 'text');
            $workflowType = in_array($incidentType, self::FIELD_TYPES, true)
                ? $incidentType
                : 'text';
            $configuration['fields'][] = [
                'key' => $fieldKey,
                'label' => (string) ($incidentField['label'] ?? $incidentField['key']),
                'type' => $workflowType,
                'scope' => 'common',
                'required' => true,
                'operator_visible' => false,
                'help_text' => $incidentType === 'flight_time'
                    ? 'Gebruik begin- en eindtijd als UU:MM-UU:MM.'
                    : null,
                'options' => in_array($workflowType, ['select', 'radio'], true)
                    ? ($incidentField['options'] ?? [])
                    : [],
            ];
            $configuration['bindings'][] = [
                'field_key' => $fieldKey,
                'target' => $target,
            ];
            $fieldKeys[$fieldKey] = true;
            $boundTargets[] = self::canonicalBindingTarget($target);
        }

        return $configuration;
    }

    private function ensureDraft(IncidentIntakeWorkflowRevision $published): IncidentIntakeWorkflowRevision
    {
        return IncidentIntakeWorkflowRevision::query()->firstOrCreate(
            ['draft_marker' => 'active'],
            [
                'status' => 'draft',
                'lock_version' => 1,
                'configuration' => $published->configuration,
                'created_by' => $published->published_by,
                'updated_by' => $published->published_by,
            ],
        );
    }

    /** @return list<array{key: string, label: string}> */
    private function normalizeSubjectTypes(mixed $subjectTypes): array
    {
        if (! is_array($subjectTypes) || count($subjectTypes) !== 3) {
            throw ValidationException::withMessages(['configuration.subject_types' => ['Mens, dier en object moeten alle drie geconfigureerd blijven.']]);
        }

        $normalized = [];
        foreach ($subjectTypes as $index => $subjectType) {
            if (! is_array($subjectType) || array_diff(array_keys($subjectType), ['key', 'label']) !== []) {
                throw ValidationException::withMessages(["configuration.subject_types.$index" => ['Meldingstype is ongeldig.']]);
            }
            if (! is_string($subjectType['key'] ?? null) || ! is_string($subjectType['label'] ?? null)) {
                throw ValidationException::withMessages(["configuration.subject_types.$index" => ['Meldingstype is ongeldig.']]);
            }
            $key = $subjectType['key'];
            $label = trim($subjectType['label']);
            if (! in_array($key, self::SUBJECT_TYPES, true) || $label === '' || mb_strlen($label) > 80) {
                throw ValidationException::withMessages(["configuration.subject_types.$index" => ['Meldingstype is ongeldig.']]);
            }
            $normalized[$key] = ['key' => $key, 'label' => $label];
        }
        if (array_diff(self::SUBJECT_TYPES, array_keys($normalized)) !== []) {
            throw ValidationException::withMessages(['configuration.subject_types' => ['Mens, dier en object moeten alle drie geconfigureerd blijven.']]);
        }

        return array_values($normalized);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeFields(mixed $fields): array
    {
        if (! is_array($fields) || $fields === [] || count($fields) > 100) {
            throw ValidationException::withMessages(['configuration.fields' => ['Configureer 1 tot en met 100 uitvraagvelden.']]);
        }
        $normalized = [];
        $seen = [];
        foreach ($fields as $index => $field) {
            if (! is_array($field)) {
                throw ValidationException::withMessages(["configuration.fields.$index" => ['Veldconfiguratie is ongeldig.']]);
            }
            $allowed = ['key', 'label', 'type', 'scope', 'required', 'operator_visible', 'help_text', 'options'];
            if (array_diff(array_keys($field), $allowed) !== []) {
                throw ValidationException::withMessages(["configuration.fields.$index" => ['Veldconfiguratie bevat onbekende eigenschappen.']]);
            }
            if (! is_string($field['key'] ?? null)
                || ! is_string($field['label'] ?? null)
                || ! is_string($field['type'] ?? null)
                || ! is_string($field['scope'] ?? null)) {
                throw ValidationException::withMessages(["configuration.fields.$index" => ['Label, type of scope is ongeldig.']]);
            }
            $key = $field['key'];
            $label = trim($field['label']);
            $type = $field['type'];
            $scope = $field['scope'];
            if (preg_match(self::KEY_PATTERN, $key) !== 1 || isset($seen[$key])) {
                throw ValidationException::withMessages(["configuration.fields.$index.key" => ['Veldsleutel is ongeldig of dubbel.']]);
            }
            if ($label === '' || mb_strlen($label) > 160 || ! in_array($type, self::FIELD_TYPES, true) || ! in_array($scope, self::SCOPES, true)) {
                throw ValidationException::withMessages(["configuration.fields.$index" => ['Label, type of scope is ongeldig.']]);
            }
            $seen[$key] = true;
            $options = $this->normalizeOptions($field['options'] ?? [], $type, $index);
            foreach (['required', 'operator_visible'] as $booleanKey) {
                if (array_key_exists($booleanKey, $field) && ! is_bool($field[$booleanKey])) {
                    throw ValidationException::withMessages(["configuration.fields.$index.$booleanKey" => ['Gebruik uitsluitend true of false.']]);
                }
            }
            $normalized[] = [
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'scope' => $scope,
                'required' => $type === 'section' ? false : (bool) ($field['required'] ?? false),
                'operator_visible' => $type === 'section' ? false : (bool) ($field['operator_visible'] ?? false),
                'help_text' => $this->nullableText($field['help_text'] ?? null, 500, "configuration.fields.$index.help_text"),
                'options' => $options,
            ];
        }

        return $normalized;
    }

    /** @return list<array{value: string, label: string}> */
    private function normalizeOptions(mixed $options, string $type, int $fieldIndex): array
    {
        if (! in_array($type, ['select', 'radio'], true)) {
            return [];
        }
        if (! is_array($options) || $options === [] || count($options) > 40) {
            throw ValidationException::withMessages(["configuration.fields.$fieldIndex.options" => ['Keuzevelden hebben 1 tot en met 40 opties nodig.']]);
        }
        $normalized = [];
        foreach ($options as $index => $option) {
            if (! is_array($option) || array_diff(array_keys($option), ['value', 'label']) !== []) {
                throw ValidationException::withMessages(["configuration.fields.$fieldIndex.options.$index" => ['Keuzeoptie is ongeldig.']]);
            }
            if (! is_string($option['value'] ?? null) || ! is_string($option['label'] ?? null)) {
                throw ValidationException::withMessages(["configuration.fields.$fieldIndex.options.$index" => ['Keuzeoptie is ongeldig.']]);
            }
            $value = trim($option['value']);
            $label = trim($option['label']);
            if ($value === '' || $label === '' || mb_strlen($value) > 100 || mb_strlen($label) > 160 || isset($normalized[$value])) {
                throw ValidationException::withMessages(["configuration.fields.$fieldIndex.options.$index" => ['Keuzeoptie is ongeldig of dubbel.']]);
            }
            $normalized[$value] = ['value' => $value, 'label' => $label];
        }

        return array_values($normalized);
    }

    /** @param array<string, array<string, mixed>> $fieldMap */
    private function normalizeBindings(mixed $bindings, array $fieldMap): array
    {
        if (! is_array($bindings) || count($bindings) > 100) {
            throw ValidationException::withMessages(['configuration.bindings' => ['Veldkoppelingen zijn ongeldig.']]);
        }
        $targetMap = collect($this->catalogs()['incident_fields'])->keyBy('target');
        $normalized = [];
        foreach ($bindings as $index => $binding) {
            if (! is_array($binding) || array_diff(array_keys($binding), ['field_key', 'target']) !== []) {
                throw ValidationException::withMessages(["configuration.bindings.$index" => ['Veldkoppeling is ongeldig.']]);
            }
            if (! is_string($binding['field_key'] ?? null) || ! is_string($binding['target'] ?? null)) {
                throw ValidationException::withMessages(["configuration.bindings.$index" => ['Veld of incidentdoel is niet toegestaan.']]);
            }
            $fieldKey = $binding['field_key'];
            $target = $binding['target'];
            $targetDefinition = $targetMap->get($target);
            if (! isset($fieldMap[$fieldKey]) || ($fieldMap[$fieldKey]['type'] ?? null) === 'section' || ! is_array($targetDefinition)) {
                throw ValidationException::withMessages(["configuration.bindings.$index" => ['Veld of incidentdoel is niet toegestaan.']]);
            }
            if (! $this->bindingTypesCompatible($fieldMap[$fieldKey], $targetDefinition)) {
                throw ValidationException::withMessages(["configuration.bindings.$index" => ['Het uitvraagveld en incidentveld hebben geen compatibele typen.']]);
            }
            if (isset($normalized[$fieldKey])) {
                throw ValidationException::withMessages(["configuration.bindings.$index.field_key" => ['Een uitvraagveld kan maar één incidentveld vullen.']]);
            }
            $normalized[$fieldKey] = ['field_key' => $fieldKey, 'target' => $target];
        }

        $byTarget = collect($normalized)->groupBy(
            fn (array $binding): string => self::canonicalBindingTarget((string) $binding['target']),
        );
        foreach ($byTarget as $target => $targetBindings) {
            $bindingsForTarget = $targetBindings->values()->all();
            for ($left = 0; $left < count($bindingsForTarget); $left++) {
                for ($right = $left + 1; $right < count($bindingsForTarget); $right++) {
                    $leftScope = (string) $fieldMap[$bindingsForTarget[$left]['field_key']]['scope'];
                    $rightScope = (string) $fieldMap[$bindingsForTarget[$right]['field_key']]['scope'];
                    if ($leftScope === 'common' || $rightScope === 'common' || $leftScope === $rightScope) {
                        throw ValidationException::withMessages([
                            'configuration.bindings' => ["Incidentdoel '$target' wordt door gelijktijdig actieve velden dubbel gevuld."],
                        ]);
                    }
                }
            }
        }

        return array_values($normalized);
    }

    /** @return list<array<string, mixed>> */
    private function normalizeDeploymentProfiles(mixed $profiles): array
    {
        if (! is_array($profiles) || count($profiles) > 40) {
            throw ValidationException::withMessages(['configuration.deployment_profiles' => ['Inzetprofielen zijn ongeldig.']]);
        }
        $normalized = [];
        foreach ($profiles as $index => $profile) {
            if (! is_array($profile)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index" => ['Inzetprofiel is ongeldig.']]);
            }
            $allowed = [
                'id',
                'label',
                'subject_types',
                'priorities',
                'summary',
                'team_ids',
                'resources',
                'recommended_recipient_count',
                'recommended_dispatch_mode',
                'required_certification_type_ids',
                'team_snapshots',
                'certification_type_snapshots',
            ];
            if (array_diff(array_keys($profile), $allowed) !== []) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index" => ['Inzetprofiel bevat onbekende eigenschappen.']]);
            }
            if (! is_string($profile['id'] ?? null)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.id" => ['Inzetprofiel-ID is ongeldig of dubbel.']]);
            }
            $id = $profile['id'];
            if (preg_match(self::KEY_PATTERN, $id) !== 1 || isset($normalized[$id])) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.id" => ['Inzetprofiel-ID is ongeldig of dubbel.']]);
            }
            $subjectTypes = $this->enumList($profile['subject_types'] ?? [], self::SUBJECT_TYPES, "configuration.deployment_profiles.$index.subject_types");
            $priorities = $this->enumList($profile['priorities'] ?? [], self::PRIORITIES, "configuration.deployment_profiles.$index.priorities");
            $rawTeamIds = $profile['team_ids'] ?? [];
            if (! is_array($rawTeamIds) || ! array_is_list($rawTeamIds)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.team_ids" => ['Teams moeten als lijst worden aangeleverd.']]);
            }
            $teamIds = collect($rawTeamIds)->filter(fn (mixed $value): bool => is_string($value))->unique()->values()->all();
            if (count($teamIds) !== count($rawTeamIds)
                || count($teamIds) > 50
                || Team::query()->whereIn('id', $teamIds)->where('is_operational', true)->count() !== count($teamIds)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.team_ids" => ['Een of meer gekozen teams bestaan niet of zijn niet operationeel.']]);
            }
            $rawResources = $profile['resources'] ?? [];
            if (! is_array($rawResources) || ! array_is_list($rawResources)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.resources" => ['Inzetcomponenten moeten als lijst worden aangeleverd.']]);
            }
            $resources = collect($rawResources)->map(fn (mixed $value): mixed => is_string($value) ? trim($value) : null)->all();
            if (count($resources) > 50
                || in_array(null, $resources, true)
                || in_array('', $resources, true)
                || count(array_unique($resources)) !== count($resources)
                || collect($resources)->contains(fn (string $value): bool => mb_strlen($value) > 160)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.resources" => ['Inzetcomponenten zijn ongeldig.']]);
            }
            $recipientCount = $profile['recommended_recipient_count'] ?? null;
            if ($recipientCount !== null && (! is_int($recipientCount) || $recipientCount < 1 || $recipientCount > 200)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.recommended_recipient_count" => ['Het geadviseerde aantal ontvangers moet tussen 1 en 200 liggen.']]);
            }
            $dispatchMode = $profile['recommended_dispatch_mode'] ?? null;
            if ($dispatchMode !== null && ! in_array($dispatchMode, ['preannouncement', 'direct_dispatch'], true)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.recommended_dispatch_mode" => ['Kies vooraankondiging, direct alarmeren of geen advies.']]);
            }
            $rawCertificationIds = $profile['required_certification_type_ids'] ?? [];
            if (! is_array($rawCertificationIds) || ! array_is_list($rawCertificationIds)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.required_certification_type_ids" => ['Certificaatsoorten moeten als lijst worden aangeleverd.']]);
            }
            $certificationIds = collect($rawCertificationIds)->filter(fn (mixed $value): bool => is_string($value))->unique()->values()->all();
            if (count($certificationIds) !== count($rawCertificationIds)
                || count($certificationIds) > 50
                || Certification::query()->whereIn('id', $certificationIds)->count() !== count($certificationIds)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.required_certification_type_ids" => ['Een of meer certificaatsoorten bestaan niet.']]);
            }
            if (! is_string($profile['label'] ?? null)) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.label" => ['Naam van inzetprofiel is ongeldig.']]);
            }
            $label = trim($profile['label']);
            if ($label === '' || mb_strlen($label) > 160) {
                throw ValidationException::withMessages(["configuration.deployment_profiles.$index.label" => ['Naam van inzetprofiel is ongeldig.']]);
            }
            $normalized[$id] = [
                'id' => $id,
                'label' => $label,
                'subject_types' => $subjectTypes,
                'priorities' => $priorities,
                'summary' => $this->nullableText($profile['summary'] ?? null, 2000, "configuration.deployment_profiles.$index.summary"),
                'team_ids' => $teamIds,
                'resources' => $resources,
                'recommended_recipient_count' => $recipientCount,
                'recommended_dispatch_mode' => $dispatchMode,
                'required_certification_type_ids' => $certificationIds,
                'team_snapshots' => Team::query()
                    ->whereIn('id', $teamIds)
                    ->where('is_operational', true)
                    ->get(['id', 'code', 'name'])
                    ->map->only(['id', 'code', 'name'])
                    ->values()
                    ->all(),
                'certification_type_snapshots' => Certification::query()
                    ->whereIn('id', $certificationIds)
                    ->get(['id', 'code', 'name'])
                    ->map->only(['id', 'code', 'name'])
                    ->values()
                    ->all(),
            ];
        }

        return array_values($normalized);
    }

    /**
     * @param  array<string, array<string, mixed>>  $fieldMap
     * @param  array<string, array<string, mixed>>  $profileMap
     * @return list<array<string, mixed>>
     */
    private function normalizePriorityRules(mixed $rules, array $fieldMap, array $profileMap): array
    {
        if (! is_array($rules) || $rules === [] || count($rules) > 100) {
            throw ValidationException::withMessages(['configuration.priority_rules' => ['Configureer 1 tot en met 100 prioriteitsregels.']]);
        }
        $normalized = [];
        foreach ($rules as $index => $rule) {
            if (! is_array($rule)) {
                throw ValidationException::withMessages(["configuration.priority_rules.$index" => ['Prioriteitsregel is ongeldig.']]);
            }
            $allowed = ['id', 'label', 'subject_types', 'match', 'conditions', 'priority', 'explanation', 'deployment_profile_id'];
            if (array_diff(array_keys($rule), $allowed) !== []) {
                throw ValidationException::withMessages(["configuration.priority_rules.$index" => ['Prioriteitsregel bevat onbekende eigenschappen.']]);
            }
            if (! is_string($rule['id'] ?? null)
                || ! is_string($rule['label'] ?? null)
                || ! is_string($rule['match'] ?? null)
                || ! is_string($rule['priority'] ?? null)) {
                throw ValidationException::withMessages(["configuration.priority_rules.$index" => ['ID, naam, combinatiewijze of prioriteit is ongeldig.']]);
            }
            $id = $rule['id'];
            $label = trim($rule['label']);
            $match = $rule['match'];
            $priority = $rule['priority'];
            if (preg_match(self::KEY_PATTERN, $id) !== 1
                || isset($normalized[$id])
                || $label === ''
                || mb_strlen($label) > 160
                || ! in_array($match, ['all', 'any'], true)
                || ! in_array($priority, self::PRIORITIES, true)) {
                throw ValidationException::withMessages(["configuration.priority_rules.$index" => ['ID, naam, combinatiewijze of prioriteit is ongeldig.']]);
            }
            $subjectTypes = $this->enumList($rule['subject_types'] ?? [], self::SUBJECT_TYPES, "configuration.priority_rules.$index.subject_types");
            $conditions = $this->normalizeConditions($rule['conditions'] ?? [], $fieldMap, $subjectTypes, $index);
            $profileId = $rule['deployment_profile_id'] ?? null;
            if ($profileId !== null && (! is_string($profileId) || ! isset($profileMap[$profileId]))) {
                throw ValidationException::withMessages(["configuration.priority_rules.$index.deployment_profile_id" => ['Gekozen inzetprofiel bestaat niet.']]);
            }
            if (is_string($profileId)) {
                $profile = $profileMap[$profileId];
                if (! in_array($priority, $profile['priorities'], true)
                    || array_diff($subjectTypes, $profile['subject_types']) !== []) {
                    throw ValidationException::withMessages(["configuration.priority_rules.$index.deployment_profile_id" => ['Het inzetprofiel dekt niet alle meldingstypen en de prioriteit van deze regel.']]);
                }
            }
            $normalized[$id] = [
                'id' => $id,
                'label' => $label,
                'subject_types' => $subjectTypes,
                'match' => $match,
                'conditions' => $conditions,
                'priority' => $priority,
                'explanation' => $this->nullableText($rule['explanation'] ?? null, 1000, "configuration.priority_rules.$index.explanation") ?? $label,
                'deployment_profile_id' => $profileId,
            ];
        }

        return array_values($normalized);
    }

    /** @param array<string, array<string, mixed>> $fieldMap @param list<string> $subjectTypes */
    private function normalizeConditions(mixed $conditions, array $fieldMap, array $subjectTypes, int $ruleIndex): array
    {
        if (! is_array($conditions) || count($conditions) > 20) {
            throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions" => ['Voorwaarden zijn ongeldig.']]);
        }
        $normalized = [];
        foreach ($conditions as $index => $condition) {
            if (! is_array($condition) || array_diff(array_keys($condition), ['field_key', 'operator', 'value']) !== []) {
                throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions.$index" => ['Voorwaarde is ongeldig.']]);
            }
            if (! is_string($condition['field_key'] ?? null) || ! is_string($condition['operator'] ?? null)) {
                throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions.$index" => ['Veld of operator is ongeldig.']]);
            }
            $fieldKey = $condition['field_key'];
            $operator = $condition['operator'];
            $field = $fieldMap[$fieldKey] ?? null;
            if ($field === null || ($field['type'] ?? null) === 'section' || ! in_array($operator, self::OPERATORS, true)) {
                throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions.$index" => ['Veld of operator is ongeldig.']]);
            }
            foreach ($subjectTypes as $subjectType) {
                if (! $this->fieldApplies($field, $subjectType)) {
                    throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions.$index.field_key" => ['Het veld is niet beschikbaar voor alle meldingstypen van deze regel.']]);
                }
            }
            $compatible = $this->operatorSupportsFieldType($operator, (string) $field['type']);
            if (! $compatible) {
                throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions.$index.operator" => ['Deze operator past niet bij het veldtype.']]);
            }
            if (! in_array($operator, ['is_true', 'is_false', 'is_present'], true)
                && (! array_key_exists('value', $condition) || $condition['value'] === null || $condition['value'] === '')) {
                throw ValidationException::withMessages(["configuration.priority_rules.$ruleIndex.conditions.$index.value" => ['Vul een vergelijkingswaarde in.']]);
            }
            $value = in_array($operator, ['is_true', 'is_false', 'is_present'], true)
                ? null
                : $this->normalizeAnswerValue($field, $condition['value'] ?? null, "configuration.priority_rules.$ruleIndex.conditions.$index.value");
            $normalized[] = ['field_key' => $fieldKey, 'operator' => $operator, 'value' => $value];
        }

        return $normalized;
    }

    private function normalizeAnswerValue(array $field, mixed $value, string $path): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field['type']) {
            'number' => is_int($value) || (is_float($value) && floor($value) === $value)
                ? (int) $value
                : throw ValidationException::withMessages([$path => ['Vul een geldig geheel getal in.']]),
            'checkbox' => is_bool($value)
                ? $value
                : throw ValidationException::withMessages([$path => ['Gebruik uitsluitend true of false.']]),
            'select', 'radio' => is_string($value) && in_array($value, array_column($field['options'] ?? [], 'value'), true)
                ? $value
                : throw ValidationException::withMessages([$path => ['Kies een geldige optie.']]),
            'date' => is_string($value)
                ? $this->parseDate($value, $path)
                : throw ValidationException::withMessages([$path => ['Vul een geldige datum in.']]),
            'datetime' => is_string($value)
                ? $this->parseDateTime($value, $path)
                : throw ValidationException::withMessages([$path => ['Vul een geldig datum-tijdstip met tijdzone in.']]),
            default => $this->boundedText($value, 10000, $path),
        };
    }

    private function evaluateCondition(array $condition, array $answers): ?bool
    {
        $fieldKey = (string) $condition['field_key'];
        $operator = (string) $condition['operator'];
        $exists = array_key_exists($fieldKey, $answers) && ! $this->isEmpty($answers[$fieldKey]);
        if ($operator === 'is_present') {
            return $exists;
        }
        if (! $exists) {
            return null;
        }

        $actual = $answers[$fieldKey];
        $expected = $condition['value'] ?? null;

        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'greater_than_or_equal' => $actual >= $expected,
            'less_than_or_equal' => $actual <= $expected,
            'is_true' => $actual === true,
            'is_false' => $actual === false,
            default => null,
        };
    }

    /** @return array<string, mixed>|null */
    private function deploymentProposal(array $configuration, string $subjectType, string $priority, mixed $preferredId): ?array
    {
        $profiles = collect($configuration['deployment_profiles'] ?? [])
            ->filter(fn (mixed $profile): bool => is_array($profile)
                && in_array($subjectType, $profile['subject_types'] ?? [], true)
                && in_array($priority, $profile['priorities'] ?? [], true));
        $profile = is_string($preferredId) ? $profiles->firstWhere('id', $preferredId) : null;
        $profile ??= $profiles->first();

        if (! is_array($profile)) {
            return null;
        }

        return [
            'profile_id' => $profile['id'],
            'label' => $profile['label'],
            'summary' => $profile['summary'],
            'team_ids' => $profile['team_ids'],
            'teams' => $profile['team_snapshots'] ?? [],
            'resources' => $profile['resources'],
            'recommended_recipient_count' => $profile['recommended_recipient_count'],
            'recommended_dispatch_mode' => $profile['recommended_dispatch_mode'],
            'required_certification_type_ids' => $profile['required_certification_type_ids'],
            'required_certification_types' => $profile['certification_type_snapshots'] ?? [],
        ];
    }

    private function fieldApplies(array $field, string $subjectType): bool
    {
        return ($field['scope'] ?? null) === 'common' || ($field['scope'] ?? null) === $subjectType;
    }

    private function assertSubjectType(string $subjectType): void
    {
        if (! in_array($subjectType, self::SUBJECT_TYPES, true)) {
            throw ValidationException::withMessages(['subject_type' => ['Kies mens, dier of object.']]);
        }
    }

    /** @param list<string> $allowed @return list<string> */
    private function enumList(mixed $values, array $allowed, string $path): array
    {
        if (! is_array($values) || $values === []) {
            throw ValidationException::withMessages([$path => ['Kies minimaal één geldige waarde.']]);
        }
        $normalized = collect($values)->filter(fn (mixed $value): bool => is_string($value))->unique()->values()->all();
        if (count($normalized) !== count($values) || array_diff($normalized, $allowed) !== []) {
            throw ValidationException::withMessages([$path => ['Een of meer waarden zijn ongeldig.']]);
        }

        return $normalized;
    }

    private function priorityRank(string $priority): int
    {
        return array_search($priority, self::PRIORITIES, true) ?: 0;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private function parseDate(string $value, string $path): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$path => ['Vul een geldige datum in.']]);
        }

        return $date->format('Y-m-d');
    }

    private function parseDateTime(string $value, string $path): string
    {
        $parsed = null;
        foreach (['!Y-m-d\TH:iP', '!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s.vP', '!Y-m-d\TH:i:s.uP'] as $format) {
            $candidate = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($candidate !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                $parsed = $candidate;
                break;
            }
        }
        if ($parsed === null) {
            throw ValidationException::withMessages([$path => ['Vul een geldig datum-tijdstip met tijdzone in.']]);
        }

        return CarbonImmutable::instance($parsed)->utc()->toIso8601String();
    }

    private function operatorSupportsFieldType(string $operator, string $fieldType): bool
    {
        return match ($operator) {
            'is_true', 'is_false' => $fieldType === 'checkbox',
            'contains' => in_array($fieldType, ['text', 'textarea', 'select', 'radio'], true),
            'greater_than_or_equal', 'less_than_or_equal' => in_array($fieldType, ['number', 'date', 'datetime'], true),
            'equals', 'not_equals', 'is_present' => $fieldType !== 'section',
            default => false,
        };
    }

    private function bindingTypesCompatible(array $sourceField, array $targetDefinition): bool
    {
        $sourceType = (string) ($sourceField['type'] ?? '');
        $targetType = (string) ($targetDefinition['type'] ?? 'text');

        return match ($targetType) {
            'number' => $sourceType === 'number',
            'checkbox' => $sourceType === 'checkbox',
            'select', 'radio' => in_array($sourceType, ['select', 'radio'], true)
                && array_diff(
                    array_column($sourceField['options'] ?? [], 'value'),
                    array_column($targetDefinition['options'] ?? [], 'value'),
                ) === [],
            'phone' => in_array($sourceType, ['text', 'select', 'radio'], true),
            'flight_time' => in_array($sourceType, ['text', 'textarea'], true),
            default => in_array($sourceType, ['text', 'textarea', 'select', 'radio', 'date', 'datetime'], true),
        };
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function assertBoundAnswerFitsCurrentIncidentTarget(
        array $configuration,
        string $fieldKey,
        mixed $value,
        string $path,
        ?array $targetDefinitions = null,
    ): void {
        if ($value === null) {
            return;
        }
        $binding = collect($configuration['bindings'] ?? [])
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ($candidate['field_key'] ?? null) === $fieldKey);
        if (! is_array($binding)) {
            return;
        }
        $target = (string) ($binding['target'] ?? '');
        $targetDefinitions ??= collect($this->incidentFieldCatalog())->keyBy('target')->all();
        $definition = $targetDefinitions[$target] ?? null;
        if (! is_array($definition)) {
            throw ValidationException::withMessages([
                $path => ["Het gekoppelde incidentveld '$target' is niet meer beschikbaar."],
            ]);
        }
        $maximum = $definition['max_length'] ?? null;
        if (is_int($maximum) && is_string($value) && mb_strlen($value) > $maximum) {
            throw ValidationException::withMessages([
                $path => ["Gebruik maximaal $maximum tekens voor het gekoppelde incidentveld."],
            ]);
        }
        if (($definition['type'] ?? null) === 'number' && is_int($value)) {
            $minimumValue = is_int($definition['minimum'] ?? null) ? $definition['minimum'] : null;
            $maximumValue = is_int($definition['maximum'] ?? null) ? $definition['maximum'] : null;
            if (($minimumValue !== null && $value < $minimumValue)
                || ($maximumValue !== null && $value > $maximumValue)) {
                throw ValidationException::withMessages([
                    $path => ["Gebruik een waarde van $minimumValue tot en met $maximumValue voor het gekoppelde incidentveld."],
                ]);
            }
        }
        if (in_array($definition['type'] ?? null, ['select', 'radio'], true)
            && is_string($value)
            && ! in_array($value, array_column($definition['options'] ?? [], 'value'), true)) {
            throw ValidationException::withMessages([
                $path => ['Deze waarde is niet beschikbaar in het gekoppelde incidentveld.'],
            ]);
        }
        if (($definition['type'] ?? null) === 'flight_time'
            && (! is_string($value)
                || preg_match('/^([01]\d|2[0-4]):[0-5]\d\\s*-\\s*([01]\d|2[0-4]):[0-5]\d$/', $value) !== 1)) {
            throw ValidationException::withMessages([
                $path => ['Gebruik begin- en eindtijd als UU:MM-UU:MM.'],
            ]);
        }
    }

    /**
     * @param  list<array{field_key: string, target: string}>  $bindings
     * @param  array<string, array<string, mixed>>  $fieldMap
     */
    private function assertRequiredIncidentBindings(array $bindings, array $fieldMap): void
    {
        $requiredTargets = ['title', 'description', 'location_label'];
        foreach ($this->incidentFormService->fields() as $field) {
            if (($field['type'] ?? null) !== 'section'
                && ($field['visible'] ?? true) === true
                && ($field['required'] ?? false) === true
                && ($field['key'] ?? null) !== 'required_resources') {
                $requiredTargets[] = 'custom_fields.'.$field['key'];
            }
        }
        $requiredTargets = array_values(array_unique($requiredTargets));

        foreach (self::SUBJECT_TYPES as $subjectType) {
            foreach ($requiredTargets as $target) {
                $covered = collect($bindings)->contains(function (array $binding) use ($fieldMap, $subjectType, $target): bool {
                    $field = $fieldMap[$binding['field_key']] ?? null;
                    $canonicalTarget = self::canonicalBindingTarget($target);

                    return self::canonicalBindingTarget((string) $binding['target']) === $canonicalTarget
                        && is_array($field)
                        && (($field['scope'] ?? null) === 'common' || ($field['scope'] ?? null) === $subjectType)
                        && ($field['required'] ?? false) === true
                        && (! in_array($canonicalTarget, ['title', 'description', 'location_label'], true)
                            || ($field['operator_visible'] ?? false) === true);
                });
                if (! $covered) {
                    throw ValidationException::withMessages([
                        'configuration.bindings' => ["Meldingstype '$subjectType' mist een verplicht, vereist en waar nodig operator-zichtbaar veld voor incidentdoel '$target'."],
                    ]);
                }
            }
        }
    }

    private function boundedText(mixed $value, int $max, string $path): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages([$path => ['Gebruik een tekstwaarde.']]);
        }
        $text = trim($value);
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages([$path => ["Gebruik maximaal $max tekens."]]);
        }

        return $text;
    }

    private function nullableText(mixed $value, int $max, string $path): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$path => ['Gebruik een tekstwaarde.']]);
        }
        if (trim($value) === '') {
            return null;
        }

        return $this->boundedText($value, $max, $path);
    }

    private function bindingLabel(string $target): string
    {
        return match ($target) {
            'title' => 'Incidenttitel',
            'description' => 'Incidentomschrijving',
            'reporter_name' => 'Naam melder',
            'reporter_phone' => 'Telefoon melder',
            'requesting_organization' => 'Aanvragende organisatie',
            'requesting_unit' => 'Aanvragende eenheid',
            'on_scene_contact_name' => 'Contactpersoon ter plaatse',
            'on_scene_contact_phone' => 'Telefoon ter plaatse',
            'on_scene_contact_role' => 'Rol contactpersoon',
            'required_resources' => 'Benodigde middelen',
            'location_label' => 'Incidentlocatie',
            default => $target,
        };
    }

    /** @return array<string, mixed> */
    private function fixedBindingDefinition(string $target): array
    {
        [$type, $maximum] = match ($target) {
            'title' => ['text', 180],
            'description' => ['textarea', 10000],
            'reporter_name', 'requesting_organization', 'requesting_unit', 'on_scene_contact_name' => ['text', 180],
            'reporter_phone', 'on_scene_contact_phone' => ['phone', 40],
            'on_scene_contact_role' => ['text', 120],
            'location_label' => ['text', 255],
            default => ['text', null],
        };

        return [
            'target' => $target,
            'label' => $this->bindingLabel($target),
            'type' => $type,
            'max_length' => $maximum,
            'options' => [],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function incidentFieldCatalog(): array
    {
        return collect(self::FIXED_BINDING_TARGETS)
            ->map(fn (string $target): array => $this->fixedBindingDefinition($target))
            ->merge(collect($this->incidentFormService->fields())
                ->filter(fn (array $field): bool => ($field['type'] ?? null) !== 'section'
                    && ($field['key'] ?? null) !== 'required_resources')
                ->map(fn (array $field): array => [
                    'target' => 'custom_fields.'.$field['key'],
                    'label' => 'Incidentveld: '.$field['label'],
                    'type' => $field['type'],
                    'max_length' => $field['max_length'] ?? null,
                    'minimum' => ($field['type'] ?? null) === 'number' ? 0 : null,
                    'maximum' => ($field['type'] ?? null) === 'number' ? (int) ($field['max'] ?? 999999) : null,
                    'options' => $field['options'] ?? [],
                ]))
            ->values()
            ->all();
    }

    public static function canonicalBindingTarget(string $target): string
    {
        if (! str_starts_with($target, 'custom_fields.')) {
            return $target;
        }

        $key = substr($target, strlen('custom_fields.'));

        return in_array($key, self::LEGACY_MIRRORED_FIELD_KEYS, true) ? $key : $target;
    }
}
