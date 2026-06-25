<?php

namespace App\Services;

use App\Events\AvailabilityChanged;
use App\Models\AvailabilityStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StatusService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function setStatus(User $user, string $status, User $actor, ?string $reason = null, bool $systemApplied = false): AvailabilityStatus
    {
        $record = DB::transaction(function () use ($user, $status, $actor, $reason, $systemApplied): AvailabilityStatus {
            $isAvailable = $status === 'available';

            if ($isAvailable && ! $user->push_enabled) {
                throw ValidationException::withMessages(['status' => ['Push notifications are required before a user can be available.']]);
            }

            $record = AvailabilityStatus::query()->create([
                'user_id' => $user->id,
                'status' => $status,
                'is_available' => $isAvailable,
                'is_system_applied' => $systemApplied,
                'changed_by' => $actor->id,
                'reason' => $reason,
                'effective_at' => now(),
            ]);

            $this->auditService->record($systemApplied ? 'status.system_updated' : 'status.updated', $user, $actor, ['status' => $status], $reason);

            return $record;
        });

        $this->dispatchAvailabilityChanged($record);

        return $record;
    }

    public function enforcePushUnavailable(User $user): void
    {
        if (! $user->push_enabled) {
            $record = AvailabilityStatus::query()->create([
                'user_id' => $user->id,
                'status' => 'unavailable',
                'is_available' => false,
                'is_system_applied' => true,
                'changed_by' => null,
                'reason' => 'Push notifications disabled.',
                'effective_at' => now(),
            ]);
            $this->dispatchAvailabilityChanged($record);
        }
    }

    private function dispatchAvailabilityChanged(AvailabilityStatus $status): void
    {
        try {
            AvailabilityChanged::dispatch($status);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
