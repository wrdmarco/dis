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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminController extends Controller
{
    private const SENSITIVE_SETTING_KEYS = [
        'mail.password',
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
        return ApiResponse::success(Team::query()->with('parent')->orderBy('code')->get());
    }

    public function storeTeam(Request $request): JsonResponse
    {
        $team = Team::query()->create($request->validate([
            'code' => ['required', 'string', 'max:40', 'unique:teams,code'],
            'name' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:base,subset,support'],
            'parent_team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'is_operational' => ['required', 'boolean'],
        ]));
        $this->auditService->record('admin.team_created', $team, $request->user());

        return ApiResponse::success($team, 201);
    }

    public function updateTeam(Request $request, Team $team): JsonResponse
    {
        $team->update($request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'type' => ['sometimes', 'in:base,subset,support'],
            'parent_team_id' => ['nullable', 'ulid', 'exists:teams,id'],
            'is_operational' => ['sometimes', 'boolean'],
        ]));
        $this->auditService->record('admin.team_updated', $team, $request->user());

        return ApiResponse::success($team->refresh());
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
                    'value' => $setting->is_sensitive ? null : $setting->value,
                    'is_sensitive' => $setting->is_sensitive,
                ];
            });

        return ApiResponse::success($settings);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['settings' => ['required', 'array']]);
        foreach ($data['settings'] as $key => $value) {
            if (in_array($key, self::SENSITIVE_SETTING_KEYS, true) && ($value === null || $value === '')) {
                continue;
            }

            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'is_sensitive' => in_array($key, self::SENSITIVE_SETTING_KEYS, true),
                    'updated_by' => $request->user()?->id,
                ],
            );
        }
        $this->auditService->record('admin.settings_updated', SystemSetting::class, $request->user(), ['keys' => array_keys($data['settings'])]);

        return $this->settings();
    }

    public function pushLogs(Request $request): JsonResponse
    {
        return ApiResponse::paginated(PushDeliveryLog::query()->latest()->paginate((int) $request->integer('per_page', 50)));
    }
}
