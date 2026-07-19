<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallboard;
use App\Repositories\WallboardRepository;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WallboardService
{
    public function __construct(
        private readonly WallboardRepository $repository,
        private readonly AuditService $auditService,
        private readonly WallboardDisplayService $displayService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $incidentActive = $this->displayService->hasActiveAlarmIncident();

        return $this->repository->allForManagement()
            ->map(fn (Wallboard $wallboard): array => $this->resource($wallboard, $incidentActive))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function show(Wallboard $wallboard): array
    {
        return $this->resource(
            $this->repository->findForManagement((string) $wallboard->getKey()),
            $this->displayService->hasActiveAlarmIncident(),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor, Request $request): Wallboard
    {
        $configuration = WallboardConfiguration::normalize((array) ($data['configuration'] ?? []));

        return DB::transaction(function () use ($data, $configuration, $actor, $request): Wallboard {
            $wallboard = $this->repository->create([
                'name' => trim((string) $data['name']),
                'layout' => (string) ($data['layout'] ?? Wallboard::LAYOUT_FULLSCREEN_MAP),
                'configuration' => $configuration,
                'rotation_started_at' => now(),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $wallboard instanceof Wallboard) {
                throw new \LogicException('Wallboard repository returned an unexpected model.');
            }

            $this->auditService->record('wallboards.created', $wallboard, $actor, [
                'layout' => $wallboard->layout,
                'is_enabled' => $wallboard->is_enabled,
            ], null, $request);

            return $wallboard;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Wallboard $wallboard, array $data, User $actor, Request $request): Wallboard
    {
        return DB::transaction(function () use ($wallboard, $data, $actor, $request): Wallboard {
            $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
            if (array_key_exists('expected_config_version', $data)
                && (int) $data['expected_config_version'] !== (int) $locked->config_version) {
                throw new ConflictHttpException('Wallboard configuration changed.');
            }

            $changes = [];

            if (array_key_exists('name', $data)) {
                $changes['name'] = trim((string) $data['name']);
            }
            if (array_key_exists('layout', $data)) {
                $changes['layout'] = (string) $data['layout'];
            }
            $nextConfiguration = (array) $locked->configuration;
            if (array_key_exists('configuration', $data)) {
                $nextConfiguration = WallboardConfiguration::normalize(
                    (array) $data['configuration'],
                    (array) $locked->configuration,
                );
                $changes['configuration'] = $nextConfiguration;
            }
            if (array_key_exists('is_enabled', $data)) {
                $changes['is_enabled'] = (bool) $data['is_enabled'];
            }

            if (array_key_exists('layout', $changes) || array_key_exists('configuration', $changes)) {
                $changes['config_version'] = (int) $locked->config_version + 1;
                $changes['control_version'] = (int) $locked->control_version + 1;
                $changes['rotation_started_at'] = now();
                if ($locked->manual_page_id !== null
                    && ! WallboardConfiguration::hasPage($nextConfiguration, (string) $locked->manual_page_id)) {
                    $changes['manual_page_id'] = null;
                    $changes['manual_page_set_at'] = null;
                }
            }
            $changes['updated_by'] = $actor->id;
            $locked->fill($changes)->save();

            if ($locked->is_enabled !== true) {
                $locked->sessions()->whereNull('revoked_at')->update([
                    'revoked_at' => now(),
                    'updated_at' => now(),
                ]);
                $locked->pairingRequests()->whereNull('consumed_at')->delete();
                $locked->forceFill([
                    'manual_page_id' => null,
                    'manual_page_set_at' => null,
                    'control_version' => array_key_exists('control_version', $changes)
                        ? (int) $locked->control_version
                        : (int) $locked->control_version + 1,
                ])->save();
            }

            $this->auditService->record('wallboards.updated', $locked, $actor, [
                'changed_fields' => array_values(array_diff(array_keys($data), ['expected_config_version'])),
                'config_version' => (int) $locked->config_version,
                'is_enabled' => (bool) $locked->is_enabled,
            ], null, $request);

            return $locked->refresh();
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    public function setDisplay(
        Wallboard $wallboard,
        ?string $pageId,
        int $expectedControlVersion,
        User $actor,
        Request $request,
    ): array {
        return DB::transaction(function () use ($wallboard, $pageId, $expectedControlVersion, $actor, $request): array {
            $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
            if (! $locked->is_enabled) {
                throw ValidationException::withMessages([
                    'wallboard' => ['Een uitgeschakeld wallboard kan niet worden bestuurd.'],
                ]);
            }
            if ($expectedControlVersion !== (int) $locked->control_version) {
                throw new ConflictHttpException('Wallboard control changed.');
            }

            $configuration = WallboardConfiguration::normalize((array) $locked->configuration);
            if ($pageId !== null && ! WallboardConfiguration::hasPage($configuration, $pageId)) {
                throw ValidationException::withMessages([
                    'page_id' => ['De gekozen pagina bestaat niet op dit wallboard.'],
                ]);
            }

            $previousPageId = $locked->manual_page_id;
            $locked->forceFill([
                'manual_page_id' => $pageId,
                'manual_page_set_at' => $pageId === null ? null : now(),
                'rotation_started_at' => $pageId === null ? now() : $locked->rotation_started_at,
                'control_version' => (int) $locked->control_version + 1,
                'updated_by' => $actor->id,
            ])->save();

            $this->auditService->record('wallboards.display_commanded', $locked, $actor, [
                'page_id' => $pageId,
                'previous_page_id' => $previousPageId,
                'mode' => $pageId === null ? 'rotation' : 'manual',
                'control_version' => (int) $locked->control_version,
            ], null, $request);

            return $this->resource($locked->refresh(), $this->displayService->hasActiveAlarmIncident());
        }, 3);
    }

    public function delete(Wallboard $wallboard, User $actor, Request $request): void
    {
        DB::transaction(function () use ($wallboard, $actor, $request): void {
            $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
            $this->auditService->record('wallboards.deleted', $locked, $actor, [
                'name' => $locked->name,
            ], null, $request);
            $locked->delete();
        }, 3);
    }

    /** @return array{revoked: bool} */
    public function revokeSessions(Wallboard $wallboard, User $actor, Request $request): array
    {
        return DB::transaction(function () use ($wallboard, $actor, $request): array {
            $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
            $revoked = $locked->sessions()->whereNull('revoked_at')->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
            $cancelledPairings = $locked->pairingRequests()->whereNull('consumed_at')->delete();
            $locked->forceFill(['updated_by' => $actor->id])->save();

            $this->auditService->record('wallboards.sessions_revoked', $locked, $actor, [
                'revoked_sessions' => $revoked,
                'cancelled_pairing_requests' => $cancelledPairings,
            ], null, $request);

            return ['revoked' => true];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function resource(Wallboard $wallboard, ?bool $incidentActive = null): array
    {
        $configuration = WallboardConfiguration::normalize((array) $wallboard->configuration);

        return [
            'id' => (string) $wallboard->id,
            'name' => (string) $wallboard->name,
            'layout' => (string) $wallboard->layout,
            'configuration' => $configuration,
            'is_enabled' => (bool) $wallboard->is_enabled,
            'config_version' => (int) $wallboard->config_version,
            'control_version' => (int) $wallboard->control_version,
            'display' => $this->displayService->display($wallboard, $configuration, $incidentActive),
            'paired_at' => ApiDateTime::dateTime($wallboard->paired_at),
            'last_seen_at' => ApiDateTime::dateTime($wallboard->last_seen_at),
            'active_sessions_count' => (int) ($wallboard->active_sessions_count ?? 0),
            'created_at' => ApiDateTime::dateTime($wallboard->created_at),
            'updated_at' => ApiDateTime::dateTime($wallboard->updated_at),
        ];
    }
}
