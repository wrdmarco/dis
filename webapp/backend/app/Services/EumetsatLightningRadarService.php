<?php

namespace App\Services;

use App\Repositories\EumetsatLightningSnapshotRepository;
use Carbon\CarbonImmutable;
use Throwable;

final class EumetsatLightningRadarService
{
    private const INTERVAL_MINUTES = 5;

    private const SOURCE = [
        'name' => 'EUMETSAT MTG Lightning Imager',
        'url' => 'https://view.eumetsat.int/',
        'layer' => 'mtg_fd:li_afa',
    ];

    private const LICENSE = [
        'name' => 'EUMETSAT Data Policy (vrije EUMETView-toegang)',
        'url' => 'https://www.eumetsat.int/eumetsat-data-policy',
    ];

    public function __construct(
        private readonly EumetsatLightningSnapshotRepository $snapshots,
        private readonly EumetsatLightningConfiguration $configuration,
    ) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        try {
            $snapshot = $this->snapshots->activeSnapshot();
            if ($snapshot === null) {
                return $this->unavailable(
                    false,
                    'Er is nog geen geldige lokale EUMETSAT-bliksemsnapshot beschikbaar.',
                );
            }

            $latest = CarbonImmutable::parse($snapshot['latest_frame_at'])->utc();
            $now = CarbonImmutable::now()->utc();
            if ($latest->greaterThan($now->addMinute())) {
                return $this->unavailable(
                    true,
                    'De lokale EUMETSAT-bliksemsnapshot heeft een referentietijd die meer dan één minuut in de toekomst ligt en telt daarom als onbekend.',
                    $snapshot['latest_frame_at'],
                    $snapshot['activated_at'],
                );
            }

            $periodEnd = $latest->addMinutes($this->configuration->intervalMinutes());
            $ageSeconds = max(0, (int) $periodEnd->diffInSeconds($now, false));
            if ($ageSeconds > $this->configuration->maximumFallbackAgeSeconds()) {
                return $this->unavailable(
                    true,
                    'De laatste EUMETSAT-waarnemingsperiode is ouder dan twee uur en wordt niet meer als kaartbeeld getoond.',
                    $snapshot['latest_frame_at'],
                    $snapshot['activated_at'],
                );
            }

            $stale = $ageSeconds > $this->configuration->maximumAgeSeconds();
            $maximumAgeMinutes = intdiv($this->configuration->maximumAgeSeconds(), 60);

            return $this->snapshotMetadata(
                $snapshot,
                ! $stale,
                $stale,
                $stale
                    ? "De laatste EUMETSAT-waarnemingsperiode is ouder dan {$maximumAgeMinutes} minuten. Het laatst gevalideerde beeld blijft tijdelijk als verouderde terugval zichtbaar."
                    : null,
                $now,
            );
        } catch (Throwable) {
            return $this->unavailable(
                false,
                'De lokale EUMETSAT-bliksemsnapshot is onvolledig of beschadigd en telt daarom als onbekend.',
            );
        }
    }

    /**
     * @return array{path: string, size_bytes: int, sha256: string, content_type: string, last_modified: string}|null
     */
    public function file(string $snapshotId): ?array
    {
        if (preg_match('/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D', $snapshotId) !== 1) {
            return null;
        }

        try {
            $snapshot = $this->snapshots->retainedSnapshot($snapshotId);
            if ($snapshot === null || ! $this->withinFallbackWindow($snapshot['latest_frame_at'])) {
                return null;
            }

            return [
                'path' => $snapshot['path'],
                'size_bytes' => $snapshot['atlas']['size_bytes'],
                'sha256' => $snapshot['atlas']['sha256'],
                'content_type' => 'image/png',
                'last_modified' => $snapshot['activated_at'],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private function withinFallbackWindow(string $latestFrameAt): bool
    {
        $latest = CarbonImmutable::parse($latestFrameAt)->utc();
        $now = CarbonImmutable::now()->utc();
        $periodEnd = $latest->addMinutes($this->configuration->intervalMinutes());

        return ! $latest->greaterThan($now->addMinute())
            && $periodEnd->diffInSeconds($now, false)
                <= $this->configuration->maximumFallbackAgeSeconds();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function snapshotMetadata(
        array $snapshot,
        bool $available,
        bool $stale,
        ?string $note,
        CarbonImmutable $now,
    ): array {
        $latest = CarbonImmutable::parse($snapshot['latest_frame_at'])->utc();
        $periodEnd = $latest->addMinutes($this->configuration->intervalMinutes());
        $refreshed = CarbonImmutable::parse($snapshot['activated_at'])->utc();

        return [
            'available' => $available,
            'stale' => $stale,
            'snapshot_id' => $snapshot['snapshot_id'],
            'latest_frame_at' => $snapshot['latest_frame_at'],
            'observed_period_end' => $periodEnd->toIso8601String(),
            'age_seconds' => max(0, (int) $periodEnd->diffInSeconds($now, false)),
            'lag_seconds' => max(0, (int) $periodEnd->diffInSeconds($refreshed, false)),
            'refreshed_at' => $snapshot['activated_at'],
            'frame_count' => count($snapshot['frames']),
            'interval_minutes' => $this->configuration->intervalMinutes(),
            'frames' => $snapshot['frames'],
            'atlas' => [
                'columns' => $snapshot['atlas']['columns'],
                'rows' => $snapshot['atlas']['rows'],
                'frame_width' => $snapshot['atlas']['frame_width'],
                'frame_height' => $snapshot['atlas']['frame_height'],
                'width' => $snapshot['atlas']['width'],
                'height' => $snapshot['atlas']['height'],
                'frame_count' => count($snapshot['frames']),
            ],
            'source' => $snapshot['source'],
            'license' => $snapshot['license'],
            'availability_note' => $note,
        ];
    }

    /** @return array<string, mixed> */
    private function unavailable(
        bool $stale,
        string $note,
        ?string $latestFrameAt = null,
        ?string $refreshedAt = null,
    ): array {
        $periodEnd = null;
        $ageSeconds = null;
        $lagSeconds = null;

        try {
            if ($latestFrameAt !== null) {
                $period = CarbonImmutable::parse($latestFrameAt)
                    ->utc()
                    ->addMinutes($this->configuration->intervalMinutes());
                $periodEnd = $period->toIso8601String();
                $ageSeconds = max(
                    0,
                    (int) $period->diffInSeconds(CarbonImmutable::now()->utc(), false),
                );
                if ($refreshedAt !== null) {
                    $lagSeconds = max(
                        0,
                        (int) $period->diffInSeconds(
                            CarbonImmutable::parse($refreshedAt)->utc(),
                            false,
                        ),
                    );
                }
            }
        } catch (Throwable) {
            $periodEnd = null;
            $ageSeconds = null;
            $lagSeconds = null;
        }

        return [
            'available' => false,
            'stale' => $stale,
            'snapshot_id' => null,
            'latest_frame_at' => $latestFrameAt,
            'observed_period_end' => $periodEnd,
            'age_seconds' => $ageSeconds,
            'lag_seconds' => $lagSeconds,
            'refreshed_at' => $refreshedAt,
            'frame_count' => 0,
            'interval_minutes' => self::INTERVAL_MINUTES,
            'frames' => [],
            'atlas' => null,
            'source' => self::SOURCE,
            'license' => self::LICENSE,
            'availability_note' => $note,
        ];
    }
}
