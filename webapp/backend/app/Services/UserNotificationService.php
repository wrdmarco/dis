<?php

namespace App\Services;

use App\Events\UserNotificationCreated;
use App\Events\UserNotificationsChanged;
use App\Models\Asset;
use App\Models\PersonalAccessToken;
use App\Models\ProductRequest;
use App\Models\ProductRequestStatusHistory;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\UserCertification;
use App\Models\UserNotification;
use App\Repositories\UserNotificationRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Throwable;

final class UserNotificationService
{
    public function __construct(private readonly UserNotificationRepository $notifications) {}

    /**
     * @return array{
     *     notifications: Collection<int, UserNotification>,
     *     unread_count: int,
     *     current_page: int,
     *     last_page: int,
     *     next_page: int|null
     * }
     */
    public function inbox(User $user, int $page = 1): array
    {
        $this->requireWebSession($user);
        $notifications = $this->notifications->unreadPageForUser(
            (string) $user->id,
            $page,
        );

        return [
            'notifications' => $notifications->getCollection(),
            'unread_count' => $notifications->total(),
            'current_page' => $notifications->currentPage(),
            'last_page' => $notifications->lastPage(),
            'next_page' => $notifications->hasMorePages()
                ? $notifications->currentPage() + 1
                : null,
        ];
    }

    public function markRead(string $notificationId, User $user): UserNotification
    {
        $this->requireWebSession($user);

        return $this->notifications->markReadForUser($notificationId, (string) $user->id);
    }

    public function markAllRead(User $user): int
    {
        $this->requireWebSession($user);

        return $this->notifications->markAllReadForUser((string) $user->id);
    }

    public function createProductRequestStatusNotification(
        ProductRequest $productRequest,
        ProductRequestStatusHistory $history,
    ): ?UserNotification {
        if ($productRequest->requester_id === null) {
            return null;
        }

        $statusLabel = $this->productRequestStatusLabel($history->to_status);
        $requestTitle = Str::limit(trim((string) $productRequest->title), 100);

        return $this->notifications->createOnce([
            'user_id' => (string) $productRequest->requester_id,
            'type' => UserNotification::TYPE_PRODUCT_REQUEST_STATUS,
            'tone' => match ($history->to_status) {
                'resolved' => 'success',
                'rejected' => 'critical',
                'open' => 'warning',
                default => 'info',
            },
            'title' => match ($history->to_status) {
                'resolved' => 'Verzoek opgelost',
                'rejected' => 'Verzoek afgewezen',
                'open' => 'Verzoek heropend',
                default => 'Verzoek wordt behandeld',
            },
            'message' => sprintf('Je verzoek "%s" staat nu op %s.', $requestTitle, $statusLabel),
            'action_url' => '/verzoeken?tab=mine&request='.(string) $productRequest->id,
            'source_type' => 'product_request',
            'source_id' => (string) $productRequest->id,
            'deduplication_key' => $this->deduplicationKey(
                (string) $productRequest->requester_id,
                UserNotification::TYPE_PRODUCT_REQUEST_STATUS,
                'product_request',
                (string) $productRequest->id,
                (string) $history->id,
            ),
            'occurred_at' => $history->created_at ?? now(),
            'read_at' => null,
        ]);
    }

    /**
     * @return array{active: int, created: int, removed: int}
     */
    public function syncDueReminders(): array
    {
        $today = now()->toImmutable()->startOfDay();
        $expectedKeys = [];
        $created = 0;
        $notifiedUserIds = [];

        $certificationWarningDays = max(
            1,
            SystemSetting::integer('certification.warning_days_before_expiry', 30),
        );
        $certifications = UserCertification::query()
            ->with(['user:id,account_status', 'certification:id,name'])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '<=', $today->addDays($certificationWarningDays)->toDateString())
            ->whereHas('user', fn ($query) => $query->where('account_status', 'active'))
            ->get();

        foreach ($certifications as $userCertification) {
            $user = $userCertification->user;
            $certification = $userCertification->certification;
            $expiresAt = $userCertification->expires_at;
            if ($user === null || $certification === null || $expiresAt === null) {
                continue;
            }

            $daysUntilExpiry = (int) $today->diffInDays($expiresAt, false);
            $expired = $daysUntilExpiry < 0;
            $type = $expired
                ? UserNotification::TYPE_CERTIFICATION_EXPIRED
                : UserNotification::TYPE_CERTIFICATION_EXPIRING;
            $key = $this->deduplicationKey(
                (string) $user->id,
                $type,
                'user_certification',
                (string) $userCertification->id,
                $expiresAt->toDateString(),
            );
            $expectedKeys[] = $key;

            $notification = $this->notifications->createOnce([
                'user_id' => (string) $user->id,
                'type' => $type,
                'tone' => $expired ? 'critical' : 'warning',
                'title' => $this->certificationTitle($daysUntilExpiry),
                'message' => $this->certificationMessage(
                    (string) $certification->name,
                    $expiresAt->format('d-m-Y'),
                    $daysUntilExpiry,
                ),
                'action_url' => '/profile?section=certifications&certification='.(string) $userCertification->id,
                'source_type' => 'user_certification',
                'source_id' => (string) $userCertification->id,
                'deduplication_key' => $key,
                'occurred_at' => now(),
                'read_at' => null,
            ]);
            if ($notification !== null) {
                $created++;
                $notifiedUserIds[(string) $user->id] = true;
            }
        }

