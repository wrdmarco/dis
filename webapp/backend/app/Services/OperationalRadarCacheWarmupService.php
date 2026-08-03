<?php

namespace App\Services;

use App\Contracts\OperationalRadarProvider;
use Throwable;

final class OperationalRadarCacheWarmupService
{
    /** @var list<string> */
    private const KINDS = ['precipitation', 'lightning'];

    private const IMAGE_URL_PATTERN = '#\A/api/operational-weather/radar/(?<kind>precipitation|lightning)/(?<snapshot>\d{8}T\d{6}Z-(?:o|f\d{8}T\d{6}Z)-[a-f0-9]{16})\.png\z#D';

    public function __construct(
        private readonly OperationalRadarProvider $radar,
    ) {}

    /** @return array{requested: int, warmed: int} */
    public function warmReferenceFrames(): array
    {
        try {
            $metadata = $this->radar->metadata();
        } catch (Throwable) {
            return ['requested' => 0, 'warmed' => 0];
        }

        $requested = 0;
        $warmed = 0;

        foreach (self::KINDS as $kind) {
            $layer = $metadata[$kind] ?? null;
            if (! is_array($layer)
                || ! in_array($layer['status'] ?? null, ['available', 'stale'], true)) {
                continue;
            }

            $snapshot = $this->referenceSnapshot($kind, $layer);
            if ($snapshot === null) {
                continue;
            }

            $requested++;
            try {
                if ($this->radar->file($kind, $snapshot) !== null) {
                    $warmed++;
                }
            } catch (Throwable) {
                // Cache warming is best effort; the normal request path retains
                // its existing safe fallback behavior when a provider is down.
            }
        }

        return ['requested' => $requested, 'warmed' => $warmed];
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function referenceSnapshot(string $kind, array $layer): ?string
    {
        $frames = $layer['frames'] ?? null;
        if (! is_array($frames)) {
            return null;
        }

        foreach ($frames as $frame) {
            if (is_array($frame) && ($frame['lead_minutes'] ?? null) === 0) {
                $snapshot = $this->snapshotFromImageUrl($kind, $frame['image_url'] ?? null);
                if ($snapshot !== null) {
                    return $snapshot;
                }
            }
        }

        $referenceTime = $layer['reference_time'] ?? null;
        if (! is_string($referenceTime) || $referenceTime === '') {
            return null;
        }

        foreach ($frames as $frame) {
            if (! is_array($frame) || ($frame['valid_at'] ?? null) !== $referenceTime) {
                continue;
            }

            $snapshot = $this->snapshotFromImageUrl($kind, $frame['image_url'] ?? null);
            if ($snapshot !== null) {
                return $snapshot;
            }
        }

        return null;
    }

    private function snapshotFromImageUrl(string $kind, mixed $imageUrl): ?string
    {
        if (! is_string($imageUrl)
            || preg_match(self::IMAGE_URL_PATTERN, $imageUrl, $matches) !== 1
            || ($matches['kind'] ?? null) !== $kind) {
            return null;
        }

        return $matches['snapshot'];
    }
}
