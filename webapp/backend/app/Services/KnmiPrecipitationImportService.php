<?php

namespace App\Services;

use App\DTO\KnmiPrecipitationRemoteFile;
use App\Exceptions\KnmiPrecipitationImportException;
use App\Repositories\KnmiPrecipitationSnapshotRepository;
use Carbon\CarbonImmutable;
use Throwable;

final class KnmiPrecipitationImportService
{
    private const RADAR_DATASET = 'radar_forecast';

    private const PROBABILITY_DATASET = 'seamless_precipitation_ensemble_forecast_probabilities';

    public function __construct(
        private readonly KnmiPrecipitationOpenDataClient $client,
        private readonly KnmiPrecipitationHdf5Reader $reader,
        private readonly KnmiPrecipitationSnapshotRepository $snapshots,
        private readonly WallboardForecastLocationService $locations,
    ) {}

    /**
     * @return array{
     *   changed: bool,
     *   reference_time: string,
     *   snapshot_id: string,
     *   datasets: array{
     *     radar_forecast: array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null},
     *     seamless_precipitation_ensemble_forecast_probabilities: array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}
     *   }
     * }
     */
    public function refresh(): array
    {
        $radar = $this->client->latestRadarFile();
        $reference = $radar->referenceTime->toIso8601String();
        [$probability, $probabilityResult] = $this->probabilityCandidate($radar->referenceTime);
        $active = $this->snapshots->activeSnapshot();
        $activeRadarMatches = $this->activeRadarMatches($active, $radar, $reference);
        $activeProbabilityMatches = $this->activeProbabilityMatches($active, $probability);

        if ($activeRadarMatches && ($probability === null || $activeProbabilityMatches)) {
            if ($activeProbabilityMatches) {
                $probabilityResult = $this->datasetResult('unchanged', false, $reference);
            }

            return $this->result(
                false,
                $active['reference_time'],
                $active['snapshot_id'],
                $this->datasetResult('unchanged', false, $reference),
                $probabilityResult,
            );
        }

        $staging = $this->snapshots->createStagingDirectory();
        try {
            $radarPath = $staging.DIRECTORY_SEPARATOR.$radar->filename;
            $files = ['radar' => $radar];
            $sha256 = ['radar' => $this->client->download($radar, $radarPath)];
            $this->reader->validateRadarFile($radarPath, $radar->referenceTime);
            $atlas = $this->reader->renderRadarAtlas(
                $radarPath,
                $radar->referenceTime,
                $staging.DIRECTORY_SEPARATOR.KnmiPrecipitationHdf5Reader::RADAR_ATLAS_FILENAME,
            );

            if ($probability !== null) {
                [$files, $sha256, $probabilityResult] = $this->addProbability(
                    $staging,
                    $radarPath,
                    $radar->referenceTime,
                    $probability,
                    $files,
                    $sha256,
                );
            }
            if (! isset($files['probability'])) {
                $this->validateNationalPoints($radarPath, null, $radar->referenceTime);
            }

            if ($activeRadarMatches && ! isset($files['probability'])) {
                $this->snapshots->discardStaging($staging);

                return $this->result(
                    false,
                    $active['reference_time'],
                    $active['snapshot_id'],
                    $this->datasetResult('unchanged', false, $reference),
                    $probabilityResult,
                );
            }

            $manifest = $this->snapshots->activate($staging, $files, $sha256, $atlas);

            return $this->result(
                true,
                $manifest['reference_time'],
                $manifest['snapshot_id'],
                $this->datasetResult(
                    $activeRadarMatches ? 'unchanged' : 'succeeded',
                    ! $activeRadarMatches,
                    $reference,
                ),
                $probabilityResult,
            );
        } catch (Throwable $exception) {
            $this->snapshots->discardStaging($staging);

            throw $exception;
        }
    }

    /**
     * @return array{
     *   KnmiPrecipitationRemoteFile|null,
     *   array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}
     * }
     */
    private function probabilityCandidate(CarbonImmutable $reference): array
    {
        try {
            $probability = $this->client->matchingProbabilityFile($reference);
            if ($probability === null) {
                return [
                    null,
                    $this->datasetResult(
                        'unavailable',
                        false,
                        null,
                        'matching_run_unavailable',
                        'Voor deze radarcyclus is geen bijpassende KNMI-ensemblekans gepubliceerd.',
                    ),
                ];
            }

            return [
                $probability,
                $this->datasetResult('succeeded', true, $probability->referenceTime->toIso8601String()),
            ];
        } catch (Throwable $exception) {
            return [null, $this->failureResult($exception, null)];
        }
    }

