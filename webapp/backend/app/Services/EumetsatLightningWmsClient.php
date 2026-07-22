<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

final class EumetsatLightningWmsClient
{
    private const CAPABILITIES_CONTENT_TYPES = [
        'application/xml',
        'text/xml',
        'application/vnd.ogc.wms_xml',
    ];

    public function __construct(private readonly EumetsatLightningConfiguration $configuration) {}

    /** @return list<CarbonImmutable> */
    public function latestFrameTimes(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        try {
            $response = Http::accept(implode(', ', self::CAPABILITIES_CONTENT_TYPES))
                ->withHeaders($this->headers())
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->capabilitiesTimeoutSeconds())
                ->withoutRedirecting()
                ->withOptions($this->boundedOptions($this->configuration->maximumCapabilitiesBytes()))
                ->get($this->validatedEndpoint(), [
                    'service' => 'WMS',
                    'version' => '1.3.0',
                    'request' => 'GetCapabilities',
                ]);
        } catch (Throwable $exception) {
            throw new EumetsatLightningImportException(
                'capabilities_download_failed',
                'The EUMETSAT lightning capabilities could not be downloaded.',
                $exception,
            );
        }

        $body = $this->validatedBody(
            $response,
            self::CAPABILITIES_CONTENT_TYPES,
            $this->configuration->maximumCapabilitiesBytes(),
            128,
            'capabilities',
        );

