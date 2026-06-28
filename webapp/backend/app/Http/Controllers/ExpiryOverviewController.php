<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\UserCertification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExpiryOverviewController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user?->hasPermission('assets.view') !== true && $user?->hasPermission('certifications.view') !== true) {
            return ApiResponse::error('forbidden', 'You do not have permission to view expiry information.', 403);
        }

        $days = min(max((int) $request->integer('days', 60), 1), 365);
        $until = now()->addDays($days)->toDateString();

        $assets = $user->hasPermission('assets.view')
            ? Asset::query()
                ->with('droneType')
                ->whereNotNull('maintenance_due_at')
                ->whereDate('maintenance_due_at', '<=', $until)
                ->where('status', '!=', 'retired')
                ->orderBy('maintenance_due_at')
                ->limit(250)
                ->get()
                ->map(fn (Asset $asset): array => [
                    'id' => $asset->id,
                    'name' => $asset->name,
                    'asset_tag' => $asset->asset_tag,
                    'type' => $asset->type,
                    'status' => $asset->status,
                    'maintenance_due_at' => $asset->maintenance_due_at?->toDateString(),
                    'drone_type' => $asset->droneType === null ? null : [
                        'manufacturer' => $asset->droneType->manufacturer,
                        'model' => $asset->droneType->model,
                    ],
                ])
                ->values()
            : collect();

        $certifications = $user->hasPermission('certifications.view')
            ? UserCertification::query()
                ->with(['user', 'certification'])
                ->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', $until)
                ->orderBy('expires_at')
                ->limit(250)
                ->get()
                ->map(fn (UserCertification $certification): array => [
                    'id' => $certification->id,
                    'user_id' => $certification->user_id,
                    'user_name' => $certification->user?->name,
                    'user_email' => $certification->user?->email,
                    'certification_id' => $certification->certification_id,
                    'certification_name' => $certification->certification?->name,
                    'certification_code' => $certification->certification?->code,
                    'status' => $certification->status,
                    'issued_at' => $certification->issued_at?->toDateString(),
                    'expires_at' => $certification->expires_at?->toDateString(),
                    'certificate_number' => $certification->certificate_number,
                ])
                ->values()
            : collect();

        return ApiResponse::success([
            'days' => $days,
            'until' => $until,
            'assets' => $assets,
            'certifications' => $certifications,
        ]);
    }
}
