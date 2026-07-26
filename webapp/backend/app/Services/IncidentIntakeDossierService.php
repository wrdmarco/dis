<?php

namespace App\Services;

use App\Events\IncidentIntakeChanged;
use App\Exceptions\IncidentIntakeConflictException;
use App\Models\Certification;
use App\Models\Incident;
use App\Models\IncidentIntakeDossier;
use App\Models\IncidentIntakeMutation;
use App\Models\Team;
use App\Models\User;
use App\Repositories\IncidentIntakeDossierRepository;
use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class IncidentIntakeDossierService
{
    /** @var array<string, string> */
    private const INCIDENT_PRIORITIES = [
        'low' => 'low',
        'medium' => 'normal',
        'high' => 'high',
        'urgent' => 'critical',
    ];

    public function __construct(
        private readonly IncidentIntakeDossierRepository $repository,
        private readonly IncidentIntakeWorkflowService $workflowService,
        private readonly IncidentService $incidentService,
        private readonly IncidentFormService $incidentFormService,
        private readonly AuditService $auditService,
    ) {}

    /** @param list<string> $statuses */
    public function search(array $statuses, int $perPage): LengthAwarePaginator
    {
        return $this->repository->search($statuses, $perPage);
    }

    public function lockForIncidentUpdate(Incident $incident): void
    {
        $this->repository->lockForIncident((string) $incident->getKey());
    }

    /** @param array<string, mixed> $data */
    public function assertLinkedDecisionFieldsUnchanged(Incident $incident, array $data): void
    {
        $dossier = $this->repository->forIncident((string) $incident->getKey());
        if ($dossier === null) {
            return;
        }
        $configuration = $dossier->workflowRevision->configuration ?? [];
        $fieldMap = collect($configuration['fields'] ?? [])->keyBy('key');
        foreach ($configuration['bindings'] ?? [] as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            $field = $fieldMap->get($binding['field_key'] ?? null);
            if (! is_array($field) || ! $this->fieldApplies($field, (string) $dossier->subject_type)) {
                continue;
            }
            $target = (string) ($binding['target'] ?? '');
            [$submitted, $submittedValue] = $this->readIncidentTarget($data, $target);
            if ($submitted && ! $this->incidentTargetValuesEquivalent(
                $target,
                $submittedValue,
                $this->storedIncidentTarget($incident, $target),
            )) {
                throw ValidationException::withMessages([
                    'intake_dossier' => ['Wijzig gekoppelde uitvraagvelden via het meldingsdossier.'],
                ]);
            }
        }
        if (array_key_exists('priority', $data) && $data['priority'] !== $incident->priority) {
            throw ValidationException::withMessages([
                'priority' => ['Wijzig de vastgestelde prioriteit via het gekoppelde meldingsdossier.'],
            ]);
        }
        if (array_key_exists('required_resources', $data)
            && $this->nullableText($data['required_resources'] ?? null, 5000) !== $this->nullableText($incident->required_resources, 5000)) {
            throw ValidationException::withMessages([
                'required_resources' => ['Wijzig het inzetvoorstel via het gekoppelde meldingsdossier.'],
            ]);
        }
        if (array_key_exists('team_ids', $data) || array_key_exists('team_id', $data)) {
            $requested = array_key_exists('team_ids', $data)
                ? (is_array($data['team_ids']) ? $data['team_ids'] : [])
                : ($data['team_id'] === null ? [] : [$data['team_id']]);
            $current = $incident->teams()->pluck('teams.id')->all();
            sort($requested);
            sort($current);
            if ($requested !== $current) {
                throw ValidationException::withMessages([
                    'team_ids' => ['Wijzig de gekozen inzetteams via het gekoppelde meldingsdossier.'],
                ]);
            }
        }
    }

    private function incidentTargetValuesEquivalent(string $target, mixed $left, mixed $right): bool
    {
        if (! str_starts_with($target, 'custom_fields.') || ! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        $key = substr($target, strlen('custom_fields.'));
        $field = collect($this->incidentFormService->fields())->firstWhere('key', $key);
        if (! is_array($field) || ($field['type'] ?? null) !== 'flight_time') {
            return $left === $right;
        }

        return ($left['start'] ?? null) === ($right['start'] ?? null)
            && ($left['end'] ?? null) === ($right['end'] ?? null);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function create(array $data, User $actor): array
    {
        $operation = 'create';
        $mutationId = $this->mutationId($data);
        $hash = $this->requestHash($operation, $data);
        if (($replay = $this->replayByMutationId($mutationId, $operation, $hash, $actor)) !== null) {
            return $replay;
        }

        $published = $this->workflowService->published();
        $subjectType = (string) ($data['subject_type'] ?? '');
        $answers = $this->workflowService->normalizeAnswers(
            $published->configuration ?? [],
            $subjectType,
            is_array($data['answers'] ?? null) ? $data['answers'] : [],
        );
        $evaluation = $this->workflowService->evaluate($published->configuration ?? [], $subjectType, $answers);

        try {
            return DB::transaction(function () use ($published, $subjectType, $answers, $evaluation, $actor, $mutationId, $operation, $hash): array {
                $dossier = IncidentIntakeDossier::query()->create([
                    'workflow_revision_id' => $published->id,
                    'status' => 'open',
                    'subject_type' => $subjectType,
                    'answers' => $answers,
                    'triage' => $this->storedTriage($evaluation),
                    'recommended_priority' => $evaluation['triage']['recommended_priority'],
                    'lock_version' => 1,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                $dossier->setRelation('workflowRevision', $published);
                $payload = $this->present($dossier, $actor);
                $this->storeMutation($dossier, $mutationId, $operation, $hash, $payload, $actor);
                $this->auditService->record('intake_dossiers.created', $dossier, $actor, [
                    'subject_type' => $subjectType,
                    'workflow_version' => $published->version,
                ]);
                $this->broadcastAfterCommit($dossier, $actor);

                return $payload;
            });
        } catch (QueryException $exception) {
            $replay = $this->replayByMutationId($mutationId, $operation, $hash, $actor);
            if ($replay !== null) {
                return $replay;
            }
            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function patch(IncidentIntakeDossier $dossier, array $data, User $actor): array
    {
        return $this->mutate($dossier, 'patch', $data, $actor, function (IncidentIntakeDossier $locked, array $input) use ($actor): void {
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => ['Een afgesloten meldingsdossier kan niet worden gewijzigd.']]);
            }

            $configuration = $locked->workflowRevision->configuration ?? [];
            $changes = is_array($input['changes'] ?? null) ? $input['changes'] : [];
            $oldSubjectType = (string) $locked->subject_type;
            $subjectType = array_key_exists('subject_type', $changes)
                ? (string) $changes['subject_type']
                : $oldSubjectType;
            $oldAnswers = is_array($locked->answers) ? $locked->answers : [];
            $answers = $oldAnswers;
            if (array_key_exists('answers', $changes)) {
                $answerPatch = $this->workflowService->normalizeAnswers(
                    $configuration,
                    $subjectType,
                    is_array($changes['answers']) ? $changes['answers'] : [],
                    patch: true,
                );
                foreach ($answerPatch as $key => $value) {
                    if ($value === null) {
                        unset($answers[$key]);
                    } else {
                        $answers[$key] = $value;
                    }
                }
            }

            $evaluation = $this->workflowService->evaluate($configuration, $subjectType, $answers);
            $contentChanged = $subjectType !== $oldSubjectType || $answers !== $oldAnswers;
            $locked->forceFill([
                'subject_type' => $subjectType,
                'answers' => $answers,
                'triage' => $this->storedTriage($evaluation),
                'recommended_priority' => $evaluation['triage']['recommended_priority'],
                'decided_priority' => $contentChanged ? null : $locked->decided_priority,
                'priority_decision_reason' => $contentChanged ? null : $locked->priority_decision_reason,
                'selected_deployment_profile_id' => $contentChanged ? null : $locked->selected_deployment_profile_id,
                'selected_deployment_proposal' => $contentChanged ? null : $locked->selected_deployment_proposal,
                'updated_by' => $actor->id,
            ]);

            if ($locked->incident_id !== null) {
                $nextBoundValues = $this->boundIncidentValues($configuration, $subjectType, $answers);
                foreach (['title', 'description', 'location_label'] as $requiredTarget) {
                    if (trim((string) ($nextBoundValues[$requiredTarget] ?? '')) === '') {
                        throw ValidationException::withMessages([
                            'answers' => ["Een gekoppeld incident moet altijd een ingevuld veld voor $requiredTarget behouden."],
                        ]);
                    }
                }
                $incident = $locked->incident()->firstOrFail();
                $incidentPatch = [];
                $fieldMap = collect($configuration['fields'] ?? [])->keyBy('key');
                foreach ($configuration['bindings'] ?? [] as $binding) {
                    if (! is_array($binding)) {
                        continue;
                    }
                    $field = $fieldMap->get($binding['field_key'] ?? null);
                    if (! is_array($field)) {
                        continue;
                    }
                    $key = (string) $binding['field_key'];
                    $oldApplies = $this->fieldApplies($field, $oldSubjectType);
                    $newApplies = $this->fieldApplies($field, $subjectType);
                    $oldExists = $oldApplies && array_key_exists($key, $oldAnswers);
                    $newExists = $newApplies && array_key_exists($key, $answers);
                    $oldValue = $oldExists ? $oldAnswers[$key] : null;
                    $newValue = $newExists ? $answers[$key] : null;
                    if ($oldExists !== $newExists || $oldValue !== $newValue) {
                        $this->writeIncidentTarget($incidentPatch, (string) $binding['target'], $newValue);
                    }
                }
                if ($contentChanged) {
                    $incidentPatch['intake_decision_valid'] = false;
                    if ($incident->status === 'draft') {
                        $incidentPatch['required_resources'] = null;
                        $incidentPatch['team_ids'] = [];
                    }
                }
                if ($incidentPatch !== []) {
                    $this->incidentService->update(
                        $incident,
                        $this->mirrorLegacyIncidentFields($incidentPatch, $incident),
                        $actor,
                    );
                }
                if ($contentChanged) {
                    $this->incidentService->invalidateDraftDispatchesAfterIntakeChange($incident, $actor);
                }
            }
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function decidePriority(IncidentIntakeDossier $dossier, array $data, User $actor): array
    {
        return $this->mutate($dossier, 'priority', $data, $actor, function (IncidentIntakeDossier $locked, array $input) use ($actor): void {
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => ['Een afgesloten meldingsdossier kan niet worden beoordeeld.']]);
            }

            $priority = (string) ($input['priority'] ?? '');
            if (! in_array($priority, IncidentIntakeWorkflowService::PRIORITIES, true)) {
                throw ValidationException::withMessages(['priority' => ['Kies laag, middel, hoog of urgent.']]);
            }

            $configuration = $locked->workflowRevision->configuration ?? [];
            $recommendedProposal = $locked->triage['deployment_proposal'] ?? null;
            $profileIdProvided = array_key_exists('selected_deployment_profile_id', $input);
            $profileId = $profileIdProvided
                ? $input['selected_deployment_profile_id']
                : ($priority === $locked->recommended_priority ? ($recommendedProposal['profile_id'] ?? null) : null);
            $selected = $this->selectedProposal(
                $configuration,
                (string) $locked->subject_type,
                $priority,
                is_string($profileId) ? $profileId : null,
                is_array($input['deployment_adjustments'] ?? null) ? $input['deployment_adjustments'] : [],
            );
            $override = $priority !== $locked->recommended_priority
                || ($selected['profile_id'] ?? null) !== ($recommendedProposal['profile_id'] ?? null)
                || ($input['deployment_adjustments'] ?? []) !== [];
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($override && ! $actor->hasPermission('intakes.priority.override')) {
                throw ValidationException::withMessages(['priority' => ['Je hebt geen recht om van het advies af te wijken.']]);
            }
            if ($override && $reason === '') {
                throw ValidationException::withMessages(['reason' => ['Leg vast waarom je van het advies of inzetvoorstel afwijkt.']]);
            }

            $locked->forceFill([
                'decided_priority' => $priority,
                'priority_decision_reason' => $reason === '' ? null : $reason,
                'selected_deployment_profile_id' => $selected['profile_id'] ?? null,
                'selected_deployment_proposal' => $selected,
                'updated_by' => $actor->id,
            ]);

            if ($locked->incident_id !== null) {
                $incidentPatch = [
                    'priority' => self::INCIDENT_PRIORITIES[$priority],
                    'required_resources' => ($selected['resources'] ?? []) === []
                        ? null
                        : implode(', ', $selected['resources']),
                    'team_ids' => $selected['team_ids'] ?? [],
                    'intake_decision_valid' => true,
                ];
                $incident = $locked->incident()->firstOrFail();
                $this->incidentService->update(
                    $incident,
                    $this->mirrorLegacyIncidentFields($incidentPatch, $incident),
                    $actor,
                );
            }
        });
    }

    /** @param array<string, mixed> $data @return array{dossier: array<string, mixed>, incident: Incident} */
    public function promote(IncidentIntakeDossier $dossier, array $data, User $actor): array
    {
        $operation = 'promote';
        $mutationId = $this->mutationId($data);
        $hash = $this->requestHash($operation, $data);
        if (($replay = $this->replay($dossier, $mutationId, $operation, $hash, $actor)) !== null) {
            $incident = Incident::query()->findOrFail((string) ($replay['incident_id'] ?? $replay['dossier']['incident_id'] ?? ''));

            return ['dossier' => $replay['dossier'] ?? $replay, 'incident' => $incident];
        }

        try {
            return DB::transaction(function () use ($dossier, $data, $actor, $mutationId, $operation, $hash): array {
                $locked = $this->repository->lock((string) $dossier->getKey());
                if (($replay = $this->replay($locked, $mutationId, $operation, $hash, $actor)) !== null) {
                    $incident = Incident::query()->findOrFail((string) ($replay['incident_id'] ?? $replay['dossier']['incident_id'] ?? ''));

                    return ['dossier' => $replay['dossier'] ?? $replay, 'incident' => $incident];
                }
                $this->assertLockVersion($locked, (int) ($data['lock_version'] ?? 0), $actor);
                if ($locked->incident_id !== null) {
                    $incident = $locked->incident()->firstOrFail();
                    $payload = $this->present($locked, $actor);
                    $stored = ['dossier' => $payload, 'incident_id' => $incident->id];
                    $this->storeMutation($locked, $mutationId, $operation, $hash, $stored, $actor);

                    return ['dossier' => $payload, 'incident' => $incident];
                }
                if ($locked->status !== 'open') {
                    throw ValidationException::withMessages(['status' => ['Alleen een open meldingsdossier kan een incident worden.']]);
                }
                if ($locked->decided_priority === null) {
                    throw ValidationException::withMessages(['decided_priority' => ['Stel eerst de prioriteit vast.']]);
                }
                if (($locked->triage['state'] ?? 'unknown') === 'incomplete') {
                    throw ValidationException::withMessages(['triage' => ['Vul alle verplichte beslisinformatie in voordat je een incident maakt.']]);
                }

                $configuration = $locked->workflowRevision->configuration ?? [];
                $this->workflowService->assertCurrentBindingTargets(
                    $configuration,
                    (string) $locked->subject_type,
                    is_array($locked->answers) ? $locked->answers : [],
                );
                $incidentData = $this->boundIncidentValues($configuration, (string) $locked->subject_type, $locked->answers ?? []);
                foreach (['title', 'description', 'location_label'] as $requiredTarget) {
                    if (trim((string) ($incidentData[$requiredTarget] ?? '')) === '') {
                        throw ValidationException::withMessages([
                            'bindings' => ["Vul het gekoppelde veld voor $requiredTarget in voordat je een incident maakt."],
                        ]);
                    }
                }
                $incidentData['priority'] = self::INCIDENT_PRIORITIES[(string) $locked->decided_priority];
                $incidentData['status'] = 'draft';
                $incidentData['intake_decision_valid'] = true;
                $selected = is_array($locked->selected_deployment_proposal) ? $locked->selected_deployment_proposal : [];
                $this->assertCurrentDeploymentTargets($selected);
                if (($selected['resources'] ?? []) !== [] && ! array_key_exists('required_resources', $incidentData)) {
                    $incidentData['required_resources'] = implode(', ', $selected['resources']);
                }
                if (($selected['team_ids'] ?? []) !== []) {
                    $incidentData['team_ids'] = $selected['team_ids'];
                }
                $incidentData = $this->mirrorLegacyIncidentFields($incidentData);

                $rules = array_merge(
                    $this->incidentFormService->fixedInputValidationRules(partial: true, enforceConfigurableRequired: false),
                    $this->incidentFormService->validationRules(partial: true),
                    ['team_ids' => ['sometimes', 'array'], 'team_ids.*' => ['string', 'exists:teams,id']],
                );
                Validator::make($incidentData, $rules)->validate();
                unset($incidentData['status']);
                $incident = $this->incidentService->create($incidentData, $actor);
                $locked->forceFill([
                    'incident_id' => $incident->id,
                    'status' => 'promoted',
                    'updated_by' => $actor->id,
                    'lock_version' => $locked->lock_version + 1,
                ])->save();
                $locked->setRelation('incident', $incident);
                $payload = $this->present($locked->refresh()->load(['workflowRevision', 'incident']), $actor);
                $stored = ['dossier' => $payload, 'incident_id' => $incident->id];
                $this->storeMutation($locked, $mutationId, $operation, $hash, $stored, $actor);
                $this->auditService->record('intake_dossiers.promoted', $locked, $actor, [
                    'incident_id' => $incident->id,
                    'decided_priority' => $locked->decided_priority,
                    'workflow_version' => $locked->workflowRevision->version,
                ]);
                $this->broadcastAfterCommit($locked, $actor);

                return ['dossier' => $payload, 'incident' => $incident];
            });
        } catch (QueryException $exception) {
            $replay = $this->replay($dossier, $mutationId, $operation, $hash, $actor);
            if ($replay !== null) {
                $incident = Incident::query()->findOrFail((string) ($replay['incident_id'] ?? $replay['dossier']['incident_id'] ?? ''));

                return ['dossier' => $replay['dossier'] ?? $replay, 'incident' => $incident];
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function close(IncidentIntakeDossier $dossier, array $data, User $actor): array
    {
        return $this->mutate($dossier, 'close', $data, $actor, function (IncidentIntakeDossier $locked, array $input) use ($actor): void {
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => ['Een afgesloten meldingsdossier kan niet opnieuw worden afgesloten.']]);
            }
            if ($locked->incident_id !== null || $locked->status === 'promoted') {
                throw ValidationException::withMessages(['status' => ['Een dossier met incident wordt via de incidentstatus afgehandeld.']]);
            }
            $locked->forceFill([
                'status' => 'closed',
                'closed_by' => $actor->id,
                'close_reason' => $this->nullableText($input['reason'] ?? null, 2000),
                'closed_at' => now(),
                'updated_by' => $actor->id,
            ]);
        });
    }

    /** @return array<string, mixed> */
    public function present(IncidentIntakeDossier $dossier, ?User $actor = null, bool $operatorOnly = false): array
    {
        $dossier->loadMissing(['workflowRevision', 'incident']);
        $configuration = $dossier->workflowRevision->configuration ?? [];
        $subjectType = (string) $dossier->subject_type;
        $currentPublished = $operatorOnly ? $this->workflowService->published() : null;
        $currentFieldMap = collect($currentPublished?->configuration['fields'] ?? [])->keyBy('key');
        $answers = is_array($dossier->answers) ? $dossier->answers : [];
        $answerRows = [];
        $section = null;

        foreach ($configuration['fields'] ?? [] as $field) {
            if (! is_array($field) || ! $this->fieldApplies($field, $subjectType)) {
                continue;
            }
            if (($field['type'] ?? null) === 'section') {
                $section = (string) $field['label'];

                continue;
            }
            $key = (string) $field['key'];
            if (! array_key_exists($key, $answers) || $this->isEmpty($answers[$key])) {
                continue;
            }
            if ($operatorOnly) {
                $current = $currentFieldMap->get($key);
                if (($field['operator_visible'] ?? false) !== true
                    || ! is_array($current)
                    || ($current['operator_visible'] ?? false) !== true
                    || ! $this->fieldApplies($current, $subjectType)) {
                    continue;
                }
            }
            $answerRows[] = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $answers[$key],
                'display_value' => $this->displayValue($field, $answers[$key]),
                'section' => $section,
                'operator_visible' => (bool) ($field['operator_visible'] ?? false),
            ];
        }

        return [
            'id' => $dossier->id,
            'status' => $dossier->status,
            'subject_type' => $subjectType,
            'subject_type_label' => $this->subjectTypeLabel($configuration, $subjectType),
            'workflow_revision' => [
                'id' => $dossier->workflowRevision->id,
                'version' => $dossier->workflowRevision->version,
            ],
            'answers' => $operatorOnly
                ? (object) collect($answerRows)->mapWithKeys(fn (array $row): array => [$row['key'] => $row['value']])->all()
                : (object) $answers,
            'answer_rows' => $answerRows,
            'triage' => [
                'state' => $dossier->triage['state'] ?? 'unknown',
                'recommended_priority' => $dossier->recommended_priority,
                'reasons' => $dossier->triage['reasons'] ?? [],
                'missing_fields' => $dossier->triage['missing_fields'] ?? [],
            ],
            'decided_priority' => $dossier->decided_priority,
            'priority_override_reason' => $dossier->priority_decision_reason,
            'deployment_proposal' => $dossier->triage['deployment_proposal'] ?? null,
            'selected_deployment_proposal' => $dossier->selected_deployment_proposal,
            'lock_version' => $dossier->lock_version,
            'incident_id' => $dossier->incident_id,
            'created_at' => ApiDateTime::dateTime($dossier->created_at),
            'updated_at' => ApiDateTime::dateTime($dossier->updated_at),
        ];
    }

    /** @return array<string, mixed>|null */
    public function projectionForIncident(Incident $incident, ?User $actor = null): ?array
    {
        $dossier = $incident->relationLoaded('intakeDossier')
            ? $incident->intakeDossier
            : $this->repository->forIncident((string) $incident->getKey());
        if ($dossier === null) {
            return null;
        }

        $payload = $this->present($dossier, $actor, operatorOnly: $actor?->isOperatorClient() === true);

        return [
            'subject_type' => $payload['subject_type'],
            'subject_type_label' => $payload['subject_type_label'],
            'answers' => $payload['answer_rows'],
        ];
    }

    /** @return list<string> */
    public function hiddenIncidentTargetsForOperator(Incident $incident): array
    {
        return $this->workflowService->hiddenIncidentTargetsForOperator($incident);
    }

    /**
     * @param  callable(IncidentIntakeDossier, array<string, mixed>): void  $change
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mutate(IncidentIntakeDossier $dossier, string $operation, array $data, User $actor, callable $change): array
    {
        $mutationId = $this->mutationId($data);
        $hash = $this->requestHash($operation, $data);
        if (($replay = $this->replay($dossier, $mutationId, $operation, $hash, $actor)) !== null) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($dossier, $operation, $data, $actor, $change, $mutationId, $hash): array {
                $locked = $this->repository->lock((string) $dossier->getKey());
                if (($replay = $this->replay($locked, $mutationId, $operation, $hash, $actor)) !== null) {
                    return $replay;
                }
                $this->assertLockVersion($locked, (int) ($data['lock_version'] ?? 0), $actor);
                $beforeSubjectType = (string) $locked->subject_type;
                $beforeAnswers = is_array($locked->answers) ? $locked->answers : [];
                $beforePriority = $locked->decided_priority;
                $beforeProfile = $locked->selected_deployment_profile_id;
                $change($locked, $data);
                $locked->forceFill(['lock_version' => $locked->lock_version + 1])->save();
                $locked->refresh()->load(['workflowRevision', 'incident']);
                $payload = $this->present($locked, $actor);
                $this->storeMutation($locked, $mutationId, $operation, $hash, $payload, $actor);
                $auditReason = $operation === 'priority'
                    ? $this->nullableText($data['reason'] ?? null, 2000)
                    : null;
                $this->auditService->record(
                    'intake_dossiers.'.$operation,
                    $locked,
                    $actor,
                    [
                        'incident_id' => $locked->incident_id,
                        'lock_version' => $locked->lock_version,
                        'status' => $locked->status,
                        'changed_answer_keys' => $this->changedAnswerKeys($beforeAnswers, is_array($locked->answers) ? $locked->answers : []),
                        'subject_type_from' => $beforeSubjectType,
                        'subject_type_to' => $locked->subject_type,
                        'priority_from' => $beforePriority,
                        'priority_to' => $locked->decided_priority,
                        'deployment_profile_from' => $beforeProfile,
                        'deployment_profile_to' => $locked->selected_deployment_profile_id,
                        'reason_recorded' => $auditReason !== null,
                    ],
                    reason: $auditReason,
                );
                $this->broadcastAfterCommit($locked, $actor);

                return $payload;
            });
        } catch (QueryException $exception) {
            $replay = $this->replay($dossier, $mutationId, $operation, $hash, $actor);
            if ($replay !== null) {
                return $replay;
            }
            throw $exception;
        }
    }

    private function assertLockVersion(IncidentIntakeDossier $dossier, int $expected, ?User $actor): void
    {
        if ($expected !== $dossier->lock_version) {
            throw new IncidentIntakeConflictException(
                'intake_version_conflict',
                'Het meldingsdossier is intussen gewijzigd.',
                $this->present($dossier, $actor),
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function replay(IncidentIntakeDossier $dossier, string $mutationId, string $operation, string $hash, User $actor): ?array
    {
        $mutation = IncidentIntakeMutation::query()
            ->where('actor_id', $actor->id)
            ->where('client_mutation_id', $mutationId)
            ->first();
        if ($mutation === null) {
            return null;
        }
        $currentDossier = $mutation->dossier()->with(['workflowRevision', 'incident'])->firstOrFail();
        $current = $this->present($currentDossier, $actor);
        if ((string) $mutation->dossier_id !== (string) $dossier->id) {
            throw new IncidentIntakeConflictException(
                'intake_mutation_conflict',
                'Deze mutatie-ID is al voor een ander meldingsdossier gebruikt.',
                $current,
            );
        }
        if ($mutation->operation !== $operation || ! hash_equals($mutation->request_hash, $hash)) {
            throw new IncidentIntakeConflictException(
                'intake_mutation_conflict',
                'Deze mutatie-ID is al voor een andere wijziging gebruikt.',
                $current,
            );
        }

        return $operation === 'promote'
            ? ['dossier' => $current, 'incident_id' => $currentDossier->incident_id]
            : $current;
    }

    /** @return array<string, mixed>|null */
    private function replayByMutationId(string $mutationId, string $operation, string $hash, User $actor): ?array
    {
        $mutation = IncidentIntakeMutation::query()
            ->where('actor_id', $actor->id)
            ->where('client_mutation_id', $mutationId)
            ->first();
        if ($mutation === null) {
            return null;
        }
        $dossier = $mutation->dossier()->with(['workflowRevision', 'incident'])->firstOrFail();
        $current = $this->present($dossier, $actor);
        if ($mutation->operation !== $operation || ! hash_equals($mutation->request_hash, $hash)) {
            throw new IncidentIntakeConflictException(
                'intake_mutation_conflict',
                'Deze mutatie-ID is al voor een andere wijziging gebruikt.',
                $current,
            );
        }

        return $operation === 'promote'
            ? ['dossier' => $current, 'incident_id' => $dossier->incident_id]
            : $current;
    }

    /** @param array<string, mixed> $payload */
    private function storeMutation(IncidentIntakeDossier $dossier, string $mutationId, string $operation, string $hash, array $payload, User $actor): void
    {
        IncidentIntakeMutation::query()->create([
            'dossier_id' => $dossier->id,
            'actor_id' => $actor->id,
            'client_mutation_id' => $mutationId,
            'operation' => $operation,
            'request_hash' => $hash,
            'response_payload' => [
                'dossier_id' => $dossier->id,
                'lock_version' => $dossier->lock_version,
                'incident_id' => $dossier->incident_id
                    ?? ($payload['incident_id'] ?? $payload['dossier']['incident_id'] ?? null),
            ],
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $data */
    private function mutationId(array $data): string
    {
        $mutationId = trim((string) ($data['client_mutation_id'] ?? ''));
        if ($mutationId === '' || mb_strlen($mutationId) > 120 || preg_match('/^[A-Za-z0-9._:-]+$/', $mutationId) !== 1) {
            throw ValidationException::withMessages(['client_mutation_id' => ['Geef een geldige unieke mutatie-ID mee.']]);
        }

        return $mutationId;
    }

    /** @param array<string, mixed> $data */
    private function requestHash(string $operation, array $data): string
    {
        $copy = $data;
        unset($copy['client_mutation_id']);
        $copy = $this->sortRecursively($copy);

        return hash('sha256', $operation."\n".json_encode($copy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursively($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    /** @param array<string, mixed> $evaluation @return array<string, mixed> */
    private function storedTriage(array $evaluation): array
    {
        return $evaluation['triage'] + ['deployment_proposal' => $evaluation['deployment_proposal']];
    }

    /** @return array<string, mixed> */
    private function selectedProposal(array $configuration, string $subjectType, string $priority, ?string $profileId, array $adjustments): array
    {
        $availableProfiles = collect($configuration['deployment_profiles'] ?? [])
            ->filter(fn (mixed $candidate): bool => is_array($candidate)
                && in_array($subjectType, $candidate['subject_types'] ?? [], true)
                && in_array($priority, $candidate['priorities'] ?? [], true));
        $profile = $profileId === null
            ? $availableProfiles->first()
            : $availableProfiles->firstWhere('id', $profileId);
        if ($profileId !== null && ! is_array($profile)) {
            throw ValidationException::withMessages(['selected_deployment_profile_id' => ['Dit inzetprofiel past niet bij type en prioriteit.']]);
        }

        $hasTeamAdjustment = array_key_exists('team_ids', $adjustments);
        $teamIds = $hasTeamAdjustment ? $adjustments['team_ids'] : ($profile['team_ids'] ?? []);
        if (! is_array($teamIds) || ! array_is_list($teamIds)) {
            throw ValidationException::withMessages(['deployment_adjustments.team_ids' => ['Teams moeten als lijst worden aangeleverd.']]);
        }
        $normalizedTeamIds = collect($teamIds)->filter(fn (mixed $id): bool => is_string($id))->unique()->values()->all();
        if (count($normalizedTeamIds) !== count($teamIds)
            || Team::query()->whereIn('id', $normalizedTeamIds)->where('is_operational', true)->count() !== count($normalizedTeamIds)) {
            throw ValidationException::withMessages(['deployment_adjustments.team_ids' => ['Een of meer gekozen teams bestaan niet of zijn niet operationeel.']]);
        }
        $teamIds = $normalizedTeamIds;
        $hasResourceAdjustment = array_key_exists('resources', $adjustments);
        $resources = $hasResourceAdjustment ? $adjustments['resources'] : ($profile['resources'] ?? []);
        if (! is_array($resources) || ! array_is_list($resources)) {
            throw ValidationException::withMessages(['deployment_adjustments.resources' => ['Inzetcomponenten moeten als lijst worden aangeleverd.']]);
        }
        $resources = collect($resources)->map(fn (mixed $item): mixed => is_string($item) ? trim($item) : null)->all();
        if (count($resources) > 50
            || in_array(null, $resources, true)
            || in_array('', $resources, true)
            || count(array_unique($resources)) !== count($resources)
            || collect($resources)->contains(fn (string $item): bool => mb_strlen($item) > 160)) {
            throw ValidationException::withMessages(['deployment_adjustments.resources' => ['Inzetcomponenten zijn ongeldig.']]);
        }
        $recipientCount = array_key_exists('recommended_recipient_count', $adjustments)
            ? $adjustments['recommended_recipient_count']
            : ($profile['recommended_recipient_count'] ?? null);
        if ($recipientCount !== null && (! is_int($recipientCount) || $recipientCount < 1 || $recipientCount > 200)) {
            throw ValidationException::withMessages(['deployment_adjustments.recommended_recipient_count' => ['Het geadviseerde aantal ontvangers moet tussen 1 en 200 liggen.']]);
        }
        $dispatchMode = array_key_exists('recommended_dispatch_mode', $adjustments)
            ? $adjustments['recommended_dispatch_mode']
            : ($profile['recommended_dispatch_mode'] ?? null);
        if ($dispatchMode !== null && ! in_array($dispatchMode, ['preannouncement', 'direct_dispatch'], true)) {
            throw ValidationException::withMessages(['deployment_adjustments.recommended_dispatch_mode' => ['Kies een geldig alarmeringsadvies.']]);
        }
        $hasCertificationAdjustment = array_key_exists('required_certification_type_ids', $adjustments);
        $certificationIds = $hasCertificationAdjustment
            ? $adjustments['required_certification_type_ids']
            : ($profile['required_certification_type_ids'] ?? []);
        if (! is_array($certificationIds) || ! array_is_list($certificationIds)) {
            throw ValidationException::withMessages(['deployment_adjustments.required_certification_type_ids' => ['Certificaatsoorten moeten als lijst worden aangeleverd.']]);
        }
        $normalizedCertificationIds = collect($certificationIds)->filter(fn (mixed $id): bool => is_string($id))->unique()->values()->all();
        if (count($normalizedCertificationIds) !== count($certificationIds)
            || count($normalizedCertificationIds) > 50
            || Certification::query()->whereIn('id', $normalizedCertificationIds)->count() !== count($normalizedCertificationIds)) {
            throw ValidationException::withMessages(['deployment_adjustments.required_certification_type_ids' => ['Een of meer certificaatsoorten bestaan niet.']]);
        }
        $certificationIds = $normalizedCertificationIds;

        return [
            'profile_id' => $profile['id'] ?? null,
            'label' => $profile['label'] ?? 'Aangepast inzetvoorstel',
            'summary' => $profile['summary'] ?? null,
            'team_ids' => $teamIds,
            'teams' => $hasTeamAdjustment
                ? Team::query()->whereIn('id', $teamIds)->where('is_operational', true)->get(['id', 'code', 'name'])->map->only(['id', 'code', 'name'])->values()->all()
                : ($profile['team_snapshots'] ?? []),
            'resources' => $resources,
            'notes' => $this->nullableText($adjustments['notes'] ?? null, 2000),
            'recommended_recipient_count' => $recipientCount,
            'recommended_dispatch_mode' => $dispatchMode,
            'required_certification_type_ids' => $certificationIds,
            'required_certification_types' => $hasCertificationAdjustment
                ? Certification::query()->whereIn('id', $certificationIds)->get(['id', 'code', 'name'])->map->only(['id', 'code', 'name'])->values()->all()
                : ($profile['certification_type_snapshots'] ?? []),
        ];
    }

    /** @param array<string, mixed> $proposal */
    private function assertCurrentDeploymentTargets(array $proposal): void
    {
        $teamIds = is_array($proposal['team_ids'] ?? null) ? $proposal['team_ids'] : [];
        if (Team::query()->whereIn('id', $teamIds)->where('is_operational', true)->count() !== count($teamIds)) {
            throw ValidationException::withMessages([
                'selected_deployment_profile_id' => ['Het gekozen inzetprofiel bevat een verwijderd of niet-operationeel team. Kies de inzet opnieuw.'],
            ]);
        }
        $certificationIds = is_array($proposal['required_certification_type_ids'] ?? null)
            ? $proposal['required_certification_type_ids']
            : [];
        if (Certification::query()->whereIn('id', $certificationIds)->count() !== count($certificationIds)) {
            throw ValidationException::withMessages([
                'selected_deployment_profile_id' => ['Het gekozen inzetprofiel bevat een verwijderde certificaatsoort. Kies de inzet opnieuw.'],
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function boundIncidentValues(array $configuration, string $subjectType, array $answers): array
    {
        $fieldMap = collect($configuration['fields'] ?? [])->keyBy('key');
        $data = [];
        foreach ($configuration['bindings'] ?? [] as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            $key = (string) ($binding['field_key'] ?? '');
            $target = (string) ($binding['target'] ?? '');
            $field = $fieldMap->get($key);
            if (! is_array($field) || ! $this->fieldApplies($field, $subjectType) || ! array_key_exists($key, $answers)) {
                continue;
            }
            $this->writeIncidentTarget($data, $target, $answers[$key]);
        }

        return $data;
    }

    /**
     * Keeps the configurable incident fields and their legacy columns equal.
     * Existing custom values are preserved for partial incident updates.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mirrorLegacyIncidentFields(array $data, ?Incident $incident = null): array
    {
        $incomingCustom = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
        $custom = array_replace(
            is_array($incident?->custom_fields) ? $incident->custom_fields : [],
            $incomingCustom,
        );
        $touched = array_key_exists('custom_fields', $data);

        foreach (IncidentIntakeWorkflowService::LEGACY_MIRRORED_FIELD_KEYS as $key) {
            $hasFixed = array_key_exists($key, $data);
            $hasCustom = array_key_exists($key, $incomingCustom);
            if (! $hasFixed && ! $hasCustom) {
                continue;
            }
            if ($hasFixed && $hasCustom && $data[$key] !== $incomingCustom[$key]) {
                throw ValidationException::withMessages([
                    $key => ['Vast incidentveld en configureerbaar incidentveld bevatten verschillende waarden.'],
                ]);
            }

            $value = $hasFixed ? $data[$key] : $incomingCustom[$key];
            $data[$key] = $value;
            $custom[$key] = $value;
            $touched = true;
        }

        if ($touched) {
            $data['custom_fields'] = $custom;
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function writeIncidentTarget(array &$data, string $target, mixed $value): void
    {
        $canonicalTarget = IncidentIntakeWorkflowService::canonicalBindingTarget($target);
        if (in_array($canonicalTarget, IncidentIntakeWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
            $data[$canonicalTarget] = $value;
            $data['custom_fields'] ??= [];
            $data['custom_fields'][$canonicalTarget] = $value;

            return;
        }
        if (str_starts_with($target, 'custom_fields.')) {
            $key = substr($target, strlen('custom_fields.'));
            $data['custom_fields'] ??= [];
            $data['custom_fields'][$key] = $this->incidentCustomTargetValue($key, $value);

            return;
        }
        $allowed = array_column($this->workflowService->catalogs()['incident_fields'], 'target');
        if (in_array($target, $allowed, true) && ! str_starts_with($target, 'custom_fields.')) {
            $data[$target] = $value;
        }
    }

    private function incidentCustomTargetValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $field = collect($this->incidentFormService->fields())->firstWhere('key', $key);
        if (! is_array($field) || ($field['type'] ?? null) !== 'flight_time' || ! is_string($value)) {
            return $value;
        }
        preg_match(
            '/^((?:[01]\d|2[0-4]):[0-5]\d)\s*-\s*((?:[01]\d|2[0-4]):[0-5]\d)$/',
            $value,
            $matches,
        );
        $start = $matches[1] ?? null;
        $end = $matches[2] ?? null;
        if (! is_string($start) || ! is_string($end)) {
            return $value;
        }
        [$startHour, $startMinute] = array_map('intval', explode(':', $start));
        [$endHour, $endMinute] = array_map('intval', explode(':', $end));
        $startTotal = $startHour * 60 + $startMinute;
        $endTotal = $endHour * 60 + $endMinute;
        if ($endTotal < $startTotal) {
            $endTotal += 24 * 60;
        }

        return [
            'start' => $start,
            'end' => $end,
            'duration_minutes' => $endTotal - $startTotal,
        ];
    }

    /** @return array{0: bool, 1: mixed} */
    private function readIncidentTarget(array $data, string $target): array
    {
        $canonicalTarget = IncidentIntakeWorkflowService::canonicalBindingTarget($target);
        if (in_array($canonicalTarget, IncidentIntakeWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
            if (array_key_exists($canonicalTarget, $data)) {
                return [true, $data[$canonicalTarget]];
            }
            if (is_array($data['custom_fields'] ?? null) && array_key_exists($canonicalTarget, $data['custom_fields'])) {
                return [true, $data['custom_fields'][$canonicalTarget]];
            }

            return [false, null];
        }
        if (str_starts_with($target, 'custom_fields.')) {
            $key = substr($target, strlen('custom_fields.'));
            if (! array_key_exists('custom_fields', $data) || ! is_array($data['custom_fields']) || ! array_key_exists($key, $data['custom_fields'])) {
                return [false, null];
            }

            return [true, $data['custom_fields'][$key]];
        }

        return array_key_exists($target, $data) ? [true, $data[$target]] : [false, null];
    }

    private function storedIncidentTarget(Incident $incident, string $target): mixed
    {
        $canonicalTarget = IncidentIntakeWorkflowService::canonicalBindingTarget($target);
        if (in_array($canonicalTarget, IncidentIntakeWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
            return $incident->getAttribute($canonicalTarget);
        }
        if (str_starts_with($target, 'custom_fields.')) {
            $key = substr($target, strlen('custom_fields.'));

            return is_array($incident->custom_fields) ? ($incident->custom_fields[$key] ?? null) : null;
        }

        return $incident->getAttribute($target);
    }

    private function displayValue(array $field, mixed $value): string
    {
        if (($field['type'] ?? null) === 'checkbox') {
            return $value === true ? 'Ja' : 'Nee';
        }
        if (in_array($field['type'] ?? null, ['select', 'radio'], true)) {
            $option = collect($field['options'] ?? [])->firstWhere('value', $value);

            return is_array($option) ? (string) $option['label'] : (string) $value;
        }
        if (($field['type'] ?? null) === 'datetime') {
            try {
                return CarbonImmutable::parse((string) $value)->setTimezone('Europe/Amsterdam')->format('d-m-Y H:i');
            } catch (Throwable) {
                return (string) $value;
            }
        }
        if (($field['type'] ?? null) === 'date') {
            try {
                return CarbonImmutable::parse((string) $value, 'UTC')->format('d-m-Y');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        return is_bool($value) ? ($value ? 'Ja' : 'Nee') : (string) $value;
    }

    private function subjectTypeLabel(array $configuration, string $subjectType): string
    {
        $subject = collect($configuration['subject_types'] ?? [])->firstWhere('key', $subjectType);

        return is_array($subject) ? (string) $subject['label'] : $subjectType;
    }

    private function fieldApplies(array $field, string $subjectType): bool
    {
        return ($field['scope'] ?? null) === 'common' || ($field['scope'] ?? null) === $subjectType;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || (is_array($value) && $value === []);
    }

    private function nullableText(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withMessages(['reason' => ["Gebruik maximaal $max tekens."]]);
        }

        return $text;
    }

    private function broadcastAfterCommit(IncidentIntakeDossier $dossier, User $actor): void
    {
        $id = (string) $dossier->getKey();
        DB::afterCommit(function () use ($id, $actor): void {
            $fresh = IncidentIntakeDossier::query()->with('incident')->find($id);
            if ($fresh !== null) {
                try {
                    event(new IncidentIntakeChanged($fresh, $actor));
                } catch (Throwable $exception) {
                    report($exception);
                }
            }
        });
    }

    /** @param array<string, mixed> $before @param array<string, mixed> $after @return list<string> */
    private function changedAnswerKeys(array $before, array $after): array
    {
        return collect(array_unique(array_merge(array_keys($before), array_keys($after))))
            ->filter(fn (string $key): bool => ($before[$key] ?? null) !== ($after[$key] ?? null))
            ->values()
            ->all();
    }
}
