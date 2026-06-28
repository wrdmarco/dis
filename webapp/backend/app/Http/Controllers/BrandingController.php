<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\SystemSetting;
use Illuminate\Http\JsonResponse;

final class BrandingController extends Controller
{
    public function show(): JsonResponse
    {
        $name = SystemSetting::string('app.brand_name', 'D.I.S Operationeel Beeld') ?? 'D.I.S Operationeel Beeld';
        $shortName = SystemSetting::string('app.brand_short_name', 'DIS') ?? 'DIS';
        $tenantName = SystemSetting::string('mobile.tenant_name', 'Nationaal Droneteam') ?? 'Nationaal Droneteam';

        return ApiResponse::success([
            'name' => $name,
            'short_name' => $shortName,
            'tenant_name' => $tenantName,
        ]);
    }
}
