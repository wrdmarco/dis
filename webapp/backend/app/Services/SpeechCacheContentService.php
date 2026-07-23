<?php

namespace App\Services;

use App\Models\SpeechCacheEntry;
use App\Models\SpeechVoiceProfile;
use App\Repositories\SpeechCacheContentRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SpeechCacheContentService
{
    private const MAX_SEARCH_CANDIDATES = 500;

    public function __construct(
        private readonly SpeechCacheContentRepository $repository,
        private readonly SpeechAudioPipeline $audio,
        private readonly SpeechCacheKeyService $keys,
    ) {}

    /**
     * @param  array{search?: string|null, category?: string|null, status?: string|null, page?: int|null, per_page?: int|null}  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $category = $this->string($filters['category'] ?? null);
        $status = $this->string($filters['status'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 25)));
        $search = mb_strtolower($this->string($filters['search'] ?? null) ?? '');

        if ($search === '') {
            $paginator = $this->repository->paginateForManagement($category, $status, $page, $perPage);
            /** @var EloquentCollection<int, SpeechCacheEntry> $entries */
            $entries = new EloquentCollection($paginator->items());
            $legacy = $this->repository->legacyContexts($entries);
            $paginator->setCollection($entries->map(fn (SpeechCacheEntry $entry): array => $this->payload(
                $entry,
                $legacy[(string) $entry->id] ?? [],
            )));

            return $paginator;
        }

        if ($this->repository->searchExceedsLimit(
            $category,
            $status,
            self::MAX_SEARCH_CANDIDATES,
        )) {
            throw ValidationException::withMessages([
                'search' => [
                    'Kies eerst een categorie of status; doorzoekbare cache-inhoud is begrensd tot '
                    .self::MAX_SEARCH_CANDIDATES.' items.',
                ],
            ]);
        }
        $entries = $this->repository->entriesForBoundedSearch(
            $category,
            $status,
            self::MAX_SEARCH_CANDIDATES,
        );
        $legacy = $this->repository->legacyContexts($entries);
        $items = $entries
            ->map(fn (SpeechCacheEntry $entry): array => $this->payload(
                $entry,
                $legacy[(string) $entry->id] ?? [],
            ));
        $items = $items->filter(fn (array $item): bool => str_contains(
            mb_strtolower(implode("\n", array_filter([
                $item['text'],
                $item['category'],
                $item['status'],
                $item['locale'],
                $item['model_id'],
                $item['model_name'],
                $item['model_revision'],
                $item['voice_name'],
                $item['voice_revision'],
                $item['audio_recipe_revision'],
            ], static fn (mixed $value): bool => is_string($value) && $value !== ''))),
            $search,
        ));
        $total = $items->count();

        return new LengthAwarePaginator(
            $items->values()->slice(($page - 1) * $perPage, $perPage)->all(),
            $total,
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query()],
        );
    }

    /**
     * @return array{path: string, etag: string}
     */
    public function audio(SpeechCacheEntry $entry): array
    {
        $managed = $this->repository->entryForAudio((string) $entry->id);
        if ($managed === null) {
            throw new NotFoundHttpException;
        }
        if ($managed->expires_at?->isPast() === true) {
            throw new GoneHttpException('Speech cache entry expired.');
        }
        if ($managed->status !== 'ready' || $managed->audioAsset === null
            || $managed->audioAsset->mime_type !== 'audio/mp4') {
            throw new GoneHttpException('Speech cache audio is unavailable.');
        }
        if ($managed->voice_profile_id !== null
            && (! $managed->voiceProfile instanceof SpeechVoiceProfile
                || $managed->voiceProfile->status !== 'ready')) {
            throw new GoneHttpException('Speech cache voice was revoked.');
        }

        try {
            $path = $this->audio->verifiedAssetPath($managed->audioAsset);
        } catch (\Throwable $exception) {
            throw new GoneHttpException('Speech cache audio is unavailable.', $exception);
        }
        $etag = $this->keys->key('management-audio-etag', [
            'content_sha256' => (string) $managed->audioAsset->content_sha256,
        ]);

        return [
            'path' => $path,
            'etag' => '"speech-cache-'.$etag.'"',
        ];
    }

    /**
     * @param  array<string, mixed>  $legacy
     * @return array<string, mixed>
     */
    private function payload(SpeechCacheEntry $entry, array $legacy): array
    {
        $cachedText = $this->encryptedText($entry);
        $text = $cachedText ?? $this->string($legacy['text'] ?? null);
        $textSource = $text === null
            ? null
            : ($cachedText === null
                ? $this->string($legacy['text_source'] ?? null)
                : 'cache');
        $modelId = $this->string($entry->model_catalog_key)
            ?? $this->string($legacy['model_catalog_key'] ?? null);
        $modelRevision = $this->string($entry->model_revision)
            ?? $this->string($legacy['model_revision'] ?? null);
        $voice = $entry->voiceProfile instanceof SpeechVoiceProfile
            ? $entry->voiceProfile
            : (($legacy['voice_profile'] ?? null) instanceof SpeechVoiceProfile
                ? $legacy['voice_profile']
                : null);
        $voiceDesignRevision = $this->string($entry->voice_design_revision)
            ?? $this->string($legacy['voice_design_revision'] ?? null);
        $expired = $entry->expires_at?->isPast() === true;
        $status = $expired ? 'expired' : (
            in_array($entry->status, ['queued', 'processing', 'ready', 'failed'], true)
                ? (string) $entry->status
                : 'failed'
        );
        $audioAvailable = $status === 'ready'
            && $entry->audioAsset !== null
            && $entry->audioAsset->mime_type === 'audio/mp4'
            && ($entry->voice_profile_id === null
                || ($voice instanceof SpeechVoiceProfile && $voice->status === 'ready'));
        $voiceType = $voice !== null ? 'profile' : ($voiceDesignRevision !== null ? 'built_in' : null);
        $voiceName = $voice?->name
            ?? ($voiceDesignRevision !== null ? 'Ingebouwde serverstem' : null);
        $voiceRevision = $voice !== null
            ? 'consent-v'.max(1, (int) $voice->consent_version)
            : $voiceDesignRevision;
        $configuredModel = $modelId === null ? null : config('dis.speech.models.'.$modelId);
        $modelName = is_array($configuredModel) && is_string($configuredModel['name'] ?? null)
            ? $configuredModel['name']
            : null;

        return [
            'id' => (string) $entry->id,
            'text' => $text,
            'text_available' => $text !== null,
            'text_source' => $textSource,
            'category' => (string) $entry->category,
            'status' => $status,
            'error_code' => $status === 'failed' ? $this->string($entry->error_code) : null,
            'model_id' => $modelId,
            'model_name' => $modelName,
            'model_revision' => $modelRevision,
            'voice_type' => $voiceType,
            'voice_name' => $voiceName,
            'voice_revision' => $voiceRevision,
            'audio_recipe_revision' => $this->string($entry->audio_recipe_revision)
                ?? $this->string($legacy['audio_recipe_revision'] ?? null),
            'speed' => $this->speed($entry->speed ?? ($legacy['speed'] ?? null)),
            'locale' => $this->string($entry->locale)
                ?? $this->string($legacy['locale'] ?? null),
            'hit_count' => max(0, (int) $entry->hit_count),
            'byte_size' => $entry->audioAsset === null ? null : max(0, (int) $entry->audioAsset->byte_size),
            'duration_ms' => $entry->audioAsset === null ? null : max(0, (int) $entry->audioAsset->duration_ms),
            'audio_available' => $audioAvailable,
            'audio_url' => $audioAvailable
                ? '/api/admin/speech/cache/entries/'.(string) $entry->id.'/audio'
                : null,
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
            'last_used_at' => $entry->last_used_at?->toIso8601String(),
            'expires_at' => $entry->expires_at?->toIso8601String(),
        ];
    }

    private function encryptedText(SpeechCacheEntry $entry): ?string
    {
        try {
            return $this->string($entry->display_text);
        } catch (\Throwable) {
            return null;
        }
    }

    private function speed(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }
        $speed = round((float) $value, 2);

        return $speed >= 0.5 && $speed <= 2.0 ? $speed : null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
