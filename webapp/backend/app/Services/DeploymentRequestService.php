<?php

namespace App\Services;

use App\Events\DeploymentRequestChanged;
use App\Exceptions\DeploymentRequestConflictException;
use App\Models\Certification;
use App\Models\Deployment;
use App\Models\DeploymentRequest;
use App\Models\DeploymentRequestMutation;
use App\Models\Team;
use App\Models\User;
use App\Repositories\DeploymentRequestRepository;
use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeploymentRequestService
{
    /** @var array<string, string> */
    private const DEPLOYMENT_PRIORITIES = [
        'low' => 'low',
        'medium' => 'normal',
        'high' => 'high',
        'urgent' => 'critical',
    ];

    public function __construct(
        private readonly DeploymentRequestRepository $repository,
        private readonly DeploymentRequestWorkflowService $workflowService,
        private readonly DeploymentService $deploymentService,
        private readonly DeploymentFormService $deploymentFormService,
        private readonly AuditService $auditService,
    ) {}

    /** @param list<string> $statuses */
    public function search(array $statuses, int $perPage): LengthAwarePaginator
    {
        return $this->repository->search($statuses, $perPage);
    }

    public function lockForDeploymentUpdate(Deployment $deployment): void
    {
        $this->repository->lockForDeployment((string) $deployment->getKey());
    }

    /** @param array<string, mixed> $data */
    public function assertLinkedDecisionFieldsUnchanged(Deployment $deployment, array $data): void
    {
        $deploymentRequest = $this->repository->forDeployment((string) $deployment->getKey());
        if ($deploymentRequest === null) {
            return;
        }
        $configuration = $deploymentRequest->workflowRevision->configuration ?? [];
        $fieldMap = collect($configuration['fields'] ?? [])->keyBy('key');
        foreach ($configuration['bindings'] ?? [] as $binding) {
            if (! is_array($binding)) {
                continue;
            }
            $field = $fieldMap->get($binding['field_key'] ?? null);
            if (! is_array($field) || ! $this->fieldApplies($field, (string) $deploymentRequest->subject_type)) {
                continue;
            }
            $target = (string) ($binding['target'] ?? '');
            [$submitted, $submittedValue] = $this->readDeploymentTarget($data, $target);
            if ($submitted && ! $this->deploymentTargetValuesEquivalent(
                $target,
                $submittedValue,
                $this->storedDeploymentTarget($deployment, $target),
            )) {
                throw ValidationException::withMessages([
                    'deployment_request' => ['Wijzig gekoppelde uitvraagvelden via het aanvraagdossier.'],
                ]);
            }
        }
        if (array_key_exists('priority', $data) && $data['priority'] !== $deployment->priority) {
            throw ValidationException::withMessages([
                'priority' => ['Wijzig de vastgestelde prioriteit via het gekoppelde aanvraagdossier.'],
            ]);
        }
        if (array_key_exists('required_resources', $data)
            && $this->nullableText($data['required_resources'] ?? null, 5000) !== $this->nullableText($deployment->required_resources, 5000)) {
            throw ValidationException::withMessages([
                'required_resources' => ['Wijzig het inzetvoorstel via het gekoppelde aanvraagdossier.'],
            ]);
        }
        if (array_key_exists('team_ids', $data) || array_key_exists('team_id', $data)) {
            $requested = array_key_exists('team_ids', $data)
                ? (is_array($data['team_ids']) ? $data['team_ids'] : [])
                : ($data['team_id'] === null ? [] : [$data['team_id']]);
            $current = $deployment->teams()->pluck('teams.id')->all();
            sort($requested);
            sort($current);
            if ($requested !== $current) {
                throw ValidationException::withMessages([
                    'team_ids' => ['Wijzig de gekozen inzetteams via het gekoppelde aanvraagdossier.'],
                ]);
            }
        }
    }

    private function deploymentTargetValuesEquivalent(string $target, mixed $left, mixed $right): bool
    {
        if (! str_starts_with($target, 'custom_fields.') || ! is_array($left) || ! is_array($right)) {
            return $left === $right;
        }
        $key = substr($target, strlen('custom_fields.'));
        $field = collect($this->deploymentFormService->fields())->firstWhere('key', $key);
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
                $deploymentRequest = DeploymentRequest::query()->create([
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
                $deploymentRequest->setRelation('workflowRevision', $published);
                $payload = $this->present($deploymentRequest, $actor);
                $this->storeMutation($deploymentRequest, $mutationId, $operation, $hash, $payload, $actor);
                $this->auditService->record('deployment_requests.created', $deploymentRequest, $actor, [
                    'subject_type' => $subjectType,
                    'workflow_version' => $published->version,
                ]);
                $this->broadcastAfterCommit($deploymentRequest, $actor);

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
    public function patch(DeploymentRequest $deploymentRequest, array $data, User $actor): array
    {
        return $this->mutate($deploymentRequest, 'patch', $data, $actor, function (DeploymentRequest $locked, array $input) use ($actor): void {
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => ['Een afgesloten aanvraagdossier kan niet worden gewijzigd.']]);
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
            $preserveLinkedDeploymentPlan = $contentChanged && $locked->deployment_id !== null;
            $locked->forceFill([
                'subject_type' => $subjectType,
                'answers' => $answers,
                'triage' => $this->storedTriage($evaluation),
                'recommended_priority' => $evaluation['triage']['recommended_priority'],
                'decided_priority' => $contentChanged ? null : $locked->decided_priority,
                'priority_decision_reason' => $contentChanged ? null : $locked->priority_decision_reason,
                'selected_deployment_profile_id' => $contentChanged && ! $preserveLinkedDeploymentPlan
                    ? null
                    : $locked->selected_deployment_profile_id,
                'selected_deployment_proposal' => $contentChanged && ! $preserveLinkedDeploymentPlan
                    ? null
                    : $locked->selected_deployment_proposal,
                'updated_by' => $actor->id,
            ]);

            if ($locked->deployment_id !== null) {
                $nextBoundValues = $this->boundDeploymentValues($configuration, $subjectType, $answers);
                foreach (['title', 'description', 'location_label'] as $requiredTarget) {
                    if (trim((string) ($nextBoundValues[$requiredTarget] ?? '')) === '') {
                        throw ValidationException::withMessages([
                            'answers' => ["Een gekoppelde inzet moet altijd een ingevuld veld voor $requiredTarget behouden."],
                        ]);
                    }
                }
                $deployment = $locked->deployment()->firstOrFail();
                $deploymentPatch = [];
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
                        $this->writeDeploymentTarget($deploymentPatch, (string) $binding['target'], $newValue);
                    }
                }
                if ($contentChanged) {
                    $deploymentPatch['deployment_request_decision_valid'] = false;
                }
                if ($deploymentPatch !== []) {
                    $this->deploymentService->update(
                        $deployment,
                        $this->mirrorLegacyDeploymentFields($deploymentPatch, $deployment),
                        $actor,
                    );
                }
                if ($contentChanged) {
                    $this->deploymentService->invalidateDraftDispatchesAfterDeploymentRequestChange($deployment, $actor);
                }
            }
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function decidePriority(DeploymentRequest $deploymentRequest, array $data, User $actor): array
    {
        return $this->mutate($deploymentRequest, 'priority', $data, $actor, function (DeploymentRequest $locked, array $input) use ($actor): void {
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => ['Een afgesloten aanvraagdossier kan niet worden beoordeeld.']]);
            }

            $priority = (string) ($input['priority'] ?? '');
            if (! in_array($priority, DeploymentRequestWorkflowService::PRIORITIES, true)) {
                throw ValidationException::withMessages(['priority' => ['Kies laag, middel, hoog of urgent.']]);
            }

            $configuration = $locked->workflowRevision->configuration ?? [];
            $recommendedProposal = $locked->triage['deployment_proposal'] ?? null;
            $profileIdProvided = array_key_exists('selected_deployment_profile_id', $input);
            $profileId = $profileIdProvided
                ? $input['selected_deployment_profile_id']
                : ($priority === $locked->recommended_priority ? ($recommendedProposal['profile_id'] ?? null) : null);
            $deploymentAdjustments = is_array($input['deployment_adjustments'] ?? null)
                ? $input['deployment_adjustments']
                : [];
            $linkedDeployment = null;
            if ($locked->deployment_id !== null && is_array($locked->selected_deployment_proposal)) {
                foreach ([
                    'team_ids',
                    'resources',
                    'notes',
                    'recommended_recipient_count',
                    'recommended_dispatch_mode',
                ] as $planKey) {
                    if (! array_key_exists($planKey, $deploymentAdjustments)
                        && array_key_exists($planKey, $locked->selected_deployment_proposal)) {
                        $deploymentAdjustments[$planKey] = $locked->selected_deployment_proposal[$planKey];
                    }
                }
            }
            if ($locked->deployment_id !== null) {
                $linkedDeployment = Deployment::query()
                    ->lockForUpdate()
                    ->findOrFail($locked->deployment_id);
                $protectedTeamIds = $linkedDeployment->dispatchRequests()
                    ->where('status', '!=', 'cancelled')
                    ->whereNotNull('target_team_id')
                    ->pluck('target_team_id')
                    ->filter(fn (mixed $teamId): bool => is_string($teamId) && $teamId !== '')
                    ->unique()
                    ->values()
                    ->all();
                if ($protectedTeamIds !== []) {
                    $requestedTeamIds = $deploymentAdjustments['team_ids'] ?? [];
                    if (is_array($requestedTeamIds) && array_is_list($requestedTeamIds)) {
                        $deploymentAdjustments['team_ids'] = collect($requestedTeamIds)
                            ->merge($protectedTeamIds)
                            ->unique()
                            ->values()
                            ->all();
                    }
                }
            }
            $selected = $this->selectedProposal(
                $configuration,
                (string) $locked->subject_type,
                $priority,
                is_string($profileId) ? $profileId : null,
                $deploymentAdjustments,
            );
            $override = $priority !== $locked->recommended_priority
                || $this->deploymentProposalDiffersFromRecommendation(
                    $selected,
                    is_array($recommendedProposal) ? $recommendedProposal : null,
                );
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($override && ! $actor->hasPermission('deployment-requests.priority.override')) {
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

            if ($locked->deployment_id !== null) {
                $deploymentPatch = [
                    'priority' => self::DEPLOYMENT_PRIORITIES[$priority],
                    'required_resources' => ($selected['resources'] ?? []) === []
                        ? null
                        : implode(', ', $selected['resources']),
                    'team_ids' => $selected['team_ids'] ?? [],
                    'deployment_request_decision_valid' => true,
                ];
                $deployment = $linkedDeployment ?? $locked->deployment()->firstOrFail();
                $this->deploymentService->update(
                    $deployment,
                    $this->mirrorLegacyDeploymentFields($deploymentPatch, $deployment),
                    $actor,
                );
            }
        });
    }

    /** @param array<string, mixed> $data @return array{deployment_request: array<string, mixed>, deployment: Deployment} */
    public function prepareDeployment(DeploymentRequest $deploymentRequest, array $data, User $actor): array
    {
        $operation = 'prepare_deployment';
        $mutationId = $this->mutationId($data);
        $hash = $this->requestHash($operation, $data);
        $legacyHash = $this->requestHash('promote', $data);
        if (($replay = $this->replay($deploymentRequest, $mutationId, $operation, $hash, $actor, $legacyHash)) !== null) {
            $deployment = Deployment::query()->findOrFail((string) ($replay['deployment_id'] ?? $replay['deployment_request']['deployment_id'] ?? ''));

            return ['deployment_request' => $replay['deployment_request'] ?? $replay, 'deployment' => $deployment];
        }

        try {
            return DB::transaction(function () use ($deploymentRequest, $data, $actor, $mutationId, $operation, $hash, $legacyHash): array {
                $locked = $this->repository->lock((string) $deploymentRequest->getKey());
                if (($replay = $this->replay($locked, $mutationId, $operation, $hash, $actor, $legacyHash)) !== null) {
                    $deployment = Deployment::query()->findOrFail((string) ($replay['deployment_id'] ?? $replay['deployment_request']['deployment_id'] ?? ''));

                    return ['deployment_request' => $replay['deployment_request'] ?? $replay, 'deployment' => $deployment];
                }
                $this->assertLockVersion($locked, (int) ($data['lock_version'] ?? 0), $actor);
                if ($locked->deployment_id !== null) {
                    $deployment = $locked->deployment()->firstOrFail();
                    $payload = $this->present($locked, $actor);
                    $stored = ['deployment_request' => $payload, 'deployment_id' => $deployment->id];
                    $this->storeMutation($locked, $mutationId, $operation, $hash, $stored, $actor);

                    return ['deployment_request' => $payload, 'deployment' => $deployment];
                }
                if ($locked->status !== 'open') {
                    throw ValidationException::withMessages(['status' => ['Alleen vanuit een open aanvraagdossier kan een inzet worden voorbereid.']]);
                }
                if ($locked->decided_priority === null) {
                    throw ValidationException::withMessages(['decided_priority' => ['Stel eerst de prioriteit vast.']]);
                }
                if (($locked->triage['state'] ?? 'unknown') === 'incomplete') {
                    throw ValidationException::withMessages(['triage' => ['Vul alle verplichte beslisinformatie in voordat je een inzet voorbereidt.']]);
                }

                $configuration = $locked->workflowRevision->configuration ?? [];
                $this->workflowService->assertCurrentBindingTargets(
                    $configuration,
                    (string) $locked->subject_type,
                    is_array($locked->answers) ? $locked->answers : [],
                );
                $deploymentData = $this->boundDeploymentValues($configuration, (string) $locked->subject_type, $locked->answers ?? []);
                foreach (['title', 'description', 'location_label'] as $requiredTarget) {
                    if (trim((string) ($deploymentData[$requiredTarget] ?? '')) === '') {
                        throw ValidationException::withMessages([
                            'bindings' => ["Vul het gekoppelde veld voor $requiredTarget in voordat je een inzet voorbereidt."],
                        ]);
                    }
                }
                $deploymentData['priority'] = self::DEPLOYMENT_PRIORITIES[(string) $locked->decided_priority];
                $deploymentData['status'] = 'draft';
                $deploymentData['deployment_request_decision_valid'] = true;
                $selected = is_array($locked->selected_deployment_proposal) ? $locked->selected_deployment_proposal : [];
                $this->assertCurrentDeploymentTargets($selected);
                if (($selected['resources'] ?? []) !== [] && ! array_key_exists('required_resources', $deploymentData)) {
                    $deploymentData['required_resources'] = implode(', ', $selected['resources']);
                }
                if (($selected['team_ids'] ?? []) !== []) {
                    $deploymentData['team_ids'] = $selected['team_ids'];
                }
                $deploymentData = $this->mirrorLegacyDeploymentFields($deploymentData);

                $rules = array_merge(
                    $this->deploymentFormService->fixedInputValidationRules(partial: true, enforceConfigurableRequired: false),
                    $this->deploymentFormService->validationRules(partial: true),
                    ['team_ids' => ['sometimes', 'array'], 'team_ids.*' => ['string', 'exists:teams,id']],
                );
                Validator::make($deploymentData, $rules)->validate();
                unset($deploymentData['status']);
                $deployment = $this->deploymentService->create($deploymentData, $actor);
                $locked->forceFill([
                    'deployment_id' => $deployment->id,
                    'status' => 'prepared',
                    'updated_by' => $actor->id,
                    'lock_version' => $locked->lock_version + 1,
                ])->save();
                $locked->setRelation('deployment', $deployment);
                $payload = $this->present($locked->refresh()->load(['workflowRevision', 'deployment']), $actor);
                $stored = ['deployment_request' => $payload, 'deployment_id' => $deployment->id];
                $this->storeMutation($locked, $mutationId, $operation, $hash, $stored, $actor);
                $this->auditService->record('deployment_requests.prepared', $locked, $actor, [
                    'deployment_id' => $deployment->id,
                    'decided_priority' => $locked->decided_priority,
                    'workflow_version' => $locked->workflowRevision->version,
                ]);
                $this->broadcastAfterCommit($locked, $actor);

                return ['deployment_request' => $payload, 'deployment' => $deployment];
            });
        } catch (QueryException $exception) {
            $replay = $this->replay($deploymentRequest, $mutationId, $operation, $hash, $actor, $legacyHash);
            if ($replay !== null) {
                $deployment = Deployment::query()->findOrFail((string) ($replay['deployment_id'] ?? $replay['deployment_request']['deployment_id'] ?? ''));

                return ['deployment_request' => $replay['deployment_request'] ?? $replay, 'deployment' => $deployment];
            }
            throw $exception;
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function close(DeploymentRequest $deploymentRequest, array $data, User $actor): array
    {
        return $this->mutate($deploymentRequest, 'close', $data, $actor, function (DeploymentRequest $locked, array $input) use ($actor): void {
            if ($locked->status === 'closed') {
                throw ValidationException::withMessages(['status' => ['Een afgesloten aanvraagdossier kan niet opnieuw worden afgesloten.']]);
            }
            if ($locked->deployment_id !== null || $locked->status === 'prepared') {
                throw ValidationException::withMessages(['status' => ['Een aanvraagdossier met een inzet wordt via de inzetstatus afgehandeld.']]);
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
    public function present(DeploymentRequest $deploymentRequest, ?User $actor = null, bool $operatorOnly = false): array
    {
        $deploymentRequest->loadMissing(['workflowRevision', 'deployment']);
        $configuration = $deploymentRequest->workflowRevision->configuration ?? [];
        $subjectType = (string) $deploymentRequest->subject_type;
        $currentPublished = $this->workflowService->published();
        $currentFieldMap = collect($currentPublished->configuration['fields'] ?? [])->keyBy('key');
        $answers = is_array($deploymentRequest->answers) ? $deploymentRequest->answers : [];
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
            $current = $currentFieldMap->get($key);
            $operatorVisible = ($field['operator_visible'] ?? false) === true
                && is_array($current)
                && ($current['operator_visible'] ?? false) === true
                && $this->fieldApplies($current, $subjectType);
            if ($operatorOnly && ! $operatorVisible) {
                continue;
            }
            $answerRows[] = [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'value' => $answers[$key],
                'display_value' => $this->displayValue($field, $answers[$key]),
                'section' => $section,
                'operator_visible' => $operatorVisible,
            ];
        }

        return [
            'id' => $deploymentRequest->id,
            'status' => $deploymentRequest->status,
            'subject_type' => $subjectType,
            'subject_type_label' => $this->subjectTypeLabel($configuration, $subjectType),
            'workflow_revision' => [
                'id' => $deploymentRequest->workflowRevision->id,
                'version' => $deploymentRequest->workflowRevision->version,
            ],
            'answers' => $operatorOnly
                ? (object) collect($answerRows)->mapWithKeys(fn (array $row): array => [$row['key'] => $row['value']])->all()
                : (object) $answers,
            'answer_rows' => $answerRows,
            'triage' => [
                'state' => $deploymentRequest->triage['state'] ?? 'unknown',
                'recommended_priority' => $deploymentRequest->recommended_priority,
                'reasons' => $deploymentRequest->triage['reasons'] ?? [],
                'missing_fields' => $deploymentRequest->triage['missing_fields'] ?? [],
            ],
            'decided_priority' => $deploymentRequest->decided_priority,
            'priority_override_reason' => $deploymentRequest->priority_decision_reason,
            'deployment_proposal' => $deploymentRequest->triage['deployment_proposal'] ?? null,
            'selected_deployment_proposal' => $deploymentRequest->selected_deployment_proposal,
            'lock_version' => $deploymentRequest->lock_version,
            'deployment_id' => $deploymentRequest->deployment_id,
            'created_at' => ApiDateTime::dateTime($deploymentRequest->created_at),
            'updated_at' => ApiDateTime::dateTime($deploymentRequest->updated_at),
        ];
    }

    /** @return array<string, mixed>|null */
    public function projectionForDeployment(Deployment $deployment, ?User $actor = null): ?array
    {
        $deploymentRequest = $deployment->relationLoaded('deploymentRequest')
            ? $deployment->deploymentRequest
            : $this->repository->forDeployment((string) $deployment->getKey());
        if ($deploymentRequest === null) {
            return null;
        }

        $payload = $this->present($deploymentRequest, $actor, operatorOnly: $actor?->isOperatorClient() === true);

        return [
            'subject_type' => $payload['subject_type'],
            'subject_type_label' => $payload['subject_type_label'],
            'answers' => $payload['answer_rows'],
        ];
    }

    /** @return list<string> */
    public function hiddenDeploymentTargetsForOperator(Deployment $deployment): array
    {
        return $this->workflowService->hiddenDeploymentTargetsForOperator($deployment);
    }

    /**
     * @param  callable(DeploymentRequest, array<string, mixed>): void  $change
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mutate(DeploymentRequest $deploymentRequest, string $operation, array $data, User $actor, callable $change): array
    {
        $mutationId = $this->mutationId($data);
        $hash = $this->requestHash($operation, $data);
        if (($replay = $this->replay($deploymentRequest, $mutationId, $operation, $hash, $actor)) !== null) {
            return $replay;
        }

        try {
            return DB::transaction(function () use ($deploymentRequest, $operation, $data, $actor, $change, $mutationId, $hash): array {
                $locked = $this->repository->lock((string) $deploymentRequest->getKey());
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
                $locked->refresh()->load(['workflowRevision', 'deployment']);
                $payload = $this->present($locked, $actor);
                $this->storeMutation($locked, $mutationId, $operation, $hash, $payload, $actor);
                $auditReason = $operation === 'priority'
                    ? $this->nullableText($data['reason'] ?? null, 2000)
                    : null;
                $this->auditService->record(
                    'deployment_requests.'.$operation,
                    $locked,
                    $actor,
                    [
                        'deployment_id' => $locked->deployment_id,
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
            $replay = $this->replay($deploymentRequest, $mutationId, $operation, $hash, $actor);
            if ($replay !== null) {
                return $replay;
            }
            throw $exception;
        }
    }

    private function assertLockVersion(DeploymentRequest $deploymentRequest, int $expected, ?User $actor): void
    {
        if ($expected !== $deploymentRequest->lock_version) {
            throw new DeploymentRequestConflictException(
                'deployment_request_version_conflict',
                'Het aanvraagdossier is intussen gewijzigd.',
                $this->present($deploymentRequest, $actor),
            );
        }
    }

    /** @return array<string, mixed>|null */
    private function replay(
        DeploymentRequest $deploymentRequest,
        string $mutationId,
        string $operation,
        string $hash,
        User $actor,
        ?string $legacyHash = null,
    ): ?array {
        $mutation = DeploymentRequestMutation::query()
            ->where('actor_id', $actor->id)
            ->where('client_mutation_id', $mutationId)
            ->first();
        if ($mutation === null) {
            return null;
        }
        $currentDeploymentRequest = $mutation->deploymentRequest()->with(['workflowRevision', 'deployment'])->firstOrFail();
        $current = $this->present($currentDeploymentRequest, $actor);
        if ((string) $mutation->deployment_request_id !== (string) $deploymentRequest->id) {
            throw new DeploymentRequestConflictException(
                'deployment_request_mutation_conflict',
                'Deze mutatie-ID is al voor een ander aanvraagdossier gebruikt.',
                $current,
            );
        }
        if ($mutation->operation !== $operation || ! $this->requestHashMatches($mutation, $hash, $legacyHash)) {
            throw new DeploymentRequestConflictException(
                'deployment_request_mutation_conflict',
                'Deze mutatie-ID is al voor een andere wijziging gebruikt.',
                $current,
            );
        }

        return $operation === 'prepare_deployment'
            ? ['deployment_request' => $current, 'deployment_id' => $currentDeploymentRequest->deployment_id]
            : $current;
    }

    /** @return array<string, mixed>|null */
    private function replayByMutationId(string $mutationId, string $operation, string $hash, User $actor): ?array
    {
        $mutation = DeploymentRequestMutation::query()
            ->where('actor_id', $actor->id)
            ->where('client_mutation_id', $mutationId)
            ->first();
        if ($mutation === null) {
            return null;
        }
        $deploymentRequest = $mutation->deploymentRequest()->with(['workflowRevision', 'deployment'])->firstOrFail();
        $current = $this->present($deploymentRequest, $actor);
        if ($mutation->operation !== $operation || ! hash_equals($mutation->request_hash, $hash)) {
            throw new DeploymentRequestConflictException(
                'deployment_request_mutation_conflict',
                'Deze mutatie-ID is al voor een andere wijziging gebruikt.',
                $current,
            );
        }

        return $operation === 'prepare_deployment'
            ? ['deployment_request' => $current, 'deployment_id' => $deploymentRequest->deployment_id]
            : $current;
    }

    /** @param array<string, mixed> $payload */
    private function storeMutation(DeploymentRequest $deploymentRequest, string $mutationId, string $operation, string $hash, array $payload, User $actor): void
    {
        DeploymentRequestMutation::query()->create([
            'deployment_request_id' => $deploymentRequest->id,
            'actor_id' => $actor->id,
            'client_mutation_id' => $mutationId,
            'operation' => $operation,
            'request_hash' => $hash,
            'response_payload' => [
                'request_hash_version' => 2,
                'deployment_request_id' => $deploymentRequest->id,
                'lock_version' => $deploymentRequest->lock_version,
                'deployment_id' => $deploymentRequest->deployment_id
                    ?? ($payload['deployment_id'] ?? $payload['deployment_request']['deployment_id'] ?? null),
            ],
            'created_at' => now(),
        ]);
    }

    private function requestHashMatches(
        DeploymentRequestMutation $mutation,
        string $canonicalHash,
        ?string $legacyHash = null,
    ): bool {
        if (hash_equals((string) $mutation->request_hash, $canonicalHash)) {
            return true;
        }

        $responsePayload = is_array($mutation->response_payload) ? $mutation->response_payload : [];

        return (int) ($responsePayload['request_hash_version'] ?? 2) === 1
            && $legacyHash !== null
            && hash_equals((string) $mutation->request_hash, $legacyHash);
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
        $profileTeamSelection = is_array($profile)
            ? $this->workflowService->deploymentProposalTeamSelection($profile)
            : ['team_ids' => [], 'teams' => []];

        $hasTeamAdjustment = array_key_exists('team_ids', $adjustments);
        $teamIds = $hasTeamAdjustment ? $adjustments['team_ids'] : $profileTeamSelection['team_ids'];
        if (! is_array($teamIds) || ! array_is_list($teamIds)) {
            throw ValidationException::withMessages(['deployment_adjustments.team_ids' => ['Teams moeten als lijst worden aangeleverd.']]);
        }
        $normalizedTeamIds = collect($teamIds)->filter(fn (mixed $id): bool => is_string($id))->unique()->values()->all();
        if (count($normalizedTeamIds) !== count($teamIds)
            || count($normalizedTeamIds) > 50
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
                : $profileTeamSelection['teams'],
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

    /** @param array<string, mixed> $selected @param array<string, mixed>|null $recommended */
    private function deploymentProposalDiffersFromRecommendation(array $selected, ?array $recommended): bool
    {
        if ($recommended === null) {
            return true;
        }

        return ($selected['profile_id'] ?? null) !== ($recommended['profile_id'] ?? null)
            || ! $this->sameStringSet($selected['team_ids'] ?? null, $recommended['team_ids'] ?? null)
            || ($selected['resources'] ?? null) !== ($recommended['resources'] ?? null)
            || ($selected['recommended_recipient_count'] ?? null) !== ($recommended['recommended_recipient_count'] ?? null)
            || ($selected['recommended_dispatch_mode'] ?? null) !== ($recommended['recommended_dispatch_mode'] ?? null)
            || trim((string) ($selected['notes'] ?? '')) !== trim((string) ($recommended['notes'] ?? ''))
            || ! $this->sameStringSet(
                $selected['required_certification_type_ids'] ?? null,
                $recommended['required_certification_type_ids'] ?? null,
            );
    }

    private function sameStringSet(mixed $left, mixed $right): bool
    {
        if (! is_array($left) || ! is_array($right)) {
            return false;
        }

        $normalizedLeft = collect($left)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->unique()
            ->sort()
            ->values()
            ->all();
        $normalizedRight = collect($right)
            ->filter(fn (mixed $value): bool => is_string($value))
            ->unique()
            ->sort()
            ->values()
            ->all();

        return count($normalizedLeft) === count($left)
            && count($normalizedRight) === count($right)
            && $normalizedLeft === $normalizedRight;
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
    private function boundDeploymentValues(array $configuration, string $subjectType, array $answers): array
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
            $this->writeDeploymentTarget($data, $target, $answers[$key]);
        }

        return $data;
    }

    /**
     * Keeps the configurable deployment fields and their legacy columns equal.
     * Existing custom values are preserved for partial deployment updates.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function mirrorLegacyDeploymentFields(array $data, ?Deployment $deployment = null): array
    {
        $incomingCustom = is_array($data['custom_fields'] ?? null) ? $data['custom_fields'] : [];
        $custom = array_replace(
            is_array($deployment?->custom_fields) ? $deployment->custom_fields : [],
            $incomingCustom,
        );
        $touched = array_key_exists('custom_fields', $data);

        foreach (DeploymentRequestWorkflowService::LEGACY_MIRRORED_FIELD_KEYS as $key) {
            $hasFixed = array_key_exists($key, $data);
            $hasCustom = array_key_exists($key, $incomingCustom);
            if (! $hasFixed && ! $hasCustom) {
                continue;
            }
            if ($hasFixed && $hasCustom && $data[$key] !== $incomingCustom[$key]) {
                throw ValidationException::withMessages([
                    $key => ['Vast inzetveld en configureerbaar inzetveld bevatten verschillende waarden.'],
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
    private function writeDeploymentTarget(array &$data, string $target, mixed $value): void
    {
        $canonicalTarget = DeploymentRequestWorkflowService::canonicalBindingTarget($target);
        if (in_array($canonicalTarget, DeploymentRequestWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
            // Workflow revisions are immutable. A historical revision can
            // therefore still contain a numeric field for this mirrored target
            // after the live deployment form has repaired itself to a phone
            // contract. Preserve the frozen answer while presenting it to the
            // current deployment validator as the textual phone value it is.
            if ($canonicalTarget === 'on_scene_contact_phone' && is_int($value)) {
                $value = (string) $value;
            }
            $data[$canonicalTarget] = $value;
            $data['custom_fields'] ??= [];
            $data['custom_fields'][$canonicalTarget] = $value;

            return;
        }
        if (str_starts_with($target, 'custom_fields.')) {
            $key = substr($target, strlen('custom_fields.'));
            $data['custom_fields'] ??= [];
            $data['custom_fields'][$key] = $this->deploymentCustomTargetValue($key, $value);

            return;
        }
        $allowed = array_column($this->workflowService->catalogs()['deployment_fields'], 'target');
        if (in_array($target, $allowed, true) && ! str_starts_with($target, 'custom_fields.')) {
            $data[$target] = $value;
        }
    }

    private function deploymentCustomTargetValue(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $field = collect($this->deploymentFormService->fields())->firstWhere('key', $key);
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
    private function readDeploymentTarget(array $data, string $target): array
    {
        $canonicalTarget = DeploymentRequestWorkflowService::canonicalBindingTarget($target);
        if (in_array($canonicalTarget, DeploymentRequestWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
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

    private function storedDeploymentTarget(Deployment $deployment, string $target): mixed
    {
        $canonicalTarget = DeploymentRequestWorkflowService::canonicalBindingTarget($target);
        if (in_array($canonicalTarget, DeploymentRequestWorkflowService::LEGACY_MIRRORED_FIELD_KEYS, true)) {
            return $deployment->getAttribute($canonicalTarget);
        }
        if (str_starts_with($target, 'custom_fields.')) {
            $key = substr($target, strlen('custom_fields.'));

            return is_array($deployment->custom_fields) ? ($deployment->custom_fields[$key] ?? null) : null;
        }

        return $deployment->getAttribute($target);
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

    private function broadcastAfterCommit(DeploymentRequest $deploymentRequest, User $actor): void
    {
        $id = (string) $deploymentRequest->getKey();
        DB::afterCommit(function () use ($id, $actor): void {
            $fresh = DeploymentRequest::query()->with('deployment')->find($id);
            if ($fresh !== null) {
                try {
                    event(new DeploymentRequestChanged($fresh, $actor));
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
