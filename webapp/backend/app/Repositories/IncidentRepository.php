<?php

namespace App\Repositories;

use App\Models\Incident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IncidentRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return Incident::class;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function search(array $filters, int $perPage): LengthAwarePaginator
    {
        return Incident::query()
            ->with(['coordinator'])
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->latest()
            ->paginate(min(max($perPage, 1), 100));
    }
}

