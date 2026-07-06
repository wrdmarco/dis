<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\ApiDateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatusAuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'ulid', 'exists:users,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);
        $limit = min(max((int) $request->integer('limit', 100), 1), 250);
        $query = AuditLog::query()->whereIn('action', ['status.updated', 'status.system_updated']);

        if (is_string($filters['user_id'] ?? null)) {
            $query->where('target_id', $filters['user_id']);
        }
        if (is_string($filters['from'] ?? null)) {
            $query->whereDate('created_at', '>=', $filters['from']);
        }
        if (is_string($filters['to'] ?? null)) {
            $query->whereDate('created_at', '<=', $filters['to']);
        }

        $logs = $query->latest('created_at')->limit($limit)->get();

        $userIds = $logs
            ->flatMap(fn (AuditLog $log): array => array_filter([(string) $log->target_id, (string) $log->actor_id]))
            ->unique()
            ->values();
        $users = User::query()
            ->whereIn('id', $userIds)
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        return ApiResponse::success($logs->map(function (AuditLog $log) use ($users): array {
            $metadata = is_array($log->metadata) ? $log->metadata : [];
            $target = is_string($log->target_id) ? $users->get($log->target_id) : null;
            $actor = is_string($log->actor_id) ? $users->get($log->actor_id) : null;

            return [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $target === null ? null : [
                    'id' => $target->id,
                    'name' => $target->name,
                    'email' => $target->email,
                ],
                'actor' => $actor === null ? null : [
                    'id' => $actor->id,
                    'name' => $actor->name,
                    'email' => $actor->email,
                ],
                'from_status' => $metadata['from_status'] ?? null,
                'to_status' => $metadata['to_status'] ?? ($metadata['status'] ?? null),
                'is_system_applied' => (bool) ($metadata['is_system_applied'] ?? $log->action === 'status.system_updated'),
                'reason' => $log->reason,
                'created_at' => ApiDateTime::dateTime($log->created_at),
            ];
        })->values());
    }
}
