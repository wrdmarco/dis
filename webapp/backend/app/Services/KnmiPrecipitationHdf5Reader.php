<?php

namespace App\Services;

use App\Exceptions\KnmiPrecipitationImportException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Process;
use Throwable;

final class KnmiPrecipitationHdf5Reader
{
    private const H5DUMP = '/usr/bin/h5dump';

    private const ROWS = 765;

    private const COLUMNS = 700;

    private const X_FIRST_METRES = 500.00130649;

    private const X_STEP_METRES = 1000.00261298;

    private const Y_FIRST_METRES = -3650495.41359594;

    private const Y_STEP_METRES = -1000.00507047;

    private const SEMI_MAJOR_METRES = 6_378_137.0;

    private const SEMI_MINOR_METRES = 6_356_752.0;

    private const STANDARD_PARALLEL_DEGREES = 60.0;

    private const RADAR_IMAGES = 25;

    private const THIRD_HOUR_SAMPLES = 13;

    private const MAX_HEADER_BYTES = 524_288;

    private const MAX_DATA_BYTES = 65_536;

    public function __construct(private readonly KnmiPrecipitationConfiguration $configuration) {}

    public function validatePair(string $radarPath, string $probabilityPath, CarbonImmutable $reference): void
    {
        $this->validateRadar($this->safePath($radarPath), $reference);
        $this->validateProbability($this->safePath($probabilityPath), $reference);
    }

    /**
     * @return array{
     *   reference_time: string,
     *   radar_peak_mm_h: float,
     *   radar_first_precipitation_at: string|null,
     *   radar_until: string,
     *   third_hour_probability_pct: float,
     *   third_hour_from: string,
     *   forecast_until: string,
     *   radar_sample_count: int,
     *   third_hour_sample_count: int
     * }
     */
    public function readPoint(
        string $radarPath,
        string $probabilityPath,
        CarbonImmutable $reference,
        float $latitude,
        float $longitude,
    ): array {
        [$row, $column] = $this->gridIndex($latitude, $longitude);
        $radarCommand = [self::H5DUMP, '-A', '0', '-w', '0'];
        for ($image = 1; $image <= self::RADAR_IMAGES; $image++) {
            array_push(
                $radarCommand,
                '-d',
                '/image'.$image.'/image_data',
                '-s',
                $row.','.$column,
                '-c',
                '1,1',
            );
        }
        $radarCommand[] = $this->safePath($radarPath);
        $radarBlocks = $this->numericBlocks($this->run($radarCommand, self::MAX_DATA_BYTES));
        if (count($radarBlocks) !== self::RADAR_IMAGES) {
            throw new KnmiPrecipitationImportException(
                'local_data_invalid',
                'KNMI radar point query returned an incomplete time series.',
            );
        }

        $peak = 0.0;
        $firstPrecipitation = null;
        foreach ($radarBlocks as $index => $block) {
            if (count($block) !== 1 || ! $this->wholeNumber($block[0])) {
                throw new KnmiPrecipitationImportException('local_data_invalid', 'KNMI radar pixel value is invalid.');
            }
            $raw = (int) $block[0];
            if ($raw < 0 || $raw >= 65_534) {
                throw new KnmiPrecipitationImportException('local_data_invalid', 'KNMI radar pixel is missing or out of bounds.');
            }
            // The source stores 0.01 mm accumulated per five-minute image.
            $rate = $raw * 0.01 * 12.0;
            if (! is_finite($rate) || $rate > 500.0) {
                throw new KnmiPrecipitationImportException('local_data_invalid', 'KNMI radar intensity is out of bounds.');
            }
            $peak = max($peak, $rate);
            if ($firstPrecipitation === null && $rate >= 0.1) {
                $firstPrecipitation = $reference->addMinutes($index * 5);
            }
        }

        $probabilityCommand = [
            self::H5DUMP,
            '-A',
            '0',
            '-w',
            '0',
            '-d',
            '/exceedance_probability',
            '-s',
            '0,23,'.$row.','.$column,
            '-c',
            '1,'.self::THIRD_HOUR_SAMPLES.',1,1',
            $this->safePath($probabilityPath),
        ];
        $probabilityBlocks = $this->numericBlocks($this->run($probabilityCommand, self::MAX_DATA_BYTES));
        $probabilityValues = $probabilityBlocks[0] ?? [];
        if (count($probabilityBlocks) !== 1 || count($probabilityValues) !== self::THIRD_HOUR_SAMPLES) {
            throw new KnmiPrecipitationImportException(
                'local_data_invalid',
                'KNMI third-hour probability query returned an incomplete series.',
            );
        }
        foreach ($probabilityValues as $value) {
            if (! $this->wholeNumber($value) || $value < 0 || $value > 100) {
                throw new KnmiPrecipitationImportException(
                    'local_data_invalid',
                    'KNMI precipitation probability is missing or out of bounds.',
                );
            }
        }

        return [
            'reference_time' => $reference->toIso8601String(),
            'radar_peak_mm_h' => $peak,
            'radar_first_precipitation_at' => $firstPrecipitation?->toIso8601String(),
            'radar_until' => $reference->addMinutes(120)->toIso8601String(),
            'third_hour_probability_pct' => (float) max($probabilityValues),
            'third_hour_from' => $reference->addMinutes(120)->toIso8601String(),
            'forecast_until' => $reference->addMinutes(180)->toIso8601String(),
            'radar_sample_count' => self::RADAR_IMAGES,
            'third_hour_sample_count' => self::THIRD_HOUR_SAMPLES,
        ];
    }

