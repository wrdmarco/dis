<?php

namespace App\Services;

use App\Repositories\KnmiPrecipitationSnapshotRepository;
use Carbon\CarbonImmutable;
use Throwable;

final class KnmiPrecipitationImportService
{
    public function __construct(
        private readonly KnmiPrecipitationOpenDataClient $client,
        private readonly KnmiPrecipitationHdf5Reader $reader,
        private readonly KnmiPrecipitationSnapshotRepository $snapshots,
        private readonly WallboardForecastLocationService $locations,
    ) {}

    /** @return array{changed: bool, reference_time: string, snapshot_id: string} */
    public function refresh(): array
    {
        $files = $this->client->latestMatchingFiles();
        $active = $this->snapshots->activeSnapshot();
        $reference = $files['radar']->referenceTime->toIso8601String();
        if (is_array($active)
            && is_string($active['reference_time'] ?? null)
            && hash_equals($reference, $active['reference_time'])
            && ($active['files']['radar']['filename'] ?? null) === $files['radar']->filename
            && ($active['files']['radar']['size_bytes'] ?? null) === $files['radar']->sizeBytes
            && ($active['files']['probability']['filename'] ?? null) === $files['probability']->filename
            && ($active['files']['probability']['size_bytes'] ?? null) === $files['probability']->sizeBytes) {
            return [
                'changed' => false,
                'reference_time' => $active['reference_time'],
                'snapshot_id' => $active['snapshot_id'],
            ];
        }

        $staging = $this->snapshots->createStagingDirectory();
        try {
            $radarPath = $staging.DIRECTORY_SEPARATOR.$files['radar']->filename;
            $probabilityPath = $staging.DIRECTORY_SEPARATOR.$files['probability']->filename;
            $sha256 = [
                'radar' => $this->client->download($files['radar'], $radarPath),
                'probability' => $this->client->download($files['probability'], $probabilityPath),
            ];
            $this->reader->validatePair($radarPath, $probabilityPath, $files['radar']->referenceTime);
            $this->validateNationalPoints(
                $radarPath,
                $probabilityPath,
                $files['radar']->referenceTime,
            );
            $manifest = $this->snapshots->activate($staging, $files, $sha256);

            return [
                'changed' => true,
                'reference_time' => $manifest['reference_time'],
                'snapshot_id' => $manifest['snapshot_id'],
            ];
        } catch (Throwable $exception) {
            $this->snapshots->discardStaging($staging);

            throw $exception;
        }
    }

    private function validateNationalPoints(
        string $radarPath,
        string $probabilityPath,
        CarbonImmutable $reference,
    ): void {
        $resolution = $this->locations->resolve([
            'location_mode' => WallboardForecastLocationService::MODE_NETHERLANDS,
        ]);
        $locations = $resolution['locations'] ?? null;
        $expected = $resolution['expected_locations'] ?? null;
        if (($resolution['complete'] ?? false) !== true
            || ! is_array($locations)
            || ! array_is_list($locations)
            || ! is_int($expected)
            || $expected < 1
            || $expected > 12
            || count($locations) !== $expected) {
            throw new \RuntimeException('The national KNMI precipitation validation locations are incomplete.');
        }
        foreach ($locations as $location) {
            if (! is_array($location)
                || ! is_numeric($location['latitude'] ?? null)
                || ! is_numeric($location['longitude'] ?? null)) {
                throw new \RuntimeException('A national KNMI precipitation validation location is invalid.');
            }
            $this->reader->readPoint(
                $radarPath,
                $probabilityPath,
                $reference,
                (float) $location['latitude'],
                (float) $location['longitude'],
            );
        }
    }
}
