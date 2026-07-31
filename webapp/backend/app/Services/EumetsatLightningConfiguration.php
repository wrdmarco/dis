<?php

namespace App\Services;

final class EumetsatLightningConfiguration
{
    private const ENDPOINT = 'https://view.eumetsat.int/geoserver/wms';

    private const LAYER = 'mtg_fd:li_afa';

    private const STYLE = 'mtg_li_afa';

    private const CRS = 'CRS:84';

    private const BBOX = [1.0, 49.0, 10.0, 55.0];

    private const FRAME_WIDTH = 960;

    private const FRAME_HEIGHT = 640;

    private const FRAME_COUNT = 7;

    private const INTERVAL_MINUTES = 5;

    private const SOURCE_NAME = 'EUMETSAT MTG Lightning Imager';

    private const SOURCE_URL = 'https://view.eumetsat.int/';

    private const LICENSE_NAME = 'CC BY 4.0';

    private const LICENSE_URL = 'https://user.eumetsat.int/resources/user-guides/data-registration-and-licensing';

    public function endpoint(): string
    {
        return $this->fixedString('endpoint', self::ENDPOINT);
    }

    public function host(): string
    {
        return 'view.eumetsat.int';
    }

    public function layer(): string
    {
        return $this->fixedString('layer', self::LAYER);
    }

    public function style(): string
    {
        return $this->fixedString('style', self::STYLE);
    }

    public function crs(): string
    {
        return $this->fixedString('crs', self::CRS);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public function bbox(): array
    {
        $value = config('dis.eumetsat_lightning.bbox');
        if (! is_array($value) || array_values($value) !== self::BBOX) {
            throw new \RuntimeException('The fixed EUMETSAT lightning bbox is not configured safely.');
        }

        return self::BBOX;
    }

    public function frameWidth(): int
    {
        return $this->fixedInt('frame_width', self::FRAME_WIDTH);
    }

    public function frameHeight(): int
    {
        return $this->fixedInt('frame_height', self::FRAME_HEIGHT);
    }

    /** @return array<string, mixed> */
    public function renderContract(): array
    {
        return [
            'endpoint' => $this->endpoint(),
            'service' => 'WMS',
            'version' => '1.3.0',
            'layer' => $this->layer(),
            'style' => $this->style(),
            'crs' => $this->crs(),
            'bbox' => $this->bbox(),
            'width' => $this->frameWidth(),
            'height' => $this->frameHeight(),
            'format' => 'image/png',
            'transparent' => true,
        ];
    }

    public function frameCount(): int
    {
        return $this->fixedInt('frame_count', self::FRAME_COUNT);
    }

    public function intervalMinutes(): int
    {
        return $this->fixedInt('interval_minutes', self::INTERVAL_MINUTES);
    }

    public function connectTimeoutSeconds(): int
    {
        return min(15, max(1, $this->positiveInt('connect_timeout_seconds', 5)));
    }

    public function capabilitiesTimeoutSeconds(): int
    {
        return min(30, max(5, $this->positiveInt('capabilities_timeout_seconds', 15)));
    }

    public function frameTimeoutSeconds(): int
    {
        return min(45, max(5, $this->positiveInt('frame_timeout_seconds', 20)));
    }

    public function maximumCapabilitiesBytes(): int
    {
        return min(2_097_152, max(131_072, $this->positiveInt('maximum_capabilities_bytes', 1_048_576)));
    }

    public function maximumFrameBytes(): int
    {
        return min(262_144, max(65_536, $this->positiveInt('maximum_frame_bytes', 262_144)));
    }

    public function maximumAgeSeconds(): int
    {
        return $this->fixedInt('maximum_age_seconds', 1800);
    }

    public function maximumFallbackAgeSeconds(): int
    {
        return $this->maximumAgeSeconds() * 2;
    }

    public function frameCacheSeconds(): int
    {
        return $this->maximumFallbackAgeSeconds()
            + (($this->frameCount() - 1) * $this->intervalMinutes() * 60);
    }

    public function timelineLockSeconds(): int
    {
        return $this->capabilitiesTimeoutSeconds() + 10;
    }

    public function frameLockSeconds(): int
    {
        return $this->frameTimeoutSeconds() + 10;
    }

    /** @return array{name: string, url: string, layer: string} */
    public function source(): array
    {
        return [
            'name' => $this->fixedString('source_name', self::SOURCE_NAME),
            'url' => $this->fixedString('source_url', self::SOURCE_URL),
            'layer' => $this->layer(),
        ];
    }

    /** @return array{name: string, url: string} */
    public function license(): array
    {
        return [
            'name' => $this->fixedString('license_name', self::LICENSE_NAME),
            'url' => $this->fixedString('license_url', self::LICENSE_URL),
        ];
    }

    private function fixedString(string $key, string $expected): string
    {
        $configured = trim((string) config('dis.eumetsat_lightning.'.$key));
        if (! hash_equals($expected, $configured)) {
            throw new \RuntimeException("The fixed EUMETSAT lightning {$key} is not configured safely.");
        }

        return $expected;
    }

    private function fixedInt(string $key, int $expected): int
    {
        $configured = config('dis.eumetsat_lightning.'.$key);
        if (! is_int($configured) || $configured !== $expected) {
            throw new \RuntimeException("The fixed EUMETSAT lightning {$key} is not configured safely.");
        }

        return $expected;
    }

    private function positiveInt(string $key, int $fallback): int
    {
        $value = config('dis.eumetsat_lightning.'.$key, $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
