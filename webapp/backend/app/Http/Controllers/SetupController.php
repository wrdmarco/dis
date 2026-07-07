<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Role;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\AuditService;
use App\Support\ApiDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

final class SetupController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function status(): JsonResponse
    {
        return ApiResponse::success([
            'setup_completed' => SystemSetting::boolean('app.setup_completed', false),
            'has_users' => User::query()->exists(),
            'requires_first_admin' => ! User::query()->exists(),
            'public_url' => SystemSetting::string('app.public_url', config('app.url')),
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        if (SystemSetting::boolean('app.setup_completed', false) || User::query()->exists()) {
            return ApiResponse::error('setup_locked', 'Initial setup is already completed.', 403);
        }

        $data = $request->validate([
            'tenant_name' => ['required', 'string', 'max:160'],
            'public_url' => ['required', 'url:http,https', 'max:2048'],
            'admin_name' => ['required', 'string', 'max:160'],
            'admin_email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'admin_password' => ['required', 'confirmed', Password::min(14)->mixedCase()->numbers()->symbols()],
            'mail.mailer' => ['nullable', 'string', 'max:40'],
            'mail.host' => ['nullable', 'string', 'max:255'],
            'mail.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mail.encryption' => ['nullable', 'in:,tls,ssl'],
            'mail.username' => ['nullable', 'string', 'max:255'],
            'mail.password' => ['nullable', 'string', 'max:1000'],
            'mail.from_address' => ['nullable', 'email:rfc', 'max:255'],
            'mail.from_name' => ['nullable', 'string', 'max:255'],
            'firebase.project_id' => ['nullable', 'string', 'max:160'],
            'firebase.service_account.client_email' => ['nullable', 'email:rfc', 'max:255'],
            'firebase.service_account.private_key' => ['nullable', 'string', 'max:8000'],
            'firebase.service_account.private_key_id' => ['nullable', 'string', 'max:255'],
            'firebase.service_account.client_id' => ['nullable', 'string', 'max:255'],
            'firebase.service_account.client_x509_cert_url' => ['nullable', 'url:http,https', 'max:2048'],
            'mobile.firebase_config.application_id' => ['nullable', 'string', 'max:255'],
            'mobile.firebase_config.api_key' => ['nullable', 'string', 'max:255'],
            'mobile.firebase_config.project_id' => ['nullable', 'string', 'max:160'],
            'mobile.firebase_config.messaging_sender_id' => ['nullable', 'string', 'max:160'],
            'mobile.firebase_config.storage_bucket' => ['nullable', 'string', 'max:255'],
        ]);

        $publicUrl = $this->normalizePublicUrl((string) $data['public_url']);

        $user = DB::transaction(function () use ($data, $publicUrl): User {
            [$firstName, $lastName] = $this->splitDisplayName((string) $data['admin_name']);
            $user = User::query()->create([
                'name' => $data['admin_name'],
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $data['admin_email'],
                'password' => $data['admin_password'],
                'account_status' => 'active',
                'push_enabled' => false,
                'two_factor_enabled' => false,
            ]);

            $role = Role::query()->where('name', 'system-administrator')->firstOrFail();
            $user->roles()->attach($role->id, ['assigned_by' => $user->id]);

            $settings = [
                'app.setup_completed' => true,
                'app.setup_completed_at' => ApiDateTime::now(),
                'app.public_url' => $publicUrl,
                'mobile.tenant_name' => $data['tenant_name'],
                'mobile.api_base_url' => $publicUrl.'/api',
                'mail.mailer' => $data['mail']['mailer'] ?? 'smtp',
                'mail.host' => $data['mail']['host'] ?? '',
                'mail.port' => (int) ($data['mail']['port'] ?? 587),
                'mail.encryption' => $data['mail']['encryption'] ?? 'tls',
                'mail.username' => $data['mail']['username'] ?? '',
                'mail.from_address' => $data['mail']['from_address'] ?? 'no-reply@'.parse_url($publicUrl, PHP_URL_HOST),
                'mail.from_name' => $data['mail']['from_name'] ?? $data['tenant_name'],
                'firebase.project_id' => $data['firebase']['project_id'] ?? '',
                'firebase.service_account' => [
                    'client_email' => $data['firebase']['service_account']['client_email'] ?? '',
                    'private_key' => $data['firebase']['service_account']['private_key'] ?? '',
                    'private_key_id' => $data['firebase']['service_account']['private_key_id'] ?? '',
                    'client_id' => $data['firebase']['service_account']['client_id'] ?? '',
                    'client_x509_cert_url' => $data['firebase']['service_account']['client_x509_cert_url'] ?? '',
                ],
                'mobile.firebase_config' => [
                    'application_id' => $data['mobile']['firebase_config']['application_id'] ?? '',
                    'api_key' => $data['mobile']['firebase_config']['api_key'] ?? '',
                    'project_id' => $data['mobile']['firebase_config']['project_id'] ?? ($data['firebase']['project_id'] ?? ''),
                    'messaging_sender_id' => $data['mobile']['firebase_config']['messaging_sender_id'] ?? '',
                    'storage_bucket' => $data['mobile']['firebase_config']['storage_bucket'] ?? '',
                ],
            ];

            if (($data['mail']['password'] ?? '') !== '') {
                $settings['mail.password'] = $data['mail']['password'];
            }

            foreach ($settings as $key => $value) {
                SystemSetting::query()->updateOrCreate(
                    ['key' => $key],
                    [
                        'value' => $value,
                        'is_sensitive' => in_array($key, ['mail.password', 'firebase.service_account'], true),
                        'updated_by' => $user->id,
                    ],
                );
            }

            return $user;
        });

        $this->auditService->record('setup.completed', SystemSetting::class, $user, [
            'public_url' => $publicUrl,
            'admin_email' => $user->email,
        ], null, $request);

        return ApiResponse::success([
            'setup_completed' => true,
            'login_url' => '/login',
            'token' => $user->createToken('DIS Command Center')->plainTextToken,
            'user' => $user->load(['roles.permissions', 'teams']),
        ], 201);
    }

    private function normalizePublicUrl(string $url): string
    {
        $trimmed = rtrim(trim($url), '/');

        if (! str_starts_with($trimmed, 'http://') && ! str_starts_with($trimmed, 'https://')) {
            return 'https://'.$trimmed;
        }

        return $trimmed;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitDisplayName(string $name): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($normalized === '') {
            return [null, null];
        }

        $parts = explode(' ', $normalized, 2);

        return [
            $parts[0] !== '' ? $parts[0] : null,
            isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null,
        ];
    }
}
