<?php

use App\Models\Incident;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('operations', fn ($user) => $user->hasPermission('incidents.view') || $user->hasPermission('incidents.dispatch.view') || $user->hasPermission('status.view'));

Broadcast::channel('admin.system', fn ($user) => $user->hasPermission('system.health'));

Broadcast::channel('incidents.{incidentId}', function ($user, string $incidentId): bool {
    return Incident::query()->whereKey($incidentId)->exists() && $user->hasPermission('incidents.view');
});

Broadcast::channel('users.{userId}', fn ($user, string $userId) => (string) $user->id === $userId || $user->hasPermission('users.view'));
