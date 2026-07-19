<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Wallboard;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;
use Carbon\CarbonInterface;

final class WallboardDisplayService
{
    public function __construct(private readonly WallboardPlaylistResolver $playlistResolver) {}

    /**
     * Resolve the page server-side so an administrator and the kiosk always see
     * the same rotation position. Incident override deliberately takes priority
     * over a manual command, which in turn takes priority over normal rotation.
     *
     * @param  array<string, mixed>|null  $configuration
     * @return array{mode: string, page_id: string, incident_active: bool, next_change_at: string|null}
     */
    public function display(Wallboard $wallboard, ?array $configuration = null, ?bool $incidentActive = null): array
    {
        $configuration ??= $this->playlistResolver->resolve($wallboard);
        $pages = array_values((array) $configuration['pages']);
        $firstPageId = (string) $pages[0]['id'];
        $incidentActive ??= $this->hasActiveAlarmIncident();

        $override = (array) $configuration['incident_override'];
        if ($incidentActive && ($override['enabled'] ?? false) === true) {
            return $this->result('incident_override', (string) $override['page_id'], true);
        }

        $manualPageId = (string) ($wallboard->manual_page_id ?? '');
        if ($manualPageId !== '' && WallboardConfiguration::hasPage($configuration, $manualPageId)) {
            return $this->result('manual', $manualPageId, $incidentActive);
        }

        if (($configuration['rotation_enabled'] ?? false) !== true || count($pages) === 1) {
            return $this->result('static', $firstPageId, $incidentActive);
        }

        return $this->rotationResult($wallboard, $pages, $incidentActive);
    }

    public function hasActiveAlarmIncident(): bool
    {
        return Incident::query()
            ->whereIn('status', ['dispatching', 'in_progress'])
            ->where('is_test', false)
            ->exists();
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array{mode: string, page_id: string, incident_active: bool, next_change_at: string|null}
     */
    private function rotationResult(Wallboard $wallboard, array $pages, bool $incidentActive): array
    {
        $now = ApiDateTime::comparableWallClock(now());
        $anchor = $wallboard->rotation_started_at instanceof CarbonInterface
            ? ApiDateTime::comparableWallClock($wallboard->rotation_started_at)
            : null;
        if (! $anchor instanceof CarbonInterface || $anchor->isAfter($now)) {
            $anchor = collect([$wallboard->updated_at, $wallboard->created_at])
                ->map(fn (mixed $candidate): ?CarbonInterface => $candidate instanceof CarbonInterface
                    ? ApiDateTime::comparableWallClock($candidate)
                    : null)
                ->first(fn (mixed $candidate): bool => $candidate instanceof CarbonInterface && ! $candidate->isAfter($now))
                ?? $now;
        }

        $cycleSeconds = array_sum(array_map(
            static fn (array $page): int => (int) $page['duration_seconds'],
            $pages,
        ));
        $elapsedSeconds = max(0, $now->getTimestamp() - $anchor->getTimestamp());
        $cycleOffset = $cycleSeconds === 0 ? 0 : $elapsedSeconds % $cycleSeconds;
        $cycleStartedAfterSeconds = $elapsedSeconds - $cycleOffset;
        $pageStartedAfterSeconds = 0;

        foreach ($pages as $page) {
            $duration = (int) $page['duration_seconds'];
            if ($cycleOffset < $duration) {
                $nextChangeAt = $anchor->copy()->addSeconds(
                    $cycleStartedAfterSeconds + $pageStartedAfterSeconds + $duration,
                );

                return $this->result(
                    'rotation',
                    (string) $page['id'],
                    $incidentActive,
                    $nextChangeAt,
                );
            }

            $cycleOffset -= $duration;
            $pageStartedAfterSeconds += $duration;
        }

        return $this->result('rotation', (string) $pages[0]['id'], $incidentActive, $now->copy()->addSecond());
    }

    /**
     * @return array{mode: string, page_id: string, incident_active: bool, next_change_at: string|null}
     */
    private function result(
        string $mode,
        string $pageId,
        bool $incidentActive,
        ?CarbonInterface $nextChangeAt = null,
    ): array {
        return [
            'mode' => $mode,
            'page_id' => $pageId,
            'incident_active' => $incidentActive,
            'next_change_at' => ApiDateTime::dateTime($nextChangeAt),
        ];
    }
}
