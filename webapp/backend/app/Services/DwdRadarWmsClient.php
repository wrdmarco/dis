<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class DwdRadarWmsClient
{
    private const CAPABILITIES_CONTENT_TYPES = [
        'application/xml',
        'text/xml',
        'application/vnd.ogc.wms_xml',
    ];

    public function __construct(private readonly DwdRadarConfiguration $configuration) {}

    /**
     * @return array{
     *   reference_time: string,
     *   frames: list<array{valid_at: string, phase: 'observation'|'forecast'}>
     * }
     */
    public function timeline(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $lastException = null;

        foreach ($this->configuration->endpoints() as $endpoint) {
            try {
                return $this->downloadTimeline($endpoint, $now);
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        throw new \RuntimeException(
            'Neither DWD radar endpoint returned a valid timeline.',
            0,
            $lastException,
        );
    }

    public function frame(
        CarbonImmutable $validAt,
        CarbonImmutable $referenceTime,
        string $phase,
    ): string {
        if (! in_array($phase, ['observation', 'forecast'], true)) {
            throw new \InvalidArgumentException('The DWD radar frame phase is invalid.');
        }
        $lastException = null;
        foreach ($this->configuration->endpoints() as $endpoint) {
            try {
                return $this->downloadFrame($endpoint, $validAt->utc(), $referenceTime->utc(), $phase);
            } catch (Throwable $exception) {
                $lastException = $exception;
            }
        }

        throw new \RuntimeException(
            'Neither DWD radar endpoint returned a valid frame.',
            0,
            $lastException,
        );
    }

    /**
     * @return array{
     *   reference_time: string,
     *   frames: list<array{valid_at: string, phase: 'observation'|'forecast'}>
     * }
     */
    private function downloadTimeline(string $endpoint, CarbonImmutable $now): array
    {
        $response = Http::accept(implode(', ', self::CAPABILITIES_CONTENT_TYPES))
            ->withHeaders($this->headers())
            ->connectTimeout($this->configuration->connectTimeoutSeconds())
            ->timeout($this->configuration->capabilitiesTimeoutSeconds())
            ->withoutRedirecting()
            ->withOptions($this->boundedOptions($this->configuration->maximumCapabilitiesBytes()))
            ->get($endpoint, [
                'service' => 'WMS',
                'version' => '1.3.0',
                'request' => 'GetCapabilities',
            ]);

        $body = $this->validatedBody(
            $response,
            $endpoint,
            self::CAPABILITIES_CONTENT_TYPES,
            $this->configuration->maximumCapabilitiesBytes(),
            128,
        );

        return $this->parseTimeline($body, $now);
    }

    private function downloadFrame(
        string $endpoint,
        CarbonImmutable $validAt,
        CarbonImmutable $referenceTime,
        string $phase,
    ): string {
        [$west, $south, $east, $north] = $this->configuration->bbox();
        // WMS 1.3 uses latitude,longitude axis order for EPSG:4326.
        $bbox = implode(',', [$south, $west, $north, $east]);
        $selectionReference = $phase === 'observation' ? $validAt : $referenceTime;
        $response = Http::accept('image/png')
            ->withHeaders($this->headers())
            ->connectTimeout($this->configuration->connectTimeoutSeconds())
            ->timeout($this->configuration->frameTimeoutSeconds())
            ->withoutRedirecting()
            ->withOptions($this->boundedOptions($this->configuration->maximumFrameBytes()))
            ->get($endpoint, [
                'service' => 'WMS',
                'version' => '1.3.0',
                'request' => 'GetMap',
                'layers' => $this->configuration->layer(),
                'styles' => $this->configuration->style(),
                'crs' => 'EPSG:4326',
                'bbox' => $bbox,
                'width' => $this->configuration->frameWidth(),
                'height' => $this->configuration->frameHeight(),
                'format' => 'image/png',
                'transparent' => 'true',
                'time' => $validAt->format('Y-m-d\TH:i:s\Z'),
                'REFERENCE_TIME' => $selectionReference->format('Y-m-d\TH:i:s\Z'),
            ]);

        $body = $this->validatedBody(
            $response,
            $endpoint,
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
     *   reference_time: string,
     *   frames: list<array{valid_at: string, phase: 'observation'|'forecast'}>
     * }
     */
    private function parseTimeline(string $xml, CarbonImmutable $now): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \UnexpectedValueException('The DWD capabilities XML contains prohibited declarations.');
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
                throw new \UnexpectedValueException('The DWD capabilities XML is invalid.');
            }
            $xpath = new DOMXPath($document);
            $layers = [];
            foreach ($xpath->query('//*[local-name()="Layer"]/*[local-name()="Name"]') ?: [] as $nameNode) {
                if (trim($nameNode->textContent) === $this->configuration->layer()
                    && $nameNode->parentNode instanceof DOMElement) {
                    $layers[] = $nameNode->parentNode;
                }
            }
            if (count($layers) !== 1) {
                throw new \UnexpectedValueException('The required DWD radar layer is not uniquely available.');
            }
            $time = $this->singleDimension($xpath, $layers[0], 'time');
            $reference = $this->singleDimension($xpath, $layers[0], 'REFERENCE_TIME');
            $referenceTime = $this->timestamp($reference->getAttribute('default'));
            if ($referenceTime->greaterThan($now->addMinutes(10))) {
                throw new \UnexpectedValueException('The DWD radar reference time is unexpectedly in the future.');
            }
            [$availableFrom, $availableUntil, $stepMinutes] = $this->timeRange($time->textContent);
            if ($stepMinutes !== $this->configuration->intervalMinutes()) {
                throw new \UnexpectedValueException('The DWD radar timeline has an unsupported interval.');
            }

            $first = $referenceTime->subMinutes($this->configuration->historyMinutes());
            $last = $referenceTime->addMinutes($this->configuration->forecastMinutes());
            if ($first->lessThan($availableFrom) || $last->greaterThan($availableUntil)) {
                throw new \UnexpectedValueException('The DWD radar timeline is incomplete.');
            }

            $frames = [];
            $cursor = $first;
            while ($cursor->lessThanOrEqualTo($last)) {
                $frames[] = [
                    'valid_at' => $cursor->toIso8601String(),
                    'phase' => $cursor->lessThanOrEqualTo($referenceTime)
                        ? 'observation'
                        : 'forecast',
                ];
                $cursor = $cursor->addMinutes($stepMinutes);
            }
            $expected = intdiv(
                $this->configuration->historyMinutes() + $this->configuration->forecastMinutes(),
                $stepMinutes,
            ) + 1;
            if (count($frames) !== $expected) {
                throw new \UnexpectedValueException('The DWD radar frame set is incomplete.');
            }

            return [
                'reference_time' => $referenceTime->toIso8601String(),
                'frames' => $frames,
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
            throw new \UnexpectedValueException("The DWD radar {$name} dimension is incomplete.");
        }
        $dimension = $dimensions->item(0);
        if (! $dimension instanceof DOMElement
            || strtoupper(trim($dimension->getAttribute('units'))) !== 'ISO8601'
            || strlen($dimension->textContent) > 262_144) {
            throw new \UnexpectedValueException("The DWD radar {$name} dimension is invalid.");
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
            throw new \UnexpectedValueException('The DWD radar time range is invalid.');
        }
        $start = $this->timestamp($parts[0]);
        $end = $this->timestamp($parts[1]);
        $minutes = (int) $matches[1];
        if ($end->lessThan($start)) {
            throw new \UnexpectedValueException('The DWD radar time range is reversed.');
        }

        return [$start, $end, $minutes];
    }

    private function timestamp(string $value): CarbonImmutable
    {
        if (strlen($value) > 32
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z\z/D', $value) !== 1) {
            throw new \UnexpectedValueException('A DWD radar timestamp is invalid.');
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable $exception) {
            throw new \UnexpectedValueException('A DWD radar timestamp cannot be parsed.', 0, $exception);
        }
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Accept-Encoding' => 'identity',
            'User-Agent' => 'DIS-Live-Radar/1.0',
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
                    throw new \RuntimeException('DWD response length is invalid.');
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
                    throw new \RuntimeException('DWD response exceeded its size limit.');
                }
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options['curl'] = [CURLOPT_PROTOCOLS => CURLPROTO_HTTPS];
        }

        return $options;
    }

    /** @param list<string> $contentTypes */
    private function validatedBody(
        Response $response,
        string $endpoint,
        array $contentTypes,
        int $maximumBytes,
        int $minimumBytes,
    ): string {
        if ($response->status() !== 200
            || $response->redirect()
            || trim((string) $response->header('Location')) !== '') {
            throw new \RuntimeException('The DWD response was not an exact HTTP 200.');
        }
        $this->validateEffectiveUri($response, $endpoint);
        $encoding = strtolower(trim((string) $response->header('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new \UnexpectedValueException('The DWD response encoding is unsupported.');
        }
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0]));
        if (! in_array($contentType, $contentTypes, true)) {
            throw new \UnexpectedValueException('The DWD response content type is invalid.');
        }
        $body = $response->body();
        $size = strlen($body);
        $announced = trim((string) $response->header('Content-Length'));
        if ($size < $minimumBytes
            || $size > $maximumBytes
            || ($announced !== '' && (! ctype_digit($announced) || (int) $announced !== $size))) {
            throw new \UnexpectedValueException('The DWD response size is invalid.');
        }

        return $body;
    }

    private function validateEffectiveUri(Response $response, string $endpoint): void
    {
        $effective = $response->effectiveUri();
        if ($effective === null) {
            return;
        }
        $expected = parse_url($endpoint);
        $actual = parse_url((string) $effective);
        if (! is_array($expected)
            || ! is_array($actual)
            || ($actual['scheme'] ?? null) !== 'https'
            || ($actual['host'] ?? null) !== ($expected['host'] ?? null)
            || ($actual['path'] ?? null) !== ($expected['path'] ?? null)
            || isset($actual['user'])
            || isset($actual['pass'])
            || isset($actual['fragment'])
            || isset($actual['port'])) {
            throw new \UnexpectedValueException('The DWD response resolved to an unexpected endpoint.');
        }
    }

    private function validatePng(string $body, int $expectedWidth, int $expectedHeight): void
    {
        if (! str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            throw new \UnexpectedValueException('The DWD radar frame does not have a PNG signature.');
        }
        $dimensions = @getimagesizefromstring($body);
        if (! is_array($dimensions)
            || ($dimensions[0] ?? null) !== $expectedWidth
            || ($dimensions[1] ?? null) !== $expectedHeight
            || ($dimensions[2] ?? null) !== IMAGETYPE_PNG) {
            throw new \UnexpectedValueException('The DWD radar frame dimensions are invalid.');
        }
    }
}
