<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\PushDeliveryLog;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Services\AuditService;
use App\Services\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminController extends Controller
{
    private const SENSITIVE_SETTING_KEYS = [
        'mail.password',
        'firebase.service_account',
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public function roles(): JsonResponse
    {
        return ApiResponse::success(Role::query()->with('permissions')->orderBy('name')->get());
    }

    public function storeRole(Request $request): JsonResponse
    {
        $role = Role::query()->create($request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:roles,name'],
            'display_name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_two_factor' => ['required', 'boolean'],
        ]));
        $this->auditService->record('admin.role_created', $role, $request->user());

        return ApiResponse::success($role, 201);
    }

    public function updateRole(Request $request, Role $role): JsonResponse
    {
        $role->update($request->validate([
            'display_name' => ['sometimes', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'requires_two_factor' => ['sometimes', 'boolean'],
        ]));
        $this->auditService->record('admin.role_updated', $role, $request->user());

        return ApiResponse::success($role->refresh());
    }

    public function permissions(): JsonResponse
    {
        return ApiResponse::success(Permission::query()->orderBy('category')->orderBy('name')->get());
    }

    public function teams(): JsonResponse
    {
        return ApiResponse::success(Team::query()->with(['parent', 'alertTeams', 'requiredCertifications'])->orderBy('code')->get());
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
        return ApiResponse::paginated(AuditLog::query()->latest('created_at')->paginate((int) $request->integer('per_page', 50)));
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

    private function validateSettingValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            PasswordPolicy::MIN_LENGTH_KEY => $this->validateIntegerSetting($key, $value, 8, 128),
            PasswordPolicy::MIXED_CASE_KEY,
            PasswordPolicy::NUMBERS_KEY,
            PasswordPolicy::SYMBOLS_KEY,
            PasswordPolicy::UNCOMPROMISED_KEY => $this->validateBooleanSetting($key, $value),
            default => $value,
        };
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

        return $setting->is_sensitive ? null : $setting->value;
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
}
