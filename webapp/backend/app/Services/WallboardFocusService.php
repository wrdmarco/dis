<?php

namespace App\Services;

use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;

final class WallboardFocusService
{
    private const RESPONSE_ITEM_LIMIT = 24;

    /**
     * Resolve focus once for the supplied normalized wallboard configuration.
     * An active real incident is an absolute priority boundary: lower-severity
     * transient phases never cover it, even when real-alarm focus is disabled.
     *
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>|null
     */
    public function resolve(array $configuration): ?array
    {
        $focusConfiguration = (array) ($configuration['focus'] ?? []);
        $realIncident = $this->activeRealIncident();
        if ($realIncident instanceof Incident) {
            $settings = (array) ($focusConfiguration['real_alarm'] ?? []);
            if (($settings['enabled'] ?? false) !== true) {
                return null;
            }

            $candidate = $this->realAlarmCandidate($realIncident);

            return $candidate === null
                ? null
                : $this->payload($candidate, $settings, (array) ($configuration['pages'] ?? []));
        }

        $candidates = [];
        foreach (['preannouncement', 'test_alarm'] as $kind) {
            $settings = (array) ($focusConfiguration[$kind] ?? []);
            if (($settings['enabled'] ?? false) !== true) {
                continue;
            }

            $candidate = $kind === 'preannouncement'
                ? $this->preannouncementCandidate()
                : $this->testAlarmCandidate();
            if ($candidate === null || ! $this->isTransientCandidateCurrent($candidate, $settings)) {
                continue;
            }

            $candidates[] = $candidate;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            if (! $left['started_at']->equalTo($right['started_at'])) {
                return $left['started_at']->isAfter($right['started_at']) ? -1 : 1;
            }

            return strcmp((string) $right['dispatch']->id, (string) $left['dispatch']->id);
        });

        $candidate = $candidates[0];
        $settings = (array) ($focusConfiguration[$candidate['kind']] ?? []);

