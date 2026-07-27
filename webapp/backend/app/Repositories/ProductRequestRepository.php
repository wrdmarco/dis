<?php

namespace App\Repositories;

use App\Models\ProductRequest;
use App\Models\ProductRequestStatusHistory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends BaseRepository<ProductRequest>
 */
final class ProductRequestRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return ProductRequest::class;
    }

    /**
     * @param  list<string>  $statuses
     * @param  list<string>  $types
     */
    public function search(
        array $statuses,
        array $types,
        ?string $requesterId,
        ?string $search,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->query()
            ->with('resolver:id,name')
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->when($types !== [], fn (Builder $query) => $query->whereIn('type', $types))
            ->when($requesterId !== null, fn (Builder $query) => $query->where('requester_id', $requesterId))
            ->when($search !== null, function (Builder $query) use ($search): void {
                $escaped = str_replace(
                    ['!', '%', '_'],
                    ['!!', '!%', '!_'],
                    mb_strtolower($search),
                );
                $pattern = '%'.$escaped.'%';
                $query->where(function (Builder $searchQuery) use ($pattern): void {
                    $searchQuery
                        ->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(description) LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("LOWER(requester_name_snapshot) LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    /** @param array<string, mixed> $attributes */
    public function createRequest(array $attributes): ProductRequest
    {
        return ProductRequest::query()->create($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function createStatusHistory(array $attributes): ProductRequestStatusHistory
    {
        return ProductRequestStatusHistory::query()->create($attributes);
    }

    public function lock(string $id): ProductRequest
    {
        return ProductRequest::query()
            ->lockForUpdate()
            ->findOrFail($id);
    }

    public function forPresentation(string $id, bool $withHistory = false): ProductRequest
    {
        return ProductRequest::query()
            ->with('resolver:id,name')
            ->when($withHistory, fn (Builder $query) => $query->with('statusHistories'))
            ->findOrFail($id);
    }
}
