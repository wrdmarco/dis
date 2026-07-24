<?php

namespace App\Services;

use App\Exceptions\QueueActionException;
use App\Models\DispatchPushOutbox;
use App\Models\PushQueueWorkItem;
use App\Models\User;
use App\Repositories\QueueMonitorRepository;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

final class QueueActionService
{
    public function __construct(
        private readonly DispatchPushOutboxService $outbox,
        private readonly PushQueueWorkItemActionService $workItemActions,
        private readonly QueueMonitorRepository $monitor,
        private readonly AuditService $audit,
    ) {}

    /** @return array{action:'started'|'retried'} */
    public function execute(
        string $queue,
        string $workItemId,
        string $action,
        User $actor,
        Request $request,
    ): array {
        if ($queue !== 'push' || ! in_array($action, ['start', 'retry'], true)) {
            throw new QueueActionException(
                'queue_action_unsupported',
                422,
                'Deze wachtrijactie wordt niet ondersteund.',
            );
        }

        $before = $this->monitor->findItem($queue, $workItemId);
        if ($before === null) {
            throw new QueueActionException(
                'queue_item_not_found',
                404,
                'De wachtrijtaak bestaat niet meer.',
            );
        }
        if (! in_array($action, $before['available_actions'] ?? [], true)) {
            throw new QueueActionException(
                'queue_action_conflict',
                409,
                'De wachtrijtaak is niet meer beschikbaar voor deze actie.',
            );
        }
        $standaloneTarget = PushQueueWorkItem::query()
            ->whereKey($workItemId)
            ->whereNull('dispatch_push_outbox_id')
            ->first();
        $target = $standaloneTarget
            ?? DispatchPushOutbox::query()->find($workItemId)
            ?? DispatchPushOutbox::class;
        $this->audit->record(
            'system.queue.action_requested',
            $target,
            $actor,
            [
                'queue' => $queue,
                'workload_type' => $before['workload_type'] ?? 'unknown',
                'previous_state' => $before['state'] ?? 'unknown',
                'requested_action' => $action,
            ],
            null,
            $request,
        );

        if ($standaloneTarget !== null) {
            $outcome = $this->workItemActions->execute($workItemId, $action);
        } elseif ($action === 'retry') {
            $outcome = $this->outbox->retryNow($workItemId);
        } else {
            $outcome = $this->workItemActions->startLinkedOutbox($workItemId);
            if ($outcome === 'unsupported') {
                $outcome = $this->outbox->startNow($workItemId);
            }
        }
        if ($outcome === 'failed') {
            throw new QueueActionException(
                'queue_transport_unavailable',
                503,
                'De wachtrij is tijdelijk niet beschikbaar.',
            );
        }
        if ($outcome !== 'queued') {
            throw new QueueActionException(
                'queue_action_conflict',
                409,
                'De wachtrijtaak is niet meer beschikbaar voor deze actie.',
            );
        }

        $resultAction = $action === 'start' ? 'started' : 'retried';
        try {
            $this->audit->record(
                $action === 'start' ? 'system.queue.started' : 'system.queue.retried',
                $target,
                $actor,
                [
                    'queue' => $queue,
                    'workload_type' => $before['workload_type'] ?? 'unknown',
                    'previous_state' => $before['state'] ?? 'unknown',
                    'outcome' => $resultAction,
                ],
                null,
                $request,
            );
        } catch (Throwable $exception) {
            // The durable request audit was written before transport mutation.
            // Never report a successful action as failed merely because its
            // supplemental outcome audit could not be appended.
            report(new RuntimeException(
                'Queue action outcome audit failed in '.$exception::class.'.',
            ));
        }

        return [
            'action' => $resultAction,
        ];
    }
}
