<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Repositories\WallboardContentSnapshotRepository;
use App\Repositories\WallboardPlaylistRepository;
use App\Repositories\WallboardRepository;
use App\Support\WallboardConfiguration;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class WallboardPlaylistService
{
    public function __construct(
        private readonly WallboardPlaylistRepository $repository,
        private readonly WallboardRepository $wallboards,
        private readonly WallboardPlaylistSynchronizer $synchronizer,
        private readonly WallboardMediaCoordinationService $mediaCoordination,
        private readonly WallboardMediaUsageSynchronizer $mediaUsage,
        private readonly WallboardForecastLocationService $forecastLocations,
        private readonly WallboardContentSnapshotRepository $contentSnapshots,
        private readonly AuditService $auditService,
    ) {}

    /** @return Collection<int, WallboardPlaylist> */
    public function all(): Collection
    {
        return $this->repository->allForManagement();
    }

    public function show(WallboardPlaylist $playlist): WallboardPlaylist
    {
        return $this->repository->findForManagement((string) $playlist->getKey());
    }

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor, Request $request): WallboardPlaylist
    {
        $configuration = WallboardConfiguration::normalize((array) $data['configuration']);
        $dataMode = (string) ($data['data_mode'] ?? WallboardPlaylist::DATA_MODE_LIVE);
        $purpose = (string) ($data['purpose'] ?? WallboardPlaylist::PURPOSE_NORMAL);
        if ($dataMode === WallboardPlaylist::DATA_MODE_LIVE) {
            $this->forecastLocations->assertResolvableAddresses($configuration);
        }

        return DB::transaction(function () use (
            $data,
            $configuration,
            $dataMode,
            $purpose,
            $actor,
            $request,
        ): WallboardPlaylist {
            $this->mediaCoordination->lock();
            $playlist = $this->repository->create([
                'name' => trim((string) $data['name']),
                'data_mode' => $dataMode,
                'purpose' => $purpose,
                'configuration' => $configuration,
                'version' => 1,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            if (! $playlist instanceof WallboardPlaylist) {
                throw new \LogicException('Wallboard playlist repository returned an unexpected model.');
            }
            $configuration = $this->mediaUsage->synchronize($playlist, $configuration);
            $playlist->forceFill(['configuration' => $configuration])->save();

            $this->auditService->record('wallboard_playlists.created', $playlist, $actor, [
                'name' => $playlist->name,
                'data_mode' => $playlist->data_mode,
                'purpose' => $playlist->normalizedPurpose(),
                'version' => 1,
            ], null, $request);

            return $playlist->loadCount('wallboards');
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function update(
        WallboardPlaylist $playlist,
        array $data,
        User $actor,
        Request $request,
    ): WallboardPlaylist {
        $currentDataMode = in_array($playlist->data_mode, WallboardPlaylist::DATA_MODES, true)
            ? (string) $playlist->data_mode
            : WallboardPlaylist::DATA_MODE_LIVE;
        $nextDataMode = (string) ($data['data_mode'] ?? $currentDataMode);
        if ($nextDataMode === WallboardPlaylist::DATA_MODE_LIVE
            && (array_key_exists('configuration', $data) || $nextDataMode !== $currentDataMode)) {
            $this->forecastLocations->assertResolvableAddresses(
                (array) ($data['configuration'] ?? $playlist->configuration),
            );
        }

        return DB::transaction(function () use ($playlist, $data, $actor, $request): WallboardPlaylist {
            if (array_key_exists('configuration', $data)) {
                $this->mediaCoordination->lock();
            }
            $locked = $this->repository->lockPlaylist((string) $playlist->getKey());
            if ((int) $data['expected_version'] !== (int) $locked->version) {
                throw new ConflictHttpException('Wallboard playlist changed.');
            }

            $currentDataMode = in_array($locked->data_mode, WallboardPlaylist::DATA_MODES, true)
                ? (string) $locked->data_mode
                : WallboardPlaylist::DATA_MODE_LIVE;
            $nextDataMode = (string) ($data['data_mode'] ?? $currentDataMode);
            $dataModeChanged = $nextDataMode !== $currentDataMode;
            $currentPurpose = $locked->normalizedPurpose();
            $nextPurpose = (string) ($data['purpose'] ?? $currentPurpose);
            $purposeChanged = $nextPurpose !== $currentPurpose;
            if ($dataModeChanged
                && $nextDataMode === WallboardPlaylist::DATA_MODE_DEMO
                && $this->repository->activeIncidentLinkedWallboardsExist((string) $locked->id)) {
                throw ValidationException::withMessages([
                    'data_mode' => ['Een actieve-inzetplaylist moet live gegevens blijven gebruiken.'],
                ]);
            }
            if ($purposeChanged
                && $nextPurpose === WallboardPlaylist::PURPOSE_ALARM
                && $this->repository->normalLinkedWallboardsExist((string) $locked->id)) {
                throw ValidationException::withMessages([
                    'purpose' => ['Een normaal toegewezen playlist kan niet als alarmplaylist worden geclassificeerd.'],
                ]);
            }
            if ($purposeChanged
                && $nextPurpose === WallboardPlaylist::PURPOSE_NORMAL
                && $this->repository->activeIncidentLinkedWallboardsExist((string) $locked->id)) {
                throw ValidationException::withMessages([
                    'purpose' => ['Een geselecteerde alarmplaylist kan niet als normale playlist worden geclassificeerd.'],
                ]);
            }

            $linkedWallboards = new Collection;
            if (array_key_exists('configuration', $data) || $dataModeChanged || $purposeChanged) {
                $configuration = WallboardConfiguration::normalize(
                    (array) ($data['configuration'] ?? $locked->configuration),
                    (array) $locked->configuration,
                );
                if (array_key_exists('configuration', $data)) {
                    $configuration = $this->mediaUsage->synchronize($locked, $configuration);
                }
                $linkedWallboards = $this->repository->lockLinkedWallboards((string) $locked->id);
                if (array_key_exists('name', $data)) {
                    $locked->name = trim((string) $data['name']);
                }
                $this->synchronizer->updatePlaylistAndLinkedWallboards(
                    $locked,
                    $linkedWallboards,
                    $configuration,
                    $actor,
                    $nextDataMode,
                    $nextPurpose,
                );
                if ($dataModeChanged) {
                    $this->contentSnapshots->deleteForPlaylist((string) $locked->id);
                }
            } else {
                $changes = [
                    'version' => (int) $locked->version + 1,
                    'updated_by' => $actor->id,
                ];
                if (array_key_exists('name', $data)) {
                    $changes['name'] = trim((string) $data['name']);
                }
                $locked->forceFill($changes)->save();
            }
            $linkedWallboardsCount = $this->repository->linkedWallboardsCount((string) $locked->id);

            $this->auditService->record('wallboard_playlists.updated', $locked, $actor, [
                'changed_fields' => array_values(array_diff(array_keys($data), ['expected_version'])),
                'version' => (int) $locked->version,
                'linked_wallboards_count' => $linkedWallboardsCount,
                ...($dataModeChanged ? [
                    'previous_data_mode' => $currentDataMode,
                    'data_mode' => $nextDataMode,
                ] : []),
                ...($purposeChanged ? [
                    'previous_purpose' => $currentPurpose,
                    'purpose' => $nextPurpose,
                ] : []),
            ], null, $request);

            return $this->repository->findForManagement((string) $locked->id);
        }, 3);
    }

    public function assign(
        Wallboard $wallboard,
        string $playlistId,
        int $expectedConfigVersion,
        User $actor,
        Request $request,
    ): Wallboard {
        return DB::transaction(function () use (
            $wallboard,
            $playlistId,
            $expectedConfigVersion,
            $actor,
            $request,
        ): Wallboard {
            // Every write that needs both rows locks the playlist first. Playlist
            // updates use the same order before locking linked wallboards.
            $playlist = $this->repository->lockPlaylist($playlistId);
            $this->assertNormalPlaylist($playlist);
            $lockedWallboard = $this->wallboards->lockWallboard((string) $wallboard->getKey());
            if ($expectedConfigVersion !== (int) $lockedWallboard->config_version) {
                throw new ConflictHttpException('Wallboard configuration changed.');
            }

            $previousPlaylistId = $lockedWallboard->playlist_id;
            $configuration = WallboardConfiguration::normalize((array) $playlist->configuration);
            $this->synchronizer->copyConfigurationToWallboard(
                $lockedWallboard,
                $playlist,
                $configuration,
                $actor,
                true,
            );

            $this->auditService->record('wallboards.playlist_assigned', $lockedWallboard, $actor, [
                'previous_playlist_id' => $previousPlaylistId,
                'playlist_id' => (string) $playlist->id,
                'playlist_version' => (int) $playlist->version,
                'data_mode' => in_array($playlist->data_mode, WallboardPlaylist::DATA_MODES, true)
                    ? (string) $playlist->data_mode
                    : WallboardPlaylist::DATA_MODE_LIVE,
                'purpose' => $playlist->normalizedPurpose(),
                'config_version' => (int) $lockedWallboard->config_version,
                'control_version' => (int) $lockedWallboard->control_version,
            ], null, $request);

            return $lockedWallboard->refresh()->load('playlist');
        }, 3);
    }

    private function assertNormalPlaylist(WallboardPlaylist $playlist): void
    {
        if ($playlist->normalizedPurpose() !== WallboardPlaylist::PURPOSE_NORMAL) {
            throw ValidationException::withMessages([
                'playlist_id' => ['Een wallboard kan alleen een normale playlist als standaardrotatie gebruiken.'],
            ]);
        }
    }

    public function delete(
        WallboardPlaylist $playlist,
        int $expectedVersion,
        User $actor,
        Request $request,
    ): void {
        DB::transaction(function () use ($playlist, $expectedVersion, $actor, $request): void {
            $this->mediaCoordination->lock();
            $locked = $this->repository->lockPlaylist((string) $playlist->getKey());
            if ($expectedVersion !== (int) $locked->version) {
                throw new ConflictHttpException('Wallboard playlist changed.');
            }
            if ($this->repository->linkedWallboardsExist((string) $locked->id)) {
                throw new ConflictHttpException('A linked wallboard still uses this playlist.');
            }

            $this->auditService->record('wallboard_playlists.deleted', $locked, $actor, [
                'name' => $locked->name,
                'version' => (int) $locked->version,
            ], null, $request);
            $this->mediaUsage->clear($locked);
            $locked->delete();
        }, 3);
    }
}
