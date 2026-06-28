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

        $user->loadMissing(['roles.permissions', 'teams']);

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
                'can_use_operator_app' => (bool) $role->can_use_operator_app,
                'can_use_admin_app' => (bool) $role->can_use_admin_app,
                'permissions' => $role->permissions->map(fn ($permission): array => [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'category' => $permission->category,
                    'display_name' => $permission->display_name,
                ])->values(),
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
            'application_id' => $version->application_id,
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
            'user' => $status->relationLoaded('user') && $status->user !== null ? [
                'id' => $status->user->id,
                'name' => $status->user->name,
                'email' => $status->user->email,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function incident(Incident $incident): array
    {
        $incident->loadMissing(['coordinator', 'team', 'teams']);

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
            'drone_flight_context' => $incident->drone_flight_context,
            'coordinator' => self::user($incident->coordinator),
            'team' => $incident->team === null ? null : [
                'id' => $incident->team->id,
                'code' => $incident->team->code,
                'name' => $incident->team->name,
                'type' => $incident->team->type,
            ],
            'teams' => $incident->teams->map(fn ($team): array => [
                'id' => $team->id,
                'code' => $team->code,
                'name' => $team->name,
                'type' => $team->type,
            ])->values(),
            'opened_at' => $incident->opened_at?->toIso8601String(),
            'closed_at' => $incident->closed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function asset(Asset $asset): array
    {
        $asset->loadMissing(['droneType', 'activeAssignment.user']);
        $activeAssignment = $asset->activeAssignment;

        return [
            'id' => $asset->id,
            'asset_tag' => $asset->asset_tag,
            'name' => $asset->name,
            'type' => $asset->type,
            'drone_type_id' => $asset->drone_type_id,
            'drone_type' => $asset->droneType === null ? null : self::droneType($asset->droneType),
            'has_spotlight' => (bool) $asset->has_spotlight,
            'has_speaker' => (bool) $asset->has_speaker,
            'status' => $asset->status,
            'serial_number' => $asset->serial_number,
            'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
            'notes' => $asset->notes,
            'active_assignment' => $activeAssignment === null ? null : [
                'id' => $activeAssignment->id,
                'asset_id' => $activeAssignment->asset_id,
                'user_id' => $activeAssignment->user_id,
                'assigned_at' => $activeAssignment->assigned_at?->toIso8601String(),
                'released_at' => $activeAssignment->released_at?->toIso8601String(),
                'user' => $activeAssignment->user === null ? null : [
                    'id' => $activeAssignment->user->id,
                    'name' => $activeAssignment->user->name,
                    'email' => $activeAssignment->user->email,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function droneType(\App\Models\DroneType $droneType): array
    {
        return [
            'id' => $droneType->id,
            'manufacturer' => $droneType->manufacturer,
            'model' => $droneType->model,
            'has_thermal' => (bool) $droneType->has_thermal,
            'has_spotlight' => (bool) $droneType->has_spotlight,
            'has_speaker' => (bool) $droneType->has_speaker,
            'is_active' => (bool) $droneType->is_active,
            'notes' => $droneType->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function certification(Certification $certification): array
    {
        $certification->loadMissing('userCertifications.user');

        return [
            'id' => $certification->id,
            'code' => $certification->code,
            'name' => $certification->name,
            'description' => $certification->description,
            'is_required_for_dispatch' => (bool) $certification->is_required_for_dispatch,
            'warning_days_before_expiry' => (int) $certification->warning_days_before_expiry,
            'user_certifications' => $certification->userCertifications->map(fn ($userCertification): array => [
                'id' => $userCertification->id,
                'user_id' => $userCertification->user_id,
                'certification_id' => $userCertification->certification_id,
                'issued_at' => $userCertification->issued_at?->toDateString(),
                'expires_at' => $userCertification->expires_at?->toDateString(),
                'certificate_number' => $userCertification->certificate_number,
                'status' => $userCertification->status,
                'user' => $userCertification->user === null ? null : [
                    'id' => $userCertification->user->id,
                    'name' => $userCertification->user->name,
                    'email' => $userCertification->user->email,
                ],
            ])->values(),
        ];
    }
}
