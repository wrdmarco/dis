<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('deployment_requests')
            || ! Schema::hasTable('deployments')
            || ! Schema::hasTable('deployment_team')
            || ! Schema::hasTable('teams')
            || ! Schema::hasColumn('deployment_requests', 'selected_deployment_proposal')) {
            return;
        }

        DB::table('deployment_requests')
            ->whereNotNull('deployment_id')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function (Collection $deploymentRequests): void {
                foreach ($deploymentRequests as $deploymentRequest) {
                    $this->backfillDeploymentRequest((string) $deploymentRequest->id);
                }
            }, 'id');
    }

    public function down(): void
    {
        // The backfill only incorporates teams already coupled to a real
        // deployment. Reverting it could reintroduce a destructive stale plan.
    }

    private function backfillDeploymentRequest(string $deploymentRequestId): void
    {
        DB::transaction(function () use ($deploymentRequestId): void {
            // Runtime request mutations use the same request -> deployment lock
            // order. The migration must not overwrite an intake update that
            // happens while a rolling multi-server deployment is in progress.
            $deploymentRequest = DB::table('deployment_requests')
                ->where('id', $deploymentRequestId)
                ->lockForUpdate()
                ->first();
            if ($deploymentRequest === null) {
                return;
            }
            $deploymentId = (string) ($deploymentRequest->deployment_id ?? '');
            if ($deploymentId === '') {
                return;
            }
            $deployment = DB::table('deployments')
                ->where('id', $deploymentId)
                ->lockForUpdate()
                ->first(['id', 'team_id']);
            if ($deployment === null) {
                return;
            }

            $currentTeamIds = DB::table('deployment_team')
                ->where('deployment_id', $deploymentId)
                ->pluck('team_id')
                ->map(fn (mixed $teamId): string => (string) $teamId)
                ->filter()
                ->values();
            if (is_string($deployment->team_id) && $deployment->team_id !== '') {
                $currentTeamIds->push($deployment->team_id);
            }
            if (Schema::hasTable('dispatch_requests')) {
                $currentTeamIds = $currentTeamIds
                    ->merge(
                        DB::table('dispatch_requests')
                            ->where('deployment_id', $deploymentId)
                            ->where('status', '!=', 'cancelled')
                            ->whereNotNull('target_team_id')
                            ->pluck('target_team_id')
                            ->map(fn (mixed $teamId): string => (string) $teamId),
                    );
            }
            $currentTeamIds = $currentTeamIds->unique()->values();
            $proposal = $this->proposal($deploymentRequest);
            $previousTeamIds = collect($proposal['team_ids'] ?? [])
                ->filter(fn (mixed $teamId): bool => is_string($teamId) && $teamId !== '')
                ->unique()
                ->values();
            $teamRows = DB::table('teams')
                ->whereIn('id', $currentTeamIds->all())
                ->get(['id', 'code', 'name'])
                ->keyBy(fn (object $team): string => (string) $team->id);
            $orderedCurrentTeamIds = $previousTeamIds
                ->filter(fn (string $teamId): bool => $currentTeamIds->contains($teamId))
                ->values();
            $missingTeamIds = $currentTeamIds
                ->reject(fn (string $teamId): bool => $orderedCurrentTeamIds->contains($teamId))
                ->sort(function (string $left, string $right) use ($teamRows): int {
                    $leftTeam = $teamRows->get($left);
                    $rightTeam = $teamRows->get($right);

                    return strcasecmp((string) ($leftTeam->code ?? ''), (string) ($rightTeam->code ?? ''))
                        ?: strcmp($left, $right);
                })
                ->values();
            $nextTeamIds = $orderedCurrentTeamIds->merge($missingTeamIds)->values();
            $previousSnapshots = collect($proposal['teams'] ?? [])
                ->filter(fn (mixed $team): bool => is_array($team) && is_string($team['id'] ?? null))
                ->keyBy(fn (array $team): string => $team['id']);
            $nextSnapshots = $nextTeamIds
                ->map(function (string $teamId) use ($previousSnapshots, $teamRows): array {
                    $previous = $previousSnapshots->get($teamId);
                    if (is_array($previous)) {
                        return $previous;
                    }
                    $team = $teamRows->get($teamId);

                    return [
                        'id' => $teamId,
                        'code' => (string) ($team->code ?? ''),
                        'name' => (string) ($team->name ?? ''),
                    ];
                })
                ->all();
            $nextProposal = array_replace($proposal, [
                'team_ids' => $nextTeamIds->all(),
                'teams' => $nextSnapshots,
            ]);

            if ($nextProposal === $proposal) {
                return;
            }

            DB::table('deployment_requests')
                ->where('id', $deploymentRequest->id)
                ->update([
                    'selected_deployment_proposal' => json_encode(
                        $nextProposal,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                    ),
                    'lock_version' => ((int) $deploymentRequest->lock_version) + 1,
                    'updated_by' => null,
                    'updated_at' => now(),
                ]);
        });
    }

    /** @return array<string, mixed> */
    private function proposal(object $deploymentRequest): array
    {
        $selected = $this->decode($deploymentRequest->selected_deployment_proposal ?? null);
        if ($selected !== null) {
            return $selected;
        }

        $triage = $this->decode($deploymentRequest->triage ?? null);
        $recommended = $triage['deployment_proposal'] ?? null;
        if (is_array($recommended)) {
            return $recommended;
        }

        return [
            'profile_id' => $deploymentRequest->selected_deployment_profile_id ?? null,
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

    /** @return array<string, mixed>|null */
    private function decode(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
};
