<?php

namespace App\Services;

use App\Contracts\OperationalRadarProvider;
use App\Support\OperationalRadarContent;
use Carbon\CarbonImmutable;
use Throwable;

final class OperationalRadarService implements OperationalRadarProvider
{
    private const SNAPSHOT_PATTERN = '/\A\d{8}T\d{6}Z-[a-f0-9]{16}\z/D';

    public function __construct(
        private readonly KnmiPrecipitationRadarService $precipitation,
        private readonly EumetsatLightningRadarService $lightning,
    ) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        try {
            $precipitation = $this->precipitationMetadata($this->precipitation->metadata());
        } catch (Throwable) {
            $precipitation = $this->precipitationMetadata([
                'availability_note' => 'De lokale KNMI-buienradar kon niet veilig worden gelezen.',
            ]);
        }
        try {
            $lightning = $this->lightningMetadata($this->lightning->metadata());
        } catch (Throwable) {
            $lightning = $this->lightningMetadata([
                'availability_note' => 'De lokale EUMETSAT-bliksemradar kon niet veilig worden gelezen.',
            ]);
        }

        return [
            'precipitation' => $precipitation,
            'lightning' => $lightning,
        ];
    }

    public function file(string $kind, string $snapshotId): ?OperationalRadarContent
    {
        if (preg_match(self::SNAPSHOT_PATTERN, $snapshotId) !== 1) {
            return null;
        }

        try {
            $file = match ($kind) {
                'precipitation' => $this->precipitation->file($snapshotId),
                'lightning' => $this->lightning->file($snapshotId),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
        if (! is_array($file)) {
            return null;
        }

        $path = $file['path'] ?? null;
        $size = $file['size_bytes'] ?? null;
        $sha256 = $file['sha256'] ?? null;
        $contentType = $file['content_type'] ?? $file['media_type'] ?? null;
        if (! is_string($path)
            || $path === ''
            || str_contains($path, "\0")
            || ! is_int($size)
            || $size < 1
            || ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
            || $contentType !== 'image/png') {
            return null;
        }

        return new OperationalRadarContent($path, $size, $sha256);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function precipitationMetadata(array $metadata): array
    {
        $referenceTime = $this->timestamp($metadata['reference_time'] ?? null);
        $refreshedAt = $this->timestamp($metadata['refreshed_at'] ?? null);
        $frames = $this->precipitationFrames($metadata['frames'] ?? null, $referenceTime);
        $atlas = $this->atlas($metadata['atlas'] ?? null, 5, 5, 25);
        $snapshotId = $this->snapshotId($metadata['snapshot_id'] ?? null);
        $stale = ($metadata['stale'] ?? false) === true;
        $integral = (($metadata['available'] ?? false) === true || $stale)
            && $snapshotId !== null
            && $atlas !== null
            && count($frames) === 25;
        $now = CarbonImmutable::now()->utc();
        $reference = $referenceTime === null ? null : CarbonImmutable::parse($referenceTime)->utc();
        $lastFrame = $frames === []
            ? null
            : CarbonImmutable::parse($frames[array_key_last($frames)]['valid_at'])->utc();
        $future = $reference?->greaterThan($now->addMinutes(10)) ?? false;
        $displayable = $integral
            && ! $future
            && (! $stale || ($lastFrame?->greaterThanOrEqualTo($now) ?? false));
        $availabilityNote = $this->note($metadata['availability_note'] ?? null);
        if ($integral && $stale && ! $displayable && ! $future) {
            $availabilityNote = 'Alle tijdstappen van de laatst gevalideerde KNMI-radarverwachting zijn verstreken; de kaart wordt daarom niet meer getoond.';
        }

        return $this->publicLayer(
            kind: 'precipitation',
            available: $integral && ! $stale,
            stale: $stale,
            displayable: $displayable,
            snapshotId: $snapshotId,
            referenceTime: $referenceTime,
            observedPeriodEnd: null,
            ageSeconds: $this->ageSeconds($referenceTime),
            lagSeconds: $this->lagSeconds($referenceTime, $refreshedAt),
            refreshedAt: $refreshedAt,
            atlas: $atlas,
            frames: $frames,
            fixedColumns: 5,
            fixedRows: 5,
            source: [
                'name' => 'KNMI radar_forecast 2.0',
                'url' => 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
                'license' => 'CC BY 4.0',
            ],
            availabilityNote: $availabilityNote,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function lightningMetadata(array $metadata): array
    {
        $referenceTime = $this->timestamp($metadata['latest_frame_at'] ?? null);
        $refreshedAt = $this->timestamp($metadata['refreshed_at'] ?? null);
        $observedPeriodEnd = $this->timestamp($metadata['observed_period_end'] ?? null);
        if ($observedPeriodEnd === null && $referenceTime !== null) {
            $observedPeriodEnd = CarbonImmutable::parse($referenceTime)
                ->utc()
                ->addMinutes(5)
                ->toIso8601String();
        }
        $frames = $this->lightningFrames($metadata['frames'] ?? null, $referenceTime);
        $atlas = $this->atlas($metadata['atlas'] ?? null, 4, 2, 7);
        $snapshotId = $this->snapshotId($metadata['snapshot_id'] ?? null);
        $stale = ($metadata['stale'] ?? false) === true;
        $integral = (($metadata['available'] ?? false) === true || $stale)
            && $snapshotId !== null
            && $atlas !== null
            && count($frames) === 7;

        return $this->publicLayer(
            kind: 'lightning',
            available: $integral && ! $stale,
            stale: $stale,
            displayable: $integral,
            snapshotId: $snapshotId,
            referenceTime: $referenceTime,
            observedPeriodEnd: $observedPeriodEnd,
            ageSeconds: $this->ageSeconds($observedPeriodEnd),
            lagSeconds: $this->lagSeconds($observedPeriodEnd, $refreshedAt),
            refreshedAt: $refreshedAt,
            atlas: $atlas,
            frames: $frames,
            fixedColumns: 4,
            fixedRows: 2,
            source: [
                'name' => 'EUMETSAT MTG Lightning Imager',
                'url' => 'https://view.eumetsat.int/',
                'license' => 'EUMETSAT Data Policy - vrije EUMETView-toegang',
            ],
            availabilityNote: $this->note($metadata['availability_note'] ?? null),
        );
    }

    /**
     * @param  list<array{index: int, valid_at: string, lead_minutes: int}>  $frames
     * @param  array{width: int, height: int, columns: int, rows: int, frame_width: int, frame_height: int, frame_count: int}|null  $atlas
     * @param  array{name: string, url: string, license: string}  $source
     * @return array<string, mixed>
     */
    private function publicLayer(
        string $kind,
        bool $available,
        bool $stale,
        bool $displayable,
        ?string $snapshotId,
        ?string $referenceTime,
        ?string $observedPeriodEnd,
        ?int $ageSeconds,
        ?int $lagSeconds,
        ?string $refreshedAt,
        ?array $atlas,
        array $frames,
        int $fixedColumns,
        int $fixedRows,
        array $source,
        ?string $availabilityNote,
    ): array {
        $usable = $displayable && $snapshotId !== null && $atlas !== null;

        return [
            'status' => $available ? 'available' : ($stale ? 'stale' : 'unavailable'),
            'reference_time' => $referenceTime,
            'observed_period_end' => $observedPeriodEnd,
            'age_seconds' => $ageSeconds,
            'lag_seconds' => $lagSeconds,
            'refreshed_at' => $refreshedAt,
            'atlas_url' => $usable
                ? route('operational-weather.radar-atlas', [
                    'kind' => $kind,
                    'snapshot' => $snapshotId,
                ], false)
                : null,
            'atlas_columns' => $atlas['columns'] ?? $fixedColumns,
            'atlas_rows' => $atlas['rows'] ?? $fixedRows,
            'frame_width' => $atlas['frame_width'] ?? 0,
            'frame_height' => $atlas['frame_height'] ?? 0,
            'frames' => $usable ? $frames : [],
            'source' => $source,
            'availability_note' => $availabilityNote,
        ];
    }

    /** @return array{width: int, height: int, columns: int, rows: int, frame_width: int, frame_height: int, frame_count: int}|null */
    private function atlas(mixed $value, int $columns, int $rows, int $frameCount): ?array
    {
        if (! is_array($value)
            || ($value['columns'] ?? null) !== $columns
            || ($value['rows'] ?? null) !== $rows
            || ($value['frame_count'] ?? $frameCount) !== $frameCount) {
            return null;
        }

        $frameWidth = $value['frame_width'] ?? null;
        $frameHeight = $value['frame_height'] ?? null;
        $width = $value['width'] ?? null;
        $height = $value['height'] ?? null;
        if (! is_int($frameWidth)
            || ! is_int($frameHeight)
            || ! is_int($width)
            || ! is_int($height)
            || $frameWidth < 1
            || $frameHeight < 1
            || $frameWidth > 4096
            || $frameHeight > 4096
            || $width > 32_768
            || $height > 32_768
            || $width !== $frameWidth * $columns
            || $height !== $frameHeight * $rows) {
            return null;
        }

        return [
            'width' => $width,
            'height' => $height,
            'columns' => $columns,
            'rows' => $rows,
            'frame_width' => $frameWidth,
            'frame_height' => $frameHeight,
            'frame_count' => $frameCount,
        ];
    }

    /** @return list<array{index: int, valid_at: string, lead_minutes: int}> */
    private function precipitationFrames(mixed $value, ?string $referenceTime): array
    {
        if (! is_array($value) || count($value) !== 25 || $referenceTime === null) {
            return [];
        }
        $reference = CarbonImmutable::parse($referenceTime)->utc();

        $frames = [];
        foreach (array_values($value) as $index => $frame) {
            if (! is_array($frame)) {
                return [];
            }
            $validAt = $this->timestamp($frame['valid_at'] ?? null);
            $leadMinutes = $frame['lead_minutes'] ?? null;
            if (($frame['index'] ?? null) !== $index
                || $validAt === null
                || ! is_int($leadMinutes)
                || $leadMinutes !== $index * 5
                || CarbonImmutable::parse($validAt)->utc()->getTimestamp()
                    !== $reference->addMinutes($leadMinutes)->getTimestamp()) {
                return [];
            }
            $frames[] = ['index' => $index, 'valid_at' => $validAt, 'lead_minutes' => $leadMinutes];
        }

        return $frames;
    }

    /** @return list<array{index: int, valid_at: string, lead_minutes: int}> */
    private function lightningFrames(mixed $value, ?string $referenceTime): array
    {
        if (! is_array($value) || count($value) !== 7 || $referenceTime === null) {
            return [];
        }

        $frames = [];
        $previous = null;
        foreach (array_values($value) as $index => $timestamp) {
            $validAt = $this->timestamp($timestamp);
            if ($validAt === null) {
                return [];
            }
            $current = CarbonImmutable::parse($validAt)->utc();
            if ($previous !== null && $current->getTimestamp() - $previous->getTimestamp() !== 300) {
                return [];
            }
            $frames[] = [
                'index' => $index,
                'valid_at' => $validAt,
                'lead_minutes' => ($index - 6) * 5,
            ];
            $previous = $current;
        }

        if ($previous?->getTimestamp() !== CarbonImmutable::parse($referenceTime)->utc()->getTimestamp()) {
            return [];
        }

        return $frames;
    }

    private function snapshotId(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::SNAPSHOT_PATTERN, $value) === 1 ? $value : null;
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value)
            || strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return null;
        }

        try {
            CarbonImmutable::parse($value);

            return $value;
        } catch (Throwable) {
            return null;
        }
    }

    private function note(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function ageSeconds(?string $anchor): ?int
    {
        if ($anchor === null) {
            return null;
        }

        return max(
            0,
            (int) CarbonImmutable::parse($anchor)
                ->utc()
                ->diffInSeconds(CarbonImmutable::now()->utc(), false),
        );
    }

    private function lagSeconds(?string $anchor, ?string $refreshedAt): ?int
    {
        if ($anchor === null || $refreshedAt === null) {
            return null;
        }

        return max(
            0,
            (int) CarbonImmutable::parse($anchor)
                ->utc()
                ->diffInSeconds(CarbonImmutable::parse($refreshedAt)->utc(), false),
        );
    }
}
