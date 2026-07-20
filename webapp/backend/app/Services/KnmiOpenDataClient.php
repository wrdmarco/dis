<?php

namespace App\Services;

use App\DTO\KnmiOpenDataArchive;
use App\Exceptions\KnmiForecastImportException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

final class KnmiOpenDataClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly KnmiOpenDataConfiguration $configuration,
    ) {}

    public function latestArchive(): KnmiOpenDataArchive
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            throw new KnmiForecastImportException('not_configured', 'KNMI Open Data API key is not configured.');
        }
        $dataset = $this->configuration->dataset();
        $version = $this->configuration->datasetVersion();
        $url = $this->configuration->apiBaseUrl().'/datasets/'.$dataset.'/versions/'.$version.'/files';
        $response = $this->apiRequest($apiKey, $url, [
            'maxKeys' => 1,
            'orderBy' => 'created',
            'sorting' => 'desc',
        ]);
        $payload = $this->jsonObject($response, 'metadata_unavailable');
        $files = $payload['files'] ?? null;
        if (! is_array($files) || ! array_is_list($files) || count($files) !== 1 || ! is_array($files[0])) {
            throw new KnmiForecastImportException('metadata_invalid', 'KNMI archive listing has an invalid files shape.');
        }
        $filename = $files[0]['filename'] ?? null;
        $size = $files[0]['size'] ?? null;
        if (! is_string($filename)
            || preg_match('/\AHARM43_V1_P1_(\d{10})\.tar\z/D', $filename) !== 1
            || ! is_int($size)
            || $size < $this->configuration->minimumArchiveBytes()
            || $size > $this->configuration->maximumArchiveBytes()) {
            throw new KnmiForecastImportException('metadata_invalid', 'KNMI archive metadata failed validation.');
        }
        foreach (['created', 'lastModified'] as $timestampKey) {
            if (array_key_exists($timestampKey, $files[0])
                && (! is_string($files[0][$timestampKey]) || ! $this->validIsoTimestamp($files[0][$timestampKey]))) {
                throw new KnmiForecastImportException('metadata_invalid', 'KNMI archive timestamp metadata failed validation.');
            }
        }

        return new KnmiOpenDataArchive($filename, $size);
    }

    /**
     * @param  callable(int, int): void  $progress
     */
    public function download(KnmiOpenDataArchive $archive, string $destination, callable $progress): string
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            throw new KnmiForecastImportException('not_configured', 'KNMI Open Data API key is not configured.');
        }
        $dataset = $this->configuration->dataset();
        $version = $this->configuration->datasetVersion();
        $metadataUrl = $this->configuration->apiBaseUrl().'/datasets/'.$dataset.'/versions/'.$version.'/files/'.rawurlencode($archive->filename).'/url';
        $urlResponse = $this->apiRequest($apiKey, $metadataUrl);
        $payload = $this->jsonObject($urlResponse, 'download_url_unavailable');
        $downloadUrl = $payload['temporaryDownloadUrl'] ?? null;
        if (! is_string($downloadUrl) || ! $this->validDownloadUrl($downloadUrl, $archive->filename)) {
            throw new KnmiForecastImportException('download_url_invalid', 'KNMI returned an unsafe download URL.');
        }

        if (is_file($destination) && ! @unlink($destination)) {
            throw new KnmiForecastImportException('storage_unavailable', 'Existing KNMI staging file could not be removed.');
        }

        try {
            $response = $this->http
                ->accept('application/x-tar, application/octet-stream')
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->downloadTimeoutSeconds())
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $destination,
                    'progress' => static function (
                        int|float $downloadTotal,
                        int|float $downloadedBytes,
                    ) use ($progress, $archive): void {
                        $total = (int) $downloadTotal;
                        $downloaded = (int) $downloadedBytes;
                        if ($downloaded > $archive->sizeBytes
                            || ($total > 0 && $total !== $archive->sizeBytes)) {
                            throw new KnmiForecastImportException(
                                'download_size_mismatch',
                                'KNMI download exceeded or differed from its declared archive size.',
                            );
                        }
                        $progress($downloaded, $total > 0 ? $total : $archive->sizeBytes);
                    },
                ])
                // The presigned object URL authenticates itself. In particular,
                // do not forward the KNMI API key or a Range header to S3.
                ->get($downloadUrl);
        } catch (KnmiForecastImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KnmiForecastImportException('download_failed', 'KNMI archive download failed.', $exception);
        }
        if ($response->status() !== 200) {
            throw new KnmiForecastImportException('download_failed', 'KNMI archive download did not return HTTP 200.');
        }
        $contentLength = $response->header('Content-Length');
        if (! is_string($contentLength)
            || preg_match('/\A\d+\z/D', $contentLength) !== 1
            || (int) $contentLength !== $archive->sizeBytes) {
            throw new KnmiForecastImportException('download_size_mismatch', 'KNMI response Content-Length did not match metadata.');
        }

        // Laravel's fake HTTP transport does not honour Guzzle's sink option.
        // This branch is also safe for real responses, whose body is empty once
        // Guzzle has streamed it to the configured sink.
        clearstatcache(true, $destination);
        if (app()->runningUnitTests()
            && (! is_file($destination) || filesize($destination) !== $archive->sizeBytes)
            && strlen($response->body()) === $archive->sizeBytes) {
            if (@file_put_contents($destination, $response->body(), LOCK_EX) !== $archive->sizeBytes) {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI archive could not be written to staging.');
            }
        }
        clearstatcache(true, $destination);
        if (! is_file($destination) || filesize($destination) !== $archive->sizeBytes) {
            throw new KnmiForecastImportException('download_size_mismatch', 'Downloaded KNMI archive size did not match metadata.');
        }
        $sha256 = @hash_file('sha256', $destination);
        if (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new KnmiForecastImportException('download_integrity_failed', 'KNMI archive checksum could not be calculated.');
        }
        $progress($archive->sizeBytes, $archive->sizeBytes);

        return $sha256;
    }

    /** @param array<string, scalar> $query */
    private function apiRequest(string $apiKey, string $url, array $query = []): Response
    {
        try {
            $response = $this->http
                ->withHeaders(['Authorization' => $apiKey])
                ->acceptJson()
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout(30)
                ->withOptions(['allow_redirects' => false])
                ->get($url, $query);
        } catch (Throwable $exception) {
            throw new KnmiForecastImportException('metadata_unavailable', 'KNMI metadata request failed.', $exception);
        }
        if ($response->status() !== 200) {
            throw new KnmiForecastImportException('metadata_unavailable', 'KNMI metadata request did not return HTTP 200.');
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function jsonObject(Response $response, string $errorCode): array
    {
        if (strlen($response->body()) > 65_536) {
            throw new KnmiForecastImportException($errorCode, 'KNMI metadata response exceeded its size limit.');
        }
        try {
            $payload = $response->json();
        } catch (Throwable $exception) {
            throw new KnmiForecastImportException($errorCode, 'KNMI returned malformed JSON.', $exception);
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new KnmiForecastImportException($errorCode, 'KNMI returned an invalid JSON object.');
        }

        return $payload;
    }

    private function validDownloadUrl(string $url, string $filename): bool
    {
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || ! hash_equals($this->configuration->downloadHost(), strtolower($parts['host']))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! is_string($parts['query'] ?? null)
            || $parts['query'] === '') {
            return false;
        }
        $expectedPath = '/'.$this->configuration->dataset().'/'.$this->configuration->datasetVersion().'/'.$filename;
        $path = is_string($parts['path'] ?? null) ? rawurldecode($parts['path']) : '';

        return hash_equals($expectedPath, $path);
    }

    private function validIsoTimestamp(string $value): bool
    {
        if (strlen($value) > 64
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})\z/D', $value) !== 1) {
            return false;
        }
        try {
            new \DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
