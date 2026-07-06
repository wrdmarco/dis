<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\FcmToken;
use App\Models\SystemSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PushNotificationService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly StatusService $statusService,
    ) {}

    /**
     * @param array<string, mixed> $data
     * @return array{queued_tokens: int, recipient_users: int}
     */
    public function sendManual(User $actor, array $data): array
    {
        $this->ensureFirebaseConfigured();

        /** @var Collection<int, User> $users */
        $users = User::query()
            ->with(['fcmTokens' => fn ($tokens) => $this->onlineOperatorTokenQuery($tokens)])
            ->where('account_status', 'active')
            ->where('push_enabled', true)
            ->whereHas('fcmTokens', fn ($tokens) => $this->onlineOperatorTokenQuery($tokens))
            ->where(function (Builder $query) use ($data): void {
                $teamIds = $this->expandTeamIds($data['team_ids'] ?? []);
                $roleIds = $data['role_ids'] ?? [];
                $userIds = $data['user_ids'] ?? [];

                if ($teamIds !== []) {
                    $query->orWhereHas('teams', fn ($teams) => $teams->whereIn('teams.id', $teamIds));
                }

                if ($roleIds !== []) {
                    $query->orWhereHas('roles', fn ($roles) => $roles->whereIn('roles.id', $roleIds));
                }

                if ($userIds !== []) {
                    $query->orWhereIn('id', $userIds);
                }
            })
            ->get();

        $queuedTokens = 0;
        foreach ($users as $user) {
            foreach ($user->fcmTokens as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'manual_admin',
                    (string) $data['title'],
                    (string) $data['body'],
                    [
                        'type' => 'manual_admin',
                        'sent_by' => (string) $actor->id,
                    ],
                )->onQueue('push');
                $queuedTokens++;
            }
        }

        $this->auditService->record('push.manual_sent', User::class, $actor, [
            'team_ids' => $data['team_ids'] ?? [],
            'expanded_team_ids' => $this->expandTeamIds($data['team_ids'] ?? []),
            'role_ids' => $data['role_ids'] ?? [],
            'user_ids' => $data['user_ids'] ?? [],
            'recipient_users' => $users->count(),
            'queued_tokens' => $queuedTokens,
        ]);

        return [
            'queued_tokens' => $queuedTokens,
            'recipient_users' => $users->count(),
        ];
    }

    public function revokeToken(FcmToken $token, ?User $actor): void
    {
        DB::transaction(function () use ($token, $actor): void {
            $token->update(['is_active' => false, 'revoked_at' => now()]);
            $user = $token->user;

            if ($user !== null && ! $user->fcmTokens()->where('is_active', true)->exists()) {
                $user->update(['push_enabled' => false]);
                $this->statusService->enforcePushUnavailable($user);
            }

            $this->auditService->record('push.token_admin_revoked', $token, $actor, [
                'user_id' => $token->user_id,
                'device_id' => $token->device_id,
            ]);
        });
    }

    public function activateToken(FcmToken $token, ?User $actor): void
    {
        DB::transaction(function () use ($token, $actor): void {
            $token->update(['is_active' => true, 'revoked_at' => null, 'last_seen_at' => now()]);
            $token->user?->update(['push_enabled' => true]);

            $this->auditService->record('push.token_admin_activated', $token, $actor, [
                'user_id' => $token->user_id,
                'device_id' => $token->device_id,
            ]);
        });
    }

    /**
     * @param array<int, string> $teamIds
     * @return array<int, string>
     */
    private function expandTeamIds(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $alertTeamIds = Team::query()
            ->whereIn('id', $teamIds)
            ->with('alertTeams:id')
            ->get()
            ->flatMap(fn (Team $team) => $team->alertTeams->pluck('id'))
            ->all();

        return array_values(array_unique([...$teamIds, ...$alertTeamIds]));
    }

    private function ensureFirebaseConfigured(): void
    {
        $projectId = SystemSetting::string('firebase.project_id', config('dis.push.fcm_project_id'));
        $credentials = SystemSetting::value('firebase.service_account', []);

        if (! filled($projectId)) {
            throw ValidationException::withMessages(['firebase' => ['Firebase project id is not configured.']]);
        }

        if (! is_array($credentials) || ! filled($credentials['client_email'] ?? null) || ! filled($credentials['private_key'] ?? null)) {
            throw ValidationException::withMessages(['firebase' => ['Firebase service account is not configured.']]);
        }
    }

    private function onlineOperatorTokenQuery($tokens)
    {
        return $tokens
            ->where('is_active', true)
            ->where('client_type', 'operator')
            ->where('last_seen_at', '>', now()->subMinutes(FcmToken::onlineThresholdMinutes()));
    }
}
