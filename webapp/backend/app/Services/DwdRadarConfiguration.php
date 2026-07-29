<?php

namespace App\Services;

final class DwdRadarConfiguration
{
    private const PRIMARY_ENDPOINT = 'https://maps.dwd.de/geoserver/dwd/Radar_rv_product_1x1km_ger/wms';

    private const FALLBACK_ENDPOINT = 'https://brz-maps.dwd.de/geoserver/dwd/Radar_rv_product_1x1km_ger/wms';

    private const LAYER = 'Radar_rv_product_1x1km_ger';

    private const STYLE = 'radar_rv_product_1x1km_ger';

    private const BBOX = [2.5, 50.5, 7.8, 53.7];

    /** @return list<string> */
    public function endpoints(): array
    {
        $configured = config('dis.dwd_radar.endpoints');
        if (! is_array($configured) || array_values($configured) !== [
            self::PRIMARY_ENDPOINT,
            self::FALLBACK_ENDPOINT,
        ]) {
            throw new \RuntimeException('The fixed DWD radar endpoints are not configured safely.');
        }

        return [self::PRIMARY_ENDPOINT, self::FALLBACK_ENDPOINT];
    }

    public function layer(): string
    {
        return $this->fixedString('layer', self::LAYER);
    }

    public function style(): string
    {
        return $this->fixedString('style', self::STYLE);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public function bbox(): array
    {
        $configured = config('dis.dwd_radar.bbox');
        if (! is_array($configured) || array_values($configured) !== self::BBOX) {
            throw new \RuntimeException('The fixed DWD radar bbox is not configured safely.');
        }

        return self::BBOX;
    }

    public function frameWidth(): int
    {
        return $this->fixedInt('frame_width', 960);
    }

    public function frameHeight(): int
    {
        return $this->fixedInt('frame_height', 580);
    }

    public function historyMinutes(): int
    {
        return $this->fixedInt('history_minutes', 60);
    }

    public function forecastMinutes(): int
    {
        return $this->fixedInt('forecast_minutes', 120);
    }

    public function intervalMinutes(): int
    {
        return $this->fixedInt('interval_minutes', 5);
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
        return min(1_048_576, max(32_768, $this->positiveInt('maximum_capabilities_bytes', 262_144)));
    }

    public function maximumFrameBytes(): int
    {
        return min(262_144, max(65_536, $this->positiveInt('maximum_frame_bytes', 262_144)));
    }

    public function timelineCacheSeconds(): int
    {
        return min(300, max(60, $this->positiveInt('timeline_cache_seconds', 240)));
    }

    public function frameCacheSeconds(): int
    {
        return $this->fixedInt('frame_cache_seconds', 7200);
    }

    public function timelineLockSeconds(): int
    {
        return (count($this->endpoints()) * $this->capabilitiesTimeoutSeconds()) + 10;
    }

    public function frameLockSeconds(): int
    {
        return (count($this->endpoints()) * $this->frameTimeoutSeconds()) + 10;
    }

    public function maximumAgeSeconds(): int
    {
        return min(3600, max(300, $this->positiveInt('maximum_age_seconds', 1200)));
    }

    public function maximumFallbackAgeSeconds(): int
    {
        return min(14_400, max(
            $this->maximumAgeSeconds(),
            $this->positiveInt('maximum_fallback_age_seconds', 3600),
        ));
    }

    /** @return array{name: string, url: string, license: string, license_url: string} */
    public function source(): array
    {
        return [
            'name' => 'DWD RV neerslagradar',
            'url' => 'https://www.dwd.de/DE/leistungen/radarprodukte/radarlayer.html',
            'license' => 'CC BY 4.0',
            'license_url' => 'https://www.dwd.de/DE/leistungen/opendata/faqs_opendata.html',
        ];
    }

    private function fixedString(string $key, string $expected): string
    {
        $configured = trim((string) config('dis.dwd_radar.'.$key));
        if (! hash_equals($expected, $configured)) {
            throw new \RuntimeException("The fixed DWD radar {$key} is not configured safely.");
        }

        return $expected;
    }

    private function fixedInt(string $key, int $expected): int
    {
        $configured = config('dis.dwd_radar.'.$key);
        if (! is_int($configured) || $configured !== $expected) {
            throw new \RuntimeException("The fixed DWD radar {$key} is not configured safely.");
        }

        return $expected;
    }

    private function positiveInt(string $key, int $fallback): int
    {
        $value = config('dis.dwd_radar.'.$key, $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
