<?php

namespace App\Services;

use App\DTO\KnmiOpenDataArchive;
use App\Exceptions\KnmiForecastImportException;
use Illuminate\Support\Carbon;

final class KnmiForecastTarExtractor
{
    private const BLOCK_SIZE = 512;

    private const MAX_MEMBER_BYTES = 33_554_432;

    /**
     * @return array{version: int, dataset: string, dataset_version: string, source_filename: string, source_size_bytes: int, source_sha256: string, model_run_at: string, forecast_start_at: string, forecast_end_at: string, members: list<array{filename: string, lead_hours: int, valid_at: string, size_bytes: int, sha256: string}>}
     */
    public function extract(
        string $archivePath,
        string $destination,
        KnmiOpenDataArchive $archive,
        string $archiveSha256,
    ): array {
        if (! is_file($archivePath)
            || is_link($archivePath)
            || filesize($archivePath) !== $archive->sizeBytes
            || $archive->sizeBytes % self::BLOCK_SIZE !== 0) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI staging archive is unavailable or changed.');
        }
        if (! is_dir($destination) || is_link($destination)) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI extraction directory is unavailable.');
        }
        if (preg_match('/\AHARM43_V1_P1_(\d{10})\.tar\z/D', $archive->filename, $archiveMatch) !== 1) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI archive filename is invalid.');
        }
        $run = Carbon::createFromFormat('!YmdH', $archiveMatch[1], 'UTC');
        if ($run === false || $run->format('YmdH') !== $archiveMatch[1]) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI archive run timestamp is invalid.');
        }

        $handle = @fopen($archivePath, 'rb');
        if ($handle === false) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI archive could not be opened.');
        }
        $members = [];
        $pendingPax = null;
        $globalPax = [];
        try {
            while (true) {
                $header = $this->readExact($handle, self::BLOCK_SIZE, allowEof: true);
                if ($header === null) {
                    throw new KnmiForecastImportException('archive_invalid', 'KNMI archive has no TAR end marker.');
                }
                if ($header === str_repeat("\0", self::BLOCK_SIZE)) {
                    $second = $this->readExact($handle, self::BLOCK_SIZE);
                    if ($second !== str_repeat("\0", self::BLOCK_SIZE)) {
                        throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR end marker is incomplete.');
                    }
                    $this->assertRemainingZeroPadding($handle);
                    break;
                }
                $parsed = $this->parseHeader($header);
                if ($parsed['type'] === 'x' || $parsed['type'] === 'g') {
                    if ($parsed['size'] < 1 || $parsed['size'] > 4096) {
                        throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX metadata size is invalid.');
                    }
                    if ($parsed['type'] === 'x' && ($pendingPax !== null || $parsed['name'] !== '././@PaxHeader')) {
                        throw new KnmiForecastImportException('archive_invalid', 'KNMI local PAX header ordering is invalid.');
                    }
                    $payload = $this->readExact($handle, $parsed['size']);
                    $this->skipPadding($handle, $parsed['size']);
                    $pax = $this->parsePax($payload, $parsed['type'] === 'x');
                    if ($parsed['type'] === 'x') {
                        $pendingPax = $pax;
                    } else {
                        if (array_key_exists('path', $pax)) {
                            throw new KnmiForecastImportException('archive_invalid', 'KNMI global PAX path is not allowed.');
                        }
                        $globalPax = [...$globalPax, ...$pax];
                    }

                    continue;
                }
                if ($parsed['type'] !== '0' || $pendingPax === null) {
                    throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR contains an unexpected entry type or missing local PAX metadata.');
                }
                $effectivePax = [...$globalPax, ...$pendingPax];
                $headerName = $parsed['name'];
                $name = $effectivePax['path'] ?? $headerName;
                $pendingPax = null;
                if ($headerName !== basename($headerName)
                    || preg_match('/\AHA43_N20_\d{12}_\d{5}_GB\z/D', $headerName) !== 1
                    || ! is_string($name)
                    || $name !== basename($name)
                    || preg_match('/\AHA43_N20_(\d{12})_(\d{3})00_GB\z/D', $name, $memberMatch) !== 1
                    || $name !== $headerName
                    || $memberMatch[1] !== $archiveMatch[1].'00') {
                    throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR member name is invalid.');
                }
                $lead = (int) $memberMatch[2];
                if ($lead < 0 || $lead > 60 || isset($members[$lead])) {
                    throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR contains an invalid or duplicate forecast lead.');
                }
                if ($parsed['size'] < 12 || $parsed['size'] > self::MAX_MEMBER_BYTES) {
                    throw new KnmiForecastImportException('archive_invalid', 'KNMI GRIB member size is invalid.');
                }
                $path = $destination.DIRECTORY_SEPARATOR.$name;
                $memberSha256 = $this->extractMember($handle, $path, $parsed['size']);
                $this->skipPadding($handle, $parsed['size']);
                $this->assertGrib1File($path, $parsed['size']);
                $members[$lead] = [
                    'filename' => $name,
                    'lead_hours' => $lead,
                    'valid_at' => $run->copy()->addHours($lead)->toIso8601ZuluString(),
                    'size_bytes' => $parsed['size'],
                    'sha256' => $memberSha256,
                ];
            }
        } finally {
            fclose($handle);
        }
        if ($pendingPax !== null || count($members) !== 61) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR does not contain exactly 61 complete forecast members.');
        }
        ksort($members, SORT_NUMERIC);
        if (array_keys($members) !== range(0, 60)) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR forecast leads are incomplete.');
        }

        return [
            'version' => 1,
            'dataset' => 'harmonie_arome_cy43_p1',
            'dataset_version' => '1.0',
            'source_filename' => $archive->filename,
            'source_size_bytes' => $archive->sizeBytes,
            'source_sha256' => $archiveSha256,
            'model_run_at' => $run->toIso8601ZuluString(),
            'forecast_start_at' => $run->toIso8601ZuluString(),
            'forecast_end_at' => $run->copy()->addHours(60)->toIso8601ZuluString(),
            'members' => array_values($members),
        ];
    }

    /** @return array{name: string, type: string, size: int} */
    private function parseHeader(string $header): array
    {
        if (substr($header, 257, 6) !== "ustar\0" || substr($header, 263, 2) !== '00') {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR is not an uncompressed POSIX ustar archive.');
        }
        $storedChecksum = $this->parseOctal(substr($header, 148, 8), 'checksum');
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);
        $actualChecksum = array_sum(unpack('C*', $checksumHeader));
        if ($storedChecksum !== $actualChecksum) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR header checksum is invalid.');
        }
        $name = $this->parseTextField(substr($header, 0, 100));
        $prefix = $this->parseTextField(substr($header, 345, 155), allowEmpty: true);
        if ($prefix !== '') {
            $name = $prefix.'/'.$name;
        }
        $linkName = $this->parseTextField(substr($header, 157, 100), allowEmpty: true);
        if ($linkName !== '') {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR links are not allowed.');
        }
        $type = $header[156] === "\0" ? '0' : $header[156];
        if (! in_array($type, ['0', 'x', 'g'], true)) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR entry type is not allowed.');
        }

        return [
            'name' => $name,
            'type' => $type,
            'size' => $this->parseOctal(substr($header, 124, 12), 'size'),
        ];
    }

    /** @return array<string, string> */
    private function parsePax(string $payload, bool $local): array
    {
        $result = [];
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $space = strpos($payload, ' ', $offset);
            if ($space === false || $space === $offset) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX record length is invalid.');
            }
            $lengthText = substr($payload, $offset, $space - $offset);
            if (preg_match('/\A[1-9]\d{0,5}\z/D', $lengthText) !== 1) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX record length is invalid.');
            }
            $recordLength = (int) $lengthText;
            $record = substr($payload, $offset, $recordLength);
            if (strlen($record) !== $recordLength || ! str_ends_with($record, "\n")) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX record is truncated.');
            }
            $body = substr($record, strlen($lengthText) + 1, -1);
            $equals = strpos($body, '=');
            if ($equals === false || $equals === 0) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX record is malformed.');
            }
            $key = substr($body, 0, $equals);
            $value = substr($body, $equals + 1);
            if (isset($result[$key]) || ! in_array($key, $local ? ['mtime', 'path'] : ['mtime'], true)) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX contains unsupported or duplicate metadata.');
            }
            if ($key === 'mtime' && preg_match('/\A\d{1,12}(?:\.\d{1,12})?\z/D', $value) !== 1) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX mtime is invalid.');
            }
            if ($key === 'path' && ($value !== basename($value) || preg_match('/\AHA43_N20_\d{12}_\d{5}_GB\z/D', $value) !== 1)) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX path is unsafe.');
            }
            $result[$key] = $value;
            $offset += $recordLength;
        }
        if (! isset($result['mtime'])) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI PAX mtime is missing.');
        }

        return $result;
    }

    /** @param resource $archive */
    private function extractMember($archive, string $destination, int $size): string
    {
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            throw new KnmiForecastImportException('storage_unavailable', 'KNMI GRIB staging file could not be created.');
        }
        $hash = hash_init('sha256');
        $remaining = $size;
        try {
            while ($remaining > 0) {
                $chunk = $this->readExact($archive, min(1_048_576, $remaining));
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new KnmiForecastImportException('storage_unavailable', 'KNMI GRIB staging file could not be written completely.');
                }
                hash_update($hash, $chunk);
                $remaining -= strlen($chunk);
            }
            if (! fflush($output)) {
                throw new KnmiForecastImportException('storage_unavailable', 'KNMI GRIB staging file could not be flushed.');
            }
        } finally {
            fclose($output);
        }

        return hash_final($hash);
    }

    private function assertGrib1File(string $path, int $expectedSize): void
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new KnmiForecastImportException('grib_invalid', 'Extracted KNMI GRIB file could not be opened.');
        }
        $offset = 0;
        $messages = 0;
        try {
            while ($offset < $expectedSize) {
                if (fseek($handle, $offset) !== 0) {
                    throw new KnmiForecastImportException('grib_invalid', 'KNMI GRIB message offset is invalid.');
                }
                $indicator = $this->readExact($handle, 8);
                if (substr($indicator, 0, 4) !== 'GRIB' || ord($indicator[7]) !== 1) {
                    throw new KnmiForecastImportException('grib_invalid', 'KNMI member is not a GRIB edition 1 message stream.');
                }
                $messageLength = (ord($indicator[4]) << 16) | (ord($indicator[5]) << 8) | ord($indicator[6]);
                if ($messageLength < 12 || $offset + $messageLength > $expectedSize) {
                    throw new KnmiForecastImportException('grib_invalid', 'KNMI GRIB message length is invalid.');
                }
                if (fseek($handle, $offset + $messageLength - 4) !== 0 || $this->readExact($handle, 4) !== '7777') {
                    throw new KnmiForecastImportException('grib_invalid', 'KNMI GRIB message end marker is invalid.');
                }
                $offset += $messageLength;
                $messages++;
            }
        } finally {
            fclose($handle);
        }
        if ($offset !== $expectedSize || $messages < 1) {
            throw new KnmiForecastImportException('grib_invalid', 'KNMI GRIB stream is incomplete.');
        }
    }

    /** @param resource $handle */
    private function skipPadding($handle, int $size): void
    {
        $padding = (self::BLOCK_SIZE - ($size % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
        if ($padding > 0 && $this->readExact($handle, $padding) !== str_repeat("\0", $padding)) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR entry padding is invalid.');
        }
    }

    /** @param resource $handle */
    private function assertRemainingZeroPadding($handle): void
    {
        while (! feof($handle)) {
            $chunk = fread($handle, 1_048_576);
            if ($chunk === false || ($chunk !== '' && trim($chunk, "\0") !== '')) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR contains data after its end marker.');
            }
        }
    }

    /** @param resource $handle */
    private function readExact($handle, int $length, bool $allowEof = false): ?string
    {
        $contents = '';
        while (strlen($contents) < $length && ! feof($handle)) {
            $chunk = fread($handle, $length - strlen($contents));
            if ($chunk === false) {
                throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR could not be read.');
            }
            $contents .= $chunk;
        }
        if ($contents === '' && $allowEof && feof($handle)) {
            return null;
        }
        if (strlen($contents) !== $length) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR is truncated.');
        }

        return $contents;
    }

    private function parseOctal(string $field, string $label): int
    {
        if (str_contains($field, "\x80")) {
            throw new KnmiForecastImportException('archive_invalid', "KNMI TAR $label uses unsupported binary encoding.");
        }
        $value = trim($field, " \0");
        if ($value === '' || preg_match('/\A[0-7]+\z/D', $value) !== 1) {
            throw new KnmiForecastImportException('archive_invalid', "KNMI TAR $label is not valid octal.");
        }

        return intval($value, 8);
    }

    private function parseTextField(string $field, bool $allowEmpty = false): string
    {
        $position = strpos($field, "\0");
        $value = $position === false ? $field : substr($field, 0, $position);
        if ((! $allowEmpty && $value === '') || ($value !== '' && preg_match('/\A[\x20-\x7E]+\z/D', $value) !== 1)) {
            throw new KnmiForecastImportException('archive_invalid', 'KNMI TAR text field is invalid.');
        }

        return $value;
    }
}
