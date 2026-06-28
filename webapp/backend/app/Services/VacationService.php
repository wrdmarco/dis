<?php

namespace App\Services;

use App\Models\AvailabilityStatus;
use App\Models\User;
use App\Models\UserVacation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class VacationService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly StatusService $statusService,
    ) {}

    /**
     * @param array{starts_at: string, ends_at: string, note?: string|null} $data
     */
    public function create(User $user, array $data, User $actor): UserVacation
    {
        $startsAt = CarbonImmutable::parse($data['starts_at'])->toDateString();
        $endsAt = CarbonImmutable::parse($data['ends_at'])->toDateString();
        if ($endsAt < $startsAt) {
            throw ValidationException::withMessages(['ends_at' => ['Einddatum mag niet voor de begindatum liggen.']]);
        }

        $this->assertNoOverlap($user, $startsAt, $endsAt);

        return DB::transaction(function () use ($user, $actor, $data, $startsAt, $endsAt): UserVacation {
            $vacation = UserVacation::query()->create([
                'user_id' => $user->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => UserVacation::STATUS_SCHEDULED,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->auditService->record('vacation.created', $vacation, $actor, [
                'user_id' => $user->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);
            $this->applyForUser($user);

            return $vacation->refresh()->load('user');
        });
    }

    public function cancel(UserVacation $vacation, User $actor): UserVacation
    {
        return DB::transaction(function () use ($vacation, $actor): UserVacation {
            if (! in_array($vacation->status, [UserVacation::STATUS_SCHEDULED, UserVacation::STATUS_ACTIVE], true)) {
                throw ValidationException::withMessages(['vacation' => ['Deze vakantie kan niet meer worden ingetrokken.']]);
            }

            $vacation->update([
                'status' => UserVacation::STATUS_CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);

            $latest = $this->latestStatus($vacation->user);
            if ($latest?->status === 'vacation') {
                $this->statusService->setStatus($vacation->user, 'unavailable', $actor, 'Vakantie ingetrokken.', true);
            }

            $this->auditService->record('vacation.cancelled', $vacation, $actor, ['user_id' => $vacation->user_id]);

            return $vacation->refresh()->load('user');
        });
    }

    public function applyDueVacations(): array
    {
        $activated = 0;
        $completed = 0;

        UserVacation::query()
            ->with('user')
            ->open()
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->get()
            ->each(function (UserVacation $vacation) use (&$activated): void {
                if ($this->applyVacation($vacation)) {
                    $activated++;
                }
            });

        UserVacation::query()
            ->with('user')
            ->where('status', UserVacation::STATUS_ACTIVE)
            ->whereDate('ends_at', '<', today())
            ->get()
            ->each(function (UserVacation $vacation) use (&$completed): void {
                if ($this->completeVacation($vacation)) {
                    $completed++;
                }
            });

        return ['activated' => $activated, 'completed' => $completed];
    }

    public function activeVacationFor(User $user): ?UserVacation
    {
        return UserVacation::query()
            ->where('user_id', $user->id)
            ->open()
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->first();
    }

    private function applyForUser(User $user): void
    {
        UserVacation::query()
            ->with('user')
            ->where('user_id', $user->id)
            ->open()
            ->whereDate('starts_at', '<=', today())
            ->whereDate('ends_at', '>=', today())
            ->get()
            ->each(fn (UserVacation $vacation): bool => $this->applyVacation($vacation));
    }

    private function applyVacation(UserVacation $vacation): bool
    {
        if ($vacation->user === null || $vacation->user->account_status !== 'active') {
            return false;
        }

        $latest = $this->latestStatus($vacation->user);
        if ($latest?->status !== 'vacation') {
            $this->statusService->setStatus($vacation->user, 'vacation', $vacation->user, 'Vakantie actief.', true);
        }

        if ($vacation->status !== UserVacation::STATUS_ACTIVE) {
            $vacation->update(['status' => UserVacation::STATUS_ACTIVE]);
        }

        return true;
    }

    private function completeVacation(UserVacation $vacation): bool
    {
        if ($vacation->user === null) {
            return false;
        }

        $vacation->update(['status' => UserVacation::STATUS_COMPLETED]);
        $latest = $this->latestStatus($vacation->user);
        if ($latest?->status === 'vacation') {
            $this->statusService->setStatus($vacation->user, 'unavailable', $vacation->user, 'Vakantie afgelopen.', true);
        }

        return true;
    }

    private function assertNoOverlap(User $user, string $startsAt, string $endsAt): void
    {
        $overlap = UserVacation::query()
            ->where('user_id', $user->id)
            ->open()
            ->whereDate('starts_at', '<=', $endsAt)
            ->whereDate('ends_at', '>=', $startsAt)
            ->exists();

        if ($overlap) {
            throw ValidationException::withMessages(['starts_at' => ['Er bestaat al een vakantie in deze periode.']]);
        }
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
