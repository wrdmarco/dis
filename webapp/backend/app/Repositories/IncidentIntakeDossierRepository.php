<?php

namespace App\Repositories;

use App\Models\IncidentIntakeDossier;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class IncidentIntakeDossierRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return IncidentIntakeDossier::class;
    }

    /** @param list<string> $statuses */
    public function search(array $statuses, int $perPage): LengthAwarePaginator
    {
        return IncidentIntakeDossier::query()
            ->with(['workflowRevision', 'incident'])
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->latest('updated_at')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function lock(string $id): IncidentIntakeDossier
    {
        return IncidentIntakeDossier::query()
            ->with('workflowRevision')
            ->lockForUpdate()
            ->findOrFail($id);
    }

    public function forIncident(string $incidentId): ?IncidentIntakeDossier
    {
        return IncidentIntakeDossier::query()
            ->with('workflowRevision')
            ->where('incident_id', $incidentId)
            ->first();
    }

    public function lockForIncident(string $incidentId): ?IncidentIntakeDossier
    {
        return IncidentIntakeDossier::query()
            ->with('workflowRevision')
            ->where('incident_id', $incidentId)
            ->lockForUpdate()
            ->first();
    }
}
