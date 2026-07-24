<?php

namespace App\Repositories;

use App\Models\SpeechPreparedPhrase;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class SpeechPreparedPhraseRepository
{
    public function paginateForManagement(
        ?string $kind,
        ?string $status,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->query($kind, $status)->paginate($perPage, ['*'], 'page', $page);
    }

    public function searchExceedsLimit(?string $kind, ?string $status, int $limit): bool
    {
        return $this->query($kind, $status, false)
            ->reorder('id')
            ->offset($limit)
            ->limit(1)
            ->value('id') !== null;
    }

    /** @return Collection<int, SpeechPreparedPhrase> */
    public function entriesForBoundedSearch(?string $kind, ?string $status, int $limit): Collection
    {
        return $this->query($kind, $status)->limit($limit)->get();
    }

    public function forAudio(string $id): ?SpeechPreparedPhrase
    {
        return SpeechPreparedPhrase::query()
            ->with(['cacheEntry.audioAsset', 'cacheEntry.voiceProfile'])
            ->find($id);
    }

    /** @return Builder<SpeechPreparedPhrase> */
    private function query(?string $kind, ?string $status, bool $withRelations = true): Builder
    {
        $query = SpeechPreparedPhrase::query()
            ->select([
                'id',
                'kind',
                'display_text',
                'status',
                'progress_percent',
                'error_code',
                'cache_entry_id',
                'prepared_at',
                'created_at',
                'updated_at',
            ])
            ->when($kind !== null, fn (Builder $builder) => $builder->where('kind', $kind))
            ->when($status !== null, fn (Builder $builder) => $builder->where('status', $status))
            ->orderByDesc('created_at')
            ->orderBy('id');

        if ($withRelations) {
            $query->with([
                'cacheEntry:id,audio_asset_id,voice_profile_id,status,is_pinned',
                'cacheEntry.audioAsset:id,mime_type,byte_size,duration_ms',
                'cacheEntry.voiceProfile:id,status',
            ]);
        }

        return $query;
    }
}
