<?php

namespace App\Services;

use App\Jobs\SendFcmNotification;
use App\Models\PushQueueWorkItem;
use App\Repositories\PushQueueWorkItemRepository;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PushQueueWorkItemActionService
{
    private const CALL_QUEUED_HANDLER = 'Illuminate\Queue\CallQueuedHandler@call';

    /**
     * @return 'queued'|'conflict'|'failed'|'unsupported'
     *
     * queued: the existing job was safely prioritized.
     * conflict: the ledger or transport state is no longer actionable.
     * failed: Redis was unavailable or returned an invalid response.
     * unsupported: this ID is not a non-outbox push lifecycle item.
     */
    public function execute(string $workItemId, string $action): string
    {
        if ($action !== 'start') {
            return 'unsupported';
        }

        try {
            return $this->workItems->startManually(
                $workItemId,
                fn (PushQueueWorkItem $item): string => $this->prioritize($item),
            );
        } catch (Throwable $exception) {
            // Redis client exceptions can contain command arguments. Report
            // only the exception class so an encrypted notification payload
            // can never reach application logs.
            report(new RuntimeException(
                'Manual push queue action failed in '.$exception::class.'.',
            ));

            return 'failed';
        }
    }

    /**
     * Prioritize the exact active Redis job linked to a durable outbox row.
     *
     * @return 'queued'|'conflict'|'failed'|'unsupported'
     */
    public function startLinkedOutbox(string $outboxId): string
    {
        try {
            return $this->workItems->startLinkedOutboxManually(
                $outboxId,
                fn (PushQueueWorkItem $item): string => $this->prioritize($item),
            );
        } catch (Throwable $exception) {
            report(new RuntimeException(
                'Manual linked push queue action failed in '.$exception::class.'.',
            ));

            return 'failed';
        }
    }

    public function __construct(
        private readonly PushQueueWorkItemRepository $workItems,
        private readonly QueueManager $queues,
        private readonly PushQueueManualActionPolicy $manualActionPolicy,
    ) {}

    /** @return 'queued'|'conflict'|'failed' */
    private function prioritize(PushQueueWorkItem $item): string
    {
        if (! $this->manualActionPolicy->canStart($item)) {
            return 'conflict';
        }

        $transport = $this->pushTransport();
        if ($transport === null) {
            return 'failed';
        }

        [, $connection, $queueKey] = $transport;
        $payload = $this->locateActivePayload(
            $connection,
            $queueKey,
            (string) $item->queue_job_uuid,
            (string) $item->queue_job_id,
        );
        if ($payload === null || $payload['location'] === 'reserved') {
            return 'conflict';
        }

        $result = $connection->eval(
            <<<'LUA'
local ready_type = redis.call('type', KEYS[1]).ok
local delayed_type = redis.call('type', KEYS[2]).ok
local notify_type = redis.call('type', KEYS[3]).ok
if (ready_type ~= 'none' and ready_type ~= 'list')
    or (delayed_type ~= 'none' and delayed_type ~= 'zset')
    or (notify_type ~= 'none' and notify_type ~= 'list') then
    return -2
end

local ready_position = redis.call('lpos', KEYS[1], ARGV[1])
local delayed_score = redis.call('zscore', KEYS[2], ARGV[1])

if ready_position and delayed_score then
    return -1
end

if not ready_position and not delayed_score then
    return 0
end

if ready_position then
    if redis.call('lrem', KEYS[1], 1, ARGV[1]) ~= 1 then
        return 0
    end
    redis.call('lpush', KEYS[1], ARGV[1])
    return 1
end

if redis.call('zrem', KEYS[2], ARGV[1]) ~= 1 then
    return 0
end
redis.call('lpush', KEYS[1], ARGV[1])
redis.call('rpush', KEYS[3], 1)
return 1
LUA,
            3,
            $queueKey,
            $queueKey.':delayed',
            $queueKey.':notify',
            $payload['raw'],
        );

        $resultCode = is_int($result)
            ? $result
            : (is_string($result) && preg_match('/^-?\d+$/', $result) === 1
                ? (int) $result
                : null);
        if ($resultCode === 1) {
            return 'queued';
        }
        if ($resultCode === 0) {
            return 'conflict';
        }

        report(new RuntimeException(
            'Manual push queue action encountered an invalid Redis queue state.',
        ));

        return 'failed';
    }

    /**
     * @return array{0: RedisQueue, 1: Connection, 2: string}|null
     */
    private function pushTransport(): ?array
    {
        $queue = $this->queues->connection('push');
        if (! $queue instanceof RedisQueue) {
            return null;
        }

        $connection = $queue->getConnection();
        $queueName = 'push';
        $queueKey = $connection->isCluster() && ! Connection::hasHashTag($queueName)
            ? $queue->getQueue('{'.$queueName.'}')
            : $queue->getQueue($queueName);

        return [$queue, $connection, $queueKey];
    }

    /**
     * @return array{location: 'ready'|'delayed'|'reserved', raw: string}|null
     */
    private function locateActivePayload(
        Connection $connection,
        string $queueKey,
        string $expectedUuid,
        string $expectedOpaqueId,
    ): ?array {
        $limit = max(
            1,
            min(
                5000,
                (int) config('dis.queue_monitor.manual_action_scan_limit', 1000),
            ),
        );
        $matches = [];
        foreach ([
            'ready' => $connection->lrange($queueKey, 0, $limit - 1),
            'delayed' => $connection->zrange($queueKey.':delayed', 0, $limit - 1),
            'reserved' => $connection->zrange($queueKey.':reserved', 0, $limit - 1),
        ] as $location => $payloads) {
            foreach (is_array($payloads) ? $payloads : [] as $rawPayload) {
                if (! is_string($rawPayload)) {
                    continue;
                }
                $decoded = json_decode($rawPayload, true);
                if (! is_array($decoded) || ($decoded['uuid'] ?? null) !== $expectedUuid) {
                    continue;
                }
                if ($this->validatedPayload(
                    $rawPayload,
                    $expectedUuid,
                    $expectedOpaqueId,
                ) === null) {
                    return null;
                }

                $matches[] = [
                    'location' => $location,
                    'raw' => $rawPayload,
                ];
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        /** @var array{location: 'ready'|'delayed'|'reserved', raw: string} */
        return $matches[0];
    }

    /** @return array<string, mixed>|null */
    private function validatedPayload(
        string $rawPayload,
        string $expectedUuid,
        string $expectedOpaqueId,
    ): ?array {
        try {
            $payload = json_decode(
                $rawPayload,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return null;
        }

        if (! Str::isUuid($expectedUuid)
            || ! is_array($payload)
            || ($payload['uuid'] ?? null) !== $expectedUuid
            || ($payload['displayName'] ?? null) !== SendFcmNotification::class
            || ($payload['job'] ?? null) !== self::CALL_QUEUED_HANDLER
            || ! is_array($payload['data'] ?? null)
            || ($payload['data']['commandName'] ?? null) !== SendFcmNotification::class
            || ! is_string($payload['data']['command'] ?? null)
            || ! is_string($payload['id'] ?? null)
            || ! hash_equals($expectedOpaqueId, hash('sha256', $payload['id']))) {
            return null;
        }

        return $payload;
    }
}