        return $this->parseFrameTimes($body, $now);
    }

    /**
     * @param  list<CarbonImmutable>  $frameTimes
     * @return list<string>
     */
    public function downloadFrames(string $stagingDirectory, array $frameTimes): array
    {
        if (count($frameTimes) !== $this->configuration->frameCount()) {
            throw new EumetsatLightningImportException(
                'frame_set_incomplete',
                'The EUMETSAT lightning frame set is incomplete.',
            );
        }
        $endpoint = $this->validatedEndpoint();
        $bbox = implode(',', array_map(
            static fn (float $coordinate): string => rtrim(rtrim(sprintf('%.4F', $coordinate), '0'), '.'),
            $this->configuration->bbox(),
        ));

        try {
            $responses = Http::pool(function (Pool $pool) use (
                $bbox,
                $endpoint,
                $frameTimes,
            ): void {
                foreach ($frameTimes as $index => $frameTime) {
                    $pool->as('frame-'.$index)
                        ->accept('image/png')
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
                            'crs' => $this->configuration->crs(),
                            'bbox' => $bbox,
                            'width' => $this->configuration->frameWidth(),
                            'height' => $this->configuration->frameHeight(),
                            'format' => 'image/png',
                            'transparent' => 'true',
                            'time' => $frameTime->format('Y-m-d\TH:i:s\Z'),
                        ]);
                }
            }, $this->configuration->frameCount());
        } catch (Throwable $exception) {
            throw new EumetsatLightningImportException(
                'frame_download_failed',
                'The EUMETSAT lightning frames could not be downloaded.',
                $exception,
            );
        }

        $paths = [];
        foreach ($frameTimes as $index => $frameTime) {
            $response = $responses['frame-'.$index] ?? null;
            if (! $response instanceof Response) {
                throw new EumetsatLightningImportException(
                    'frame_download_failed',
                    'An EUMETSAT lightning frame did not return a valid response.',
                );
            }
            $body = $this->validatedBody(
                $response,
                ['image/png'],
                $this->configuration->maximumFrameBytes(),
                67,
                'frame',
            );
            $this->validatePng($body, $this->configuration->frameWidth(), $this->configuration->frameHeight());
            $path = $stagingDirectory.DIRECTORY_SEPARATOR.sprintf('frame-%02d.png', $index);
            if (@file_put_contents($path, $body, LOCK_EX) !== strlen($body)) {
                throw new EumetsatLightningImportException(
                    'storage_unavailable',
                    'An EUMETSAT lightning frame could not be staged.',
                );
            }
            @chmod($path, 0640);
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Accept-Encoding' => 'identity',
            'User-Agent' => 'DIS-EUMETSAT-Lightning/1.0',
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
                    throw new \RuntimeException('EUMETSAT response length is invalid.');
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
                    throw new \RuntimeException('EUMETSAT response exceeded its size limit.');
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
            || ($parts['path'] ?? null) !== '/geoserver/wms'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['port'])) {
            throw new EumetsatLightningImportException(
                'configuration_invalid',
                'The fixed EUMETSAT lightning endpoint is invalid.',
            );
        }

        return $endpoint;
    }

    /** @param list<string> $contentTypes */
    private function validatedBody(
        Response $response,
        array $contentTypes,
        int $maximumBytes,
        int $minimumBytes,
        string $kind,
    ): string {
        if ($response->status() !== 200
            || $response->redirect()
            || trim((string) $response->header('Location')) !== '') {
            throw new EumetsatLightningImportException(
                $kind.'_download_failed',
                "The EUMETSAT lightning {$kind} response was not an exact HTTP 200.",
            );
        }
        $this->validateEffectiveUri($response);
        $encoding = strtolower(trim((string) $response->header('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new EumetsatLightningImportException(
                $kind.'_content_invalid',
                "The EUMETSAT lightning {$kind} response encoding is unsupported.",
            );
        }
        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'), 2)[0]));
        if (! in_array($contentType, $contentTypes, true)) {
            throw new EumetsatLightningImportException(
                $kind.'_content_invalid',
                "The EUMETSAT lightning {$kind} response content type is invalid.",
            );
        }

        $body = $response->body();
        $size = strlen($body);
        $announced = trim((string) $response->header('Content-Length'));
        if ($size < $minimumBytes
            || $size > $maximumBytes
            || ($announced !== '' && (! ctype_digit($announced) || (int) $announced !== $size))) {
            throw new EumetsatLightningImportException(
                $kind.'_content_invalid',
                "The EUMETSAT lightning {$kind} response size is invalid.",
            );
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
            || ($parts['path'] ?? null) !== '/geoserver/wms'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || isset($parts['port'])) {
            throw new EumetsatLightningImportException(
                'remote_host_invalid',
                'The EUMETSAT response resolved to an unexpected endpoint.',
            );
        }
    }

    /** @return list<CarbonImmutable> */
    private function parseFrameTimes(string $xml, CarbonImmutable $now): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new EumetsatLightningImportException(
                'capabilities_xml_invalid',
                'The EUMETSAT capabilities XML contains prohibited declarations.',
            );
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
                throw new EumetsatLightningImportException(
                    'capabilities_xml_invalid',
                    'The EUMETSAT capabilities XML is invalid.',
                );
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
                throw new EumetsatLightningImportException(
                    'capabilities_layer_invalid',
                    'The required EUMETSAT lightning layer is not uniquely available.',
                );
            }
            $dimensions = $xpath->query(
                './*[local-name()="Dimension" or local-name()="Extent"][@name="time"]',
                $layers[0],
            );
            if ($dimensions === false || $dimensions->length !== 1) {
                throw new EumetsatLightningImportException(
                    'capabilities_time_invalid',
                    'The EUMETSAT lightning time dimension is incomplete.',
                );
            }
            $dimension = $dimensions->item(0);
            if (! $dimension instanceof DOMElement
                || strtoupper(trim($dimension->getAttribute('units'))) !== 'ISO8601'
                || strlen($dimension->textContent) > 262_144) {
                throw new EumetsatLightningImportException(
                    'capabilities_time_invalid',
                    'The EUMETSAT lightning time dimension is invalid.',
                );
            }
            $value = preg_replace('/\s+/', '', trim($dimension->textContent));
            if (! is_string($value) || $value === '') {
                throw new EumetsatLightningImportException(
                    'capabilities_time_invalid',
                    'The EUMETSAT lightning time dimension is empty.',
                );
            }

            return $this->selectFrameTimes($value, $now);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
        }
    }

    /** @return list<CarbonImmutable> */
    private function selectFrameTimes(string $dimension, CarbonImmutable $now): array
    {
        $stepSeconds = $this->configuration->intervalMinutes() * 60;
        $frameCount = $this->configuration->frameCount();
        $best = null;
        $exact = [];
        $tokens = explode(',', $dimension);
        if (count($tokens) > 8192) {
            throw new EumetsatLightningImportException(
                'capabilities_time_invalid',
                'The EUMETSAT lightning time dimension is too large.',
            );
        }
        foreach ($tokens as $token) {
            $parts = explode('/', $token);
            if (count($parts) === 1) {
                $time = $this->timestamp($parts[0]);
                if ($time->lessThanOrEqualTo($now)) {
                    $exact[$time->getTimestamp()] = $time;
                }

                continue;
            }
            if (count($parts) !== 3 || $parts[2] !== 'PT'.$this->configuration->intervalMinutes().'M') {
                throw new EumetsatLightningImportException(
                    'capabilities_time_invalid',
                    'The EUMETSAT lightning time interval is unsupported.',
                );
            }
            $start = $this->timestamp($parts[0]);
            $end = $this->timestamp($parts[1]);
            if ($end->lessThan($start)) {
                throw new EumetsatLightningImportException(
                    'capabilities_time_invalid',
                    'The EUMETSAT lightning time interval is reversed.',
                );
            }
            $limitTimestamp = min($end->getTimestamp(), $now->getTimestamp());
            $startTimestamp = $start->getTimestamp();
            if ($limitTimestamp < $startTimestamp) {
                continue;
            }
            $latestTimestamp = $startTimestamp
                + intdiv($limitTimestamp - $startTimestamp, $stepSeconds) * $stepSeconds;
            if ($latestTimestamp - (($frameCount - 1) * $stepSeconds) < $startTimestamp) {
                continue;
            }
            $candidate = [];
            for ($index = $frameCount - 1; $index >= 0; $index--) {
                $candidate[] = CarbonImmutable::createFromTimestampUTC($latestTimestamp - ($index * $stepSeconds));
            }
            if ($best === null || end($candidate)->greaterThan(end($best))) {
                $best = $candidate;
            }
        }

        if (count($exact) >= $frameCount) {
            krsort($exact, SORT_NUMERIC);
            foreach ($exact as $latestTimestamp => $latest) {
                $candidate = [];
                for ($index = $frameCount - 1; $index >= 0; $index--) {
                    $timestamp = $latestTimestamp - ($index * $stepSeconds);
                    if (! isset($exact[$timestamp])) {
                        $candidate = [];
                        break;
                    }
                    $candidate[] = $exact[$timestamp];
                }
                if ($candidate !== [] && ($best === null || $latest->greaterThan(end($best)))) {
                    $best = $candidate;
                }
            }
        }

        if (! is_array($best) || count($best) !== $frameCount) {
            throw new EumetsatLightningImportException(
                'frame_set_incomplete',
                'Seven consecutive EUMETSAT lightning frames are not available.',
            );
        }
        foreach ($best as $index => $time) {
            if ($time->second !== 0
                || $time->minute % $this->configuration->intervalMinutes() !== 0
                || ($index > 0
                    && $time->getTimestamp() - $best[$index - 1]->getTimestamp() !== $stepSeconds)) {
                throw new EumetsatLightningImportException(
                    'frame_set_incomplete',
                    'The EUMETSAT lightning frames are not on one five-minute timeline.',
                );
            }
        }

        return array_values($best);
    }

    private function timestamp(string $value): CarbonImmutable
    {
        if (strlen($value) > 32
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?Z\z/D', $value) !== 1) {
            throw new EumetsatLightningImportException(
                'capabilities_time_invalid',
                'An EUMETSAT lightning timestamp is invalid.',
            );
        }
        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable $exception) {
            throw new EumetsatLightningImportException(
                'capabilities_time_invalid',
                'An EUMETSAT lightning timestamp cannot be parsed.',
                $exception,
            );
        }
    }

    private function validatePng(string $body, int $expectedWidth, int $expectedHeight): void
    {
        if (! str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
            throw new EumetsatLightningImportException(
                'frame_content_invalid',
                'An EUMETSAT lightning frame does not have a PNG signature.',
            );
        }
        $dimensions = @getimagesizefromstring($body);
        if (! is_array($dimensions)
            || ($dimensions[0] ?? null) !== $expectedWidth
            || ($dimensions[1] ?? null) !== $expectedHeight
            || ($dimensions[2] ?? null) !== IMAGETYPE_PNG) {
            throw new EumetsatLightningImportException(
                'frame_content_invalid',
                'An EUMETSAT lightning frame has invalid PNG dimensions.',
            );
        }
    }
}
