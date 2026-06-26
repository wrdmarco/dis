<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TestAlertService
{
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
            $this->expirePreviousTestAlerts($actor);

            $incident = Incident::query()->create([
                'reference' => $this->nextReference(),
                'title' => 'Proefalarmering',
                'description' => 'Automatische proefalarmering voor controle van pushmelding en opkomstknoppen.',
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
                'message' => 'Proefalarmering D.I.S - bevestig ontvangst.',
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
                    'Bevestig deze proefalarmering met Ontvangen.',
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