        return $this->payload($candidate, $settings, (array) ($configuration['pages'] ?? []));
    }

    private function activeRealIncident(): ?Incident
    {
        return Incident::query()
            ->whereIn('status', ['dispatching', 'in_progress'])
            ->where('is_test', false)
            ->orderByDesc('opened_at')
            ->orderByDesc('created_at')
            ->first([
                'id',
                'reference',
                'title',
                'status',
                'priority',
                'is_test',
                'location_label',
                'opened_at',
            ]);
    }

    /** @return array<string, mixed>|null */
    private function realAlarmCandidate(Incident $incident): ?array
    {
        $dispatch = DispatchRequest::query()
            ->where('incident_id', $incident->id)
            ->whereIn('status', ['sent', 'escalated'])
            ->whereNotNull('sent_at')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first(['id', 'incident_id', 'status', 'priority', 'sent_at']);

        return $dispatch instanceof DispatchRequest
            ? $this->candidate('real_alarm', $dispatch, $incident, $dispatch->sent_at)
            : null;
    }

    /** @return array<string, mixed>|null */
    private function preannouncementCandidate(): ?array
    {
        $dispatch = DispatchRequest::query()
            ->with('incident:id,reference,title,status,priority,is_test,location_label,opened_at')
            ->where('status', 'draft')
            ->whereNotNull('preannounced_at')
            ->whereHas('incident', static fn ($incident) => $incident
                ->where('status', 'active')
                ->where('is_test', false))
            ->orderByDesc('preannounced_at')
            ->orderByDesc('id')
            ->first(['id', 'incident_id', 'status', 'priority', 'preannounced_at']);

        return $dispatch instanceof DispatchRequest && $dispatch->incident instanceof Incident
            ? $this->candidate('preannouncement', $dispatch, $dispatch->incident, $dispatch->preannounced_at)
            : null;
    }

    /** @return array<string, mixed>|null */
    private function testAlarmCandidate(): ?array
    {
        $dispatch = DispatchRequest::query()
            ->with('incident:id,reference,title,status,priority,is_test,location_label,opened_at')
            ->whereIn('status', ['sent', 'escalated'])
            ->whereNotNull('sent_at')
            ->whereHas('incident', static fn ($incident) => $incident
                ->where('status', 'active')
                ->where('is_test', true))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first(['id', 'incident_id', 'status', 'priority', 'sent_at']);

        return $dispatch instanceof DispatchRequest && $dispatch->incident instanceof Incident
            ? $this->candidate('test_alarm', $dispatch, $dispatch->incident, $dispatch->sent_at)
            : null;
    }

    /**
     * @return array{kind: string, dispatch: DispatchRequest, incident: Incident, started_at: CarbonImmutable}|null
     */
    private function candidate(
        string $kind,
        DispatchRequest $dispatch,
        Incident $incident,
        mixed $startedAt,
    ): ?array {
        $startedAt = $startedAt instanceof \DateTimeInterface
            ? ApiDateTime::localWallClock($startedAt)
            : null;
        if (! $startedAt instanceof CarbonImmutable) {
            return null;
        }

        return [
            'kind' => $kind,
            'dispatch' => $dispatch,
            'incident' => $incident,
            'started_at' => $startedAt,
        ];
    }

    /**
     * @param  array{kind: string, dispatch: DispatchRequest, incident: Incident, started_at: CarbonImmutable}  $candidate
     * @param  array<string, mixed>  $settings
     */
    private function isTransientCandidateCurrent(array $candidate, array $settings): bool
    {
        $now = ApiDateTime::localWallClock(now());
        $expiresAt = $candidate['started_at']->addSeconds((int) ($settings['duration_seconds'] ?? 0));

        return $now instanceof CarbonImmutable
            && ! $candidate['started_at']->isAfter($now)
            && $expiresAt->isAfter($now);
    }

    /**
     * @param  array{kind: string, dispatch: DispatchRequest, incident: Incident, started_at: CarbonImmutable}  $candidate
     * @param  array<string, mixed>  $settings
     * @param  list<array<string, mixed>>  $pages
     * @return array<string, mixed>
     */
    private function payload(array $candidate, array $settings, array $pages): array
    {
        $kind = $candidate['kind'];
        $dispatch = $candidate['dispatch'];
        $incident = $candidate['incident'];
        $startedAt = $candidate['started_at'];
        $durationSeconds = (int) ($settings['duration_seconds'] ?? 0);
        $phase = $kind === 'preannouncement' ? 'preannouncement' : 'alarm';
        $dispatchIds = $this->phaseDispatchIds((string) $incident->id, $phase);
        $responseSummary = (($settings['show_response_feed'] ?? false) === true || $kind === 'preannouncement')
            ? $this->responses($dispatchIds)
            : null;
        $responses = ($settings['show_response_feed'] ?? false) === true
            ? $responseSummary
            : null;
        $pilotCounts = $kind === 'preannouncement'
            ? [
                // Dispatch recipients are the durable result of DispatchService's
                // existing eligibility and recipient-selection rules. Deriving
                // these counters from them avoids inventing a second wallboard-
                // specific interpretation of certifications or availability.
                'available' => (int) ($responseSummary['counts']['accepted'] ?? 0),
                'relevant' => (int) ($responseSummary['counts']['targeted'] ?? 0),
                'contacted' => (int) ($responseSummary['counts']['contacted'] ?? 0),
            ]
            : null;

        $expiresAt = null;
        $visible = true;
        $playlistPageId = null;
        $nextChangeAt = null;
        if ($kind === 'real_alarm') {
            [$visible, $playlistPageId, $nextChangeAt] = $this->realAlarmCycle(
                $startedAt,
                $durationSeconds,
                $pages,
            );
        } else {
            $expiresAt = $startedAt->addSeconds($durationSeconds);
            $nextChangeAt = $expiresAt;
        }

        return [
            'kind' => $kind,
            'focus_id' => hash('sha256', implode('|', [
                $kind,
                (string) $incident->id,
                $startedAt->format('Y-m-d H:i:s.u'),
            ])),
            'dispatch_id' => (string) $dispatch->id,
            'incident_id' => (string) $incident->id,
            'reference' => (string) $incident->reference,
            'title' => (string) $incident->title,
            'priority' => (string) ($incident->priority ?: $dispatch->priority),
            // A reachability test has no operational destination. Never let a
            // stale or synthetic incident location appear as a test-alarm route.
            'location_label' => $kind === 'test_alarm' ? null : $incident->location_label,
            'started_at' => ApiDateTime::dateTime($startedAt),
            'expires_at' => ApiDateTime::dateTime($expiresAt),
            'visible' => $visible,
            'playlist_page_id' => $playlistPageId,
            'next_change_at' => ApiDateTime::dateTime($nextChangeAt),
            'pilot_counts' => $pilotCounts,
            'responses' => $responses,
        ];
    }

    /** @return list<string> */
    private function phaseDispatchIds(string $incidentId, string $phase): array
    {
        return DispatchRequest::query()
            ->where('incident_id', $incidentId)
            ->when(
                $phase === 'preannouncement',
                static fn ($query) => $query->where('status', 'draft')->whereNotNull('preannounced_at'),
                static fn ($query) => $query->whereIn('status', ['sent', 'escalated'])->whereNotNull('sent_at'),
            )
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $dispatchIds
     * @return array{counts: array{targeted: int, contacted: int, pending: int, accepted: int, declined: int, no_response: int}, items: list<array{name: string, response_status: string, responded_at: string|null}>}
     */
    private function responses(array $dispatchIds): array
    {
        if ($dispatchIds === []) {
            return [
                'counts' => [
                    'targeted' => 0,
                    'contacted' => 0,
                    'pending' => 0,
                    'accepted' => 0,
                    'declined' => 0,
                    'no_response' => 0,
                ],
                'items' => [],
            ];
        }

        $recipients = DispatchRecipient::query()
            ->whereIn('dispatch_request_id', $dispatchIds)
            ->orderByDesc('updated_at')
            // PostgreSQL sorts NULL first for DESC. Prefer a real response when
            // duplicate team dispatches happen to share an update timestamp.
            ->orderByRaw('CASE WHEN responded_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('responded_at')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'user_name', 'response_status', 'notified_at', 'responded_at', 'updated_at'])
            ->unique(static fn (DispatchRecipient $recipient): string => $recipient->user_id === null
                ? 'deleted:'.(string) $recipient->id
                : 'user:'.(string) $recipient->user_id)
            ->values();
        $statusCounts = $recipients->countBy(
            static fn (DispatchRecipient $recipient): string => (string) $recipient->response_status,
        );

        $items = $recipients
            ->filter(static fn (DispatchRecipient $recipient): bool => in_array(
                (string) $recipient->response_status,
                ['accepted', 'declined', 'no_response'],
                true,
            ) && $recipient->responded_at !== null)
            ->sortBy([
                ['responded_at', 'desc'],
                ['user_name', 'asc'],
            ])
            ->take(self::RESPONSE_ITEM_LIMIT)
            ->map(static fn (DispatchRecipient $recipient): array => [
                'name' => (string) $recipient->user_name,
                'response_status' => (string) $recipient->response_status,
                'responded_at' => ApiDateTime::dateTime($recipient->responded_at),
            ])
            ->values()
            ->all();

        return [
            'counts' => [
                'targeted' => $recipients->count(),
                'contacted' => $recipients->whereNotNull('notified_at')->count(),
                'pending' => (int) $statusCounts->get('pending', 0),
                'accepted' => (int) $statusCounts->get('accepted', 0),
                'declined' => (int) $statusCounts->get('declined', 0),
                'no_response' => (int) $statusCounts->get('no_response', 0),
            ],
            'items' => $items,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $pages
     * @return array{bool, string|null, CarbonImmutable|null}
     */
    private function realAlarmCycle(CarbonImmutable $startedAt, int $focusDuration, array $pages): array
    {
        $now = ApiDateTime::localWallClock(now());
        if ($pages === []) {
            return [true, null, null];
        }
        if (! $now instanceof CarbonImmutable || $now->isBefore($startedAt)) {
            return [true, null, $startedAt->addSeconds($focusDuration)];
        }

        $pageDuration = array_sum(array_map(
            static fn (array $page): int => (int) ($page['duration_seconds'] ?? 0),
            $pages,
        ));
        $cycleDuration = $focusDuration + $pageDuration;
        if ($cycleDuration <= 0) {
            return [true, null, null];
        }

        $elapsedMicroseconds = max(0, $this->timestampMicroseconds($now) - $this->timestampMicroseconds($startedAt));
        $elapsedSeconds = intdiv($elapsedMicroseconds, 1_000_000);
        $cycleOffset = $elapsedSeconds % $cycleDuration;
        $cycleStartedAfter = $elapsedSeconds - $cycleOffset;
        if ($cycleOffset < $focusDuration) {
            return [
                true,
                null,
                $startedAt->addSeconds($cycleStartedAfter + $focusDuration),
            ];
        }

        $pageOffset = $cycleOffset - $focusDuration;
        $pageStartedAfter = 0;
        foreach ($pages as $page) {
            $duration = (int) ($page['duration_seconds'] ?? 0);
            if ($duration <= 0) {
                continue;
            }
            if ($pageOffset < $duration) {
                return [
                    false,
                    (string) ($page['id'] ?? ''),
                    $startedAt->addSeconds(
                        $cycleStartedAfter + $focusDuration + $pageStartedAfter + $duration,
                    ),
                ];
            }

            $pageOffset -= $duration;
            $pageStartedAfter += $duration;
        }

        return [true, null, $now->addSecond()];
    }

    private function timestampMicroseconds(CarbonImmutable $dateTime): int
    {
        return ((int) $dateTime->format('U') * 1_000_000) + (int) $dateTime->format('u');
    }
}
