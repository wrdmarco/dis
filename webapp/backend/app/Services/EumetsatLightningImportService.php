<?php

namespace App\Services;

use App\Repositories\EumetsatLightningSnapshotRepository;
use Carbon\CarbonImmutable;
use Throwable;

final class EumetsatLightningImportService
{
    public function __construct(
        private readonly EumetsatLightningWmsClient $client,
        private readonly EumetsatLightningAtlasBuilder $atlasBuilder,
        private readonly EumetsatLightningSnapshotRepository $snapshots,
    ) {}

    /** @return array{changed: bool, reference_time: string, snapshot_id: string} */
    public function refresh(): array
    {
        $frameTimes = $this->client->latestFrameTimes();
        $latest = end($frameTimes);
        if (! $latest instanceof CarbonImmutable) {
            throw new EumetsatLightningImportException(
                'frame_set_incomplete',
                'The EUMETSAT lightning source has no latest frame.',
            );
        }
        $reference = $latest->utc()->toIso8601String();
        $active = $this->snapshots->activeSnapshot();
        if (is_array($active)
            && is_string($active['latest_frame_at'] ?? null)
            && hash_equals($reference, $active['latest_frame_at'])) {
            return [
                'changed' => false,
                'reference_time' => $active['latest_frame_at'],
                'snapshot_id' => $active['snapshot_id'],
            ];
        }

        $staging = $this->snapshots->createStagingDirectory();
        try {
            $frames = $this->client->downloadFrames($staging, $frameTimes);
            $atlas = $this->atlasBuilder->build($staging, $frames);
            $manifest = $this->snapshots->activate($staging, $frameTimes, $atlas);

            return [
                'changed' => true,
                'reference_time' => $manifest['latest_frame_at'],
                'snapshot_id' => $manifest['snapshot_id'],
            ];
        } catch (Throwable $exception) {
            $this->snapshots->discardStaging($staging);

            throw $exception;
        }
    }
}
