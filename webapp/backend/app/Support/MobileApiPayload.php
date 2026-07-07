<?php

namespace App\Support;

use App\Models\AppVersion;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AvailabilityStatus;
use App\Models\AuditLog;
use App\Models\Certification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\DroneType;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\PilotIncidentReport;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCertification;
use App\Services\TwoFactorService;
use App\Support\ProfileLocation;
use DateTimeInterface;

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

        $user->loadMissing([
            'roles.permissions',
            'teams',
            'fcmTokens' => fn ($tokens) => $tokens
                ->where('client_type', 'operator')
                ->where('is_active', true)
                ->latest('last_seen_at'),
        ]);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'phone_number' => $user->phone_number,
            'home_city' => $user->home_city,
            'home_region' => $user->home_region,
            'home_country' => $user->home_country,
            'home_latitude' => $user->home_latitude,
            'home_longitude' => $user->home_longitude,
            'account_status' => $user->account_status,
            'last_login_at' => self::dateTime($user->last_login_at),
            'failed_login_attempts' => (int) ($user->failed_login_attempts ?? 0),
            'login_locked_until' => self::dateTime($user->login_locked_until),
            'push_enabled' => (bool) $user->push_enabled,
            'max_operator_devices' => (int) ($user->max_operator_devices ?? 1),
            'home_geocoded_at' => self::dateTime($user->home_geocoded_at),
            'home_geocode_source' => $user->home_geocode_source,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
            'mfa_required' => SystemSetting::boolean(TwoFactorService::REQUIRED_KEY, TwoFactorService::DEFAULT_REQUIRED),
            'profile_completion_required' => self::profileCompletionMissingFields($user) !== [],
            'missing_profile_fields' => self::profileCompletionMissingFields($user),
            'mail_preferences' => is_array($user->mail_preferences) ? $user->mail_preferences : null,
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
            'statuses' => $user->relationLoaded('statuses')
                ? $user->statuses->map(fn (AvailabilityStatus $status): array => self::statusSummary($status))->values()
                : [],
            'certifications' => $user->relationLoaded('certifications')
                ? $user->certifications->map(fn (UserCertification $certification): array => self::userCertification($certification))->values()
                : [],
            'asset_assignments' => $user->relationLoaded('assetAssignments')
                ? $user->assetAssignments->map(fn (AssetAssignment $assignment): array => self::assetAssignment($assignment))->values()
                : [],
            'fcm_tokens' => $user->fcmTokens->map(fn (FcmToken $token): array => self::fcmToken($token))->values(),
        ];
    }

    /**
     * @return list<string>
     */
    private static function profileCompletionMissingFields(User $user): array
    {
        $missing = [];
        if (trim((string) $user->first_name) === '') {
            $missing[] = 'first_name';
        }
        if (trim((string) $user->last_name) === '') {
            $missing[] = 'last_name';
        }
        if (! PhoneNumber::looksInternational($user->phone_number)) {
            $missing[] = 'phone_number';
        }
        if (trim((string) $user->home_country) === '') {
            $missing[] = 'home_country';
        }
        if (trim((string) $user->home_city) === '') {
            $missing[] = 'home_city';
        }
        if (ProfileLocation::regionsFor($user->home_country) !== [] && trim((string) $user->home_region) === '') {
            $missing[] = 'home_region';
        }

        return $missing;
    }

    /**
     * @return array<string, mixed>
     */
    public static function fcmToken(FcmToken $token): array
    {
        return [
            'id' => $token->id,
            'user_id' => $token->user_id,
            'device_id' => $token->device_id,
            'device_type' => $token->device_type,
            'device_name' => $token->device_name,
            'device_manufacturer' => $token->device_manufacturer,
            'device_model' => $token->device_model,
            'android_version' => $token->android_version,
            'sdk_version' => $token->sdk_version,
            'platform' => $token->platform,
            'client_type' => $token->client_type,
            'app_version' => $token->app_version,
            'is_active' => (bool) $token->is_active,
            'is_online' => (bool) $token->is_online,
            'last_seen_at' => self::dateTime($token->last_seen_at),
            'revoked_at' => self::dateTime($token->revoked_at),
            'token_preview' => self::tokenPreview((string) $token->token),
            'token_hash' => $token->token_hash ?? hash('sha256', (string) $token->token),
            'personal_access_token_id' => $token->personal_access_token_id,
            'user' => $token->relationLoaded('user') ? self::user($token->user) : null,
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
    public static function status(AvailabilityStatus $status, ?array $nextAvailabilityChange = null, ?array $nextAvailableAt = null): array
    {
        $offline = self::statusUserIsOffline($status);

        return [
            'id' => $status->id,
            'user_id' => $status->user_id,
            'status' => $offline ? 'unavailable' : $status->status,
            'is_available' => $offline ? false : (bool) $status->is_available,
            'is_system_applied' => (bool) $status->is_system_applied,
            'reason' => $offline ? 'Offline: geen online operator-device.' : self::statusReason($status),
            'effective_at' => self::dateTime($status->effective_at),
            'next_availability_change' => $nextAvailabilityChange,
            'next_available_at' => $nextAvailableAt,
            'user' => $status->relationLoaded('user') ? self::user($status->user) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function statusSummary(AvailabilityStatus $status): array
    {
        return [
            'id' => $status->id,
            'user_id' => $status->user_id,
            'status' => $status->status,
            'is_available' => (bool) $status->is_available,
            'effective_at' => self::dateTime($status->effective_at),
        ];
    }

    private static function statusReason(AvailabilityStatus $status): ?string
    {
        $reason = trim((string) $status->reason);
        if ($reason === '') {
            return null;
        }

        if ((bool) $status->is_system_applied && str_contains(mb_strtolower($reason), 'beschikbaarheidspatroon')) {
            return null;
        }

        return $reason;
    }

    private static function statusUserIsOffline(AvailabilityStatus $status): bool
    {
        if (! $status->relationLoaded('user') || $status->user === null) {
            return false;
        }

        $status->user->loadMissing([
            'fcmTokens' => fn ($tokens) => $tokens
                ->where('client_type', 'operator')
                ->where('is_active', true)
                ->latest('last_seen_at'),
        ]);

        return ! $status->user->fcmTokens->contains(fn ($token): bool => (bool) $token->is_online);
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
            'reporter_name' => $incident->reporter_name,
            'reporter_phone' => $incident->reporter_phone,
            'requesting_organization' => $incident->requesting_organization,
            'requesting_unit' => $incident->requesting_unit,
            'on_scene_contact_name' => $incident->on_scene_contact_name,
            'on_scene_contact_phone' => $incident->on_scene_contact_phone,
            'on_scene_contact_role' => $incident->on_scene_contact_role,
            'required_resources' => $incident->required_resources,
            'custom_fields' => $incident->custom_fields ?? [],
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
            'opened_at' => self::dateTime($incident->opened_at),
            'closed_at' => self::dateTime($incident->closed_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pilotIncidentReport(PilotIncidentReport $report): array
    {
        return [
            'id' => $report->id,
            'incident_id' => $report->incident_id,
            'user_id' => $report->user_id,
            'user_name' => $report->user_name,
            'status' => $report->status,
            'summary' => $report->summary,
            'observations' => $report->observations,
            'actions_taken' => $report->actions_taken,
            'result' => $report->result,
            'issues' => $report->issues,
            'equipment_used' => $report->equipment_used,
            'flight_minutes' => $report->flight_minutes,
            'custom_fields' => $report->custom_fields ?? [],
            'prepared_at' => self::dateTime($report->prepared_at),
            'submitted_at' => self::dateTime($report->submitted_at),
            'finalized_at' => self::dateTime($report->finalized_at),
            'can_edit' => $report->canBeEdited(),
            'updated_at' => self::dateTime($report->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dispatch(DispatchRequest $dispatch): array
    {
        return [
            'id' => $dispatch->id,
            'incident_id' => $dispatch->incident_id,
            'target_team_id' => $dispatch->target_team_id,
            'status' => $dispatch->status,
            'action_mode' => $dispatch->status === 'draft' ? 'availability' : 'attendance',
            'priority' => $dispatch->priority,
            'message' => $dispatch->message,
            'sent_at' => self::dateTime($dispatch->sent_at),
            'created_at' => self::dateTime($dispatch->created_at),
            'incident' => $dispatch->relationLoaded('incident') && $dispatch->incident !== null ? self::incident($dispatch->incident) : null,
            'target_team' => $dispatch->relationLoaded('targetTeam') && $dispatch->targetTeam !== null ? [
                'id' => $dispatch->targetTeam->id,
                'code' => $dispatch->targetTeam->code,
                'name' => $dispatch->targetTeam->name,
                'type' => $dispatch->targetTeam->type,
            ] : null,
            'recipients' => $dispatch->relationLoaded('recipients')
                ? $dispatch->recipients->map(fn (DispatchRecipient $recipient): array => self::dispatchRecipient($recipient))->values()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dispatchRecipient(DispatchRecipient $recipient): array
    {
        return [
            'id' => $recipient->id,
            'user_id' => $recipient->user_id,
            'response_status' => $recipient->response_status,
            'response_note' => $recipient->response_note,
            'notified_at' => self::dateTime($recipient->notified_at),
            'responded_at' => self::dateTime($recipient->responded_at),
            'user' => $recipient->relationLoaded('user') ? self::user($recipient->user) : null,
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
                ...self::assetAssignment($activeAssignment),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function assetAssignment(AssetAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'asset_id' => $assignment->asset_id,
            'incident_id' => $assignment->incident_id,
            'user_id' => $assignment->user_id,
            'assigned_by' => $assignment->assigned_by,
            'assigned_at' => self::dateTime($assignment->assigned_at),
            'released_at' => self::dateTime($assignment->released_at),
            'asset' => $assignment->relationLoaded('asset') && $assignment->asset !== null ? self::asset($assignment->asset) : null,
            'user' => $assignment->relationLoaded('user') && $assignment->user !== null ? self::user($assignment->user) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function droneType(DroneType $droneType): array
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
                'user' => self::user($userCertification->user),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function userCertification(UserCertification $certification): array
    {
        return [
            'id' => $certification->id,
            'user_id' => $certification->user_id,
            'certification_id' => $certification->certification_id,
            'issued_at' => $certification->issued_at?->toDateString(),
            'expires_at' => $certification->expires_at?->toDateString(),
            'certificate_number' => $certification->certificate_number,
            'status' => $certification->status,
            'verified_by' => $certification->verified_by,
            'verified_at' => self::dateTime($certification->verified_at),
            'certification' => $certification->relationLoaded('certification') && $certification->certification !== null
                ? self::certificationSummary($certification->certification)
                : null,
            'user' => $certification->relationLoaded('user') && $certification->user !== null ? self::user($certification->user) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function certificationSummary(Certification $certification): array
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

    /**
     * @return array<string, mixed>
     */
    public static function auditLog(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'actor_id' => $log->actor_id,
            'actor_name' => $log->actor_name,
            'actor_email' => $log->actor_email,
            'target_type' => $log->target_type,
            'target_id' => $log->target_id,
            'target_name' => $log->target_name,
            'ip_address' => $log->ip_address,
            'metadata' => $log->metadata,
            'reason' => $log->reason,
            'created_at' => self::dateTime($log->created_at),
        ];
    }

    public static function dateTime(?DateTimeInterface $value): ?string
    {
        return ApiDateTime::dateTime($value);
    }

    private static function tokenPreview(string $token): string
    {
        return strlen($token) <= 18 ? str_repeat('*', strlen($token)) : substr($token, 0, 6).'...'.substr($token, -8);
    }
}