        $assetWarningDays = max(1, SystemSetting::integer('asset.warning_days_before_expiry', 30));
        $assets = Asset::query()
            ->with(['activeAssignment.user:id,account_status'])
            ->whereNotNull('maintenance_due_at')
            ->whereDate('maintenance_due_at', '<=', $today->addDays($assetWarningDays)->toDateString())
            ->where('status', '!=', 'retired')
            ->whereHas(
                'activeAssignment.user',
                fn ($query) => $query->where('account_status', 'active'),
            )
            ->get();

        foreach ($assets as $asset) {
            $user = $asset->activeAssignment?->user;
            $maintenanceDueAt = $asset->maintenance_due_at;
            if ($user === null || $maintenanceDueAt === null) {
                continue;
            }

            $daysUntilDue = (int) $today->diffInDays($maintenanceDueAt, false);
            $overdue = $daysUntilDue < 0;
            $type = $overdue
                ? UserNotification::TYPE_ASSET_MAINTENANCE_OVERDUE
                : UserNotification::TYPE_ASSET_MAINTENANCE_DUE;
            $key = $this->deduplicationKey(
                (string) $user->id,
                $type,
                'asset',
                (string) $asset->id,
                $maintenanceDueAt->toDateString(),
            );
            $expectedKeys[] = $key;

            $notification = $this->notifications->createOnce([
                'user_id' => (string) $user->id,
                'type' => $type,
                'tone' => $overdue ? 'critical' : 'warning',
                'title' => $this->assetTitle($daysUntilDue),
                'message' => $this->assetMessage(
                    (string) $asset->name,
                    $maintenanceDueAt->format('d-m-Y'),
                    $daysUntilDue,
                ),
                'action_url' => '/profile?section=assets&asset='.(string) $asset->id,
                'source_type' => 'asset',
                'source_id' => (string) $asset->id,
                'deduplication_key' => $key,
                'occurred_at' => now(),
                'read_at' => null,
            ]);
            if ($notification !== null) {
                $created++;
                $notifiedUserIds[(string) $user->id] = true;
            }
        }

        $stale = $this->notifications->deleteStaleReminders(array_values(array_unique($expectedKeys)));

        foreach (array_keys($notifiedUserIds) as $userId) {
            $this->broadcastCreatedForUser($userId);
        }
        foreach ($stale['user_ids'] as $userId) {
            if (! isset($notifiedUserIds[$userId])) {
                $this->broadcastChangedForUser($userId);
            }
        }

        return [
            'active' => count($expectedKeys),
            'created' => $created,
            'removed' => $stale['removed'],
        ];
    }

    public function broadcastCreated(?UserNotification $notification): void
    {
        if ($notification !== null) {
            $this->broadcastCreatedForUser((string) $notification->user_id);
        }
    }

    private function requireWebSession(User $user): void
    {
        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            throw new AuthorizationException;
        }
    }

    private function broadcastCreatedForUser(string $userId): void
    {
        try {
            UserNotificationCreated::dispatch($userId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function broadcastChangedForUser(string $userId): void
    {
        try {
            UserNotificationsChanged::dispatch($userId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function deduplicationKey(
        string $userId,
        string $type,
        string $sourceType,
        string $sourceId,
        string $version,
    ): string {
        return hash('sha256', implode('|', [$userId, $type, $sourceType, $sourceId, $version]));
    }

    private function productRequestStatusLabel(string $status): string
    {
        return match ($status) {
            'in_progress' => 'in behandeling',
            'resolved' => 'opgelost',
            'rejected' => 'afgewezen',
            default => 'open',
        };
    }

    private function certificationTitle(int $daysUntilExpiry): string
    {
        return match (true) {
            $daysUntilExpiry < 0 => 'Certificaat verlopen',
            $daysUntilExpiry === 0 => 'Certificaat verloopt vandaag',
            default => 'Certificaat verloopt binnenkort',
        };
    }

    private function certificationMessage(string $name, string $date, int $daysUntilExpiry): string
    {
        return match (true) {
            $daysUntilExpiry < 0 => sprintf('Je certificaat %s is sinds %s verlopen.', $name, $date),
            $daysUntilExpiry === 0 => sprintf('Je certificaat %s verloopt vandaag.', $name),
            $daysUntilExpiry === 1 => sprintf('Je certificaat %s verloopt morgen (%s).', $name, $date),
            default => sprintf('Je certificaat %s verloopt over %d dagen (%s).', $name, $daysUntilExpiry, $date),
        };
    }

    private function assetTitle(int $daysUntilDue): string
    {
        return match (true) {
            $daysUntilDue < 0 => 'Onderhoudsdatum verstreken',
            $daysUntilDue === 0 => 'Onderhoud vandaag nodig',
            default => 'Onderhoud binnenkort nodig',
        };
    }

    private function assetMessage(string $name, string $date, int $daysUntilDue): string
    {
        return match (true) {
            $daysUntilDue < 0 => sprintf('De onderhoudsdatum van %s is sinds %s verstreken.', $name, $date),
            $daysUntilDue === 0 => sprintf('Onderhoud voor %s staat voor vandaag gepland.', $name),
            $daysUntilDue === 1 => sprintf('Onderhoud voor %s staat morgen gepland (%s).', $name, $date),
            default => sprintf('Onderhoud voor %s staat over %d dagen gepland (%s).', $name, $daysUntilDue, $date),
        };
    }
}
