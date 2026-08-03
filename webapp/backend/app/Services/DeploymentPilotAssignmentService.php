<?php

namespace App\Services;

use App\Events\DeploymentChanged;
use App\Models\AvailabilityStatus;
use App\Models\Deployment;
use App\Models\DeploymentPilotAssignment;
use App\Models\DispatchRecipient;
use App\Models\User;
use App\Repositories\DeploymentPilotAssignmentRepository;
use App\Support\ApiDateTime;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class DeploymentPilotAssignmentService
{
    public function __construct(
        private readonly DeploymentPilotAssignmentRepository $assignments,
        private readonly DeploymentAccessService $access,
        private readonly AuditService $audit,
        private readonly PushNotificationService $pushNotifications,
        private readonly LocationService $locationService,
        private readonly StatusService $statusService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function participants(Deployment $deployment, User $actor): array
    {
        $this->access->assertCanViewDeployment($actor, $deployment);

        $dispatchRecipients = $this->assignments
            ->acceptedDispatchRecipients($deployment)
            ->unique(fn (DispatchRecipient $recipient): string => $recipient->user_id === null
                ? 'deleted:'.(string) $recipient->id
                : 'user:'.(string) $recipient->user_id);
        $dispatchUserIds = $dispatchRecipients
            ->pluck('user_id')
            ->filter(fn (mixed $userId): bool => is_string($userId) && $userId !== '')
            ->all();
        $deletedDispatchEmails = $dispatchRecipients
            ->filter(fn (DispatchRecipient $recipient): bool => $recipient->user === null)
            ->map(fn (DispatchRecipient $recipient): ?string => $this->normalizedSnapshotEmail($recipient->user_email))
            ->filter()
            ->all();
        $dispatchParticipants = $dispatchRecipients
            ->map(fn (DispatchRecipient $recipient): array => $this->dispatchParticipantPayload($recipient));
        $manualParticipants = $this->assignments
            ->forDeployment($deployment)
            ->reject(function (DeploymentPilotAssignment $assignment) use ($dispatchUserIds, $deletedDispatchEmails): bool {
                if ($assignment->user_id !== null
                    && in_array((string) $assignment->user_id, $dispatchUserIds, true)) {
                    return true;
                }

                $snapshotEmail = $assignment->user === null
                    ? $this->normalizedSnapshotEmail($assignment->user_email)
                    : null;

                return $snapshotEmail !== null
                    && in_array($snapshotEmail, $deletedDispatchEmails, true);
            })
            ->map(fn (DeploymentPilotAssignment $assignment): array => $this->manualParticipantPayload($assignment));

        return $dispatchParticipants
            ->concat($manualParticipants)
            ->sortBy(fn (array $participant): string => mb_strtolower((string) ($participant['user']['name'] ?? '')))
            ->values()
            ->all();
    }

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function candidates(
        Deployment $deployment,
        User $actor,
        ?string $search,
        int $perPage,
        int $page,
    ): LengthAwarePaginator {
        $this->assertCanManage($actor);
        $this->access->assertCanViewDeployment($actor, $deployment);
        $this->assertAssignable($deployment);

        $paginator = $this->assignments->candidates($deployment, $search, $perPage, $page);
        $paginator->setCollection($paginator->getCollection()
            ->map(static fn (User $user): array => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'teams' => $user->teams->map(static fn ($team): array => [
                    'id' => (string) $team->id,
                    'code' => (string) $team->code,
                    'name' => (string) $team->name,
                    'type' => (string) $team->type,
                    'is_operational' => (bool) $team->is_operational,
                ])->values()->all(),
                'statuses' => $user->statuses->map(static fn ($status): array => [
                    'id' => (string) $status->id,
                    'user_id' => (string) $status->user_id,
                    'status' => (string) $status->status,
                    'is_available' => (bool) $status->is_available,
                    'is_system_applied' => (bool) $status->is_system_applied,
                    'reason' => $status->reason,
                    'effective_at' => ApiDateTime::dateTime($status->effective_at),
                ])->values()->all(),
            ])
            ->values());

        return $paginator;
    }

    /**
     * @return array{participant: array<string, mixed>, queued_tokens: int}
     */
    public function assign(
        Deployment $deployment,
        string $userId,
        string $reason,
        User $actor,
        Request $request,
    ): array {
        $this->assertCanManage($actor);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Leg vast waarom deze piloot handmatig aan de inzet wordt gekoppeld.'],
            ]);
        }

        $assignment = DB::transaction(function () use ($deployment, $userId, $reason, $actor, $request): DeploymentPilotAssignment {
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCanViewDeployment($actor, $deployment);
            $this->assertAssignable($deployment);

            $pilot = $this->assignments->eligiblePilotForUpdate($userId);
            if ($pilot === null) {
                throw ValidationException::withMessages([
                    'user_id' => ['Kies een actieve OCP-piloot met operationele toegang tot de Operator-app.'],
                ]);
            }
            if ($this->assignments->hasBlockingDispatchParticipation($deployment, $pilot)) {
                throw ValidationException::withMessages([
                    'user_id' => ['Deze piloot neemt al deel of heeft nog een openstaande alarmreactie.'],
                ]);
            }
            if ($this->assignments->assignmentForUpdate($deployment, $pilot) !== null) {
                throw ValidationException::withMessages([
                    'user_id' => ['Deze piloot is al handmatig aan deze inzet gekoppeld.'],
                ]);
            }

            $assignment = $this->assignments->createAssignment([
                'deployment_id' => $deployment->id,
                'user_id' => $pilot->id,
                'user_name' => $pilot->name,
                'user_email' => $pilot->email,
                'assigned_by' => $actor->id,
                'assigned_by_name' => $actor->name,
                'assigned_by_email' => $actor->email,
                'reason' => $reason,
                'assigned_at' => now(),
            ]);
            $this->audit->record(
                'deployments.pilot_manually_linked',
                $deployment,
                $actor,
                [
                    'deployment_id' => (string) $deployment->id,
                    'assignment_id' => (string) $assignment->id,
                    'user_id' => (string) $assignment->user_id,
                    'user_name' => $assignment->user_name,
                    'source' => 'manual',
                ],
                $assignment->reason,
                $request,
            );
            $this->statusService->reconcileDeploymentProgressAfterParticipantChange($deployment, $actor);

            return $assignment->load([
                'user.teams',
                'user.statuses' => fn ($statuses) => $statuses->latestPerUser(),
            ]);
        }, 3);

        // The assignment transaction is committed before an informational
        // mobile wake-up is queued. It is never represented as an alarm,
        // dispatch recipient or dispatch outbox row.
        $queuedTokens = 0;
        try {
            if ($assignment->user !== null) {
                $queuedTokens = $this->pushNotifications->sendDeploymentAssignment(
                    $assignment->user,
                    $deployment->refresh(),
                );
            }
        } catch (Throwable $exception) {
            report($exception);
        }
        try {
            $this->audit->record(
                'deployments.pilot_manual_notification_queued',
                $deployment,
                $actor,
                [
                    'deployment_id' => (string) $deployment->id,
                    'assignment_id' => (string) $assignment->id,
                    'user_id' => (string) $assignment->user_id,
                    'queued_devices' => $queuedTokens,
                ],
                null,
                $request,
            );
        } catch (Throwable $exception) {
            report($exception);
        }
        $this->broadcast($deployment->refresh(), 'pilot_manually_linked');

        return [
            'participant' => $this->manualParticipantPayload($assignment),
            'queued_tokens' => $queuedTokens,
        ];
    }

    public function remove(
        Deployment $deployment,
        DeploymentPilotAssignment $assignment,
        User $actor,
        Request $request,
    ): void {
        $this->assertCanManage($actor);

        DB::transaction(function () use ($deployment, $assignment, $actor, $request): void {
            $deployment = Deployment::query()->whereKey($deployment->id)->lockForUpdate()->firstOrFail();
            $this->access->assertCanViewDeployment($actor, $deployment);
            $this->assertAssignable($deployment);
            $assignment = $this->assignments->assignmentForDeploymentForUpdate($deployment, $assignment);
            $target = $assignment->user()->first();
            if ($target !== null) {
                $this->locationService->revokeForDeployment($deployment, $target, $actor);
            }

            $this->audit->record(
                'deployments.pilot_manual_link_removed',
                $deployment,
                $actor,
                [
                    'deployment_id' => (string) $deployment->id,
                    'assignment_id' => (string) $assignment->id,
                    'user_id' => $assignment->user_id,
                    'user_name' => $assignment->user_name,
                    'source' => 'manual',
                    'assignment_reason' => $assignment->reason,
                ],
                null,
                $request,
            );
            $assignment->delete();
            $this->statusService->reconcileDeploymentProgressAfterParticipantChange($deployment, $actor);
        }, 3);

        $this->broadcast($deployment->refresh(), 'pilot_manual_link_removed');
    }

    public function updateStatus(
        Deployment $deployment,
        User $pilot,
        string $status,
        string $reason,
        User $actor,
    ): AvailabilityStatus {
        $this->assertCanUpdateStatus($actor);
        $status = trim($status);
        $reason = trim($reason);
        if (! in_array($status, ['en_route', 'on_scene'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Kies Onderweg of Op locatie.'],
            ]);
        }
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Leg vast waarom de operationele status wordt aangepast.'],
            ]);
        }

        return $this->statusService->setStatusWithPrecondition(
            $pilot,
            $status,
            $actor,
            $reason,
            function () use ($deployment, $pilot, $actor): void {
                $lockedDeployment = Deployment::query()
                    ->whereKey($deployment->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->access->assertCanViewDeployment($actor, $lockedDeployment);
                $this->assertAssignable($lockedDeployment);

                if (! $this->assignments->isOperationalParticipant($lockedDeployment, $pilot)) {
                    throw ValidationException::withMessages([
                        'user_id' => ['Alleen aan deze inzet gekoppelde piloten kunnen vanuit de inzetdetailpagina worden bijgewerkt.'],
                    ]);
                }
            },
        );
    }

    /** @return array<string, mixed> */
    public function manualParticipantPayload(DeploymentPilotAssignment $assignment): array
    {
        return [
            'id' => (string) $assignment->id,
            'user_id' => $this->mutableUserId($assignment->user),
            'source' => 'manual',
            'linked_at' => ApiDateTime::dateTime($assignment->assigned_at),
            'user' => $this->userPayload(
                $assignment->user,
                $assignment->user_name,
                $assignment->user_email,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function dispatchParticipantPayload(DispatchRecipient $recipient): array
    {
        $dispatch = $recipient->dispatchRequest;

        return [
            'id' => (string) $recipient->id,
            'user_id' => $this->mutableUserId($recipient->user),
            'source' => 'dispatch',
            'linked_at' => ApiDateTime::dateTime(
                $recipient->responded_at
                    ?? $recipient->notified_at
                    ?? $dispatch?->sent_at
                    ?? $recipient->created_at,
            ),
            'user' => $this->userPayload(
                $recipient->user,
                $recipient->user_name,
                $recipient->user_email,
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(?User $user, mixed $name, mixed $email): array
    {
        return [
            'id' => $this->mutableUserId($user),
            'name' => $user?->name ?? (is_string($name) && $name !== '' ? $name : 'Verwijderde gebruiker'),
            'email' => $user?->email ?? (is_string($email) && $email !== '' ? $email : null),
            'account_status' => $user?->account_status ?? 'blocked',
            'push_enabled' => (bool) ($user?->push_enabled ?? false),
            'max_operator_devices' => (int) ($user?->max_operator_devices ?? 0),
            'two_factor_enabled' => (bool) ($user?->two_factor_enabled ?? false),
            'teams' => $user?->relationLoaded('teams') === true
                ? $user->teams->map(static fn ($team): array => [
                    'id' => (string) $team->id,
                    'code' => (string) $team->code,
                    'name' => (string) $team->name,
                    'type' => (string) $team->type,
                    'is_operational' => (bool) $team->is_operational,
                ])->values()->all()
                : [],
            'statuses' => $user?->relationLoaded('statuses') === true
                ? $user->statuses->map(static fn ($status): array => [
                    'id' => (string) $status->id,
                    'user_id' => (string) $status->user_id,
                    'status' => (string) $status->status,
                    'is_available' => (bool) $status->is_available,
                    'is_system_applied' => (bool) $status->is_system_applied,
                    'reason' => $status->reason,
                    'effective_at' => ApiDateTime::dateTime($status->effective_at),
                ])->values()->all()
                : [],
        ];
    }

    private function mutableUserId(?User $user): ?string
    {
        return $user === null || $user->trashed()
            ? null
            : (string) $user->id;
    }

    private function normalizedSnapshotEmail(mixed $email): ?string
    {
        if (! is_string($email) || trim($email) === '') {
            return null;
        }

        return mb_strtolower(trim($email), 'UTF-8');
    }

    private function assertCanManage(User $actor): void
    {
        if (! $actor->hasPermission('deployments.dispatch.manage')) {
            throw new AuthorizationException;
        }
    }

    private function assertCanUpdateStatus(User $actor): void
    {
        if (! $actor->hasPermission('deployments.dispatch.manage')
            && ! $actor->hasPermission('status.override')) {
            throw new AuthorizationException;
        }
    }

    private function assertAssignable(Deployment $deployment): void
    {
        if ($deployment->is_test || ! in_array($deployment->status, ['active', 'dispatching', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'deployment_id' => ['Piloten kunnen alleen worden beheerd binnen een actieve, operationele inzet.'],
            ]);
        }
    }

    private function broadcast(Deployment $deployment, string $action): void
    {
        try {
            DeploymentChanged::dispatch($deployment, $action);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
