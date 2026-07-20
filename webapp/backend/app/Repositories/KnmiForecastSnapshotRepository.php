<?php

namespace App\Repositories;

use App\Models\KnmiForecastSnapshot;
use App\Services\KnmiOpenDataConfiguration;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

final class KnmiForecastSnapshotRepository
{
    public function __construct(private readonly KnmiOpenDataConfiguration $configuration) {}

    public function active(): ?KnmiForecastSnapshot
    {
        return KnmiForecastSnapshot::query()
            ->where('active_key', KnmiForecastSnapshot::ACTIVE_KEY)
            ->first();
    }

    /**
     * Stable reader contract for forecast consumers.
     *
     * @return array{snapshot: KnmiForecastSnapshot, member: array{filename: string, lead_hours: int, valid_at: string, size_bytes: int, sha256: string}, path: string}|null
     */
    public function closestMember(CarbonInterface $at): ?array
    {
        $snapshot = $this->active();
        if ($snapshot === null) {
            return null;
        }
        $members = $this->validatedMembers($snapshot);
        if ($members === null) {
            return null;
        }

        $target = $at->getTimestamp();
        usort($members, static function (array $left, array $right) use ($target): int {
            $leftDistance = abs(Carbon::parse($left['valid_at'])->getTimestamp() - $target);
            $rightDistance = abs(Carbon::parse($right['valid_at'])->getTimestamp() - $target);

            return $leftDistance <=> $rightDistance ?: $left['lead_hours'] <=> $right['lead_hours'];
        });
        $member = $members[0];
        $path = $this->absoluteMemberPath($snapshot, $member['filename']);

        return $path === null ? null : compact('snapshot', 'member', 'path');
    }

    public function absoluteMemberPath(KnmiForecastSnapshot $snapshot, string $filename): ?string
    {
        if (preg_match('/\AHA43_N20_\d{12}_\d{5}_GB\z/D', $filename) !== 1
            || preg_match('/\Areleases\/[0-9A-HJKMNP-TV-Z]{26}\z/Di', (string) $snapshot->release_directory) !== 1) {
            return null;
        }
        $root = $this->realStorageRoot();
        $release = realpath($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $snapshot->release_directory));
        if ($release === false || is_link($release) || ! $this->isInside($release, $root)) {
            return null;
        }
        $path = $release.DIRECTORY_SEPARATOR.$filename;
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $real = realpath($path);

        return $real !== false && $this->isInside($real, $release) ? $real : null;
    }

    /**
     * @return list<array{filename: string, lead_hours: int, valid_at: string, size_bytes: int, sha256: string}>|null
     */
    public function validatedMembers(KnmiForecastSnapshot $snapshot): ?array
    {
        $manifest = $snapshot->manifest;
        if (! is_array($manifest)
            || ! $this->hasExactKeys($manifest, [
                'version',
                'dataset',
                'dataset_version',
                'source_filename',
                'source_size_bytes',
                'source_sha256',
                'model_run_at',
                'forecast_start_at',
                'forecast_end_at',
                'members',
            ])
            || ($manifest['version'] ?? null) !== 1
            || ($manifest['dataset'] ?? null) !== 'harmonie_arome_cy43_p1'
            || ($manifest['dataset_version'] ?? null) !== '1.0'
            || $snapshot->dataset !== $manifest['dataset']
            || $snapshot->dataset_version !== $manifest['dataset_version']
            || $snapshot->source_filename !== ($manifest['source_filename'] ?? null)
            || (int) $snapshot->source_size_bytes !== ($manifest['source_size_bytes'] ?? null)
            || $snapshot->source_sha256 !== ($manifest['source_sha256'] ?? null)
            || preg_match('/\AHARM43_V1_P1_(\d{10})\.tar\z/D', (string) $snapshot->source_filename, $archiveMatch) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', (string) $snapshot->source_sha256) !== 1
            || (int) $snapshot->member_count !== 61) {
            return null;
        }
        try {
            $modelRun = Carbon::parse((string) $manifest['model_run_at'])->utc();
            $forecastStart = Carbon::parse((string) $manifest['forecast_start_at'])->utc();
            $forecastEnd = Carbon::parse((string) $manifest['forecast_end_at'])->utc();
            $storedRun = Carbon::instance($snapshot->model_run_at)->utc();
            $storedStart = Carbon::instance($snapshot->forecast_start_at)->utc();
            $storedEnd = Carbon::instance($snapshot->forecast_end_at)->utc();
        } catch (\Throwable) {
            return null;
        }
        if ($modelRun->format('YmdH') !== $archiveMatch[1]
            || $modelRun->format('is.u') !== '0000.000000'
            || ! $forecastStart->equalTo($modelRun)
            || ! $forecastEnd->equalTo($modelRun->copy()->addHours(60))
            || ! $storedRun->equalTo($modelRun)
            || ! $storedStart->equalTo($forecastStart)
            || ! $storedEnd->equalTo($forecastEnd)) {
            return null;
        }
        $members = is_array($manifest) ? ($manifest['members'] ?? null) : null;
        if (! is_array($members) || ! array_is_list($members) || count($members) !== 61) {
            return null;
        }
        $result = [];
        foreach ($members as $lead => $member) {
            if (! is_array($member)
                || ! $this->hasExactKeys($member, ['filename', 'lead_hours', 'valid_at', 'size_bytes', 'sha256'])
                || ! is_string($member['filename'] ?? null)
                || ($member['lead_hours'] ?? null) !== $lead
                || ! is_string($member['valid_at'] ?? null)
                || ! is_int($member['size_bytes'] ?? null)
                || $member['size_bytes'] < 12
                || $member['size_bytes'] > 33_554_432
                || ! is_string($member['sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $member['sha256']) !== 1) {
                return null;
            }
            try {
                $validAt = Carbon::parse($member['valid_at'])->utc();
            } catch (\Throwable) {
                return null;
            }
            $expectedFilename = sprintf('HA43_N20_%s_%03d00_GB', $modelRun->format('YmdHi'), $lead);
            if (! hash_equals($expectedFilename, $member['filename'])
                || ! $validAt->equalTo($modelRun->copy()->addHours($lead))) {
                return null;
            }
            $result[] = $member;
        }

        return $result;
    }

    /** @param list<string> $expected */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $keys = array_keys($value);
        sort($keys);
        sort($expected);

        return $keys === $expected;
    }

    private function realStorageRoot(): string
    {
        $root = $this->configuration->storageRoot();
        if (! is_dir($root) && ! @mkdir($root, 0770, true) && ! is_dir($root)) {
            throw new RuntimeException('KNMI-opslag kon niet worden voorbereid.');
        }
        $real = realpath($root);
        if ($real === false || is_link($root)) {
            throw new RuntimeException('KNMI-opslag is niet veilig beschikbaar.');
        }

        return $real;
    }

    private function isInside(string $path, string $parent): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        $parent = rtrim(str_replace('\\', '/', $parent), '/').'/';

        return DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($path), strtolower($parent))
            : str_starts_with($path, $parent);
    }
}
