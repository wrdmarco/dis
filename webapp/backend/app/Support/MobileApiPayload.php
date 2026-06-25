<?php

namespace App\Support;

use App\Models\AppVersion;
use App\Models\Asset;
use App\Models\AvailabilityStatus;
use App\Models\Certification;
use App\Models\Incident;
use App\Models\User;

final class MobileApiPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public static function user(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $user->loadMissing(['roles', 'teams']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'account_status' => $user->account_status,
            'push_enabled' => (bool) $user->push_enabled,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'roles' => $user->roles->map(fn ($role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values(),
            'teams' => $user->teams->map(fn ($team): array => [
                'id' => $team->id,
                'code' => $team->code,
                'name' => $team->name,
                'type' => $team->type,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function appVersion(?AppVersion $version): ?array
    {
        if ($version === null) {
            return null;
        }

        return [
            'id' => $version->id,
            'platform' => $version->platform,
            'version_name' => $version->version_name,
            'version_code' => (int) $version->version_code,
            'status' => $version->status,
            'download_url' => $version->download_url,
            'artifact_sha256' => $version->artifact_sha256,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function status(AvailabilityStatus $status): array
    {
        return [
            'id' => $status->id,
            'user_id' => $status->user_id,
            'status' => $status->status,
            'is_available' => (bool) $status->is_available,
            'effective_at' => $status->effective_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function incident(Incident $incident): array
    {
        $incident->loadMissing(['coordinator', 'team']);

        return [
            'id' => $incident->id,
            'reference' => $incident->reference,
            'title' => $incident->title,
            'description' => $incident->description,
            'priority' => $incident->priority,
            'status' => $incident->status,
            'is_test' => (bool) $incident->is_test,
            'location_label' => $incident->location_label,
            'latitude' => $incident->latitude,
            'longitude' => $incident->longitude,
            'coordinator' => self::user($incident->coordinator),
            'team' => $incident->team === null ? null : [
                'id' => $incident->team->id,
                'code' => $incident->team->code,
                'name' => $incident->team->name,
                'type' => $incident->team->type,
            ],
            'opened_at' => $incident->opened_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        return [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'name' => $asset->name,
            'type' => $asset->type,
            'status' => $asset->status,
            'serial_number' => $asset->serial_number,
            'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
            'notes' => $asset->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function certification(Certification $certification): array
    {
        return [
            'id' => $certification->id,
            'code' => $certification->code,
            'name' => $certification->name,
            'description' => $certification->description,
            'is_required_for_dispatch' => (bool) $certification->is_required_for_dispatch,
            'warning_days_before_expiry' => (int) $certification->warning_days_before_expiry,
        ];
    }
}
