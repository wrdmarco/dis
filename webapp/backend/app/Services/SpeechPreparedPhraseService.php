<?php

namespace App\Services;

use App\Exceptions\SpeechEngineException;
use App\Jobs\GenerateSpeechPreparedPhrase;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechPreparedPhrase;
use App\Models\SpeechVoiceProfile;
use App\Models\User;
use App\Repositories\SpeechPreparedPhraseRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class SpeechPreparedPhraseService
{
    private const MAX_SEARCH_CANDIDATES = 1000;

    public function __construct(
        private readonly SpeechPreparedPhraseRepository $repository,
        private readonly SpeechSettingsService $settings,
        private readonly SpeechAddressNormalizer $addresses,
        private readonly SpeechCacheKeyService $keys,
        private readonly SpeechAudioPipeline $audio,
        private readonly SpeechAudioAssetGarbageCollector $garbageCollector,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array{search?: string|null, kind?: string|null, status?: string|null, page?: int|null, per_page?: int|null}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $kind = $this->optionalString($filters['kind'] ?? null);
        $status = $this->optionalString($filters['status'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $search = mb_strtolower($this->optionalString($filters['search'] ?? null) ?? '');

        if ($search === '') {
            $paginator = $this->repository->paginateForManagement($kind, $status, $page, $perPage);
            /** @var EloquentCollection<int, SpeechPreparedPhrase> $entries */
            $entries = new EloquentCollection($paginator->items());
            $paginator->setCollection($entries->map(
                fn (SpeechPreparedPhrase $phrase): array => $this->payload($phrase),
            ));

            return $paginator;
        }

        if ($this->repository->searchExceedsLimit($kind, $status, self::MAX_SEARCH_CANDIDATES)) {
            throw ValidationException::withMessages([
                'search' => [
                    'Kies eerst een type of status; de doorzoekbare voorbereidingsbibliotheek is begrensd tot '
                    .self::MAX_SEARCH_CANDIDATES.' items.',
                ],
            ]);
        }

        $items = $this->repository
            ->entriesForBoundedSearch($kind, $status, self::MAX_SEARCH_CANDIDATES)
            ->filter(function (SpeechPreparedPhrase $phrase) use ($search): bool {
                $value = $this->displayText($phrase);

                return $value !== null && str_contains(mb_strtolower($value), $search);
            })
            ->map(fn (SpeechPreparedPhrase $phrase): array => $this->payload($phrase))
            ->values();
        $total = $items->count();

        return new LengthAwarePaginator(
            $items->slice(($page - 1) * $perPage, $perPage)->all(),
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $rawCounts = SpeechPreparedPhrase::query()
            ->select('kind', DB::raw('COUNT(*) AS aggregate'))
            ->groupBy('kind')
            ->pluck('aggregate', 'kind');
        $counts = [];
        foreach (SpeechPreparedPhrase::KINDS as $kind) {
            $counts[$kind] = (int) ($rawCounts[$kind] ?? 0);
        }

        $assetIds = SpeechCacheEntry::query()
            ->whereIn('id', SpeechPreparedPhrase::query()
                ->select('cache_entry_id')
                ->whereNotNull('cache_entry_id'))
            ->whereNotNull('audio_asset_id')
            ->distinct()
            ->pluck('audio_asset_id');

        return [
            'counts' => $counts,
            'total_count' => array_sum($counts),
            'ready_count' => SpeechPreparedPhrase::query()->where('status', 'ready')->count(),
            'pending_count' => SpeechPreparedPhrase::query()
                ->whereIn('status', ['queued', 'processing'])
                ->count(),
            'failed_count' => SpeechPreparedPhrase::query()->where('status', 'failed')->count(),
            'disk_bytes' => (int) SpeechAudioAsset::query()->whereIn('id', $assetIds)->sum('byte_size'),
        ];
    }

    /**
     * @param  list<string>  $values
     * @return list<array<string, mixed>>
     */
    public function create(string $kind, array $values, User $actor): array
    {
        $unique = [];
        foreach ($values as $value) {
            $canonical = $this->canonical($kind, $value);
            $unique[$canonical['identity_hmac']] = $canonical;
        }

        $created = 0;
        $jobIds = [];
        $phrases = DB::transaction(function () use (
            $kind,
            $unique,
            $actor,
            &$created,
            &$jobIds,
        ): array {
            $result = [];
            foreach ($unique as $canonical) {
                $phrase = SpeechPreparedPhrase::query()->firstOrCreate(
                    [
                        'kind' => $kind,
                        'identity_hmac' => $canonical['identity_hmac'],
                    ],
                    [
                        'display_text' => $canonical['display'],
                        'status' => 'queued',
                        'progress_percent' => 0,
                        'created_by' => $actor->id,
                    ],
                );
                $wasCreated = $phrase->wasRecentlyCreated;
                $phrase = SpeechPreparedPhrase::query()
                    ->whereKey($phrase->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($wasCreated) {
                    $created++;
                    $jobIds[] = (string) $phrase->id;
                } elseif ($this->shouldRetry($phrase)) {
                    $phrase->forceFill([
                        'status' => 'queued',
                        'progress_percent' => 0,
                        'error_code' => null,
                    ])->save();
                    $jobIds[] = (string) $phrase->id;
                }
                $result[] = $phrase;
            }

            $this->audit->record(
                'speech.preparation_created',
                'speech_prepared_phrases',
                $actor,
                [
                    'kind' => $kind,
                    'requested_count' => count($unique),
                    'created_count' => $created,
                    'queued_count' => count($jobIds),
                ],
            );
            foreach ($jobIds as $phraseId) {
                DB::afterCommit(fn () => GenerateSpeechPreparedPhrase::dispatch($phraseId));
            }

            return $result;
        });

        return collect($phrases)
            ->map(fn (SpeechPreparedPhrase $phrase): array => $this->payload(
                $phrase->refresh()->load(['cacheEntry.audioAsset', 'cacheEntry.voiceProfile']),
            ))
            ->values()
            ->all();
    }

    /**
     * Validate and normalize exact fixed speech phrases without storing them.
     *
     * @param  list<string>  $values
     * @return list<string>
     */
    public function normalizeFixedPhrases(array $values): array
    {
        $unique = [];
        foreach ($values as $value) {
            $canonical = $this->canonical('fixed_phrase', $value);
            $unique[$canonical['identity_hmac']] = $canonical['display'];
        }

        return array_values($unique);
    }

    public function prepare(string $phraseId, bool $forceRegeneration = false): void
    {
        $phrase = SpeechPreparedPhrase::query()->find($phraseId);
        if ($phrase === null || ! in_array($phrase->status, ['queued', 'processing', 'failed'], true)) {
            return;
        }

        $runtime = $this->settings->selectedRuntime();
        $spokenText = $this->spokenText($phrase);
        $fingerprint = $this->audio->segmentCacheKey(
            $spokenText,
            $runtime['model'],
            $runtime['voice'],
            $runtime['speed'],
            'segment',
        );
        $phrase->forceFill([
            'status' => 'processing',
            'progress_percent' => 10,
            'error_code' => null,
            'runtime_fingerprint_hmac' => $fingerprint,
        ])->save();

        $this->audio->segment(
            $spokenText,
            $runtime['model'],
            $runtime['voice'],
            $runtime['speed'],
            'segment',
            $forceRegeneration,
        );
        SpeechPreparedPhrase::query()->whereKey($phraseId)->update([
            'progress_percent' => 90,
            'updated_at' => now(),
        ]);

        $currentRuntime = $this->settings->selectedRuntime();
        $currentFingerprint = $this->audio->segmentCacheKey(
            $spokenText,
            $currentRuntime['model'],
            $currentRuntime['voice'],
            $currentRuntime['speed'],
            'segment',
        );
        if (! hash_equals($fingerprint, $currentFingerprint)) {
            $this->queueAgain($phraseId);

            return;
        }

        $cacheEntry = SpeechCacheEntry::query()
            ->where('cache_key', $fingerprint)
            ->where('category', 'segment')
            ->where('status', 'ready')
            ->first();
        if ($cacheEntry === null || $cacheEntry->audio_asset_id === null) {
            throw new \RuntimeException('Prepared speech cache entry was not published.');
        }

        DB::transaction(function () use ($phraseId, $fingerprint, $cacheEntry): void {
            $managed = SpeechPreparedPhrase::query()->whereKey($phraseId)->lockForUpdate()->first();
            if ($managed === null) {
                return;
            }
            $entry = SpeechCacheEntry::query()->whereKey($cacheEntry->id)->lockForUpdate()->first();
            if ($entry === null || $entry->status !== 'ready' || $entry->audio_asset_id === null) {
                throw new \RuntimeException('Prepared speech cache entry became unavailable.');
            }
            $oldCacheEntryId = $managed->cache_entry_id === null
                ? null
                : (string) $managed->cache_entry_id;
            $entry->forceFill([
                'is_pinned' => true,
                'pinned_at' => now(),
                'expires_at' => null,
            ])->save();
            $managed->forceFill([
                'status' => 'ready',
                'progress_percent' => 100,
                'error_code' => null,
                'cache_entry_id' => $entry->id,
                'runtime_fingerprint_hmac' => $fingerprint,
                'prepared_at' => now(),
            ])->save();
            if ($oldCacheEntryId !== null && $oldCacheEntryId !== (string) $entry->id) {
                $this->releasePinWhenUnused($oldCacheEntryId);
            }
        });
    }

    public function fail(string $phraseId, string $errorCode): void
    {
        SpeechPreparedPhrase::query()
            ->whereKey($phraseId)
            ->whereIn('status', ['queued', 'processing'])
            ->update([
                'status' => 'failed',
                'progress_percent' => 0,
                'error_code' => $this->safeErrorCode($errorCode),
                'updated_at' => now(),
            ]);
    }

    public function requeueAll(): int
    {
        $ids = SpeechPreparedPhrase::query()->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
        if ($ids === []) {
            return 0;
        }
        DB::transaction(function () use ($ids): void {
            SpeechPreparedPhrase::query()->whereIn('id', $ids)->update([
                'status' => 'queued',
                'progress_percent' => 0,
                'error_code' => null,
                'updated_at' => now(),
            ]);
            foreach ($ids as $id) {
                DB::afterCommit(fn () => GenerateSpeechPreparedPhrase::dispatch($id));
            }
        });

        return count($ids);
    }

    /** @return array<string, mixed> */
    public function regenerate(SpeechPreparedPhrase $phrase, User $actor): array
    {
        $managed = DB::transaction(function () use ($phrase, $actor): SpeechPreparedPhrase {
            $managed = SpeechPreparedPhrase::query()
                ->whereKey($phrase->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (in_array($managed->status, ['queued', 'processing'], true)) {
                return $managed;
            }
            $managed->forceFill([
                'status' => 'queued',
                'progress_percent' => 0,
                'error_code' => null,
            ])->save();
            $this->audit->record(
                'speech.preparation_regeneration_requested',
                $managed,
                $actor,
                ['kind' => (string) $managed->kind],
            );
            DB::afterCommit(fn () => GenerateSpeechPreparedPhrase::dispatch(
                (string) $managed->id,
                true,
            ));

            return $managed;
        });

        return $this->payload(
            $managed->refresh()->load(['cacheEntry.audioAsset', 'cacheEntry.voiceProfile']),
        );
    }

    public function requeueStale(): int
    {
        try {
            $runtime = $this->settings->selectedRuntime();
        } catch (Throwable) {
            return 0;
        }
        $stale = [];
        SpeechPreparedPhrase::query()
            ->with('cacheEntry')
            ->whereNotIn('status', ['queued', 'processing'])
            ->orderBy('id')
            ->chunkById(100, function ($phrases) use ($runtime, &$stale): void {
                foreach ($phrases as $phrase) {
                    $expected = $this->audio->segmentCacheKey(
                        $this->spokenText($phrase),
                        $runtime['model'],
                        $runtime['voice'],
                        $runtime['speed'],
                        'segment',
                    );
                    $linked = $phrase->cacheEntry;
                    if ($phrase->runtime_fingerprint_hmac === null
                        || ! hash_equals((string) $phrase->runtime_fingerprint_hmac, $expected)
                        || $linked === null
                        || $linked->status !== 'ready'
                        || ! (bool) $linked->is_pinned) {
                        $stale[] = (string) $phrase->id;
                    }
                }
            });
        if ($stale === []) {
            return 0;
        }
        DB::transaction(function () use ($stale): void {
            SpeechPreparedPhrase::query()->whereIn('id', $stale)->update([
                'status' => 'queued',
                'progress_percent' => 0,
                'error_code' => null,
                'updated_at' => now(),
            ]);
            foreach ($stale as $id) {
                DB::afterCommit(fn () => GenerateSpeechPreparedPhrase::dispatch($id));
            }
        });

        return count($stale);
    }

    public function delete(SpeechPreparedPhrase $phrase, User $actor): void
    {
        DB::transaction(function () use ($phrase, $actor): void {
            $managed = SpeechPreparedPhrase::query()->whereKey($phrase->id)->lockForUpdate()->firstOrFail();
            $cacheEntryId = $managed->cache_entry_id === null ? null : (string) $managed->cache_entry_id;
            $kind = (string) $managed->kind;
            $this->audit->record(
                'speech.preparation_deleted',
                $managed,
                $actor,
                ['kind' => $kind],
            );
            $managed->delete();
            if ($cacheEntryId !== null) {
                $this->deleteUnreferencedPreparedCacheEntry($cacheEntryId);
            }
        });
    }

    /** @return array{deleted_count:int,cache_entries_removed:int} */
    public function clear(User $actor): array
    {
        $removedCacheEntries = 0;
        $deleted = DB::transaction(function () use (
            $actor,
            &$removedCacheEntries,
        ): int {
            $phrases = SpeechPreparedPhrase::query()->lockForUpdate()->get();
            $count = $phrases->count();
            $cacheEntryIds = $phrases->pluck('cache_entry_id')
                ->filter()
                ->map(fn (mixed $id): string => (string) $id)
                ->unique()
                ->values()
                ->all();
            $kindCounts = $phrases->countBy('kind')->all();
            SpeechPreparedPhrase::query()->whereIn('id', $phrases->pluck('id'))->delete();
            foreach ($cacheEntryIds as $cacheEntryId) {
                if ($this->deleteUnreferencedPreparedCacheEntry($cacheEntryId)) {
                    $removedCacheEntries++;
                }
            }
            $this->audit->record(
                'speech.preparation_cache_cleared',
                'speech_prepared_phrases',
                $actor,
                [
                    'deleted_count' => $count,
                    'cache_entries_removed' => $removedCacheEntries,
                    'kind_counts' => $kindCounts,
                ],
            );

            return $count;
        });

        return [
            'deleted_count' => $deleted,
            'cache_entries_removed' => $removedCacheEntries,
        ];
    }

    /** @return array{path:string,etag:string} */
    public function audio(SpeechPreparedPhrase $phrase): array
    {
        $managed = $this->repository->forAudio((string) $phrase->id);
        if ($managed === null) {
            throw new NotFoundHttpException;
        }
        $entry = $managed->cacheEntry;
        if ($entry === null || $entry->status !== 'ready' || ! $entry->is_pinned
            || $entry->audioAsset === null || $entry->audioAsset->mime_type !== 'audio/mp4') {
            throw new GoneHttpException('Prepared speech audio is unavailable.');
        }
        if ($entry->voice_profile_id !== null
            && (! $entry->voiceProfile instanceof SpeechVoiceProfile
                || $entry->voiceProfile->status !== 'ready')) {
            throw new GoneHttpException('Prepared speech voice was revoked.');
        }
        try {
            $path = $this->audio->verifiedAssetPath($entry->audioAsset);
        } catch (Throwable $exception) {
            throw new GoneHttpException('Prepared speech audio is unavailable.', $exception);
        }
        $etag = $this->keys->key('prepared-phrase-audio-etag', [
            'content_sha256' => (string) $entry->audioAsset->content_sha256,
        ]);

        return [
            'path' => $path,
            'etag' => '"speech-preparation-'.$etag.'"',
        ];
    }

    /** @return array<string, mixed> */
    public function payload(SpeechPreparedPhrase $phrase): array
    {
        $entry = $phrase->cacheEntry;
        $audioAvailable = $entry !== null
            && $entry->status === 'ready'
            && (bool) $entry->is_pinned
            && $entry->audioAsset !== null;

        return [
            'id' => (string) $phrase->id,
            'kind' => (string) $phrase->kind,
            'value' => $this->displayText($phrase),
            'status' => in_array($phrase->status, SpeechPreparedPhrase::STATUSES, true)
                ? (string) $phrase->status
                : 'failed',
            'progress_percent' => max(0, min(100, (int) $phrase->progress_percent)),
            'error_code' => $phrase->status === 'failed'
                ? $this->safeErrorCode((string) $phrase->error_code)
                : null,
            'byte_size' => $entry?->audioAsset === null
                ? null
                : max(0, (int) $entry->audioAsset->byte_size),
            'duration_ms' => $entry?->audioAsset === null
                ? null
                : max(0, (int) $entry->audioAsset->duration_ms),
            'audio_url' => $audioAvailable
                ? '/api/admin/speech/preparations/'.(string) $phrase->id.'/audio'
                : null,
            'created_at' => $phrase->created_at?->toIso8601String(),
            'updated_at' => $phrase->updated_at?->toIso8601String(),
            'prepared_at' => $phrase->prepared_at?->toIso8601String(),
        ];
    }

    private function shouldRetry(SpeechPreparedPhrase $phrase): bool
    {
        if ($phrase->status === 'failed') {
            return true;
        }

        return $phrase->status === 'ready'
            && ($phrase->cache_entry_id === null
                || ! SpeechCacheEntry::query()
                    ->whereKey($phrase->cache_entry_id)
                    ->where('status', 'ready')
                    ->where('is_pinned', true)
                    ->exists());
    }

    private function queueAgain(string $phraseId): void
    {
        DB::transaction(function () use ($phraseId): void {
            $updated = SpeechPreparedPhrase::query()->whereKey($phraseId)->update([
                'status' => 'queued',
                'progress_percent' => 0,
                'error_code' => null,
                'updated_at' => now(),
            ]);
            if ($updated === 1) {
                DB::afterCommit(fn () => GenerateSpeechPreparedPhrase::dispatch($phraseId));
            }
        });
    }

    private function releasePinWhenUnused(string $cacheEntryId): void
    {
        if (SpeechPreparedPhrase::query()->where('cache_entry_id', $cacheEntryId)->exists()) {
            return;
        }
        SpeechCacheEntry::query()->whereKey($cacheEntryId)->update([
            'is_pinned' => false,
            'pinned_at' => null,
            'expires_at' => now()->addDays((int) config('dis.speech.segment_retention_days', 30)),
            'updated_at' => now(),
        ]);
    }

    private function deleteUnreferencedPreparedCacheEntry(string $cacheEntryId): bool
    {
        if (SpeechPreparedPhrase::query()->where('cache_entry_id', $cacheEntryId)->exists()) {
            return false;
        }
        $entry = SpeechCacheEntry::query()->whereKey($cacheEntryId)->lockForUpdate()->first();
        if ($entry === null || ! $entry->is_pinned) {
            return false;
        }
        $assetId = $entry->audio_asset_id === null ? null : (string) $entry->audio_asset_id;
        $entry->delete();
        if ($assetId !== null) {
            $this->garbageCollector->markIfUnreferenced($assetId);
        }

        return true;
    }

    /**
     * @return array{display:string,spoken:string,identity_hmac:string}
     */
    private function canonical(string $kind, string $value): array
    {
        if (! in_array($kind, SpeechPreparedPhrase::KINDS, true)) {
            throw ValidationException::withMessages([
                'kind' => ['Het gekozen type spraakvoorbereiding is ongeldig.'],
            ]);
        }
        $display = trim($value);
        if ($display === '' || mb_strlen($display) > 240
            || preg_match('/[\x00-\x1F\x7F]/u', $display) === 1) {
            throw ValidationException::withMessages([
                'values' => ['Een spraakvoorbereiding moet veilige tekst van maximaal 240 tekens bevatten.'],
            ]);
        }
        if (str_contains($display, '<') || str_contains($display, '>')
            || preg_match('/&(?:#[0-9]+|#x[a-f0-9]+|[a-z][a-z0-9]+);/iu', $display) === 1) {
            throw ValidationException::withMessages([
                'values' => ['Markup en tekentiteiten zijn niet toegestaan.'],
            ]);
        }
        if ($kind === 'fixed_phrase' && (str_contains($display, '{') || str_contains($display, '}'))) {
            throw ValidationException::withMessages([
                'values' => ['Een vaste zin mag geen templatevariabelen bevatten.'],
            ]);
        }
        if ($kind === 'postcode') {
            $canonical = $this->addresses->displayPostcode($display);
            if (preg_match('/^[1-9][0-9]{3} [A-Z]{2}$/D', $canonical) !== 1) {
                throw ValidationException::withMessages([
                    'values' => ['Vul een geldige Nederlandse postcode in.'],
                ]);
            }
            $display = $canonical;
        }
        $spoken = $kind === 'postcode' ? $this->addresses->postcode($display) : $display;

        return [
            'display' => $display,
            'spoken' => $spoken,
            'identity_hmac' => $this->keys->key('prepared-phrase-identity', [
                'kind' => $kind,
                'value' => $display,
            ]),
        ];
    }

    private function spokenText(SpeechPreparedPhrase $phrase): string
    {
        $display = $this->displayText($phrase);
        if ($display === null) {
            throw new \RuntimeException('Prepared speech text could not be decrypted.');
        }

        return $phrase->kind === 'postcode' ? $this->addresses->postcode($display) : $display;
    }

    private function displayText(SpeechPreparedPhrase $phrase): ?string
    {
        try {
            return $this->optionalString($phrase->display_text);
        } catch (Throwable) {
            return null;
        }
    }

    private function safeErrorCode(string $errorCode): string
    {
        return preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) === 1
            ? $errorCode
            : 'prepared_phrase_generation_failed';
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    public function errorCode(Throwable $exception): string
    {
        if ($exception instanceof SpeechEngineException) {
            return $this->safeErrorCode($exception->errorCode);
        }
        if ($exception instanceof ValidationException) {
            return 'speech_configuration_missing';
        }

        return 'prepared_phrase_generation_failed';
    }
}
