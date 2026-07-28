<?php

namespace App\Repositories;

use App\Models\UserNotification;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<UserNotification>
 */
final class UserNotificationRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return UserNotification::class;
    }

    /** @return Collection<int, UserNotification> */
    public function unreadForUser(string $userId, int $limit = 30): Collection
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(min(max($limit, 1), 100))
            ->get();
    }

    public function unreadCountForUser(string $userId): int
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    /** @param array<string, mixed> $attributes */
    public function createOnce(array $attributes): ?UserNotification
    {
        $notification = $this->query()->firstOrCreate(
            ['deduplication_key' => $attributes['deduplication_key']],
            $attributes,
        );

        return $notification->wasRecentlyCreated ? $notification : null;
    }

    public function markReadForUser(string $notificationId, string $userId): UserNotification
    {
        $notification = $this->query()
            ->whereKey($notificationId)
            ->where('user_id', $userId)
            ->firstOrFail();

        if ($notification->read_at === null) {
            $notification->forceFill(['read_at' => now()])->save();
        }

        return $notification;
    }

    public function markAllReadForUser(string $userId): int
    {
        return $this->query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  list<string>  $expectedKeys
     * @return array{removed: int, user_ids: list<string>}
     */
    public function deleteStaleReminders(array $expectedKeys): array
    {
        $stale = $this->query()
            ->whereIn('type', UserNotification::REMINDER_TYPES)
            ->when(
                $expectedKeys !== [],
                fn ($query) => $query->whereNotIn('deduplication_key', $expectedKeys),
            );
        $userIds = (clone $stale)
            ->distinct()
            ->pluck('user_id')
            ->map(fn (mixed $userId): string => (string) $userId)
            ->values()
            ->all();

        return [
            'removed' => $stale->delete(),
            'user_ids' => $userIds,
        ];
    }
}
