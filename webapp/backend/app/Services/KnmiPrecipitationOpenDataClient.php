<?php

namespace App\Services;

use App\DTO\KnmiPrecipitationRemoteFile;
use App\Exceptions\KnmiPrecipitationImportException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

final class KnmiPrecipitationOpenDataClient
{
    private const LIST_LIMIT = 24;

    private const MAX_METADATA_BYTES = 65_536;

    private const HDF5_MAGIC = "\x89HDF\r\n\x1a\n";

    public function __construct(
        private readonly HttpFactory $http,
        private readonly KnmiPrecipitationConfiguration $configuration,
    ) {}

    public function latestRadarFile(): KnmiPrecipitationRemoteFile
    {
        $radar = $this->listFiles(
            $this->configuration->radarDataset(),
            $this->configuration->radarVersion(),
            '/\ARAD_NL25_RAC_FM_(\d{12})\.h5\z/D',
        );
        krsort($radar, SORT_STRING);
        $latest = reset($radar);
        if (! $latest instanceof KnmiPrecipitationRemoteFile) {
            throw new KnmiPrecipitationImportException(
                'metadata_invalid',
                'KNMI did not publish a valid radar forecast file.',
            );
        }
        $this->validateCurrentRadarReference($latest->referenceTime);

        return $latest;
    }

    public function matchingProbabilityFile(CarbonImmutable $reference): ?KnmiPrecipitationRemoteFile
    {
        $probability = $this->listFiles(
            $this->configuration->probabilityDataset(),
            $this->configuration->probabilityVersion(),
            '/\AKNMI_PYSTEPS_BLEND_PROB_(\d{12})\.nc\z/D',
        );
        $referenceKey = 't'.$reference->format('YmdHi');

        return $probability[$referenceKey] ?? null;
    }

    private function validateCurrentRadarReference(CarbonImmutable $reference): void
    {
        $now = CarbonImmutable::now()->utc();
        if ($reference->greaterThan($now->addMinutes(10))
            || $reference->lessThan($now->subSeconds($this->configuration->maximumReferenceAgeSeconds()))) {
            throw new KnmiPrecipitationImportException(
                'radar_run_stale',
                'The newest KNMI radar forecast file is outside the permitted age window.',
            );
        }
    }

