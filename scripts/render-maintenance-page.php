<?php

declare(strict_types=1);

namespace Dis\MaintenancePage;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use RuntimeException;
use Throwable;

const MAX_TEMPLATE_BYTES = 262144;
const MAX_NOTICE_BYTES = 1024;
const MAX_NOTICE_LIFETIME_SECONDS = 21600;
const MAX_FUTURE_CLOCK_SKEW_SECONDS = 60;
const MIN_ESTIMATED_DURATION_SECONDS = 180;
const MAX_ESTIMATED_DURATION_SECONDS = 2700;
const DEFAULT_ATTRIBUTES = 'data-maintenance-kind="maintenance" data-started-epoch-seconds="0" data-estimated-duration-seconds="0" data-estimated-completion-epoch-seconds="0"';

/**
 * Render the dependency-free maintenance document used while the application is unavailable.
 */
function renderMaintenancePage(string $templatePath, ?string $noticePath = null, ?int $nowEpoch = null): string
{
    $template = readBoundedRegularFile($templatePath, MAX_TEMPLATE_BYTES);
    if ($template === null) {
        throw new RuntimeException('The maintenance page template is missing, unsafe or too large.');
    }

    $notice = $noticePath === null
        ? null
        : validatedMaintenanceNotice($noticePath, $nowEpoch ?? time());
    $attributes = DEFAULT_ATTRIBUTES;
    if ($notice !== null) {
        $attributes = sprintf(
            'data-maintenance-kind="%s" data-started-epoch-seconds="%d" data-estimated-duration-seconds="%d" data-estimated-completion-epoch-seconds="%d"',
            $notice['kind'],
            $notice['started_epoch_seconds'],
            $notice['estimated_duration_seconds'],
            $notice['estimated_completion_epoch_seconds'],
        );
    }

    $rendered = str_replace(DEFAULT_ATTRIBUTES, $attributes, $template, $replacementCount);
    if ($replacementCount !== 1) {
        throw new RuntimeException('The maintenance page template does not contain exactly one metadata placeholder.');
    }

    return $rendered;
}

/**
 * @return array{kind: 'update'|'maintenance', started_epoch_seconds: int, estimated_duration_seconds: int, estimated_completion_epoch_seconds: int}|null
 */
function validatedMaintenanceNotice(string $path, int $nowEpoch): ?array
{
    $contents = readBoundedRegularFile($path, MAX_NOTICE_BYTES);
    if ($contents === null) {
        return null;
    }

    try {
        $payload = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    if (! is_array($payload)) {
        return null;
    }

    $version = $payload['version'] ?? null;
    $keys = array_keys($payload);
    sort($keys);
    $validV1Keys = ['active', 'expires_at', 'kind', 'started_at', 'version'];
    $validV2Keys = ['active', 'estimated_completion_at', 'estimated_duration_seconds', 'expires_at', 'kind', 'started_at', 'version'];
    if (! in_array($version, [1, 2], true)
        || ($version === 1 && $keys !== $validV1Keys)
        || ($version === 2 && $keys !== $validV2Keys)
        || ($payload['active'] ?? null) !== true
        || ! in_array($payload['kind'] ?? null, ['update', 'maintenance'], true)
        || ! is_string($payload['started_at'] ?? null)
        || ! is_string($payload['expires_at'] ?? null)) {
        return null;
    }

    if ($version === 2
        && (($payload['kind'] ?? null) !== 'update'
            || ! is_int($payload['estimated_duration_seconds'] ?? null)
            || ! is_string($payload['estimated_completion_at'] ?? null))) {
        return null;
    }

    $startedEpoch = strictUtcTimestamp($payload['started_at']);
    $expiresEpoch = strictUtcTimestamp($payload['expires_at']);
    if ($startedEpoch === null || $expiresEpoch === null) {
        return null;
    }

    $lifetime = $expiresEpoch - $startedEpoch;
    if ($lifetime < 1
        || $lifetime > MAX_NOTICE_LIFETIME_SECONDS
        || $startedEpoch > $nowEpoch + MAX_FUTURE_CLOCK_SKEW_SECONDS
        || $expiresEpoch <= $nowEpoch) {
        return null;
    }

    $duration = 0;
    $completionEpoch = 0;
    if ($version === 2) {
        $duration = $payload['estimated_duration_seconds'];
        $completionEpoch = strictUtcTimestamp($payload['estimated_completion_at']) ?? 0;
        if ($duration < MIN_ESTIMATED_DURATION_SECONDS
            || $duration > MAX_ESTIMATED_DURATION_SECONDS
            || $completionEpoch - $startedEpoch !== $duration
            || $completionEpoch > $expiresEpoch) {
            return null;
        }
    }

    return [
        'kind' => $payload['kind'],
        'started_epoch_seconds' => $startedEpoch,
        'estimated_duration_seconds' => $duration,
        'estimated_completion_epoch_seconds' => $completionEpoch,
    ];
}

function strictUtcTimestamp(string $value): ?int
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) !== 1) {
        return null;
    }

    try {
        $timestamp = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC'),
        );
    } catch (Throwable) {
        return null;
    }

    return $timestamp instanceof DateTimeImmutable && $timestamp->format('Y-m-d\TH:i:s\Z') === $value
        ? $timestamp->getTimestamp()
        : null;
}

function readBoundedRegularFile(string $path, int $maxBytes): ?string
{
    clearstatcache(true, $path);
    if (! is_file($path) || is_link($path)) {
        return null;
    }

    $before = @lstat($path);
    if (! is_array($before)) {
        return null;
    }

    $size = $before['size'] ?? null;
    $links = $before['nlink'] ?? null;
    if (! is_int($size) || $size < 1 || $size > $maxBytes || $links !== 1) {
        return null;
    }

    $handle = @fopen($path, 'rb');
    if ($handle === false) {
        return null;
    }

    try {
        $opened = fstat($handle);
        if (! is_array($opened)
            || ($before['dev'] ?? null) !== ($opened['dev'] ?? null)
            || ($before['ino'] ?? null) !== ($opened['ino'] ?? null)
            || ($opened['nlink'] ?? null) !== 1) {
            return null;
        }

        $contents = stream_get_contents($handle, $maxBytes + 1);

        return is_string($contents) && strlen($contents) <= $maxBytes
            ? $contents
            : null;
    } finally {
        fclose($handle);
    }
}

function writeAll($stream, string $contents): void
{
    $length = strlen($contents);
    $offset = 0;
    while ($offset < $length) {
        $written = @fwrite($stream, substr($contents, $offset));
        if (! is_int($written) || $written < 1) {
            throw new RuntimeException('The rendered maintenance page could not be written completely.');
        }
        $offset += $written;
    }
}

function runCli(array $arguments): int
{
    if (count($arguments) !== 3) {
        fwrite(STDERR, "Usage: php render-maintenance-page.php <template> <notice>\n");

        return 64;
    }

    try {
        writeAll(STDOUT, renderMaintenancePage($arguments[1], $arguments[2]));

        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage()."\n");

        return 1;
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(runCli($argv));
}
