<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class BrandingController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function show(): JsonResponse
    {
        $name = SystemSetting::string('app.brand_name', 'D.I.S Operationeel Beeld') ?? 'D.I.S Operationeel Beeld';
        $shortName = SystemSetting::string('app.brand_short_name', 'DIS') ?? 'DIS';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';
        $loginTitle = SystemSetting::string('app.login_title', 'D.I.S Command Center') ?? 'D.I.S Command Center';
        $loginSubtitle = SystemSetting::string('app.login_subtitle', '') ?? '';
        $logoDataUrl = SystemSetting::string('app.logo_data_url', '') ?? '';

        return ApiResponse::success([
            'name' => $name,
            'short_name' => $shortName,
            'tenant_name' => $tenantName,
            'login_title' => $loginTitle,
            'login_subtitle' => $loginSubtitle,
            'logo_data_url' => $logoDataUrl,
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $data = $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:512'],
        ]);

        $file = $data['logo'];
        $mimeType = $file->getMimeType();
        if (! is_string($mimeType) || ! in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw ValidationException::withMessages(['logo' => ['Gebruik een PNG, JPG of WEBP logo.']]);
        }

        $path = $file->getRealPath();
        if (! is_string($path)) {
            throw ValidationException::withMessages(['logo' => ['Logo kon niet worden gelezen.']]);
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw ValidationException::withMessages(['logo' => ['Logo kon niet worden gelezen.']]);
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'app.logo_data_url'],
            [
                'value' => 'data:'.$mimeType.';base64,'.base64_encode($contents),
                'is_sensitive' => false,
                'updated_by' => $request->user()?->id,
            ],
        );

        $this->auditService->record('branding.logo_updated', SystemSetting::class, $request->user(), [], null, $request);

        return $this->show();
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => 'app.logo_data_url'],
            [
                'value' => '',
                'is_sensitive' => false,
                'updated_by' => $request->user()?->id,
            ],
        );

        $this->auditService->record('branding.logo_deleted', SystemSetting::class, $request->user(), [], null, $request);

        return $this->show();
    }
}
