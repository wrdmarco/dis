<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TestAlertService
{
    private const DEFAULT_MESSAGE = 'Bevestig deze proefalarmering met Ontvangen.';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly DispatchService $dispatchService,
    ) {}

    public function send(User $actor): DispatchRequest
    {
        $actor->load(['fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)]);

        if (! $actor->push_enabled || $actor->fcmTokens->isEmpty()) {
            throw ValidationException::withMessages([
                'push' => ['De ingelogde gebruiker heeft geen actieve push-token. Open de Android app en registreer pushmeldingen eerst.'],
            ]);
        }

        return DB::transaction(function () use ($actor): DispatchRequest {
            $message = $this->message();
            $this->expirePreviousTestAlerts($actor);

            $incident = Incident::query()->create([
                'reference' => $this->nextReference(),
                'title' => 'Proefalarmering',
                'description' => $message,
                'priority' => 'normal',
                'status' => 'active',
                'is_test' => true,
                'created_by' => $actor->id,
                'coordinator_id' => $actor->id,
                'opened_at' => now(),
            ]);

            $dispatch = DispatchRequest::query()->create([
                'incident_id' => $incident->id,
                'requested_by' => $actor->id,
                'target_team_id' => null,
                'status' => 'sent',
                'priority' => 'normal',
                'message' => $message,
                'sent_at' => now(),
            ]);

            DispatchRecipient::query()->create([
                'dispatch_request_id' => $dispatch->id,
                'user_id' => $actor->id,
                'response_status' => 'pending',
                'notified_at' => now(),
            ]);

            foreach ($actor->fcmTokens as $token) {
                SendFcmNotification::dispatch(
                    (string) $token->id,
                    'dispatch_request',
                    'D.I.S proefalarmering',
                    $message,
                    [
                        'type' => 'dispatch_request',
                        'action_mode' => 'test_ack',
                        'is_test' => 'true',
                        'dispatch_id' => (string) $dispatch->id,
                        'incident_id' => (string) $incident->id,
                        'incident_reference' => (string) $incident->reference,
                        'incident_title' => (string) $incident->title,
                        'priority' => 'normal',
                    ],
                    (string) $dispatch->id,
                )->onQueue('push');
            }

            $this->auditService->record('test_alert.sent', $dispatch, $actor, [
                'queued_tokens' => $actor->fcmTokens->count(),
                'incident_id' => $incident->id,
            ]);
            $this->dispatchService->broadcastDispatchChange($dispatch->refresh(), 'test_sent');

            return $dispatch->load(['incident', 'recipients.user']);
        });
    }

    /**
     * @return array{sent_users: int, skipped_users: int}
     */
    public function sendScheduled(): array
    {
        if (! $this->scheduleDue()) {
            return ['sent_users' => 0, 'skipped_users' => 0];
        }

        $users = User::query()
            ->with(['roles', 'fcmTokens' => fn ($tokens) => $tokens->where('is_active', true)])
            ->where('account_status', 'active')
            ->where('push_enabled', true)
            ->whereHas('fcmTokens', fn ($tokens) => $tokens->where('is_active', true))
            ->get()
            ->filter(fn (User $user): bool => $user->canUseOperatorApp())
            ->values();

        $sent = 0;
        $skipped = 0;
        foreach ($users as $user) {
            try {
                $this->send($user);
                $sent++;
            } catch (ValidationException) {
                $skipped++;
            }
        }

        return ['sent_users' => $sent, 'skipped_users' => $skipped];
    }

    /**
     * @return array{enabled: bool, day_of_week: int, time: string, message: string, last_run_at: string|null}
     */
    public function schedule(): array
    {
        return [
            'enabled' => SystemSetting::boolean('test_alert.schedule_enabled', false),
            'day_of_week' => SystemSetting::integer('test_alert.schedule_day_of_week', 1),
            'time' => SystemSetting::string('test_alert.schedule_time', '09:00') ?? '09:00',
            'message' => $this->message(),
            'last_run_at' => SystemSetting::string('test_alert.schedule_last_run_at'),
        ];
    }

    /**
     * @param array{enabled: bool, day_of_week: int, time: string, message: string} $data
     * @return array{enabled: bool, day_of_week: int, time: string, message: string, last_run_at: string|null}
     */
    public function updateSchedule(array $data, string|null $updatedBy): array
    {
        $message = trim($data['message']);
        $settings = [
            'test_alert.schedule_enabled' => (bool) $data['enabled'],
            'test_alert.schedule_day_of_week' => (int) $data['day_of_week'],
            'test_alert.schedule_time' => $data['time'],
            'test_alert.message' => $message !== '' ? $message : self::DEFAULT_MESSAGE,
        ];

        foreach ($settings as $key => $value) {
            SystemSetting::query()->updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'is_sensitive' => false,
                    'updated_by' => $updatedBy,
                ],
            );
        }

        return $this->schedule();
    }

    private function message(): string
    {
        return SystemSetting::string('test_alert.message', self::DEFAULT_MESSAGE) ?? self::DEFAULT_MESSAGE;
    }

    private function scheduleDue(): bool
    {
        $schedule = $this->schedule();
        if (! $schedule['enabled']) {
            return false;
        }

        $now = now();
        if ((int) $schedule['day_of_week'] !== $now->dayOfWeekIso || $schedule['time'] !== $now->format('H:i')) {
            return false;
        }

        $runKey = 'test-alert-schedule:'.$now->format('Y-m-d-H-i');
        if (! Cache::add($runKey, true, $now->copy()->addHours(2))) {
            return false;
        }

        SystemSetting::query()->updateOrCreate(
            ['key' => 'test_alert.schedule_last_run_at'],
            [
                'value' => $now->toIso8601String(),
                'is_sensitive' => false,
                'updated_by' => null,
            ],
        );

        return true;
    }

    public function latestFor(User $actor): DispatchRequest|null
    {
        return DispatchRequest::query()
            ->with(['incident', 'recipients.user'])
            ->where('requested_by', $actor->id)
            ->whereHas('incident', fn ($incident) => $incident->where('is_test', true))
            ->latest()
            ->first();
    }

    private function expirePreviousTestAlerts(User $actor): void
    {
        DispatchRequest::query()
            ->with(['incident', 'recipients'])
            ->where('requested_by', $actor->id)
            ->whereIn('status', ['draft', 'sent', 'escalated'])
            ->whereHas('incident', fn ($incident) => $incident
                ->where('is_test', true)
                ->whereNotIn('status', ['resolved', 'cancelled']))
            ->get()
            ->each(function (DispatchRequest $dispatch) use ($actor): void {
                $dispatch->recipients()
                    ->where('response_status', 'pending')
                    ->update([
                        'response_status' => 'no_response',
                        'response_note' => 'Vervallen door nieuwe proefalarmering.',
                        'responded_at' => now(),
                    ]);

                $dispatch->update(['status' => 'cancelled', 'cancelled_at' => now()]);
                $dispatch->incident?->update(['status' => 'cancelled', 'closed_at' => now()]);

                $this->auditService->record('test_alert.superseded', $dispatch, $actor, [
                    'incident_id' => $dispatch->incident_id,
                ]);
                $this->dispatchService->broadcastDispatchChange($dispatch->refresh(), 'test_superseded');
            });
    }

    private function nextReference(): string
    {
        return 'TEST-'.now()->format('Ymd-His').'-'.strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
    }
}
