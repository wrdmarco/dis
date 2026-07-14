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
                'api/auth/me' => ApiResponse::success($this->reviewUser($request)),
                'api/status/me' => ApiResponse::success($this->unavailableStatus((string) $request->user()?->id)),
                'api/teams' => ApiResponse::success([$this->reviewTeam()]),
                'api/incidents' => ApiResponse::success([$this->reviewIncident('store-review-incident', null, 'active')]),
                'api/calendar-events' => ApiResponse::success([$this->reviewCalendarEvent()]),
                'api/assets/mine',
                'api/assets' => ApiResponse::success([$this->reviewAsset($request)]),
                'api/drone-types' => ApiResponse::success([$this->reviewDroneType()]),
                'api/certifications' => ApiResponse::success([$this->reviewCertification()]),
                'api/certifications/me' => ApiResponse::success([$this->reviewUserCertification($request)]),
                'api/vacations/mine',
                'api/devices' => ApiResponse::success([]),
                'api/incident-form/config',
                'api/pilot-report/form-config' => ApiResponse::success(['fields' => []]),
                'api/availability-schedule/me' => ApiResponse::success($this->emptyAvailabilitySchedule((string) $request->user()?->id)),
                default => $this->storeReviewReadResponse($path),
            };
        }

        return $this->storeReviewWriteResponse($request, $path, $method);
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
    private function reviewUser(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => (string) $user?->id,
            'name' => (string) ($user?->name ?? 'Google Play Review'),
            'email' => (string) ($user?->email ?? 'google-play-review@system.dis.local'),
            'account_status' => 'store_review',
            'push_enabled' => true,
            'two_factor_enabled' => false,
            'roles' => [],
            'teams' => [],
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

    private function storeReviewReadResponse(string $path): Response
    {
        if (preg_match('#^api/incidents/([^/]+)$#', $path, $matches) === 1) {
            return ApiResponse::success($this->reviewIncident($matches[1]));
        }

        if (preg_match('#^api/incidents/([^/]+)/(timeline|dispatches|live-locations)$#', $path) === 1) {
            return ApiResponse::success([]);
        }

        if (preg_match('#^api/incidents/([^/]+)/pilot-report$#', $path, $matches) === 1) {
            return ApiResponse::success($this->reviewPilotReport($matches[1]));
        }

        if (preg_match('#^api/incidents/([^/]+)/dispatch-preview$#', $path) === 1) {
            return ApiResponse::success([
                'team' => null,
                'recipients' => [],
                'blocked_reason' => null,
            ]);
        }

        if (preg_match('#^api/dispatches/([^/]+)(/recipients)?$#', $path, $matches) === 1) {
            return ($matches[2] ?? '') === '/recipients'
                ? ApiResponse::success([])
                : ApiResponse::success($this->reviewDispatch($matches[1]));
        }

        return ApiResponse::success([]);
    }

    private function storeReviewWriteResponse(Request $request, string $path, string $method): Response
    {
        if ($path === 'api/auth/2fa/setup' && $method === 'POST') {
            return ApiResponse::success([
                'enabled' => false,
                'secret' => null,
                'provisioning_uri' => null,
            ]);
        }

        if ($path === 'api/auth/2fa/disable' && $method === 'POST') {
            return ApiResponse::success($this->reviewUser($request));
        }

        if ($path === 'api/auth/me' && $method === 'PATCH') {
            return ApiResponse::success($this->reviewUser($request));
        }

        if ($method === 'PATCH' && $path === 'api/status/me') {
            return ApiResponse::success($this->unavailableStatus((string) $request->user()?->id));
        }

        if ($method === 'PATCH' && $path === 'api/availability-schedule/me/week-pattern') {
            return ApiResponse::success($this->emptyAvailabilitySchedule((string) $request->user()?->id));
        }

        if ($method === 'POST' && $path === 'api/availability-schedule/me/overrides') {
            return ApiResponse::success($this->emptyAvailabilitySchedule((string) $request->user()?->id));
        }

        if ($method === 'DELETE' && preg_match('#^api/availability-schedule/overrides/[^/]+$#', $path) === 1) {
            return response()->noContent();
        }

        if ($method === 'POST' && $path === 'api/vacations/mine') {
            return ApiResponse::success($this->reviewVacation($request));
        }

        if ($method === 'DELETE' && preg_match('#^api/vacations/[^/]+$#', $path) === 1) {
            return response()->noContent();
        }

        if ($method === 'POST' && $path === 'api/incidents') {
            return ApiResponse::success($this->reviewIncident('store-review-incident', $request), 201);
        }

        if (preg_match('#^api/incidents/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
            return ApiResponse::success($this->reviewIncident($matches[1], $request));
        }

        if (preg_match('#^api/incidents/([^/]+)/(close|cancel)$#', $path, $matches) === 1 && $method === 'POST') {
            return ApiResponse::success($this->reviewIncident($matches[1], $request, $matches[2] === 'cancel' ? 'cancelled' : 'resolved'));
        }

        if (preg_match('#^api/incidents/([^/]+)/pilot-report$#', $path, $matches) === 1 && $method === 'PATCH') {
            return ApiResponse::success($this->reviewPilotReport($matches[1], $request));
        }

        if (preg_match('#^api/incidents/([^/]+)/pilot-report/finalize$#', $path, $matches) === 1 && $method === 'POST') {
            return ApiResponse::success($this->reviewPilotReport($matches[1], $request, true));
        }

        if (preg_match('#^api/incidents/([^/]+)/location/(consent|decline)$#', $path, $matches) === 1 && $method === 'POST') {
            return ApiResponse::success($this->reviewLocationConsent($matches[1], $matches[2] === 'consent'));
        }

        if (preg_match('#^api/incidents/([^/]+)/location/consent$#', $path) === 1 && $method === 'DELETE') {
            return response()->noContent();
        }

        if (preg_match('#^api/incidents/([^/]+)/location$#', $path) === 1 && $method === 'POST') {
            return response()->noContent();
        }

        if (preg_match('#^api/dispatches/([^/]+)/(send|re-alert|escalate)$#', $path, $matches) === 1 && $method === 'POST') {
            return ApiResponse::success($this->reviewDispatch($matches[1]));
        }

        if (preg_match('#^api/dispatches/([^/]+)/message$#', $path) === 1 && $method === 'POST') {
            return ApiResponse::success([
                'queued_tokens' => 0,
                'recipient_users' => 0,
            ]);
        }

        if (preg_match('#^api/dispatches/([^/]+)/respond$#', $path) === 1 && $method === 'POST') {
            return response()->noContent();
        }

        if ($path === 'api/admin/push/manual' && $method === 'POST') {
            return ApiResponse::success([
                'queued_tokens' => 0,
                'recipient_users' => 0,
            ]);
        }

        if ($path === 'api/assets/mine' && $method === 'POST') {
            return ApiResponse::success($this->reviewAsset($request), 201);
        }

        if (preg_match('#^api/assets/([^/]+)/mine$#', $path, $matches) === 1 && $method === 'PATCH') {
            return ApiResponse::success($this->reviewAsset($request, $matches[1]));
        }

        if (preg_match('#^api/assets/([^/]+)/mine$#', $path) === 1 && $method === 'DELETE') {
            return response()->noContent();
        }

        if ($path === 'api/certifications/me' && $method === 'POST') {
            return ApiResponse::success($this->reviewUserCertification($request), 201);
        }

        if (preg_match('#^api/certifications/me/([^/]+)$#', $path, $matches) === 1 && $method === 'PATCH') {
            return ApiResponse::success($this->reviewUserCertification($request, $matches[1]));
        }

        if (preg_match('#^api/certifications/me/[^/]+$#', $path) === 1 && $method === 'DELETE') {
            return response()->noContent();
        }

        return ApiResponse::success([
            'review_mode' => true,
            'saved' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewIncident(string $id, ?Request $request = null, string $status = 'draft'): array
    {
        $payload = $request?->all() ?? [];

        return [
            'id' => $id,
            'reference' => 'REVIEW-0001',
            'title' => (string) ($payload['title'] ?? 'Review incident'),
            'description' => $payload['description'] ?? null,
            'reporter_name' => 'App Store Reviewer',
            'requesting_organization' => 'Nationaal Drone Team',
            'required_resources' => '1 operator en 1 drone',
            'custom_fields' => [],
            'priority' => (string) ($payload['priority'] ?? 'normal'),
            'status' => (string) ($payload['status'] ?? $status),
            'is_test' => true,
            'location_label' => $payload['location_label'] ?? 'Review locatie',
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'opened_at' => now()->toIso8601String(),
            'team' => $this->reviewTeam(),
            'teams' => [$this->reviewTeam()],
            'active_dispatch' => [
                'id' => 'store-review-dispatch',
                'status' => 'sent',
                'response_status' => 'accepted',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewDispatch(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Review melding, niet verzonden.',
            'sent_at' => now()->toIso8601String(),
            'created_at' => now()->toIso8601String(),
            'recipients' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPilotReport(string $incidentId, ?Request $request = null, bool $finalized = false): array
    {
        $payload = $request?->all() ?? [];
        $now = now()->toIso8601String();

        return [
            'id' => 'store-review-pilot-report',
            'incident_id' => $incidentId,
            'status' => $finalized ? 'final' : 'draft',
            'summary' => $payload['summary'] ?? null,
            'observations' => $payload['observations'] ?? null,
            'actions_taken' => $payload['actions_taken'] ?? null,
            'result' => $payload['result'] ?? null,
            'issues' => $payload['issues'] ?? null,
            'equipment_used' => $payload['equipment_used'] ?? null,
            'flight_minutes' => $payload['flight_minutes'] ?? null,
            'custom_fields' => $payload['custom_fields'] ?? [],
            'submitted_at' => $finalized ? $now : null,
            'finalized_at' => $finalized ? $now : null,
            'can_edit' => ! $finalized,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewLocationConsent(string $incidentId, bool $active): array
    {
        return [
            'id' => 'store-review-location-consent',
            'incident_id' => $incidentId,
            'is_active' => $active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewVacation(Request $request): array
    {
        return [
            'id' => 'store-review-vacation',
            'starts_at' => (string) $request->input('starts_at', now()->toDateString()),
            'ends_at' => (string) $request->input('ends_at', now()->toDateString()),
            'status' => 'approved',
            'note' => $request->input('note'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewAsset(Request $request, string $id = 'store-review-asset'): array
    {
        return [
            'id' => $id,
            'asset_tag' => 'REVIEW-DRONE',
            'name' => (string) $request->input('name', 'Review drone'),
            'type' => (string) $request->input('type', 'drone'),
            'drone_type_id' => $request->input('drone_type_id'),
            'drone_type' => null,
            'has_spotlight' => (bool) $request->boolean('has_spotlight'),
            'has_speaker' => (bool) $request->boolean('has_speaker'),
            'status' => (string) $request->input('status', 'ready'),
            'serial_number' => $request->input('serial_number'),
            'maintenance_due_at' => $request->input('maintenance_due_at'),
            'notes' => $request->input('notes'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewUserCertification(Request $request, string $id = 'store-review-certification'): array
    {
        $certificationId = (string) $request->input('certification_id', 'store-review-certification-type');

        return [
            'id' => $id,
            'certification_id' => $certificationId,
            'issued_at' => (string) $request->input('issued_at', now()->toDateString()),
            'expires_at' => $request->input('expires_at'),
            'certificate_number' => $request->input('certificate_number'),
            'status' => 'active',
            'certification' => [
                'id' => $certificationId,
                'code' => 'REVIEW',
                'name' => 'Review certificaat',
                'is_required_for_dispatch' => false,
                'warning_days_before_expiry' => 30,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reviewTeam(): array
    {
        return [
            'id' => 'store-review-team',
            'code' => 'OCP-REVIEW',
            'name' => 'OCP Reviewteam',
            'type' => 'ocp',
        ];
    }

    /** @return array<string, mixed> */
    private function reviewDroneType(): array
    {
        return [
            'id' => 'store-review-drone-type',
            'manufacturer' => 'DJI',
            'model' => 'Matrice Review',
            'has_thermal' => true,
            'has_spotlight' => true,
            'has_speaker' => true,
            'is_active' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function reviewCertification(): array
    {
        return [
            'id' => 'store-review-certification-type',
            'code' => 'REVIEW-A1A3',
            'name' => 'Review vliegbewijs A1/A3',
            'is_required_for_dispatch' => false,
            'warning_days_before_expiry' => 30,
        ];
    }

    /** @return array<string, mixed> */
    private function reviewCalendarEvent(): array
    {
        return [
            'id' => 'store-review-calendar-event',
            'title' => 'Review oefeninzet',
            'type' => 'training',
            'starts_at' => now()->addDay()->startOfHour()->toIso8601String(),
            'ends_at' => now()->addDay()->startOfHour()->addHours(2)->toIso8601String(),
            'location_label' => 'Utrecht',
            'description' => 'Afgeschermde demonstratiedata voor app-review.',
            'team' => $this->reviewTeam(),
            'created_by_name' => 'D.I.S Reviewomgeving',
        ];
    }
}
