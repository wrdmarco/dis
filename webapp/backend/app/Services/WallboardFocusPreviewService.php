<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\User;
use App\Models\Wallboard;
use App\Repositories\WallboardRepository;
use App\Support\ApiDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

final class WallboardFocusPreviewService
{
    public const DURATION_SECONDS = 30;

    private const CACHE_VERSION = 1;

    /** @var list<string> */
    private const KINDS = ['preannouncement', 'test_alarm', 'real_alarm'];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly WallboardRepository $wallboards,
        private readonly AuditService $auditService,
    ) {}

    /**
     * Start a short-lived, wallboard-scoped focus preview. The preview lives
     * only in cache and never creates or changes incidents or dispatches.
     *
     * @return array<string, mixed>
     */
    public function start(
        Wallboard $wallboard,
        string $kind,
        int $expectedControlVersion,
        User $actor,
        Request $request,
    ): array {
        if (! in_array($kind, self::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => ['Dit focusscherm wordt niet ondersteund.'],
            ]);
        }

        $cacheKey = $this->cacheKey((string) $wallboard->getKey());
        $cacheWritten = false;

        try {
            return DB::transaction(function () use (
                $wallboard,
                $kind,
                $expectedControlVersion,
                $actor,
                $request,
                $cacheKey,
                &$cacheWritten,
            ): array {
                $locked = $this->wallboards->lockWallboard((string) $wallboard->getKey());
                if (! $locked->is_enabled) {
                    throw ValidationException::withMessages([
                        'wallboard' => ['Een uitgeschakeld wallboard kan geen focustest tonen.'],
                    ]);
                }
                if ($expectedControlVersion !== (int) $locked->control_version) {
                    throw new ConflictHttpException('Wallboard control changed.');
                }
                if ($this->hasActiveRealAlarm()) {
                    throw ValidationException::withMessages([
                        'wallboard' => ['Een focustest kan geen actief echt alarm bedekken.'],
                    ]);
                }

                $startedAt = CarbonImmutable::now((string) config('app.timezone', 'Europe/Amsterdam'));
                $expiresAt = $startedAt->addSeconds(self::DURATION_SECONDS);
                $focus = $this->mockFocus($locked, $kind, $startedAt, $expiresAt);
                $this->cache->put($cacheKey, [
                    'version' => self::CACHE_VERSION,
                    'wallboard_id' => (string) $locked->id,
                    'expires_at_epoch' => $expiresAt->getTimestamp(),
                    'focus' => $focus,
                ], $expiresAt);
                $cacheWritten = true;

                $previousControlVersion = (int) $locked->control_version;
                $locked->forceFill([
                    'control_version' => $previousControlVersion + 1,
                    'updated_by' => $actor->id,
                ])->save();

                $this->auditService->record('wallboards.focus_preview_started', $locked, $actor, [
                    'kind' => $kind,
                    'duration_seconds' => self::DURATION_SECONDS,
                    'focus_id' => $focus['focus_id'],
                    'previous_control_version' => $previousControlVersion,
                    'control_version' => (int) $locked->control_version,
                    'expires_at' => $focus['expires_at'],
                ], null, $request);

                return [
                    'wallboard_id' => (string) $locked->id,
                    'kind' => $kind,
                    'focus_id' => $focus['focus_id'],
                    'is_preview' => true,
                    'started_at' => $focus['started_at'],
                    'expires_at' => $focus['expires_at'],
                    'duration_seconds' => self::DURATION_SECONDS,
                    'control_version' => (int) $locked->control_version,
                ];
            }, 3);
        } catch (Throwable $exception) {
            // Cache is outside the database transaction. If a later database or
            // audit write fails, fail closed by restoring the playlist early.
            if ($cacheWritten) {
                try {
                    $this->cache->forget($cacheKey);
                } catch (Throwable) {
                    // The entry is still bounded by its short TTL.
                }
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed>|null */
    public function current(Wallboard $wallboard): ?array
    {
        try {
            $cached = $this->cache->get($this->cacheKey((string) $wallboard->getKey()));
        } catch (Throwable) {
            // A preview must never make a wallboard unavailable. Cache failure
            // restores normal, server-authoritative focus and playlist state.
            return null;
        }

        if (! is_array($cached)
            || ($cached['version'] ?? null) !== self::CACHE_VERSION
            || ($cached['wallboard_id'] ?? null) !== (string) $wallboard->getKey()
            || ! is_int($cached['expires_at_epoch'] ?? null)
            || ! is_array($cached['focus'] ?? null)) {
            return null;
        }

        if ($cached['expires_at_epoch'] <= now()->getTimestamp()) {
            try {
                $this->cache->forget($this->cacheKey((string) $wallboard->getKey()));
            } catch (Throwable) {
                // Expiry is also checked above, so a failed cleanup is harmless.
            }

            return null;
        }

        $focus = $cached['focus'];
        if (($focus['is_preview'] ?? null) !== true
            || ! in_array($focus['kind'] ?? null, self::KINDS, true)
            || ! is_string($focus['focus_id'] ?? null)) {
            return null;
        }

        return $focus;
    }

    private function hasActiveRealAlarm(): bool
    {
        return Incident::query()
            ->whereIn('status', ['dispatching', 'in_progress'])
            ->where('is_test', false)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function mockFocus(
        Wallboard $wallboard,
        string $kind,
        CarbonImmutable $startedAt,
        CarbonImmutable $expiresAt,
    ): array {
        $identity = implode('|', [
            'wallboard-focus-preview-v1',
            (string) $wallboard->id,
            $kind,
            $startedAt->format('Y-m-d H:i:s.u'),
        ]);
        $responses = $this->mockResponses($kind, $startedAt);

        return [
            'kind' => $kind,
            'focus_id' => 'preview-'.hash('sha256', $identity),
            'dispatch_id' => 'preview-dispatch-'.$kind,
            'incident_id' => 'preview-incident-'.$kind,
            'reference' => match ($kind) {
                'preannouncement' => 'VOORBEELD-VOORALARM',
                'test_alarm' => 'VOORBEELD-PROEFALARM',
                default => 'VOORBEELD-ECHT-ALARM',
            },
            'title' => match ($kind) {
                'preannouncement' => 'Voorbeeld vooralarm — geen echte inzet',
                'test_alarm' => 'Voorbeeld proefalarm — geen echte inzet',
                default => 'Voorbeeld echt alarm — geen echte inzet',
            },
            'priority' => $kind === 'real_alarm' ? 'high' : 'normal',
            'location_label' => $kind === 'test_alarm' ? null : 'Demolocatie — geen echte inzet',
            'started_at' => ApiDateTime::dateTime($startedAt),
            'expires_at' => ApiDateTime::dateTime($expiresAt),
            'visible' => true,
            'playlist_page_id' => null,
            'next_change_at' => ApiDateTime::dateTime($expiresAt),
            'pilot_counts' => $kind === 'preannouncement' ? [
                'available' => 7,
                'relevant' => 12,
                'contacted' => 12,
            ] : null,
            'responses' => $responses,
            'is_preview' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function mockResponses(string $kind, CarbonImmutable $startedAt): array
    {
        if ($kind === 'preannouncement') {
            return [
                'counts' => [
                    'targeted' => 12,
                    'contacted' => 12,
                    'pending' => 3,
                    'accepted' => 7,
                    'declined' => 1,
                    'no_response' => 1,
                ],
                'items' => [
                    $this->mockResponseItem('Oefenpiloot Alfa', 'accepted', $startedAt->subSeconds(4)),
                    $this->mockResponseItem('Oefenpiloot Bravo', 'declined', $startedAt->subSeconds(7)),
                    $this->mockResponseItem('Oefenpiloot Charlie', 'accepted', $startedAt->subSeconds(9)),
                ],
                'coming' => [],
            ];
        }

        if ($kind === 'test_alarm') {
            return [
                'counts' => [
                    'targeted' => 18,
                    'contacted' => 18,
                    'pending' => 3,
                    'accepted' => 15,
                    'declined' => 0,
                    'no_response' => 0,
                ],
                'items' => [
                    $this->mockResponseItem('Oefenpiloot Alfa', 'accepted', $startedAt->subSeconds(3)),
                    $this->mockResponseItem('Oefenpiloot Bravo', 'accepted', $startedAt->subSeconds(6)),
                    $this->mockResponseItem('Oefenpiloot Charlie', 'accepted', $startedAt->subSeconds(8)),
                ],
                'coming' => [],
            ];
        }

        $coming = [
            $this->mockComingItem('Oefenpiloot Alfa', $startedAt->subSeconds(3), 6, 'navigation'),
            $this->mockComingItem('Oefenpiloot Bravo', $startedAt->subSeconds(6), 11, 'navigation'),
            $this->mockComingItem('Oefenpiloot Charlie', $startedAt->subSeconds(8), 17, 'fallback'),
            $this->mockComingItem('Oefenpiloot Delta', $startedAt->subSeconds(10), 24, 'fallback'),
            $this->mockComingItem('Oefenpiloot Echo', $startedAt->subSeconds(12), null, null),
        ];

        return [
            'counts' => [
                'targeted' => 12,
                'contacted' => 12,
                'pending' => 4,
                'accepted' => 5,
                'declined' => 2,
                'no_response' => 1,
            ],
            'items' => [
                ...array_map(static fn (array $item): array => [
                    'name' => $item['name'],
                    'response_status' => $item['response_status'],
                    'responded_at' => $item['responded_at'],
                ], $coming),
                $this->mockResponseItem('Oefenpiloot Foxtrot', 'declined', $startedAt->subSeconds(14)),
                $this->mockResponseItem('Oefenpiloot Golf', 'no_response', $startedAt->subSeconds(16)),
            ],
            'coming' => $coming,
        ];
    }

    /** @return array{name: string, response_status: string, responded_at: string|null} */
    private function mockResponseItem(string $name, string $status, ?CarbonImmutable $respondedAt): array
    {
        return [
            'name' => $name,
            'response_status' => $status,
            'responded_at' => ApiDateTime::dateTime($respondedAt),
        ];
    }

    /** @return array{name: string, response_status: string, responded_at: string|null, eta_minutes: int|null, eta_source: string|null} */
    private function mockComingItem(
        string $name,
        CarbonImmutable $respondedAt,
        ?int $etaMinutes,
        ?string $etaSource,
    ): array {
        return [
            ...$this->mockResponseItem($name, 'accepted', $respondedAt),
            'eta_minutes' => $etaMinutes,
            'eta_source' => $etaSource,
        ];
    }

    private function cacheKey(string $wallboardId): string
    {
        return 'wallboard:focus-preview:v1:'.hash('sha256', $wallboardId);
    }
}
