<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditService
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function record(string $action, Model|string $target, ?User $actor = null, array $metadata = [], ?string $reason = null, ?Request $request = null): AuditLog
    {
        if ($request !== null && $request->attributes->has('request_id')) {
            $metadata['request_id'] = $request->attributes->get('request_id');
        }

        return AuditLog::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'target_type' => is_string($target) ? $target : $target::class,
            'target_id' => is_string($target) ? null : (string) $target->getKey(),
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'metadata' => $metadata === [] ? null : $this->sanitizeMetadata($metadata),
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sensitiveFragments = ['password', 'secret', 'token', 'api_key', 'private_key', 'recovery_code', 'authorization'];

        foreach ($metadata as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);
            if (collect($sensitiveFragments)->contains(fn (string $fragment): bool => str_contains($normalizedKey, $fragment))) {
                $metadata[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $metadata[$key] = $this->sanitizeMetadata($value);
            }
        }

        return $metadata;
    }
}
