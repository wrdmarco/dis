<?php

namespace App\Services;

use App\Contracts\QueueTransportMetrics;
use App\Repositories\QueueMonitorRepository;
use App\Support\ApiDateTime;

final class QueueMonitorService
{
    private const STATES = [
        'pending',
        'queued',
        'processing',
        'retrying',
        'failed',
        'completed',
        'cancelled',
    ];

    public function __construct(
        private readonly QueueMonitorRepository $repository,
        private readonly QueueTransportMetrics $transport,
    ) {}

    /**
     * @param  array{queue:string,state:string,per_page:int,page:int}  $filters
     * @return array{data:array<string,mixed>,meta:array<string,mixed>}
     */
    public function snapshot(array $filters): array
    {
        $candidateLimit = min(2000, max(100, $filters['page'] * $filters['per_page']));
        $laneCounts = [];
        if (in_array($filters['queue'], ['all', 'push'], true)) {
            $laneCounts['push'] = $this->repository->stateCounts('push');
        }
        if (in_array($filters['queue'], ['all', 'speech'], true)) {
            $laneCounts['speech'] = $this->repository->stateCounts('speech');
        }
        $stateCounts = array_fill_keys(self::STATES, 0);
        foreach ($laneCounts as $counts) {
            foreach ($counts as $state => $count) {
                $stateCounts[$state] += $count;
            }
        }
        $total = $filters['state'] === 'all'
            ? array_sum($stateCounts)
            : ($stateCounts[$filters['state']] ?? 0);
        $visibleTotal = min(2000, $total);
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $items = $this->repository
            ->items($filters['queue'], $filters['state'], $candidateLimit)
            ->sortByDesc('_sort_at')
            ->slice($offset, $filters['per_page'])
            ->map(function (array $item): array {
                unset($item['_sort_at']);

                return $item;
            })
            ->values()
            ->all();

        return [
            'data' => [
                'generated_at' => ApiDateTime::now(),
                'refresh_after_seconds' => max(2, (int) config('dis.queue_monitor.refresh_after_seconds', 5)),
                'summary' => ['total' => array_sum($stateCounts), ...$stateCounts],
                'queues' => $this->queues($laneCounts),
                'items' => $items,
            ],
            'meta' => [
                'current_page' => $filters['page'],
                'per_page' => $filters['per_page'],
                'total' => $visibleTotal,
                'last_page' => max(1, (int) ceil($visibleTotal / $filters['per_page'])),
                'is_truncated' => $total > $visibleTotal,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    /** @param array<string, array<string, int>> $laneCounts */
    private function queues(array $laneCounts): array
    {
        $queues = [];
        if (isset($laneCounts['push'])) {
            $pushStates = $laneCounts['push'];
            $queues[] = [
                'key' => 'push',
                'label' => 'Pushmeldingen',
                'configured_parallelism' => max(
                    1,
                    (int) config('dis.queue_monitor.queues.push.configured_parallelism', 4),
                ),
                'transport_pending_count' => $this->transport->pendingCount('push', 'push'),
                'transport_failed_count' => $this->transport->failedCount('push'),
                'states' => ['total' => array_sum($pushStates), ...$pushStates],
            ];
        }
        if (isset($laneCounts['speech'])) {
            $speechStates = $laneCounts['speech'];
            $queues[] = [
                'key' => 'speech',
                'label' => 'Audio en spraak',
                'configured_parallelism' => max(
                    1,
                    (int) config('dis.queue_monitor.queues.speech.configured_parallelism', 1),
                ),
                'transport_pending_count' => $this->transport->pendingCount('speech', 'speech'),
                'transport_failed_count' => $this->transport->failedCount('speech'),
                'states' => ['total' => array_sum($speechStates), ...$speechStates],
            ];
        }

        return $queues;
    }
}
