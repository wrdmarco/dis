<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class KnmiRadarWmsClient
{
    private const CAPABILITIES_CONTENT_TYPES = [
        'application/xml',
        'text/xml',
        'application/vnd.ogc.wms_xml',
    ];

    public function __construct(
        private readonly KnmiRadarConfiguration $configuration,
        private readonly KnmiRadarWmsThrottle $throttle,
    ) {}

    /**
     * @return array{
     *   reference_time: string,
     *   frames: list<array{valid_at: string, phase: 'observation'|'forecast'}>
     * }
     */
    public function timeline(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        try {
            $observation = $this->downloadCapabilities(
                $this->configuration->observationDataset(),
                $this->configuration->observationLayer(),
                false,
            );
            $forecast = $this->downloadCapabilities(
                $this->configuration->forecastDataset(),
                $this->configuration->forecastLayer(),
                true,
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                'The fixed KNMI radar capabilities could not be downloaded.',
                0,
                $exception,
            );
        }

        $referenceTime = $forecast['reference_default'];
        if (! $referenceTime instanceof CarbonImmutable
            || $referenceTime->greaterThan($now->addMinutes(10))) {
            throw new \UnexpectedValueException('The KNMI radar reference time is invalid.');
        }

        $intervalMinutes = $this->configuration->intervalMinutes();
        $firstObservation = $referenceTime->subMinutes($this->configuration->historyMinutes());
        $lastForecast = $referenceTime->addMinutes($this->configuration->forecastMinutes());
        if ($observation['default_time']->lessThan($referenceTime)
            || $observation['default_time']->greaterThan($referenceTime->addMinutes(10))
            || $forecast['default_time']->lessThan($lastForecast)
            || $forecast['default_time']->greaterThan($lastForecast->addMinutes(10))
            || ! $this->rangeContains(
                $observation['time_range'],
                $observation['default_time'],
                $observation['default_time'],
                $intervalMinutes,
            )
            || ! $this->rangeContains(
                $forecast['time_range'],
                $forecast['default_time'],
                $forecast['default_time'],
                $intervalMinutes,
            )
            || ! $this->rangeContains(
                $observation['time_range'],
                $firstObservation,
                $referenceTime,
                $intervalMinutes,
            )
            || ! $this->rangeContains(
                $forecast['time_range'],
                $referenceTime,
                $lastForecast,
                $intervalMinutes,
            )
            || ! $this->rangeContains(
                $forecast['reference_range'],
                $referenceTime,
                $referenceTime,
                $intervalMinutes,
            )) {
            throw new \UnexpectedValueException('The KNMI radar timeline coverage is incomplete.');
        }

        $frames = [];
        for (
            $validAt = $firstObservation;
            $validAt->lessThanOrEqualTo($referenceTime);
            $validAt = $validAt->addMinutes($intervalMinutes)
        ) {
            $frames[] = [
                'valid_at' => $validAt->toIso8601String(),
                'phase' => 'observation',
            ];
        }
        for (
            $validAt = $referenceTime->addMinutes($intervalMinutes);
            $validAt->lessThanOrEqualTo($lastForecast);
            $validAt = $validAt->addMinutes($intervalMinutes)
        ) {
            $frames[] = [
                'valid_at' => $validAt->toIso8601String(),
                'phase' => 'forecast',
            ];
        }

        $expected = intdiv(
            $this->configuration->historyMinutes() + $this->configuration->forecastMinutes(),
            $intervalMinutes,
        ) + 1;
        if (count($frames) !== $expected) {
            throw new \UnexpectedValueException('The KNMI radar frame set is incomplete.');
        }

        return [
            'reference_time' => $referenceTime->toIso8601String(),
            'frames' => $frames,
        ];
    }

    public function frame(
        CarbonImmutable $validAt,
        CarbonImmutable $referenceTime,
        string $phase,
    ): string {
        if (! in_array($phase, ['observation', 'forecast'], true)) {
            throw new \InvalidArgumentException('The KNMI radar frame phase is invalid.');
        }

        $dataset = $phase === 'observation'
            ? $this->configuration->observationDataset()
            : $this->configuration->forecastDataset();
        $layer = $phase === 'observation'
            ? $this->configuration->observationLayer()
            : $this->configuration->forecastLayer();

        try {
            $body = $this->downloadFrame(
                dataset: $dataset,
                layer: $layer,
                validAt: $validAt->utc(),
                referenceTime: $referenceTime->utc(),
                phase: $phase,
            );
        } catch (Throwable $exception) {
            throw new \RuntimeException(
                'The fixed KNMI radar frame could not be downloaded.',
                0,
                $exception,
            );
        }

        return $body;
    }

    /**
     * @return array{
     *   default_time: CarbonImmutable,
     *   time_range: array{CarbonImmutable, CarbonImmutable, int},
     *   reference_default: CarbonImmutable|null,
     *   reference_range: array{CarbonImmutable, CarbonImmutable, int}|null
     * }
     */
    private function downloadCapabilities(string $dataset, string $layer, bool $forecast): array
    {
        $endpoint = $this->validatedEndpoint();
        $response = $this->throttle->request(
            fn (): Response => Http::accept(implode(', ', self::CAPABILITIES_CONTENT_TYPES))
                ->withHeaders($this->headers())
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->capabilitiesTimeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($this->boundedOptions($this->configuration->maximumCapabilitiesBytes()))
                ->get($endpoint, [
                    'dataset' => $dataset,
                    'service' => 'WMS',
                    'version' => '1.3.0',
                    'request' => 'GetCapabilities',
                ]),
        );
        $body = $this->validatedBody(
            $response,
            self::CAPABILITIES_CONTENT_TYPES,
            $this->configuration->maximumCapabilitiesBytes(),
            128,
        );

        return $this->parseCapabilities($body, $layer, $forecast);
    }

    private function downloadFrame(
        string $dataset,
        string $layer,
        CarbonImmutable $validAt,
        CarbonImmutable $referenceTime,
        string $phase,
    ): string {
        [$west, $south, $east, $north] = $this->configuration->bbox();
        $query = [
            'dataset' => $dataset,
            'service' => 'WMS',
            // ADAGUC's WMS 1.1.1 EPSG:4326 contract uses the unambiguous
            // longitude,latitude BBOX ordering required by this fixed map.
            'version' => '1.1.1',
            'request' => 'GetMap',
            'layers' => $layer,
            'styles' => $this->configuration->style(),
            'srs' => $this->configuration->srs(),
            'bbox' => implode(',', [$west, $south, $east, $north]),
            'width' => $this->configuration->frameWidth(),
            'height' => $this->configuration->frameHeight(),
            'format' => 'image/png',
            'transparent' => 'true',
            'time' => $validAt->format('Y-m-d\TH:i:s\Z'),
        ];
        if ($phase === 'forecast') {
            $query['reference_time'] = $referenceTime->format('Y-m-d\TH:i:s\Z');
        }

        $response = $this->throttle->request(
            fn (): Response => Http::accept('image/png')
                ->withHeaders($this->headers())
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->frameTimeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($this->boundedOptions($this->configuration->maximumFrameBytes()))
                ->get($this->validatedEndpoint(), $query),
        );
        $body = $this->validatedBody(
            $response,
            ['image/png'],
            $this->configuration->maximumFrameBytes(),
            67,
        );
        $this->validatePng(
            $body,
            $this->configuration->frameWidth(),
            $this->configuration->frameHeight(),
        );

        return $body;
    }

    /**
     * @return array{
     *   default_time: CarbonImmutable,
     *   time_range: array{CarbonImmutable, CarbonImmutable, int},
     *   reference_default: CarbonImmutable|null,
     *   reference_range: array{CarbonImmutable, CarbonImmutable, int}|null
     * }
     */
    private function parseCapabilities(string $xml, string $layerName, bool $forecast): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \UnexpectedValueException('The KNMI capabilities XML contains prohibited declarations.');
        }
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new DOMDocument;
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_NOBLANKS);
            if (! $loaded
                || ! $document->documentElement instanceof DOMElement
                || $document->documentElement->localName !== 'WMS_Capabilities'
                || $document->documentElement->getAttribute('version') !== '1.3.0') {
                throw new \UnexpectedValueException('The KNMI capabilities XML is invalid.');
            }

            $xpath = new DOMXPath($document);
            $layers = [];
            foreach ($xpath->query('//*[local-name()="Layer"]/*[local-name()="Name"]') ?: [] as $nameNode) {
                if (trim($nameNode->textContent) === $layerName
                    && $nameNode->parentNode instanceof DOMElement) {
                    $layers[] = $nameNode->parentNode;
                }
            }
            if (count($layers) !== 1) {
                throw new \UnexpectedValueException('The required KNMI radar layer is not uniquely available.');
            }
            $styles = $xpath->query(
                './*[local-name()="Style"]/*[local-name()="Name"]',
                $layers[0],
            );
            $matchingStyles = 0;
            foreach ($styles ?: [] as $style) {
                if (trim($style->textContent) === $this->configuration->style()) {
                    $matchingStyles++;
                }
            }
            if ($matchingStyles !== 1) {
                throw new \UnexpectedValueException('The required KNMI radar style is not uniquely available.');
            }

            $time = $this->singleDimension($xpath, $layers[0], 'time');
            $reference = $forecast
                ? $this->singleDimension($xpath, $layers[0], 'reference_time')
                : null;

            return [
                'default_time' => $this->timestamp($time->getAttribute('default')),
                'time_range' => $this->timeRange($time->textContent),
                'reference_default' => $reference instanceof DOMElement
                    ? $this->timestamp($reference->getAttribute('default'))
                    : null,
                'reference_range' => $reference instanceof DOMElement
                    ? $this->timeRange($reference->textContent)
                    : null,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    private function singleDimension(DOMXPath $xpath, DOMElement $layer, string $name): DOMElement
    {
        $dimensions = $xpath->query(
            './*[local-name()="Dimension" or local-name()="Extent"][@name="'.$name.'"]',
            $layer,
        );
        if ($dimensions === false || $dimensions->length !== 1) {
            throw new \UnexpectedValueException("The KNMI radar {$name} dimension is incomplete.");
        }
        $dimension = $dimensions->item(0);
        if (! $dimension instanceof DOMElement
            || strtoupper(trim($dimension->getAttribute('units'))) !== 'ISO8601'
            || trim($dimension->getAttribute('default')) === ''
            || strlen($dimension->textContent) > 262_144) {
            throw new \UnexpectedValueException("The KNMI radar {$name} dimension is invalid.");
        }

        return $dimension;
    }

    /** @return array{CarbonImmutable, CarbonImmutable, int} */
    private function timeRange(string $value): array
    {
        $value = preg_replace('/\s+/', '', trim($value));
        $parts = is_string($value) ? explode('/', $value) : [];
        if (count($parts) !== 3
            || preg_match('/\APT([1-9]\d*)M\z/D', $parts[2], $matches) !== 1) {
            throw new \UnexpectedValueException('The KNMI radar time range is invalid.');
        }
        $start = $this->timestamp($parts[0]);
        $end = $this->timestamp($parts[1]);
        $minutes = (int) $matches[1];
        if ($end->lessThan($start)) {
            throw new \UnexpectedValueException('The KNMI radar time range is reversed.');
        }

        return [$start, $end, $minutes];
    }

    /**
     * @param  array{CarbonImmutable, CarbonImmutable, int}|null  $range
     */
    private function rangeContains(
        ?array $range,
        CarbonImmutable $first,
        CarbonImmutable $last,
        int $expectedStepMinutes,
    ): bool {
        if ($range === null) {
            return false;
        }
        [$availableFrom, $availableUntil, $stepMinutes] = $range;
        $stepSeconds = $expectedStepMinutes * 60;

        return $stepMinutes === $expectedStepMinutes
            && ! $first->lessThan($availableFrom)
            && ! $last->greaterThan($availableUntil)
            && ($first->getTimestamp() - $availableFrom->getTimestamp()) % $stepSeconds === 0
            && ($last->getTimestamp() - $availableFrom->getTimestamp()) % $stepSeconds === 0;
    }

    private function timestamp(string $value): CarbonImmutable
    {
        if (strlen($value) > 32
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z\z/D', $value) !== 1) {
            throw new \UnexpectedValueException('A KNMI radar timestamp is invalid.');
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable $exception) {
            throw new \UnexpectedValueException('A KNMI radar timestamp cannot be parsed.', 0, $exception);
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Accept-Encoding' => 'identity',
            'User-Agent' => 'DIS-Live-KNMI-Radar/1.0',
        ];
    }

    /** @return array<string, mixed> */
    private function boundedOptions(int $maximumBytes): array
    {
        $options = [
            'allow_redirects' => false,
            'decode_content' => false,
            'http_errors' => false,
            'verify' => true,
            'on_headers' => static function ($response) use ($maximumBytes): void {
                $length = trim((string) $response->getHeaderLine('Content-Length'));
                if ($length !== '' && (! ctype_digit($length) || (int) $length > $maximumBytes)) {
                    throw new \RuntimeException('KNMI response length is invalid.');
                }
            },
            'progress' => static function (
                int|float $downloadTotal,
                int|float $downloadedBytes,
                int|float $uploadTotal,
                int|float $uploadedBytes,
            ) use ($maximumBytes): void {
                unset($downloadTotal, $uploadTotal, $uploadedBytes);
                if ($downloadedBytes > $maximumBytes) {
                    throw new \RuntimeException('KNMI response exceeded its size limit.');
                }
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options['curl'] = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        }

        return $options;
    }

    private function validatedEndpoint(): string
    {
        $endpoint = $this->configuration->endpoint();
        $parts = parse_url($endpoint);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== $this->configuration->host()
            || ($parts['path'] ?? null) !== '/wms/adaguc-server'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port'])) {
            throw new \RuntimeException('The fixed KNMI radar endpoint is invalid.');
        }

        return $endpoint;
    }

    /** @param list<string> $contentTypes */
    private function validatedBody(
        Response $response,
        array $contentTypes,
        int $maximumBytes,
        int $minimumBytes,
    ): string {
        if ($response->status() !== 200
            || $response->redirect()
            || trim((string) $response->header('Location')) !== '') {
            throw new \RuntimeException('The KNMI response was not an exact HTTP 200.');
        }
        $this->validateEffectiveUri($response);
        $encoding = strtolower(trim((string) $response->header('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new \UnexpectedValueException('The KNMI response encoding is unsupported.');
        }
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0]));
        if (! in_array($contentType, $contentTypes, true)) {
            throw new \UnexpectedValueException('The KNMI response content type is invalid.');
        }
        $body = $response->body();
        $size = strlen($body);
        $announced = trim((string) $response->header('Content-Length'));
        if ($size < $minimumBytes
            || $size > $maximumBytes
            || ($announced !== '' && (! ctype_digit($announced) || (int) $announced !== $size))) {
            throw new \UnexpectedValueException('The KNMI response size is invalid.');
        }

        return $body;
    }

    private function validateEffectiveUri(Response $response): void
    {
        $effective = $response->effectiveUri();
        if ($effective === null) {
            return;
        }
        $parts = parse_url((string) $effective);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ($parts['host'] ?? null) !== $this->configuration->host()
            || ($parts['path'] ?? null) !== '/wms/adaguc-server'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['port'])) {
            throw new \UnexpectedValueException('The KNMI response resolved to an unexpected endpoint.');
        }
    }

    private function validatePng(string $body, int $expectedWidth, int $expectedHeight): void
    {
        if (! str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            throw new \UnexpectedValueException('The KNMI radar frame does not have a PNG signature.');
        }
        $dimensions = @getimagesizefromstring($body);
        if (! is_array($dimensions)
            || ($dimensions[0] ?? null) !== $expectedWidth
            || ($dimensions[1] ?? null) !== $expectedHeight
            || ($dimensions[2] ?? null) !== IMAGETYPE_PNG) {
            throw new \UnexpectedValueException('The KNMI radar frame dimensions are invalid.');
        }
    }
}
