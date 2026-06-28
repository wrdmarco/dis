<?php

namespace App\Services;

use App\Events\AvailabilityChanged;
use App\Models\AvailabilityStatus;
use App\Models\User;
use App\Models\UserVacation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class StatusService
{
    public function __construct(private readonly AuditService $auditService) {}

    public function setStatus(User $user, string $status, User $actor, ?string $reason = null, bool $systemApplied = false): AvailabilityStatus
    {
        $record = DB::transaction(function () use ($user, $status, $actor, $reason, $systemApplied): AvailabilityStatus {
            $previousStatus = $this->latestStatus($user);
            $isAvailable = $status === 'available';

            if ($isAvailable && $this->hasActiveVacation($user)) {
                throw ValidationException::withMessages(['status' => ['Deze gebruiker staat op vakantie en kan niet beschikbaar worden gezet.']]);
            }

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

            $this->auditService->record($systemApplied ? 'status.system_updated' : 'status.updated', $user, $actor, [
                'from_status' => $previousStatus?->status,
                'to_status' => $status,
                'is_available' => $isAvailable,
                'is_system_applied' => $systemApplied,
            ], $reason);

            return $record;
        });

        $this->dispatchAvailabilityChanged($record);

        return $record;
    }

    public function enforcePushUnavailable(User $user): void
    {
        if (! $user->push_enabled) {
            $previousStatus = $this->latestStatus($user);
            $record = AvailabilityStatus::query()->create([
                'user_id' => $user->id,
                'status' => 'unavailable',
                'is_available' => false,
                'is_system_applied' => true,
                'changed_by' => null,
                'reason' => 'Push notifications disabled.',
                'effective_at' => now(),
            ]);
            $this->auditService->record('status.system_updated', $user, null, [
                'from_status' => $previousStatus?->status,
                'to_status' => 'unavailable',
                'is_available' => false,
                'is_system_applied' => true,
            ], 'Push notifications disabled.');
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

    private function hasActiveVacation(User $user): bool
    {
        return UserVacation::query()
            ->where('user_id', $user->id)
            ->open()
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->exists();
    }

    private function latestStatus(User $user): ?AvailabilityStatus
    {
        return $user->statuses()
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}
