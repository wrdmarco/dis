<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Models\WallboardSession;
use App\Repositories\WallboardPlaylistRepository;
use App\Repositories\WallboardRepository;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WallboardService
{
    public function __construct(
        private readonly WallboardRepository $repository,
        private readonly WallboardPlaylistRepository $playlistRepository,
        private readonly WallboardPlaylistSynchronizer $playlistSynchronizer,
        private readonly WallboardPlaylistResolver $playlistResolver,
        private readonly WallboardMediaCoordinationService $mediaCoordination,
        private readonly WallboardMediaUsageSynchronizer $mediaUsage,
        private readonly WallboardForecastLocationService $forecastLocations,
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
        if (! array_key_exists('playlist_id', $data)) {
            $this->forecastLocations->assertResolvableAddresses((array) ($data['configuration'] ?? []));
        }

        return DB::transaction(function () use ($data, $actor, $request): Wallboard {
            $playlist = null;
            if (array_key_exists('playlist_id', $data)) {
                $playlist = $this->playlistRepository->lockPlaylist((string) $data['playlist_id']);
                $this->assertNormalPlaylist($playlist);
                $configuration = WallboardConfiguration::normalize((array) $playlist->configuration);
            } else {
                $this->mediaCoordination->lock();
                $configuration = WallboardConfiguration::normalize((array) ($data['configuration'] ?? []));
                $createdPlaylist = $this->playlistRepository->create([
                    'name' => trim((string) $data['name']),
                    'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
                    'purpose' => WallboardPlaylist::PURPOSE_NORMAL,
                    'configuration' => $configuration,
                    'version' => 1,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
                if (! $createdPlaylist instanceof WallboardPlaylist) {
                    throw new \LogicException('Wallboard playlist repository returned an unexpected model.');
                }
                $playlist = $createdPlaylist;
                $configuration = $this->mediaUsage->synchronize($playlist, $configuration);
                $playlist->forceFill(['configuration' => $configuration])->save();
                $this->auditService->record('wallboard_playlists.created', $playlist, $actor, [
                    'name' => $playlist->name,
                    'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
                    'purpose' => WallboardPlaylist::PURPOSE_NORMAL,
                    'version' => 1,
                    'created_for_wallboard' => true,
                ], null, $request);
            }

            if (isset($data['active_incident_playlist_id'])) {
                $this->assertActiveIncidentPlaylist(
                    $this->playlistRepository->lockPlaylist((string) $data['active_incident_playlist_id']),
                );
            }

            $wallboard = $this->repository->create([
                'name' => trim((string) $data['name']),
                'playlist_id' => $playlist->id,
                'active_incident_playlist_id' => isset($data['active_incident_playlist_id'])
                    ? (string) $data['active_incident_playlist_id']
                    : null,
                'layout' => (string) ($data['layout'] ?? Wallboard::LAYOUT_FULLSCREEN_MAP),
                'display_profile' => (string) ($data['display_profile'] ?? Wallboard::DISPLAY_PROFILE_AUTO),
                'configuration' => $configuration,
                'rotation_started_at' => now(),
                'is_enabled' => (bool) ($data['is_enabled'] ?? true),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $wallboard instanceof Wallboard) {
                throw new \LogicException('Wallboard repository returned an unexpected model.');
            }
            $wallboard->setRelation('playlist', $playlist);

            $this->auditService->record('wallboards.created', $wallboard, $actor, [
                'layout' => $wallboard->layout,
                'display_profile' => $wallboard->display_profile,
                'is_enabled' => $wallboard->is_enabled,
                'playlist_id' => (string) $playlist->id,
                'active_incident_playlist_id' => $wallboard->active_incident_playlist_id === null
                    ? null
                    : (string) $wallboard->active_incident_playlist_id,
            ], null, $request);
            if (array_key_exists('playlist_id', $data)) {
                $this->auditService->record('wallboards.playlist_assigned', $wallboard, $actor, [
                    'previous_playlist_id' => null,
                    'playlist_id' => (string) $playlist->id,
                    'playlist_version' => (int) $playlist->version,
                    'data_mode' => $this->playlistDataMode($playlist),
                    'purpose' => $playlist->normalizedPurpose(),
                    'config_version' => (int) $wallboard->config_version,
                    'control_version' => (int) $wallboard->control_version,
                    'during_creation' => true,
                ], null, $request);
            }

            return $wallboard;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Wallboard $wallboard, array $data, User $actor, Request $request): Wallboard
    {
        $configurationValidatedForLive = false;
        if (array_key_exists('configuration', $data)) {
            $configurationValidatedForLive = $this->playlistResolver
                ->resolveRuntime($wallboard, false)['data_mode'] === WallboardPlaylist::DATA_MODE_LIVE;
            if ($configurationValidatedForLive) {
                $this->forecastLocations->assertResolvableAddresses((array) $data['configuration']);
            }
        }

        return DB::transaction(function () use (
            $wallboard,
            $data,
            $actor,
            $request,
            $configurationValidatedForLive,
        ): Wallboard {
            $updatesConfiguration = array_key_exists('configuration', $data);
            $playlist = null;
            $linkedWallboards = collect();
            $preparedConfiguration = null;
            $createdImplicitPlaylist = false;
            $requestedActivePlaylistId = isset($data['active_incident_playlist_id'])
                ? (string) $data['active_incident_playlist_id']
                : null;

            if ($updatesConfiguration) {
                $this->mediaCoordination->lock();
                $candidatePlaylistId = $this->repository->playlistId((string) $wallboard->getKey());
                if ($candidatePlaylistId !== null) {
                    $playlist = $this->playlistRepository->lockPlaylist($candidatePlaylistId);
                    $this->assertNormalPlaylist($playlist);
                    if ($this->playlistDataMode($playlist) === WallboardPlaylist::DATA_MODE_LIVE
                        && ! $configurationValidatedForLive) {
                        throw new ConflictHttpException(
                            'Wallboard playlist data mode changed; retry the configuration update.',
                        );
                    }
                    if ($requestedActivePlaylistId !== null) {
                        $activePlaylist = $requestedActivePlaylistId === $candidatePlaylistId
                            ? $playlist
                            : $this->playlistRepository->lockPlaylist($requestedActivePlaylistId);
                        $this->assertActiveIncidentPlaylist($activePlaylist);
                    }
                    $preparedConfiguration = WallboardConfiguration::normalize(
                        (array) $data['configuration'],
                        (array) $playlist->configuration,
                    );
                    $preparedConfiguration = $this->mediaUsage->synchronize(
                        $playlist,
                        $preparedConfiguration,
                    );
                    $linkedWallboards = $this->playlistRepository->lockLinkedWallboards($candidatePlaylistId);
                    $locked = $linkedWallboards->first(
                        fn (Wallboard $candidate): bool => (string) $candidate->id === (string) $wallboard->getKey(),
                    );
                    if (! $locked instanceof Wallboard
                        || (string) $locked->playlist_id !== (string) $playlist->id) {
                        throw new ConflictHttpException('Wallboard playlist assignment changed.');
                    }
                    $locked->setRelation('playlist', $playlist);
                } else {
                    if (! $configurationValidatedForLive) {
                        throw new ConflictHttpException(
                            'Wallboard playlist assignment changed; retry the configuration update.',
                        );
                    }
                    $preparedConfiguration = WallboardConfiguration::normalize(
                        (array) $data['configuration'],
                        (array) $wallboard->configuration,
                    );
                    $createdPlaylist = $this->playlistRepository->create([
                        'name' => (string) $wallboard->name,
                        'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
                        'purpose' => WallboardPlaylist::PURPOSE_NORMAL,
                        'configuration' => $preparedConfiguration,
                        'version' => 1,
                        'created_by' => $actor->id,
                        'updated_by' => $actor->id,
                    ]);
                    if (! $createdPlaylist instanceof WallboardPlaylist) {
                        throw new \LogicException('Wallboard playlist repository returned an unexpected model.');
                    }
                    $playlist = $createdPlaylist;
                    $preparedConfiguration = $this->mediaUsage->synchronize(
                        $playlist,
                        $preparedConfiguration,
                    );
                    $playlist->forceFill(['configuration' => $preparedConfiguration])->save();
                    if ($requestedActivePlaylistId !== null) {
                        $this->assertActiveIncidentPlaylist(
                            $this->playlistRepository->lockPlaylist($requestedActivePlaylistId),
                        );
                    }
                    $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
                    if ($locked->playlist_id !== null) {
                        throw new ConflictHttpException('Wallboard playlist assignment changed.');
                    }
                    $createdImplicitPlaylist = true;
                }
            } else {
                if ($requestedActivePlaylistId !== null) {
                    $this->assertActiveIncidentPlaylist(
                        $this->playlistRepository->lockPlaylist($requestedActivePlaylistId),
                    );
                }
                $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
            }

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
            $displayProfileChanged = array_key_exists('display_profile', $data)
                && (string) $data['display_profile'] !== (string) $locked->display_profile;
            if ($displayProfileChanged) {
                $changes['display_profile'] = (string) $data['display_profile'];
            }
            $previousActiveIncidentPlaylistId = $locked->active_incident_playlist_id === null
                ? null
                : (string) $locked->active_incident_playlist_id;
            $requestedActiveIncidentPlaylistId = ! array_key_exists('active_incident_playlist_id', $data)
                || $data['active_incident_playlist_id'] === null
                    ? null
                    : (string) $data['active_incident_playlist_id'];
            $activeIncidentPlaylistChanged = array_key_exists('active_incident_playlist_id', $data)
                && $requestedActiveIncidentPlaylistId !== $previousActiveIncidentPlaylistId;
            if ($activeIncidentPlaylistChanged) {
                $changes['active_incident_playlist_id'] = $requestedActiveIncidentPlaylistId;
            }
            $nextConfiguration = $this->playlistResolver->resolve($locked);
            $displayVersionsIncremented = false;
            if ($updatesConfiguration) {
                if (! is_array($preparedConfiguration) || ! $playlist instanceof WallboardPlaylist) {
                    throw new \LogicException('Prepared wallboard playlist configuration is missing.');
                }
                $nextConfiguration = $preparedConfiguration;

                if (! $createdImplicitPlaylist) {
                    $this->playlistSynchronizer->updatePlaylistAndLinkedWallboards(
                        $playlist,
                        $linkedWallboards,
                        $nextConfiguration,
                        $actor,
                    );
                    $this->auditService->record('wallboard_playlists.updated', $playlist, $actor, [
                        'changed_fields' => ['configuration'],
                        'version' => (int) $playlist->version,
                        'linked_wallboards_count' => $this->playlistRepository->linkedWallboardsCount(
                            (string) $playlist->id,
                        ),
                        'source_wallboard_id' => (string) $locked->id,
                    ], null, $request);
                } else {
                    $this->playlistSynchronizer->copyConfigurationToWallboard(
                        $locked,
                        $playlist,
                        $nextConfiguration,
                        $actor,
                        true,
                    );
                    $this->auditService->record('wallboard_playlists.created', $playlist, $actor, [
                        'name' => $playlist->name,
                        'data_mode' => WallboardPlaylist::DATA_MODE_LIVE,
                        'purpose' => WallboardPlaylist::PURPOSE_NORMAL,
                        'version' => 1,
                        'created_for_legacy_wallboard' => (string) $locked->id,
                    ], null, $request);
                }
                $displayVersionsIncremented = true;
            }
            if (array_key_exists('is_enabled', $data)) {
                $changes['is_enabled'] = (bool) $data['is_enabled'];
            }

            if ((array_key_exists('name', $changes)
                || array_key_exists('layout', $changes)
                || array_key_exists('display_profile', $changes)
                || array_key_exists('active_incident_playlist_id', $changes))
                && ! $displayVersionsIncremented) {
                $changes['config_version'] = (int) $locked->config_version + 1;
                $changes['control_version'] = (int) $locked->control_version + 1;
            }
            if (array_key_exists('layout', $changes) && ! $displayVersionsIncremented) {
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
                    'control_version' => $displayVersionsIncremented || array_key_exists('control_version', $changes)
                        ? (int) $locked->control_version
                        : (int) $locked->control_version + 1,
                ])->save();
            }

            $changedFields = array_values(array_diff(array_keys($data), ['expected_config_version']));
            if (array_key_exists('display_profile', $data) && ! $displayProfileChanged) {
                $changedFields = array_values(array_diff($changedFields, ['display_profile']));
            }
            if (array_key_exists('active_incident_playlist_id', $data) && ! $activeIncidentPlaylistChanged) {
                $changedFields = array_values(array_diff($changedFields, ['active_incident_playlist_id']));
            }
            $this->auditService->record('wallboards.updated', $locked, $actor, [
                'changed_fields' => $changedFields,
                'config_version' => (int) $locked->config_version,
                'is_enabled' => (bool) $locked->is_enabled,
                ...($activeIncidentPlaylistChanged ? [
                    'previous_active_incident_playlist_id' => $previousActiveIncidentPlaylistId,
                    'active_incident_playlist_id' => $requestedActiveIncidentPlaylistId,
                ] : []),
            ], null, $request);

            return $locked->refresh()->load(['playlist', 'activeIncidentPlaylist']);
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

            $configuration = $this->playlistResolver->resolve($locked);
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

    /** @return array<string, mixed> */
    public function requestRefresh(
        Wallboard $wallboard,
        int $expectedControlVersion,
        User $actor,
        Request $request,
    ): array {
        return DB::transaction(function () use ($wallboard, $expectedControlVersion, $actor, $request): array {
            $locked = $this->repository->lockWallboard((string) $wallboard->getKey());
            if (! $locked->is_enabled) {
                throw ValidationException::withMessages([
                    'wallboard' => ['Een uitgeschakeld wallboard kan niet worden herstart.'],
                ]);
            }
            if ($expectedControlVersion !== (int) $locked->control_version) {
                throw new ConflictHttpException('Wallboard control changed.');
            }

            $previousControlVersion = (int) $locked->control_version;
            $previousRefreshVersion = (int) $locked->refresh_version;
            $locked->forceFill([
                'control_version' => $previousControlVersion + 1,
                'refresh_version' => $previousRefreshVersion + 1,
                'updated_by' => $actor->id,
            ])->save();

            $this->auditService->record('wallboards.refresh_commanded', $locked, $actor, [
                'previous_control_version' => $previousControlVersion,
                'control_version' => (int) $locked->control_version,
                'previous_refresh_version' => $previousRefreshVersion,
                'refresh_version' => (int) $locked->refresh_version,
            ], null, $request);

            return $this->resource($locked->refresh(), $this->displayService->hasActiveAlarmIncident());
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
        $resolved = $this->playlistResolver->resolveRuntime($wallboard, false);
        $configuration = $resolved['configuration'];
        $wallboard->loadMissing([
            'playlist:id,name,data_mode,purpose,configuration,version',
            'activeIncidentPlaylist:id,name,data_mode,purpose,configuration,version',
            'nonRevokedSessions:id,wallboard_id,last_seen_at,expires_at',
        ]);
        $activeSessions = $this->activeSessions($wallboard);
        $playlist = $wallboard->playlist;
        $activeIncidentPlaylist = $wallboard->activeIncidentPlaylist;

        return [
            'id' => (string) $wallboard->id,
            'name' => (string) $wallboard->name,
            'playlist_id' => $wallboard->playlist_id === null ? null : (string) $wallboard->playlist_id,
            'playlist' => $playlist instanceof WallboardPlaylist ? [
                'id' => (string) $playlist->id,
                'name' => (string) $playlist->name,
                'data_mode' => $this->playlistDataMode($playlist),
                'purpose' => $playlist->normalizedPurpose(),
                'version' => (int) $playlist->version,
            ] : null,
            'active_incident_playlist_id' => $wallboard->active_incident_playlist_id === null
                ? null
                : (string) $wallboard->active_incident_playlist_id,
            'active_incident_playlist' => $activeIncidentPlaylist instanceof WallboardPlaylist ? [
                'id' => (string) $activeIncidentPlaylist->id,
                'name' => (string) $activeIncidentPlaylist->name,
                'data_mode' => $this->playlistDataMode($activeIncidentPlaylist),
                'purpose' => $activeIncidentPlaylist->normalizedPurpose(),
                'version' => (int) $activeIncidentPlaylist->version,
            ] : null,
            'layout' => (string) $wallboard->layout,
            'display_profile' => (string) $wallboard->display_profile,
            'configuration' => $configuration,
            'is_enabled' => (bool) $wallboard->is_enabled,
            'is_online' => $this->isOnline($wallboard, $configuration, $activeSessions),
            'config_version' => (int) $wallboard->config_version,
            'control_version' => (int) $wallboard->control_version,
            'refresh_version' => (int) $wallboard->refresh_version,
            'display' => $this->displayService->display(
                $wallboard,
                $configuration,
                $resolved['data_mode'] === WallboardPlaylist::DATA_MODE_DEMO ? false : $incidentActive,
            ),
            'paired_at' => ApiDateTime::dateTime($wallboard->paired_at),
            'last_seen_at' => ApiDateTime::dateTime($wallboard->last_seen_at),
            'active_sessions_count' => $activeSessions->count(),
            'created_at' => ApiDateTime::dateTime($wallboard->created_at),
            'updated_at' => ApiDateTime::dateTime($wallboard->updated_at),
        ];
    }

    private function assertNormalPlaylist(WallboardPlaylist $playlist): void
    {
        if ($playlist->normalizedPurpose() !== WallboardPlaylist::PURPOSE_NORMAL) {
            throw ValidationException::withMessages([
                'playlist_id' => ['Een wallboard kan alleen een normale playlist als standaardrotatie gebruiken.'],
            ]);
        }
    }

    private function assertActiveIncidentPlaylist(WallboardPlaylist $playlist): void
    {
        if ($playlist->normalizedPurpose() !== WallboardPlaylist::PURPOSE_ALARM) {
            throw ValidationException::withMessages([
                'active_incident_playlist_id' => ['Selecteer een playlist met het doel alarm.'],
            ]);
        }
        if ($this->playlistDataMode($playlist) !== WallboardPlaylist::DATA_MODE_LIVE) {
            throw ValidationException::withMessages([
                'active_incident_playlist_id' => ['Een actieve-inzetplaylist moet live gegevens gebruiken.'],
            ]);
        }
    }

    private function playlistDataMode(WallboardPlaylist $playlist): string
    {
        return in_array($playlist->data_mode, WallboardPlaylist::DATA_MODES, true)
            ? (string) $playlist->data_mode
            : WallboardPlaylist::DATA_MODE_LIVE;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  Collection<int, WallboardSession>  $activeSessions
     */
    private function isOnline(Wallboard $wallboard, array $configuration, Collection $activeSessions): bool
    {
        if (! $wallboard->is_enabled) {
            return false;
        }

        $allowedAgeSeconds = max(
            90,
            max(10, (int) config('dis.wallboards.touch_interval_seconds', 60)) + 30,
            (int) ($configuration['refresh_seconds'] ?? 5) * 3,
        );
        $cutoff = ApiDateTime::comparableWallClock(now())->subSeconds($allowedAgeSeconds);

        return $activeSessions->contains(
            fn (WallboardSession $session): bool => $session->last_seen_at !== null
                && ApiDateTime::comparableWallClock($session->last_seen_at)->isAfter($cutoff),
        );
    }

    /** @return Collection<int, WallboardSession> */
    private function activeSessions(Wallboard $wallboard): Collection
    {
        $now = ApiDateTime::comparableWallClock(now());

        return $wallboard->nonRevokedSessions
            ->filter(fn (WallboardSession $session): bool => $session->expires_at === null
                || ApiDateTime::comparableWallClock($session->expires_at)->isAfter($now))
            ->values();
    }
}