    private function validateRadar(string $path, CarbonImmutable $reference): void
    {
        $header = $this->run([self::H5DUMP, '-H', $path], self::MAX_HEADER_BYTES);
        if (substr_count($header, 'DATASET "image_data"') !== self::RADAR_IMAGES) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar image dataset count is invalid.');
        }
        for ($image = 1; $image <= self::RADAR_IMAGES; $image++) {
            $pattern = '/GROUP\s+"image'.preg_quote((string) $image, '/').'".*?'
                .'DATASET\s+"image_data"\s*\{.*?DATATYPE\s+H5T_STD_U16(?:LE|BE).*?'
                .'DATASPACE\s+SIMPLE\s*\{\s*\(\s*765\s*,\s*700\s*\)/s';
            if (preg_match($pattern, $header) !== 1) {
                throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar image schema is invalid.');
            }
            $validAt = $this->attributeString($path, '/image'.$image.'/image_datetime_valid');
            if (! hash_equals(
                strtoupper($reference->addMinutes(($image - 1) * 5)->format('d-M-Y;H:i:s.000')),
                strtoupper($validAt),
            )) {
                throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar image validity timestamps are invalid.');
            }
        }

        $expectedProjection = '+proj=stere +lat_0=90 +lon_0=0 +lat_ts=60 +a=6378.14 +b=6356.75 +x_0=0 y_0=0';
        if (! hash_equals($expectedProjection, $this->attributeString($path, '/geographic/map_projection/projection_proj4_params'))
            || ! hash_equals('STEREOGRAPHIC', $this->attributeString($path, '/geographic/map_projection/projection_name'))
            || $this->attributeNumbers($path, '/overview/number_image_groups') !== [25.0]
            || $this->attributeNumbers($path, '/geographic/geo_number_rows') !== [765.0]
            || $this->attributeNumbers($path, '/geographic/geo_number_columns') !== [700.0]
            || ! $this->approximately($this->singleAttributeNumber($path, '/geographic/geo_pixel_size_x'), 1.0000035, 0.0001)
            || ! $this->approximately($this->singleAttributeNumber($path, '/geographic/geo_pixel_size_y'), -1.0000048, 0.0001)
            || ! hash_equals('GEO=0.010000*PV+0.000000', $this->attributeString($path, '/image1/calibration/calibration_formulas'))
            || $this->attributeNumbers($path, '/image1/calibration/calibration_missing_data') !== [65_534.0]
            || $this->attributeNumbers($path, '/image1/calibration/calibration_out_of_image') !== [65_535.0]) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar projection or calibration metadata is invalid.');
        }

        $start = $this->radarTimestamp($this->attributeString($path, '/overview/product_datetime_start'));
        $end = $this->radarTimestamp($this->attributeString($path, '/overview/product_datetime_end'));
        if (! $start->equalTo($reference) || ! $end->equalTo($reference->addMinutes(120))) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar product time range is invalid.');
        }
    }

    private function validateProbability(string $path, CarbonImmutable $reference): void
    {
        $header = $this->run([self::H5DUMP, '-H', $path], self::MAX_HEADER_BYTES);
        $schemas = [
            'exceedance_probability' => ['H5T_STD_U8(?:LE|BE)', '6\s*,\s*72\s*,\s*765\s*,\s*700'],
            'forecast_reference_time' => ['H5T_STD_I64(?:LE|BE)', ''],
            'threshold' => ['H5T_IEEE_F64(?:LE|BE)', '6'],
            'time' => ['H5T_STD_I64(?:LE|BE)', '72'],
            'x' => ['H5T_IEEE_F64(?:LE|BE)', '700'],
            'y' => ['H5T_IEEE_F64(?:LE|BE)', '765'],
        ];
        foreach ($schemas as $dataset => [$type, $dimensions]) {
            $dataspace = $dimensions === ''
                ? 'DATASPACE\s+SCALAR'
                : 'DATASPACE\s+SIMPLE\s*\{\s*\(\s*'.$dimensions.'\s*\)';
            if (preg_match(
                '/DATASET\s+"'.preg_quote($dataset, '/').'"\s*\{.*?DATATYPE\s+'.$type.'.*?'.$dataspace.'/s',
                $header,
            ) !== 1) {
                throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI probability dataset schema is invalid.');
            }
        }

        $unitOrigin = 'seconds since '.$reference->format('Y-m-d H:i:s');
        if (! hash_equals('CF-1.7', $this->attributeString($path, '/Conventions'))
            || ! hash_equals(
                '+proj=stere +lat_0=90 +lon_0=0.0 +lat_ts=60.0 +a=6378137 +b=6356752 +x_0=0 +y_0=0',
                $this->attributeString($path, '/projection'),
            )
            || ! hash_equals('percent', $this->attributeString($path, '/exceedance_probability/units'))
            || ! hash_equals($unitOrigin, $this->attributeString($path, '/forecast_reference_time/units'))
            || ! hash_equals($unitOrigin, $this->attributeString($path, '/time/units'))
            || $this->datasetNumbers($path, '/forecast_reference_time') !== [0.0]
            || $this->datasetNumbers($path, '/threshold') !== [0.1, 0.3, 1.0, 3.0, 10.0, 30.0]
            || $this->datasetNumbers($path, '/time') !== array_map(
                static fn (int $step): float => (float) ($step * 300),
                range(1, 72),
            )) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI probability dimensions or time metadata is invalid.');
        }

        $x = $this->datasetNumbers($path, '/x');
        $y = $this->datasetNumbers($path, '/y');
        if (! $this->linearAxis($x, self::COLUMNS, self::X_FIRST_METRES, self::X_STEP_METRES)
            || ! $this->linearAxis($y, self::ROWS, self::Y_FIRST_METRES, self::Y_STEP_METRES)) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI probability grid coordinates are invalid.');
        }
    }

    /** @return array{int, int} */
    private function gridIndex(float $latitude, float $longitude): array
    {
        if (! is_finite($latitude)
            || ! is_finite($longitude)
            || $latitude < -90.0
            || $latitude > 90.0
            || $longitude < -180.0
            || $longitude > 180.0) {
            throw new KnmiPrecipitationImportException('location_invalid', 'KNMI precipitation location is invalid.');
        }

        $eccentricity = sqrt(1.0 - (self::SEMI_MINOR_METRES / self::SEMI_MAJOR_METRES) ** 2);
        $latitudeRadians = deg2rad($latitude);
        $standardParallel = deg2rad(self::STANDARD_PARALLEL_DEGREES);
        $t = tan(M_PI / 4.0 - $latitudeRadians / 2.0)
            / ((1.0 - $eccentricity * sin($latitudeRadians))
                / (1.0 + $eccentricity * sin($latitudeRadians))) ** ($eccentricity / 2.0);
        $tc = tan(M_PI / 4.0 - $standardParallel / 2.0)
            / ((1.0 - $eccentricity * sin($standardParallel))
                / (1.0 + $eccentricity * sin($standardParallel))) ** ($eccentricity / 2.0);
        $mc = cos($standardParallel)
            / sqrt(1.0 - ($eccentricity ** 2) * (sin($standardParallel) ** 2));
        $rho = self::SEMI_MAJOR_METRES * $mc * $t / $tc;
        $longitudeRadians = deg2rad($longitude);
        $x = $rho * sin($longitudeRadians);
        $y = -$rho * cos($longitudeRadians);
        $column = (int) round(($x - self::X_FIRST_METRES) / self::X_STEP_METRES);
        $row = (int) round(($y - self::Y_FIRST_METRES) / self::Y_STEP_METRES);
        if ($row < 0 || $row >= self::ROWS || $column < 0 || $column >= self::COLUMNS) {
            throw new KnmiPrecipitationImportException(
                'location_outside_grid',
                'KNMI precipitation location falls outside the validated product grid.',
            );
        }

        return [$row, $column];
    }

    private function safePath(string $path): string
    {
        $real = realpath($path);
        if ($real === false || is_link($path) || ! is_file($real) || ! is_readable($real)) {
            throw new KnmiPrecipitationImportException('local_data_invalid', 'KNMI precipitation file path is unsafe.');
        }

        return $real;
    }

    /** @param list<string> $command */
    private function run(array $command, int $maximumBytes): string
    {
        try {
            $result = Process::timeout($this->configuration->queryTimeoutSeconds())->run($command);
        } catch (Throwable $exception) {
            throw new KnmiPrecipitationImportException(
                'hdf5_unavailable',
                'The fixed KNMI HDF5 inspection tool could not run.',
                $exception,
            );
        }
        $output = $result->output();
        if (! $result->successful()
            || strlen($output) > $maximumBytes
            || trim($result->errorOutput()) !== '') {
            throw new KnmiPrecipitationImportException(
                'hdf5_invalid',
                'KNMI HDF5 inspection failed or exceeded its output boundary.',
            );
        }

        return $output;
    }

    /** @return list<float> */
    private function datasetNumbers(string $path, string $dataset): array
    {
        $output = $this->run([
            self::H5DUMP,
            '-A',
            '0',
            '-w',
            '0',
            '-d',
            $dataset,
            $path,
        ], self::MAX_DATA_BYTES);
        $blocks = $this->numericBlocks($output);
        if (count($blocks) !== 1) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI numeric dataset output is invalid.');
        }

        return $blocks[0];
    }

    private function attributeString(string $path, string $attribute): string
    {
        $output = $this->run([self::H5DUMP, '-a', $attribute, '-w', '0', $path], self::MAX_DATA_BYTES);
        $data = $this->dataBody($output);
        if (preg_match('/"([^"\r\n]*)"/', $data, $matches) !== 1) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI string attribute output is invalid.');
        }
        $decoded = stripcslashes($matches[1]);
        if ($decoded === '' || strlen($decoded) > 1024 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $decoded) === 1) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI string attribute value is invalid.');
        }

        return $decoded;
    }

    /** @return list<float> */
    private function attributeNumbers(string $path, string $attribute): array
    {
        $output = $this->run([self::H5DUMP, '-a', $attribute, '-w', '0', $path], self::MAX_DATA_BYTES);

        return $this->numbers($this->dataBody($output));
    }

    private function singleAttributeNumber(string $path, string $attribute): float
    {
        $values = $this->attributeNumbers($path, $attribute);
        if (count($values) !== 1) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI numeric attribute output is invalid.');
        }

        return $values[0];
    }

    /** @return list<list<float>> */
    private function numericBlocks(string $output): array
    {
        if (preg_match_all('/\bDATA\s*\{(.*?)\}/s', $output, $matches) === false) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI HDF5 data blocks are invalid.');
        }

        return array_map(fn (string $block): array => $this->numbers($block), $matches[1]);
    }

    private function dataBody(string $output): string
    {
        if (preg_match('/\bDATA\s*\{(.*?)\}/s', $output, $matches) !== 1) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI HDF5 data output is invalid.');
        }

        return $matches[1];
    }

    /** @return list<float> */
    private function numbers(string $body): array
    {
        // Strip h5dump coordinate prefixes such as "(23,429,365):" before
        // accepting the remaining numeric values.
        $body = preg_replace('/\([^)]*\)\s*:/', ' ', $body);
        if (! is_string($body)
            || preg_match_all('/(?<![A-Za-z0-9_.])[-+]?(?:\d+(?:\.\d*)?|\.\d+)(?:[Ee][-+]?\d+)?/', $body, $matches) === false) {
            throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI HDF5 numeric output is invalid.');
        }
        $values = array_map('floatval', $matches[0]);
        foreach ($values as $value) {
            if (! is_finite($value)) {
                throw new KnmiPrecipitationImportException('hdf5_invalid', 'KNMI HDF5 numeric value is invalid.');
            }
        }

        return $values;
    }

    /** @param list<float> $values */
    private function linearAxis(array $values, int $count, float $first, float $step): bool
    {
        if (count($values) !== $count) {
            return false;
        }
        foreach ($values as $index => $value) {
            if (! $this->approximately($value, $first + ($index * $step), 0.05)) {
                return false;
            }
        }

        return true;
    }

    private function radarTimestamp(string $value): CarbonImmutable
    {
        try {
            $timestamp = CarbonImmutable::createFromFormat('!d-M-Y;H:i:s.v', strtoupper($value), 'UTC');
        } catch (Throwable $exception) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar timestamp is invalid.', $exception);
        }
        if ($timestamp === false
            || strtoupper($timestamp->format('d-M-Y;H:i:s.v')) !== strtoupper($value)) {
            throw new KnmiPrecipitationImportException('schema_invalid', 'KNMI radar timestamp is invalid.');
        }

        return $timestamp;
    }

    private function wholeNumber(float $value): bool
    {
        return is_finite($value) && floor($value) === $value;
    }

    private function approximately(float $actual, float $expected, float $tolerance): bool
    {
        return is_finite($actual) && abs($actual - $expected) <= $tolerance;
    }
}
