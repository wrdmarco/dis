<?php

namespace App\Services;

use App\Models\KnmiForecastOperation;
use App\Models\KnmiForecastSnapshot;
use Illuminate\Support\Facades\DB;

final class KnmiForecastRestoreService
{
    public function __construct(
        private readonly KnmiOpenDataConfiguration $configuration,
        private readonly KnmiForecastOperationService $operations,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{operations_cleared: int, snapshots_cleared: int, refresh_operation_id: string|null}
     */
    public function reconcile(): array
    {
        $configured = $this->configuration->isConfigured();
        $cleared = DB::transaction(function () use ($configured): array {
            $operationIds = KnmiForecastOperation::query()
                ->lockForUpdate()
                ->pluck('id');
            $snapshotIds = KnmiForecastSnapshot::query()
                ->lockForUpdate()
                ->pluck('id');

            $operationsCleared = $operationIds->isEmpty()
                ? 0
                : KnmiForecastOperation::query()->whereKey($operationIds->all())->delete();
            $snapshotsCleared = $snapshotIds->isEmpty()
                ? 0
                : KnmiForecastSnapshot::query()->whereKey($snapshotIds->all())->delete();

            $this->audit->record(
                action: 'weather.knmi.cache_reconciled_after_restore',
                target: KnmiForecastSnapshot::class,
                actor: null,
                metadata: [
                    'operations_cleared' => $operationsCleared,
                    'snapshots_cleared' => $snapshotsCleared,
                    'refresh_required' => $configured,
                ],
                reason: 'backup-restore',
            );

            return [
                'operations_cleared' => $operationsCleared,
                'snapshots_cleared' => $snapshotsCleared,
            ];
        });

        $refreshOperationId = null;
        if ($configured) {
            // The cache claims are committed before queue I/O. A queue outage
            // must never roll the database back to references for omitted files.
            $refreshOperationId = (string) $this->operations
                ->requestRefresh(scheduled: true)
                ->id;
        }

        return [
            ...$cleared,
            'refresh_operation_id' => $refreshOperationId,
        ];
    }
}
