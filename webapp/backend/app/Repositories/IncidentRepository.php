<?php

namespace App\Repositories;

use App\Models\Incident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IncidentRepository extends BaseRepository
{
    /** @var array<int, string> */
    private const ALLOWED_STATUSES = [
        'draft',
        'active',
        'dispatching',
        'in_progress',
        'resolved',
        'cancelled',
    ];

    protected function modelClass(): string
    {
        return Incident::class;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function search(array $filters, int $perPage): LengthAwarePaginator
    {
        $statuses = $this->statusFilter($filters['status'] ?? null);

        return Incident::query()
            ->with(['coordinator', 'team', 'teams'])
            ->where('is_test', false)
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->latest()
            ->paginate(min(max($perPage, 1), 100));
    }

    /**
     * @return array<int, string>
     */
    private function statusFilter(mixed $status): array
    {
        if (! is_string($status) || trim($status) === '') {
            return [];
        }

        return collect(explode(',', $status))
            ->map(fn (string $item): string => trim($item))
            ->filter(fn (string $item): bool => in_array($item, self::ALLOWED_STATUSES, true))
            ->unique()
            ->values()
            ->all();
    }
}