    /**
     * @param  array{radar: KnmiPrecipitationRemoteFile}  $files
     * @param  array{radar: string}  $sha256
     * @return array{
     *   array{radar: KnmiPrecipitationRemoteFile, probability?: KnmiPrecipitationRemoteFile},
     *   array{radar: string, probability?: string},
     *   array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}
     * }
     */
    private function addProbability(
        string $radarStaging,
        string $radarPath,
        CarbonImmutable $reference,
        KnmiPrecipitationRemoteFile $probability,
        array $files,
        array $sha256,
    ): array {
        $probabilityStaging = null;
        try {
            $probabilityStaging = $this->snapshots->createStagingDirectory();
            $probabilityPath = $probabilityStaging.DIRECTORY_SEPARATOR.$probability->filename;
            $probabilitySha256 = $this->client->download($probability, $probabilityPath);
            $this->reader->validateProbabilityFile($probabilityPath, $reference);
            $this->validateNationalPoints($radarPath, $probabilityPath, $reference);

            $destination = $radarStaging.DIRECTORY_SEPARATOR.$probability->filename;
            if (file_exists($destination)
                || is_link($destination)
                || ! @rename($probabilityPath, $destination)) {
                throw new KnmiPrecipitationImportException(
                    'storage_unavailable',
                    'The validated KNMI probability file could not be moved into radar staging.',
                );
            }
            $files['probability'] = $probability;
            $sha256['probability'] = $probabilitySha256;

            return [
                $files,
                $sha256,
                $this->datasetResult('succeeded', true, $reference->toIso8601String()),
            ];
        } catch (Throwable $exception) {
            return [$files, $sha256, $this->failureResult($exception, $reference->toIso8601String())];
        } finally {
            if (is_string($probabilityStaging)) {
                $this->snapshots->discardStaging($probabilityStaging);
            }
        }
    }

    private function activeRadarMatches(
        mixed $active,
        KnmiPrecipitationRemoteFile $radar,
        string $reference,
    ): bool {
        return is_array($active)
            && in_array($active['version'] ?? null, [2, 3], true)
            && is_array($active['atlas'] ?? null)
            && is_string($active['reference_time'] ?? null)
            && hash_equals($reference, $active['reference_time'])
            && ($active['files']['radar']['filename'] ?? null) === $radar->filename
            && ($active['files']['radar']['size_bytes'] ?? null) === $radar->sizeBytes;
    }

    private function activeProbabilityMatches(
        mixed $active,
        ?KnmiPrecipitationRemoteFile $probability,
    ): bool {
        return $probability !== null
            && is_array($active)
            && ($active['files']['probability']['filename'] ?? null) === $probability->filename
            && ($active['files']['probability']['size_bytes'] ?? null) === $probability->sizeBytes;
    }

    private function validateNationalPoints(
        string $radarPath,
        ?string $probabilityPath,
        CarbonImmutable $reference,
    ): void {
        $resolution = $this->locations->resolve([
            'location_mode' => WallboardForecastLocationService::MODE_NETHERLANDS,
        ]);
        $locations = $resolution['locations'] ?? null;
        $expected = $resolution['expected_locations'] ?? null;
        if (($resolution['complete'] ?? false) !== true
            || ! is_array($locations)
            || ! array_is_list($locations)
            || ! is_int($expected)
            || $expected < 1
            || $expected > 12
            || count($locations) !== $expected) {
            throw new \RuntimeException('The national KNMI precipitation validation locations are incomplete.');
        }
        foreach ($locations as $location) {
            if (! is_array($location)
                || ! is_numeric($location['latitude'] ?? null)
                || ! is_numeric($location['longitude'] ?? null)) {
                throw new \RuntimeException('A national KNMI precipitation validation location is invalid.');
            }
            $this->reader->readPoint(
                $radarPath,
                $probabilityPath,
                $reference,
                (float) $location['latitude'],
                (float) $location['longitude'],
            );
        }
    }

    /**
     * @param  array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}  $radar
     * @param  array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}  $probability
     * @return array<string, mixed>
     */
    private function result(
        bool $changed,
        string $referenceTime,
        string $snapshotId,
        array $radar,
        array $probability,
    ): array {
        return [
            'changed' => $changed,
            'reference_time' => $referenceTime,
            'snapshot_id' => $snapshotId,
            'datasets' => [
                self::RADAR_DATASET => $radar,
                self::PROBABILITY_DATASET => $probability,
            ],
        ];
    }

    /**
     * @return array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}
     */
    private function datasetResult(
        string $status,
        bool $changed,
        ?string $referenceTime,
        ?string $errorCode = null,
        ?string $errorMessage = null,
    ): array {
        return [
            'status' => $status,
            'changed' => $changed,
            'reference_time' => $referenceTime,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
        ];
    }

    /**
     * @return array{status: string, changed: bool, reference_time: string|null, error_code: string|null, error_message: string|null}
     */
    private function failureResult(Throwable $exception, ?string $referenceTime): array
    {
        $code = $exception instanceof KnmiPrecipitationImportException
            ? $exception->publicCode
            : 'probability_processing_failed';

        return $this->datasetResult(
            'failed',
            false,
            $referenceTime,
            $code,
            match ($code) {
                'metadata_unavailable' => 'Het KNMI-overzicht van de ensemblekans kon niet worden opgehaald.',
                'metadata_invalid' => 'Het KNMI-overzicht van de ensemblekans is ongeldig.',
                'download_url_unavailable', 'download_url_invalid' => 'De KNMI-ensemblekans kon niet veilig worden gedownload.',
                'download_failed', 'download_size_mismatch', 'download_integrity_failed', 'container_invalid' => 'Het bestand met de KNMI-ensemblekans is niet volledig of niet integer ontvangen.',
                'schema_invalid', 'hdf5_invalid', 'local_data_invalid' => 'Het bestand met de KNMI-ensemblekans voldoet niet aan het verwachte gegevensschema.',
                'hdf5_unavailable' => 'De lokale controle van de KNMI-ensemblekans is tijdelijk niet beschikbaar.',
                'storage_unavailable' => 'De KNMI-ensemblekans kon niet veilig lokaal worden opgeslagen.',
                default => 'De KNMI-ensemblekans kon niet veilig worden verwerkt.',
            },
        );
    }
}
