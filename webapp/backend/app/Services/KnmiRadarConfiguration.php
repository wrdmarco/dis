<?php

namespace App\Services;

final class KnmiRadarConfiguration
{
    private const ENDPOINT = 'https://anonymous.api.dataplatform.knmi.nl/wms/adaguc-server';

    private const OBSERVATION_DATASET = 'nl_rdr_data_rtcor_5m';

    private const OBSERVATION_LAYER = 'precipitation_real_time';

    private const FORECAST_DATASET = 'radar_forecast_2.0';

    private const FORECAST_LAYER = 'precipitation_nowcast';

    private const STYLE = 'rainrate-blue-to-purple/shaded';

    private const SRS = 'EPSG:4326';

    private const BBOX = [2.5, 50.5, 7.8, 53.7];

    public function endpoint(): string
    {
        return $this->fixedString('endpoint', self::ENDPOINT);
    }

    public function host(): string
    {
        return 'anonymous.api.dataplatform.knmi.nl';
    }

    public function observationDataset(): string
    {
        return $this->fixedString('observation_dataset', self::OBSERVATION_DATASET);
    }

    public function observationLayer(): string
    {
        return $this->fixedString('observation_layer', self::OBSERVATION_LAYER);
    }

    public function forecastDataset(): string
    {
        return $this->fixedString('forecast_dataset', self::FORECAST_DATASET);
    }

    public function forecastLayer(): string
    {
        return $this->fixedString('forecast_layer', self::FORECAST_LAYER);
    }

    public function style(): string
    {
        return $this->fixedString('style', self::STYLE);
    }

    public function srs(): string
    {
        return $this->fixedString('srs', self::SRS);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float} */
    public function bbox(): array
    {
        $configured = config('dis.knmi_radar.bbox');
        if (! is_array($configured) || array_values($configured) !== self::BBOX) {
            throw new \RuntimeException('The fixed KNMI radar bbox is not configured safely.');
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
        return min(1_048_576, max(65_536, $this->positiveInt('maximum_frame_bytes', 1_048_576)));
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
        return (2 * $this->capabilitiesTimeoutSeconds()) + 15;
    }

    public function frameLockSeconds(): int
    {
        return $this->frameTimeoutSeconds() + 15;
    }

    public function upstreamThrottleLockSeconds(): int
    {
        return $this->frameTimeoutSeconds() + 15;
    }

    public function upstreamThrottleWaitSeconds(): int
    {
        return $this->fixedInt('upstream_throttle_wait_seconds', 5);
    }

    public function upstreamMinimumIntervalMilliseconds(): int
    {
        return $this->fixedInt('upstream_minimum_interval_milliseconds', 1050);
    }

    public function upstreamJitterMilliseconds(): int
    {
        return $this->fixedInt('upstream_jitter_milliseconds', 50);
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

    /**
     * @return array{
     *   name: string,
     *   url: string,
     *   license: string,
     *   license_url: string,
     *   attribution: string
     * }
     */
    public function source(): array
    {
        return [
            'name' => 'KNMI RTCOR + radar forecast 2.0',
            'url' => 'https://dataplatform.knmi.nl/dataset/radar-forecast-2-0',
            'license' => 'CC BY 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by/4.0/',
            'attribution' => 'KNMI nl_rdr_data_rtcor_5m en radar_forecast_2.0',
        ];
    }

    private function fixedString(string $key, string $expected): string
    {
        $configured = trim((string) config('dis.knmi_radar.'.$key));
        if (! hash_equals($expected, $configured)) {
            throw new \RuntimeException("The fixed KNMI radar {$key} is not configured safely.");
        }

        return $expected;
    }

    private function fixedInt(string $key, int $expected): int
    {
        $configured = config('dis.knmi_radar.'.$key);
        if (! is_int($configured) || $configured !== $expected) {
            throw new \RuntimeException("The fixed KNMI radar {$key} is not configured safely.");
        }

        return $expected;
    }

    private function positiveInt(string $key, int $fallback): int
    {
        $value = config('dis.knmi_radar.'.$key, $fallback);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $fallback;
    }
}
