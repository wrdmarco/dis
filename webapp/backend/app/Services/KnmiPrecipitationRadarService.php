<?php

namespace App\Services;

use App\Repositories\KnmiPrecipitationSnapshotRepository;
use Carbon\CarbonImmutable;
use Throwable;

final class KnmiPrecipitationRadarService
{
    public function __construct(
        private readonly KnmiPrecipitationSnapshotRepository $snapshots,
        private readonly KnmiPrecipitationConfiguration $configuration,
    ) {}

    /**
     * @return array{
     *   available: bool,
     *   stale: bool,
     *   snapshot_id: string|null,
     *   reference_time: string|null,
     *   refreshed_at: string|null,
     *   atlas: array{width: int, height: int, columns: int, rows: int, frame_width: int, frame_height: int, frame_count: int}|null,
     *   frames: list<array{index: int, valid_at: string, lead_minutes: int}>,
     *   source: array{name: string, url: string, license: string},
     *   availability_note: string|null
     * }
     */
    public function metadata(): array
    {
        try {
            $snapshot = $this->snapshots->activeSnapshot();
            if (! is_array($snapshot)
                || ($snapshot['version'] ?? null) !== 2
                || ! is_array($snapshot['atlas'] ?? null)
                || ! is_string($snapshot['paths']['atlas'] ?? null)) {
                return $this->unavailable();
            }
            $reference = CarbonImmutable::parse($snapshot['reference_time'])->utc();
            $now = CarbonImmutable::now()->utc();
            $future = $reference->greaterThan($now->addMinutes(10));
            $tooOld = $reference->lessThan(
                $now->subSeconds($this->configuration->maximumReferenceAgeSeconds()),
            );
            $stale = $future || $tooOld;
            $atlas = $snapshot['atlas'];
        } catch (Throwable) {
            return $this->unavailable();
        }

        return [
            'available' => true,
            'stale' => $stale,
            'snapshot_id' => $snapshot['snapshot_id'],
            'reference_time' => $snapshot['reference_time'],
            'refreshed_at' => $snapshot['activated_at'],
            'atlas' => [
                'width' => $atlas['width'],
                'height' => $atlas['height'],
                'columns' => $atlas['columns'],
                'rows' => $atlas['rows'],
                'frame_width' => $atlas['frame_width'],
                'frame_height' => $atlas['frame_height'],
                'frame_count' => $atlas['frame_count'],
            ],
            'frames' => $atlas['frames'],
            'source' => $this->source(),
            'availability_note' => match (true) {
                $future => 'De lokale KNMI-buienradar heeft een referentietijd die te ver in de toekomst ligt en is daarom niet actueel bruikbaar.',
                $tooOld => 'De lokale KNMI-buienradar is verouderd; controleer de referentietijd voordat u de kaart gebruikt.',
                default => null,
            },
        ];
    }

    /**
     * Resolve only the exact active snapshot or its single retained v2
     * predecessor. This is an internal file handoff; controllers must never
     * serialize the path into an API response.
     *
     * @return array{path: string, filename: string, media_type: string, size_bytes: int, sha256: string}|null
     */
    public function file(string $snapshotId): ?array
    {
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $snapshotId) !== 1) {
            return null;
        }
        try {
            $snapshot = $this->snapshots->retainedRadarSnapshot($snapshotId);
            if (! is_array($snapshot)
                || ($snapshot['version'] ?? null) !== 2
                || ! is_array($snapshot['atlas'] ?? null)
                || ! is_string($snapshot['paths']['atlas'] ?? null)
                || ! is_string($snapshot['snapshot_id'] ?? null)
                || ! hash_equals($snapshot['snapshot_id'], $snapshotId)) {
                return null;
            }

            return [
                'path' => $snapshot['paths']['atlas'],
                'filename' => $snapshot['atlas']['filename'],
                'media_type' => 'image/png',
                'size_bytes' => $snapshot['atlas']['size_bytes'],
                'sha256' => $snapshot['atlas']['sha256'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{name: string, url: string, license: string} */
    private function source(): array
    {
        return [
            'name' => 'KNMI radar_forecast 2.0',
            'url' => 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
            'license' => 'CC BY 4.0',
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(): array
    {
        return [
            'available' => false,
            'stale' => false,
            'snapshot_id' => null,
            'reference_time' => null,
            'refreshed_at' => null,
            'atlas' => null,
            'frames' => [],
            'source' => $this->source(),
            'availability_note' => 'Er is nog geen volledig gevalideerde lokale KNMI-buienradar beschikbaar.',
        ];
    }
}
