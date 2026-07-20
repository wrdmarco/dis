<?php

namespace App\Services;

use App\Exceptions\KnmiForecastImportException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Throwable;

final class KnmiForecastSemanticValidator
{
    /** @var array<int, array{level_type: int, level: int, time_range: int}> */
    private const PARAMETERS = [
        71 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        73 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        74 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        75 => ['level_type' => 105, 'level' => 0, 'time_range' => 0],
        186 => ['level_type' => 200, 'level' => 0, 'time_range' => 0],
    ];

    /**
     * @param  array{model_run_at: string, members: list<array{filename: string, lead_hours: int, valid_at: string, size_bytes: int, sha256: string}>}  $manifest
     */
    public function validate(array $manifest, string $releaseDirectory): void
    {
        try {
            $modelRun = Carbon::parse($manifest['model_run_at'])->utc();
        } catch (Throwable $exception) {
            throw new KnmiForecastImportException('grib_semantic_invalid', 'KNMI semantic manifest run is invalid.', $exception);
        }
        $root = realpath($releaseDirectory);
        if ($root === false || is_link($releaseDirectory)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI semantic validation root is unsafe.');
        }
        foreach ($manifest['members'] as $member) {
            $path = $root.DIRECTORY_SEPARATOR.$member['filename'];
            $realPath = realpath($path);
            if ($realPath === false || is_link($path) || ! is_file($realPath)
                || ! hash_equals($this->normalizedPath($root.DIRECTORY_SEPARATOR.$member['filename']), $this->normalizedPath($realPath))) {
                throw new KnmiForecastImportException('grib_semantic_invalid', 'KNMI semantic member path is invalid.');
            }
            try {
                $result = Process::timeout($this->timeoutSeconds())->run($this->command($realPath));
            } catch (Throwable $exception) {
                throw new KnmiForecastImportException('grib_semantic_invalid', 'KNMI ecCodes semantic validation could not run.', $exception);
            }
            if (! $result->successful()
                || strlen($result->output()) > 4096
                || trim($result->errorOutput()) !== ''
                || ! $this->validOutput($result->output(), $modelRun, (int) $member['lead_hours'])) {
                throw new KnmiForecastImportException('grib_semantic_invalid', 'KNMI GRIB parameter or time metadata is invalid.');
            }
        }
    }

    /** @return list<string> */
    private function command(string $path): array
    {
        return [
            '/usr/bin/grib_get',
            '-B',
            'indicatorOfParameter:i asc',
            '-w',
            'indicatorOfParameter:i=71/73/74/75/186',
            '-p',
            'indicatorOfParameter:i,indicatorOfTypeOfLevel:i,level:i,timeRangeIndicator:i,dataDate:i,dataTime:i,validityDate:i,validityTime:i',
            $path,
        ];
    }

    private function validOutput(string $output, Carbon $modelRun, int $leadHours): bool
    {
        $lines = preg_split('/\R/u', trim($output));
        if (! is_array($lines) || count($lines) !== count(self::PARAMETERS)) {
            return false;
        }
        $expectedValid = $modelRun->copy()->addHours($leadHours);
        $seen = [];
        foreach ($lines as $line) {
            $columns = preg_split('/\s+/', trim($line));
            if (! is_array($columns) || count($columns) !== 8) {
                return false;
            }
            foreach ($columns as $column) {
                if (preg_match('/\A\d+\z/D', $column) !== 1) {
                    return false;
                }
            }
            [$parameter, $levelType, $level, $timeRange, $dataDate, $dataTime, $validityDate, $validityTime] = array_map('intval', $columns);
            $definition = self::PARAMETERS[$parameter] ?? null;
            if ($definition === null
                || isset($seen[$parameter])
                || $levelType !== $definition['level_type']
                || $level !== $definition['level']
                || $timeRange !== $definition['time_range']
                || $dataDate !== (int) $modelRun->format('Ymd')
                || $dataTime !== (int) $modelRun->format('Hi')
                || $validityDate !== (int) $expectedValid->format('Ymd')
                || $validityTime !== (int) $expectedValid->format('Hi')) {
                return false;
            }
            $seen[$parameter] = true;
        }

        return array_keys($seen) === array_keys(self::PARAMETERS);
    }

    private function timeoutSeconds(): int
    {
        return max(2, min(30, (int) config('dis.knmi_forecast.query_timeout_seconds', 10)));
    }

    private function normalizedPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