    public function download(KnmiPrecipitationRemoteFile $file, string $destination): string
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            throw new KnmiPrecipitationImportException('not_configured', 'KNMI Open Data API key is not configured.');
        }
        $metadataUrl = $this->filesUrl($file->dataset, $file->datasetVersion)
            .'/'.rawurlencode($file->filename).'/url';
        $payload = $this->jsonObject($this->apiRequest($apiKey, $metadataUrl), 'download_url_unavailable');
        $downloadUrl = $payload['temporaryDownloadUrl'] ?? null;
        if (! is_string($downloadUrl) || ! $this->validDownloadUrl($downloadUrl, $file)) {
            throw new KnmiPrecipitationImportException('download_url_invalid', 'KNMI returned an unsafe download URL.');
        }

        if (is_file($destination) && ! @unlink($destination)) {
            throw new KnmiPrecipitationImportException('storage_unavailable', 'A KNMI staging file could not be replaced safely.');
        }

        try {
            $response = $this->http
                ->accept('application/x-hdf5, application/x-netcdf, application/octet-stream')
                ->connectTimeout($this->configuration->connectTimeoutSeconds())
                ->timeout($this->configuration->downloadTimeoutSeconds())
                ->withOptions([
                    'allow_redirects' => false,
                    'sink' => $destination,
                    'progress' => static function (
                        int|float $downloadTotal,
                        int|float $downloadedBytes,
                    ) use ($file): void {
                        $declared = (int) $downloadTotal;
                        $downloaded = (int) $downloadedBytes;
                        if ($downloaded > $file->sizeBytes
                            || ($declared > 0 && $declared !== $file->sizeBytes)) {
                            throw new KnmiPrecipitationImportException(
                                'download_size_mismatch',
                                'KNMI precipitation download exceeded or differed from its declared size.',
                            );
                        }
                    },
                ])
                // The presigned object URL authenticates itself. Never send the
                // KNMI API key or a Range header to object storage.
                ->get($downloadUrl);
        } catch (KnmiPrecipitationImportException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new KnmiPrecipitationImportException(
                'download_failed',
                'KNMI precipitation file download failed.',
                $exception,
            );
        }
        if ($response->status() !== 200) {
            throw new KnmiPrecipitationImportException(
                'download_failed',
                'KNMI precipitation file download did not return HTTP 200.',
            );
        }
        $contentLength = $response->header('Content-Length');
        if (! is_string($contentLength)
            || preg_match('/\A\d+\z/D', $contentLength) !== 1
            || (int) $contentLength !== $file->sizeBytes) {
            throw new KnmiPrecipitationImportException(
                'download_size_mismatch',
                'KNMI precipitation Content-Length did not match metadata.',
            );
        }

        // Laravel's HTTP fake does not honour Guzzle's sink option.
        clearstatcache(true, $destination);
        if (app()->runningUnitTests()
            && (! is_file($destination) || filesize($destination) !== $file->sizeBytes)
            && strlen($response->body()) === $file->sizeBytes) {
            if (@file_put_contents($destination, $response->body(), LOCK_EX) !== $file->sizeBytes) {
                throw new KnmiPrecipitationImportException(
                    'storage_unavailable',
                    'KNMI precipitation file could not be written to staging.',
                );
            }
        }

        clearstatcache(true, $destination);
        if (! is_file($destination) || filesize($destination) !== $file->sizeBytes) {
            throw new KnmiPrecipitationImportException(
                'download_size_mismatch',
                'Downloaded KNMI precipitation file size did not match metadata.',
            );
        }
        $handle = @fopen($destination, 'rb');
        $magic = is_resource($handle) ? fread($handle, 8) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        if (! is_string($magic) || ! hash_equals(self::HDF5_MAGIC, $magic)) {
            throw new KnmiPrecipitationImportException(
                'container_invalid',
                'Downloaded KNMI precipitation file is not an HDF5/NetCDF4 container.',
            );
        }
        $sha256 = @hash_file('sha256', $destination);
        if (! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new KnmiPrecipitationImportException(
                'download_integrity_failed',
                'KNMI precipitation checksum could not be calculated.',
            );
        }

        return $sha256;
    }

    /**
     * @return array<string, KnmiPrecipitationRemoteFile>
     */
    private function listFiles(string $dataset, string $version, string $filenamePattern): array
    {
        $apiKey = $this->configuration->apiKey();
        if ($apiKey === null) {
            throw new KnmiPrecipitationImportException('not_configured', 'KNMI Open Data API key is not configured.');
        }
        $response = $this->apiRequest($apiKey, $this->filesUrl($dataset, $version), [
            'maxKeys' => self::LIST_LIMIT,
            'orderBy' => 'created',
            'sorting' => 'desc',
        ]);
        $payload = $this->jsonObject($response, 'metadata_unavailable');
        $files = $payload['files'] ?? null;
        if (! is_array($files) || ! array_is_list($files) || $files === [] || count($files) > self::LIST_LIMIT) {
            throw new KnmiPrecipitationImportException(
                'metadata_invalid',
                'KNMI precipitation listing has an invalid files shape.',
            );
        }

        $validated = [];
        foreach ($files as $metadata) {
            if (! is_array($metadata)) {
                throw new KnmiPrecipitationImportException('metadata_invalid', 'KNMI precipitation file metadata is invalid.');
            }
            $filename = $metadata['filename'] ?? null;
            $size = $metadata['size'] ?? null;
            if (! is_string($filename)
                || preg_match($filenamePattern, $filename, $matches) !== 1
                || ! is_int($size)
                || $size < $this->configuration->minimumBytes($dataset)
                || $size > $this->configuration->maximumBytes($dataset)) {
                throw new KnmiPrecipitationImportException(
                    'metadata_invalid',
                    'KNMI precipitation filename or size metadata failed validation.',
                );
            }
            foreach (['created', 'lastModified'] as $timestampKey) {
                if (array_key_exists($timestampKey, $metadata)
                    && (! is_string($metadata[$timestampKey]) || ! $this->validIsoTimestamp($metadata[$timestampKey]))) {
                    throw new KnmiPrecipitationImportException(
                        'metadata_invalid',
                        'KNMI precipitation publication timestamp failed validation.',
                    );
                }
            }
            $reference = CarbonImmutable::createFromFormat('!YmdHi', $matches[1], 'UTC');
            $referenceIndex = 't'.$matches[1];
            if ($reference === false
                || $reference->format('YmdHi') !== $matches[1]
                || $reference->minute % 5 !== 0
                || isset($validated[$referenceIndex])) {
                throw new KnmiPrecipitationImportException(
                    'metadata_invalid',
                    'KNMI precipitation reference timestamp failed validation.',
                );
            }
            $validated[$referenceIndex] = new KnmiPrecipitationRemoteFile(
                $dataset,
                $version,
                $filename,
                $size,
                $reference,
            );
        }

        return $validated;
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
            throw new KnmiPrecipitationImportException(
                'metadata_unavailable',
                'KNMI precipitation metadata request failed.',
                $exception,
            );
        }
        if ($response->status() !== 200) {
            throw new KnmiPrecipitationImportException(
                'metadata_unavailable',
                'KNMI precipitation metadata request did not return HTTP 200.',
            );
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function jsonObject(Response $response, string $errorCode): array
    {
        if (strlen($response->body()) > self::MAX_METADATA_BYTES) {
            throw new KnmiPrecipitationImportException($errorCode, 'KNMI precipitation metadata exceeded its size limit.');
        }
        try {
            $payload = $response->json();
        } catch (Throwable $exception) {
            throw new KnmiPrecipitationImportException(
                $errorCode,
                'KNMI returned malformed precipitation metadata.',
                $exception,
            );
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new KnmiPrecipitationImportException($errorCode, 'KNMI returned invalid precipitation metadata.');
        }

        return $payload;
    }

    private function filesUrl(string $dataset, string $version): string
    {
        return $this->configuration->apiBaseUrl().'/datasets/'.$dataset.'/versions/'.$version.'/files';
    }

    private function validDownloadUrl(string $url, KnmiPrecipitationRemoteFile $file): bool
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
        $expectedPath = '/'.$file->dataset.'/'.$file->datasetVersion.'/'.$file->filename;
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
