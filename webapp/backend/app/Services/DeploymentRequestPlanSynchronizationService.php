<?php

namespace App\Services;

use App\Events\DeploymentRequestChanged;
use App\Models\Deployment;
use App\Models\DeploymentRequest;
use App\Models\User;
use App\Repositories\DeploymentRequestRepository;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

final class DeploymentRequestPlanSynchronizationService
{
    public function __construct(
        private readonly DeploymentRequestRepository $deploymentRequestRepository,
        private readonly AuditService $auditService,
    ) {}

    public function lockForDeployment(string $deploymentId): ?DeploymentRequest
    {
        $this->assertInsideTransaction();

        return $this->deploymentRequestRepository->lockForDeployment($deploymentId);
    }

    public function synchronizeTeams(
        DeploymentRequest $deploymentRequest,
        Deployment $deployment,
        User $actor,
    ): void {
        $this->assertInsideTransaction();

        if ((string) $deploymentRequest->deployment_id !== (string) $deployment->id) {
            throw new LogicException('Het aanvraagdossier en de inzet horen niet bij elkaar.');
        }

        $deployment->unsetRelation('teams');
        $deployment->load('teams:id,code,name');
        $teamsById = $deployment->teams->keyBy(fn ($team): string => (string) $team->id);
        $proposal = $this->proposalForSynchronization($deploymentRequest);
        $previousTeamIds = $this->normalizedTeamIds($proposal['team_ids'] ?? []);
        $currentTeamIds = $teamsById->keys()->map(fn (mixed $id): string => (string) $id)->all();
        $currentTeamIdSet = array_fill_keys($currentTeamIds, true);
        $orderedTeamIds = array_values(array_filter(
            $previousTeamIds,
            fn (string $id): bool => isset($currentTeamIdSet[$id]),
        ));
        $missingTeamIds = array_values(array_diff($currentTeamIds, $orderedTeamIds));
        usort($missingTeamIds, function (string $left, string $right) use ($teamsById): int {
            $leftTeam = $teamsById->get($left);
            $rightTeam = $teamsById->get($right);

            return strcasecmp((string) $leftTeam?->code, (string) $rightTeam?->code)
                ?: strcmp($left, $right);
        });
        $orderedTeamIds = [...$orderedTeamIds, ...$missingTeamIds];
        $teamSnapshots = collect($orderedTeamIds)
            ->map(function (string $id) use ($teamsById): array {
                $team = $teamsById->get($id);

                return [
                    'id' => $id,
                    'code' => (string) $team?->code,
                    'name' => (string) $team?->name,
                ];
            })
            ->values()
            ->all();
        $nextProposal = array_replace($proposal, [
            'team_ids' => $orderedTeamIds,
            'teams' => $teamSnapshots,
        ]);

        if ($nextProposal === $proposal) {
            return;
        }

        $deploymentRequest->forceFill([
            'selected_deployment_proposal' => $nextProposal,
            'lock_version' => $deploymentRequest->lock_version + 1,
            'updated_by' => $actor->id,
        ])->save();
        $this->auditService->record(
            'deployment_requests.operational_plan_synced',
            $deploymentRequest,
            $actor,
            [
                'deployment_id' => $deployment->id,
                'lock_version' => $deploymentRequest->lock_version,
                'team_ids_from' => $previousTeamIds,
                'team_ids_to' => $orderedTeamIds,
            ],
        );
        $this->broadcastAfterCommit($deploymentRequest, $actor);
    }

    /** @return array<string, mixed> */
    private function proposalForSynchronization(DeploymentRequest $deploymentRequest): array
    {
        if (is_array($deploymentRequest->selected_deployment_proposal)) {
            return $deploymentRequest->selected_deployment_proposal;
        }

        $recommended = is_array($deploymentRequest->triage)
            ? ($deploymentRequest->triage['deployment_proposal'] ?? null)
            : null;
        if (is_array($recommended)) {
            return $recommended;
        }

        return [
            'profile_id' => $deploymentRequest->selected_deployment_profile_id,
            'label' => 'Aangepast inzetvoorstel',
            'summary' => null,
            'team_ids' => [],
            'teams' => [],
            'resources' => [],
            'notes' => null,
            'recommended_recipient_count' => null,
            'recommended_dispatch_mode' => null,
            'required_certification_type_ids' => [],
            'required_certification_types' => [],
        ];
    }

    /** @return list<string> */
    private function normalizedTeamIds(mixed $teamIds): array
    {
        if (! is_array($teamIds)) {
            return [];
        }

        return collect($teamIds)
            ->filter(fn (mixed $id): bool => is_string($id) && $id !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Synchronisatie van het inzetplan vereist een databasetransactie.');
        }
    }

    private function broadcastAfterCommit(DeploymentRequest $deploymentRequest, User $actor): void
    {
        $id = (string) $deploymentRequest->getKey();
        DB::afterCommit(function () use ($id, $actor): void {
            $fresh = DeploymentRequest::query()->with('deployment')->find($id);
            if ($fresh === null) {
                return;
            }

            try {
                event(new DeploymentRequestChanged($fresh, $actor));
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
