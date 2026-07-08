<?php

namespace App\Http\Middleware;

use App\Http\Responses\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RestrictStoreReviewAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isStoreReviewToken($request)) {
            return $next($request);
        }

        if ($this->storeReviewTokenIsExpired($request)) {
            $request->user()?->currentAccessToken()?->delete();

            return ApiResponse::error('unauthenticated', 'Authentication is required.', 401);
        }

        $path = trim($request->path(), '/');
        $method = $request->method();

        if ($method === 'GET' && $path === 'api/auth/me') {
            return $next($request);
        }

        if ($method === 'POST' && $path === 'api/auth/logout') {
            return $next($request);
        }

        if ($method === 'POST' && in_array($path, ['api/devices/fcm-token', 'api/devices/heartbeat'], true)) {
            return response()->noContent();
        }

        if ($method === 'GET') {
            return match ($path) {
                'api/status/me' => ApiResponse::success($this->unavailableStatus((string) $request->user()?->id)),
                'api/vacations/mine',
                'api/calendar-events',
                'api/incidents',
                'api/assets/mine',
                'api/drone-types',
                'api/certifications',
                'api/certifications/me' => ApiResponse::success([]),
                'api/pilot-report/form-config' => ApiResponse::success(['fields' => []]),
                'api/availability-schedule/me' => ApiResponse::success($this->emptyAvailabilitySchedule((string) $request->user()?->id)),
                default => ApiResponse::error('store_review_access_denied', 'Deze review-login geeft alleen toegang tot accountinformatie.', 403),
            };
        }

        return ApiResponse::error('store_review_access_denied', 'Deze review-login mag geen operationele gegevens wijzigen.', 403);
    }

    private function isStoreReviewToken(Request $request): bool
    {
        $token = $request->user()?->currentAccessToken();
        $abilities = is_array($token?->abilities ?? null) ? $token->abilities : [];

        return in_array('client:store_review', $abilities, true);
    }

    private function storeReviewTokenIsExpired(Request $request): bool
    {
        $expiresAt = $request->user()?->currentAccessToken()?->expires_at;

        return $expiresAt === null || $expiresAt->isPast();
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableStatus(string $userId): array
    {
        return [
            'id' => 'store-review-status',
            'user_id' => $userId,
            'status' => 'unavailable',
            'is_available' => false,
            'is_system_applied' => true,
            'reason' => 'Google Play review-login heeft geen operationele toegang.',
            'effective_at' => now()->toIso8601String(),
            'next_availability_change' => null,
            'next_available_at' => null,
            'user' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAvailabilitySchedule(string $userId): array
    {
        $weekPattern = collect(range(1, 7))
            ->map(fn (int $day): array => [
                'day_of_week' => $day,
                'day_part' => 'all_day',
                'is_available' => false,
                'note' => null,
                'source' => 'store_review',
            ])
            ->values()
            ->all();

        return [
            'user_id' => $userId,
            'week_pattern' => $weekPattern,
            'week_day_parts' => [],
            'overrides' => [],
            'today' => [
                'is_available' => false,
                'source' => 'store_review',
                'note' => null,
            ],
        ];
    }
}
