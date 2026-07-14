<?php

use App\Models\Incident;
use App\Services\IncidentAccessService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('operations', fn ($user) => $user->hasPermission('incidents.view') || $user->hasPermission('incidents.dispatch.view') || $user->hasPermission('incidents.dispatch.manage') || $user->hasPermission('status.view'));

Broadcast::channel('admin.system', fn ($user) => $user->hasPermission('system.health.view'));

Broadcast::channel('incidents.{incidentId}', function ($user, string $incidentId): bool {
    $incident = Incident::query()->find($incidentId);

    return $incident !== null && app(IncidentAccessService::class)->canViewIncident($user, $incident);
});

Broadcast::channel('users.{userId}', fn ($user, string $userId) => (string) $user->id === $userId || $user->hasPermission('users.view'));
