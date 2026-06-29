<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Certification;
use App\Models\Permission;
use App\Models\PushDeliveryLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class AdminController extends Controller
{
    private const SENSITIVE_SETTING_KEYS = [
        'drone.aeret_api_key',
        'mail.password',
        'mail.microsoft365_client_secret',
        'firebase.service_account',
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public function roles(): JsonResponse
    {
        return ApiResponse::success(Role::query()->with('permissions')->withCount('users')->orderBy('name')->get());
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:roles,name'],
            'display_name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_two_factor' => ['required', 'boolean'],
            'can_use_operator_app' => ['required', 'boolean'],
            'can_use_admin_app' => ['required', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['ulid', 'exists:permissions,id'],
        ]);
        $permissionIds = $data['permission_ids'] ?? [];
        unset($data['permission_ids']);

        $role = Role::query()->create($data);
        $role->permissions()->sync(array_values(array_unique(is_array($permissionIds) ? $permissionIds : [])));
        $this->auditService->record('admin.role_created', $role, $request->user(), ['permission_ids' => $permissionIds]);

        return ApiResponse::success($role->load('permissions'), 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        if ($role->isSystemAdministrator()) {
            return ApiResponse::error('role_protected', 'De system administrator rol mag niet worden aangepast.', 409);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120', Rule::unique('roles', 'name')->ignore($role->id)],
            'display_name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_two_factor' => ['sometimes', 'boolean'],
            'can_use_operator_app' => ['sometimes', 'boolean'],
            'can_use_admin_app' => ['sometimes', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['ulid', 'exists:permissions,id'],
        ]);
        $permissionIds = $data['permission_ids'] ?? null;
        unset($data['permission_ids']);

        $before = $role->only(array_keys($data));
        $role->update($data);
        if (is_array($permissionIds)) {
            $role->permissions()->sync(array_values(array_unique($permissionIds)));
        }
        $this->auditService->record('admin.role_updated', $role, $request->user(), [
            'before' => $before,
            'after' => $role->only(array_keys($data)),
            'permission_ids' => $permissionIds,
        ]);

        return ApiResponse::success($role->refresh()->load('permissions'));
    }

    public function destroyRole(Request $request, Role $role): JsonResponse
    {
        if ($role->isSystemAdministrator()) {
            return ApiResponse::error('role_protected', 'De system administrator rol mag niet worden verwijderd.', 409);
        }

        $userCount = $role->users()->count();
        if ($userCount > 0) {
            return ApiResponse::error('role_in_use', 'Deze rol is nog gekoppeld aan gebruikers.', 409, ['users_count' => $userCount]);
        }

        $this->auditService->record('admin.role_deleted', $role, $request->user(), [
            'name' => $role->name,
            'display_name' => $role->display_name,
        ]);
        $role->permissions()->detach();
        $role->delete();

        return ApiResponse::success(null);
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(Permission::query()->orderBy('category')->orderBy('name')->get());
    }

    public function teams(): JsonResponse
    {
        return ApiResponse::success(Team::query()->with(['parent', 'alertTeams', 'requiredCertifications'])->orderBy('code')->get());
    }

    public function teamCertificationOptions(): JsonResponse
    {
        return ApiResponse::success(Certification::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'description', 'is_required_for_dispatch', 'warning_days_before_expiry']));
    }

    public function storeTeam(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:teams,code'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:base,subset,support'],
            'parent_team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'is_operational' => ['required', 'boolean'],
            'alert_team_ids' => ['nullable', 'array'],
            'alert_team_ids.*' => ['ulid', 'exists:teams,id'],
            'required_certification_ids' => ['nullable', 'array'],
            'required_certification_ids.*' => ['ulid', 'exists:certifications,id'],
        ]);
        $alertTeamIds = $data['alert_team_ids'] ?? [];
        $requiredCertificationIds = $data['required_certification_ids'] ?? [];
        unset($data['alert_team_ids']);
        unset($data['required_certification_ids']);

        $team = Team::query()->create($data);
        $team->alertTeams()->sync(array_values(array_unique(is_array($alertTeamIds) ? $alertTeamIds : [])));
        $team->requiredCertifications()->sync(array_values(array_unique(is_array($requiredCertificationIds) ? $requiredCertificationIds : [])));
        $this->auditService->record('admin.team_created', $team, $request->user(), [
            'alert_team_ids' => $alertTeamIds,
            'required_certification_ids' => $requiredCertificationIds,
        ]);

        return ApiResponse::success($team->load(['parent', 'alertTeams', 'requiredCertifications']), 201);
    }

    public function updateTeam(Request $request, Team $team): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('teams', 'code')->ignore($team->id)],
            'name' => ['sometimes', 'string', 'max:160'],
            'type' => ['sometimes', 'in:base,subset,support'],
            'parent_team_id' => ['nullable', 'ulid', 'exists:teams,id', Rule::notIn([$team->id])],
            'is_operational' => ['sometimes', 'boolean'],
            'alert_team_ids' => ['nullable', 'array'],
            'alert_team_ids.*' => ['ulid', 'exists:teams,id', Rule::notIn([$team->id])],
            'required_certification_ids' => ['nullable', 'array'],
            'required_certification_ids.*' => ['ulid', 'exists:certifications,id'],
        ]);
        $alertTeamIds = $data['alert_team_ids'] ?? null;
        $requiredCertificationIds = $data['required_certification_ids'] ?? null;
        unset($data['alert_team_ids']);
        unset($data['required_certification_ids']);

        $before = $team->only(array_keys($data));
        $team->update($data);
        if (is_array($alertTeamIds)) {
            $team->alertTeams()->sync(array_values(array_unique($alertTeamIds)));
        }
        if (is_array($requiredCertificationIds)) {
            $team->requiredCertifications()->sync(array_values(array_unique($requiredCertificationIds)));
        }
        $this->auditService->record('admin.team_updated', $team, $request->user(), [
            'before' => $before,
            'after' => $team->only(array_keys($data)),
            'alert_team_ids' => $alertTeamIds,
            'required_certification_ids' => $requiredCertificationIds,
        ]);

        return ApiResponse::success($team->refresh()->load(['parent', 'alertTeams', 'requiredCertifications']));
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'ulid', 'exists:users,id'],
            'action' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        $query = AuditLog::query();

        if (is_string($filters['user_id'] ?? null)) {
            $userId = $filters['user_id'];
            $query->where(function ($inner) use ($userId): void {
                $inner->where('actor_id', $userId)
                    ->orWhere(function ($target) use ($userId): void {
                        $target->where('target_type', User::class)->where('target_id', $userId);
                    });
            });
        }
        if (is_string($filters['action'] ?? null) && trim($filters['action']) !== '') {
            $query->where('action', 'like', '%'.trim($filters['action']).'%');
        }
        if (is_string($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (is_string($filters['to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $paginator = $query->latest('created_at')->paginate((int) ($filters['per_page'] ?? 100));
        $userIds = collect($paginator->items())
            ->flatMap(fn (AuditLog $log): array => array_filter([
                (string) $log->actor_id,
                $log->target_type === User::class ? (string) $log->target_id : null,
            ]))
            ->unique()
            ->values();
        $users = User::query()->whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        return ApiResponse::paginated($paginator, function (AuditLog $log) use ($users): array {
            $actor = is_string($log->actor_id) ? $users->get($log->actor_id) : null;
            $targetUser = $log->target_type === User::class && is_string($log->target_id) ? $users->get($log->target_id) : null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $actor === null ? null : [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ],
                'target_type' => $this->shortTargetType($log->target_type),
                'target_id' => $log->target_id,
                'target_user' => $targetUser === null ? null : [
                    'id' => $targetUser->id,
                    'name' => $targetUser->name,
                    'email' => $targetUser->email,
                ],
                'ip_address' => $log->ip_address,
                'metadata' => is_array($log->metadata) ? $log->metadata : [],
                'reason' => $log->reason,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        });
    }

    public function auditUsers(): JsonResponse
    {
        return ApiResponse::success(User::query()
            ->orderBy('name')
            ->get(['id', 'name', 'email']));
    }

    public function settings(): JsonResponse
    {
        $settings = SystemSetting::query()
            ->orderBy('key')
            ->get()
            ->map(function (SystemSetting $setting): array {
                return [
                    'key' => $setting->key,
                    'value' => $this->publicSettingValue($setting),
                    'is_sensitive' => $setting->is_sensitive,
                ];
            });

        return ApiResponse::success($settings);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);
        foreach ($data['settings'] as $key => $value) {
            $settingKey = (string) $key;
            $value = $this->validateSettingValue($settingKey, $value);

            if (in_array($settingKey, self::SENSITIVE_SETTING_KEYS, true) && ($value === null || $value === '')) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => $settingKey],
                [
                    'value' => $value,
                    'is_sensitive' => in_array($settingKey, self::SENSITIVE_SETTING_KEYS, true),
                    'updated_by' => $request->user()?->id,
                ],
            );
        }
        $this->auditService->record('admin.settings_updated', SystemSetting::class, $request->user(), ['keys' => array_keys($data['settings'])]);

        return $this->settings();
    }

    public function testMail(Request $request): JsonResponse
    {
        $recipient = $request->user()?->email;
        if (! is_string($recipient) || $recipient === '') {
            throw ValidationException::withMessages(['email' => ['Geen ontvanger beschikbaar voor de testmail.']]);
        }

        try {
            Mail::raw(
                'Dit is een testmail vanuit D.I.S. Als je deze mail ontvangt, is de mailconfiguratie correct.',
                fn ($message) => $message
                    ->to($recipient)
                    ->subject('D.I.S testmail'),
            );
        } catch (Throwable $exception) {
            throw ValidationException::withMessages([
                'mail' => [$this->mailFailureMessage($exception)],
            ]);
        }

        $this->auditService->record('admin.mail_test_sent', SystemSetting::class, $request->user(), ['recipient' => $recipient]);

        return ApiResponse::success(['recipient' => $recipient]);
    }

    private function validateSettingValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'mail.mailer' => $this->validateStringIn($key, $value, ['smtp', 'microsoft365', 'log']),
            'mail.host',
            'mail.username',
            'mail.password',
            'mail.from_name' => $this->validateStringSetting($key, $value, 255),
            'mail.template.welcome_subject',
            'mail.template.certification_expiry_subject',
            'mail.template.asset_expiry_subject' => $this->validateStringSetting($key, $value, 160),
            'mail.template.welcome_body',
            'mail.template.certification_expiry_body',
            'mail.template.asset_expiry_body' => $this->validateStringSetting($key, $value, 4000),
            'mail.port' => $this->validateIntegerSetting($key, $value, 1, 65535),
            'mail.encryption' => $this->validateStringIn($key, $value, ['', 'tls', 'ssl']),
            'mail.from_address' => $this->validateEmailSetting($key, $value),
            'mail.microsoft365_tenant_id',
            'mail.microsoft365_client_id' => $this->validateStringSetting($key, $value, 255),
            'mail.microsoft365_sender' => $this->validateEmailSetting($key, $value),
            'mail.microsoft365_client_secret' => $this->validateStringSetting($key, $value, 2000),
            'firebase.project_id' => $this->validateStringSetting($key, $value, 160),
            'firebase.service_account' => $this->validateFirebaseServiceAccount($key, $value),
            'mobile.firebase_config' => $this->validateMobileFirebaseConfig($key, $value),
            'drone.aeret_map_url',
            'drone.aeret_api_url',
            'drone.notam_url' => $this->validateNullableUrlSetting($key, $value, 2048),
            'drone.aeret_api_key' => $this->validateStringSetting($key, $value, 2000),
            'retention.push_logs_days',
            'retention.audit_logs_days',
            'retention.location_days' => $this->validateIntegerSetting($key, $value, 1, 3650),
            PasswordPolicy::MIN_LENGTH_KEY => $this->validateIntegerSetting($key, $value, 8, 128),
            PasswordPolicy::MIXED_CASE_KEY,
            PasswordPolicy::NUMBERS_KEY,
            PasswordPolicy::SYMBOLS_KEY,
            PasswordPolicy::UNCOMPROMISED_KEY => $this->validateBooleanSetting($key, $value),
            'security.mfa_issuer_name' => $this->validateStringSetting($key, $value, 64),
            'app.brand_name' => $this->validateStringSetting($key, $value, 120),
            'app.brand_short_name' => $this->validateStringSetting($key, $value, 12),
            'app.login_title' => $this->validateStringSetting($key, $value, 120),
            'app.login_subtitle' => $this->validateStringSetting($key, $value, 240),
            'app.logo_data_url' => $this->validateStringSetting($key, $value, 700000),
            'mobile.tenant_name' => $this->validateStringSetting($key, $value, 120),
            'mobile.api_base_url',
            'app.public_url' => $this->validateNullableUrlSetting($key, $value, 2048),
            'certification.warning_days_before_expiry',
            'asset.warning_days_before_expiry' => $this->validateIntegerSetting($key, $value, 1, 365),
            'updates.android.application_id' => $this->validateAndroidApplicationIdSetting($key, $value),
            default => throw ValidationException::withMessages(["settings.$key" => ['Deze instelling mag niet via deze pagina worden aangepast.']]),
        };
    }

    private function mailFailureMessage(Throwable $exception): string
    {
        $message = trim($exception->getMessage());

        if ($message === '') {
            return 'Testmail verzenden mislukt. Controleer de mailinstellingen en probeer opnieuw.';
        }

        return 'Testmail verzenden mislukt: '.$message;
    }

    /**
     * @return mixed
     */
    private function publicSettingValue(SystemSetting $setting): mixed
    {
        if ($setting->key === 'firebase.service_account') {
            $credentials = is_array($setting->value) ? $setting->value : [];

            return [
                'configured' => filled($credentials['client_email'] ?? null) && filled($credentials['private_key'] ?? null),
                'client_email' => $credentials['client_email'] ?? '',
                'private_key_id' => $credentials['private_key_id'] ?? '',
                'client_id' => $credentials['client_id'] ?? '',
                'client_x509_cert_url' => $credentials['client_x509_cert_url'] ?? '',
            ];
        }

        if ($setting->key === 'mail.microsoft365_client_secret') {
            return ['configured' => filled($setting->value)];
        }

        return $setting->is_sensitive ? null : $setting->value;
    }

    private function validateNullableUrlSetting(string $key, mixed $value, int $max): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = $this->validateStringSetting($key, $value, $max);

        if (! filter_var($value, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be a valid URL.']]);
        }

        return $value;
    }

    private function validateStringSetting(string $key, mixed $value, int $max): string
    {
        if (! is_string($value)) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be a string.']]);
        }

        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages(["settings.$key" => ["The setting value may not be greater than $max characters."]]);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function validateFirebaseServiceAccount(string $key, mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be an object.']]);
        }

        $clientEmail = $this->validateEmailSetting($key.'.client_email', $value['client_email'] ?? '');
        $privateKey = $this->validateStringSetting($key.'.private_key', $value['private_key'] ?? '', 8000);
        $privateKeyId = $this->validateStringSetting($key.'.private_key_id', $value['private_key_id'] ?? '', 255);
        $clientId = $this->validateStringSetting($key.'.client_id', $value['client_id'] ?? '', 255);
        $clientCertUrl = $this->validateNullableUrlSetting($key.'.client_x509_cert_url', $value['client_x509_cert_url'] ?? '', 2048) ?? '';

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'private_key_id' => $privateKeyId,
            'client_id' => $clientId,
            'client_x509_cert_url' => $clientCertUrl,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function validateMobileFirebaseConfig(string $key, mixed $value): array
    {
        if (! is_array($value)) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be an object.']]);
        }

        return [
            'application_id' => $this->validateStringSetting($key.'.application_id', $value['application_id'] ?? '', 255),
            'api_key' => $this->validateStringSetting($key.'.api_key', $value['api_key'] ?? '', 255),
            'project_id' => $this->validateStringSetting($key.'.project_id', $value['project_id'] ?? '', 160),
            'messaging_sender_id' => $this->validateStringSetting($key.'.messaging_sender_id', $value['messaging_sender_id'] ?? '', 160),
            'storage_bucket' => $this->validateStringSetting($key.'.storage_bucket', $value['storage_bucket'] ?? '', 255),
        ];
    }

    private function validateAndroidApplicationIdSetting(string $key, mixed $value): string
    {
        $value = $this->validateStringSetting($key, $value, 255);

        if ($value === '' || preg_match('/^[a-zA-Z][a-zA-Z0-9_]*(?:\.[a-zA-Z][a-zA-Z0-9_]*)+$/', $value) !== 1) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be a valid Android application id.']]);
        }

        return $value;
    }

    private function validateEmailSetting(string $key, mixed $value): string
    {
        $value = $this->validateStringSetting($key, $value, 255);

        if ($value !== '' && ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be a valid email address.']]);
        }

        return $value;
    }

    /**
     * @param array<int, string> $allowed
     */
    private function validateStringIn(string $key, mixed $value, array $allowed): string
    {
        $value = $this->validateStringSetting($key, $value, 255);

        if (! in_array($value, $allowed, true)) {
            throw ValidationException::withMessages(["settings.$key" => ['The selected setting value is invalid.']]);
        }

        return $value;
    }

    private function validateIntegerSetting(string $key, mixed $value, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages(["settings.$key" => ['The setting value must be a number.']]);
        }

        $integer = (int) $value;
        if ($integer < $min || $integer > $max) {
            throw ValidationException::withMessages(["settings.$key" => ["The setting value must be between $min and $max."]]);
        }

        return $integer;
    }

    private function validateBooleanSetting(string $key, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        throw ValidationException::withMessages(["settings.$key" => ['The setting value must be true or false.']]);
    }

    public function pushLogs(Request $request): JsonResponse
    {
        return ApiResponse::paginated(PushDeliveryLog::query()->latest()->paginate((int) $request->integer('per_page', 50)));
    }

    private function shortTargetType(string $targetType): string
    {
        $parts = explode('\\', $targetType);

        return end($parts) ?: $targetType;
    }
}
