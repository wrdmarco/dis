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
        $frames = $this->precipitationFrames($metadata['frames'] ?? null, $referenceTime);
        $atlas = $this->atlas($metadata['atlas'] ?? null, 5, 5, 25);
        $snapshotId = $this->snapshotId($metadata['snapshot_id'] ?? null);
        $available = ($metadata['available'] ?? false) === true
            && ($metadata['stale'] ?? true) === false
            && $snapshotId !== null
            && $atlas !== null
            && count($frames) === 25;

        return $this->publicLayer(
            kind: 'precipitation',
            available: $available,
            stale: ($metadata['stale'] ?? false) === true,
            snapshotId: $snapshotId,
            referenceTime: $referenceTime,
            refreshedAt: $this->timestamp($metadata['refreshed_at'] ?? null),
            atlas: $atlas,
            frames: $frames,
            fixedColumns: 5,
            fixedRows: 5,
            source: [
                'name' => 'KNMI radar_forecast 2.0',
                'url' => 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
                'license' => 'CC BY 4.0',
            ],
            availabilityNote: $this->note($metadata['availability_note'] ?? null),
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function lightningMetadata(array $metadata): array
    {
        $referenceTime = $this->timestamp($metadata['latest_frame_at'] ?? null);
        $frames = $this->lightningFrames($metadata['frames'] ?? null, $referenceTime);
        $atlas = $this->atlas($metadata['atlas'] ?? null, 4, 2, 7);
        $snapshotId = $this->snapshotId($metadata['snapshot_id'] ?? null);
        $available = ($metadata['available'] ?? false) === true
            && ($metadata['stale'] ?? true) === false
            && $snapshotId !== null
            && $atlas !== null
            && count($frames) === 7;

        return $this->publicLayer(
            kind: 'lightning',
            available: $available,
            stale: ($metadata['stale'] ?? false) === true,
            snapshotId: $snapshotId,
            referenceTime: $referenceTime,
            refreshedAt: $this->timestamp($metadata['refreshed_at'] ?? null),
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
        ?string $snapshotId,
        ?string $referenceTime,
        ?string $refreshedAt,
        ?array $atlas,
        array $frames,
        int $fixedColumns,
        int $fixedRows,
        array $source,
        ?string $availabilityNote,
    ): array {
        $usable = $available && $snapshotId !== null && $atlas !== null;

        return [
            'status' => $usable ? 'available' : ($stale ? 'stale' : 'unavailable'),
            'reference_time' => $referenceTime,
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
            $frames[] = ['index' => $index, 'valid_at' => $validAt, 'lead_minutes' => 0];
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
}
