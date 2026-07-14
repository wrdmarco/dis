<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AuditService
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /**
     * @param  array<string, mixed>  $metadata
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
            'user_agent' => $request?->userAgent() === null ? null : $this->redactor->redactString($request->userAgent()),
            'metadata' => $metadata === [] ? null : $this->redactor->redactArray($metadata),
            'reason' => $reason === null ? null : $this->redactor->redactString($reason),
            'created_at' => now(),
        ]);
    }
}
