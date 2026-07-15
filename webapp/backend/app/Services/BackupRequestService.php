<?php

namespace App\Services;

use Closure;
use JsonException;
use RuntimeException;
use Throwable;

final class BackupRequestService
{
    public const CREATE_TIMEOUT_SECONDS = 1020;

    public const VERIFY_TIMEOUT_SECONDS = 720;

    public const PROBE_TIMEOUT_SECONDS = 30;

    private const POLL_INTERVAL_MICROSECONDS = 500_000;

    private const MAX_RESULT_BYTES = 8_388_608;

    public function __construct(
        private readonly ?string $requestRootOverride = null,
        private readonly ?Closure $requestIdGenerator = null,
        private readonly ?Closure $monotonicClock = null,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * @return array{exit_code: int, output: string, request_id: string}
     */
    public function create(
        string $target,
        ?string $actorId,
        int $timeoutSeconds = self::CREATE_TIMEOUT_SECONDS,
    ): array {
        return $this->run(
            BackupRequestOperation::Create,
            $target,
            null,
            $actorId,
            $timeoutSeconds,
        );
    }

    /**
     * @return array{exit_code: int, output: string, request_id: string}
     */
    public function verify(
        string $target,
        string $backupPath,
        ?string $actorId,
        int $timeoutSeconds = self::VERIFY_TIMEOUT_SECONDS,
    ): array {
        return $this->run(
            BackupRequestOperation::Verify,
            $target,
            $backupPath,
            $actorId,
            $timeoutSeconds,
        );
    }

    /**
     * @return array{exit_code: int, output: string, request_id: string}
     */
    public function probe(int $timeoutSeconds = self::PROBE_TIMEOUT_SECONDS): array
    {
        return $this->run(
            BackupRequestOperation::Probe,
            'local',
            null,
            null,
            $timeoutSeconds,
        );
    }

    /**
     * @return array{exit_code: int, output: string, request_id: string}
     */
    private function run(
        BackupRequestOperation $operation,
        string $target,
        ?string $backupPath,
        ?string $actorId,
        int $timeoutSeconds,
    ): array {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Backup request timeout must be at least one second.');
        }
        if (! in_array($target, ['local', 'samba'], true)) {
            throw new \InvalidArgumentException('Unsupported backup target.');
        }

        $requestId = $this->newRequestId();
        $root = $this->requestRoot();
        if (! is_dir($root) || ! is_writable($root)) {
            return $this->result(
                $requestId,
                1,
                'De beveiligde backup request map is niet beschikbaar.',
            );
        }

        $temporary = $root.'/'.$requestId.'.tmp';
        $pending = $root.'/'.$requestId.'.pending';
        $result = $root.'/'.$requestId.'.result';
        $payload = json_encode([
            'operation' => $operation->value,
            'target' => $target,
            'backup_path' => $backupPath,
            'actor_id' => $actorId,
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
        ], JSON_THROW_ON_ERROR)."\n";

        try {
            $this->publish($temporary, $pending, $payload);
        } catch (Throwable $exception) {
            if (is_file($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
            report($exception);

            return $this->result(
                $requestId,
                1,
                'De backup request kon niet veilig en duurzaam worden gepubliceerd.',
            );
        }

        $deadline = $this->now() + $timeoutSeconds;
        $claimed = false;

        while (true) {
            clearstatcache(true, $result);
            if (is_file($result)) {
                return $this->readResult($requestId, $result);
            }

            clearstatcache(true, $pending);
            if (! is_file($pending)) {
                $claimed = true;
            }

            if ($this->now() >= $deadline) {
                break;
            }

            $this->sleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        // Close the race between the last deadline check and an atomic result publication.
        clearstatcache(true, $result);
        if (is_file($result)) {
            return $this->readResult($requestId, $result);
        }

        clearstatcache(true, $pending);
        if (! $claimed && is_file($pending) && @unlink($pending)) {
            return $this->result(
                $requestId,
                124,
                'De backup request is niet binnen '.$this->timeoutLabel($timeoutSeconds).' door de DIS backup request service geclaimd. Controleer dis-backup-request.path en dis-backup-request.service.',
            );
        }

        // If cancellation lost the race with the worker, classify this as a
        // claimed request and check once more for an immediately published result.
        clearstatcache(true, $result);
        if (is_file($result)) {
            return $this->readResult($requestId, $result);
        }

        return $this->result(
            $requestId,
            124,
            'De backup request is door de DIS backup request service geclaimd, maar niet binnen '.$this->timeoutLabel($timeoutSeconds).' afgerond. Controleer dis-backup-request.service en de worker-logs.',
        );
    }

    private function publish(
        string $temporary,
        string $pending,
        string $payload,
    ): void {
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Exclusive backup request staging file creation failed.');
        }

        $completed = false;
        try {
            $offset = 0;
            $length = strlen($payload);
            while ($offset < $length) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Backup request staging file could not be written completely.');
                }
                $offset += $written;
            }

            if (function_exists('fchmod') && ! fchmod($handle, 0660)) {
                throw new RuntimeException('Backup request staging file permissions could not be restricted.');
            }
            if (! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Backup request staging file could not be durably stored.');
            }
            $completed = true;
        } finally {
            fclose($handle);
            if (! $completed) {
                @unlink($temporary);
            }
        }

        if (is_file($pending) || is_link($pending)) {
            @unlink($temporary);
            throw new RuntimeException('Backup request destination already exists.');
        }
        if (! @rename($temporary, $pending)) {
            @unlink($temporary);
            throw new RuntimeException('Backup request staging file could not be atomically published.');
        }
    }

    /**
     * @return array{exit_code: int, output: string, request_id: string}
     */
    private function readResult(string $requestId, string $resultPath): array
    {
        try {
            $size = @filesize($resultPath);
            if (! is_int($size) || $size < 1 || $size > self::MAX_RESULT_BYTES) {
                throw new RuntimeException('Backup request result has an invalid size.');
            }

            $contents = @file_get_contents($resultPath);
            if (! is_string($contents) || strlen($contents) !== $size) {
                throw new RuntimeException('Backup request result could not be read completely.');
            }

            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($decoded)
                || ! is_int($decoded['exit_code'] ?? null)
                || ! is_string($decoded['output'] ?? null)) {
                throw new RuntimeException('Backup request result has an invalid structure.');
            }

            if (! @unlink($resultPath)) {
                report(new RuntimeException("Processed backup request result could not be removed: {$requestId}"));
            }

            return $this->result($requestId, $decoded['exit_code'], $decoded['output']);
        } catch (JsonException|RuntimeException $exception) {
            report($exception);

            return $this->result(
                $requestId,
                1,
                'De backup request service gaf geen geldig en volledig resultaat terug.',
            );
        }
    }

    /**
     * @return array{exit_code: int, output: string, request_id: string}
     */
    private function result(string $requestId, int $exitCode, string $output): array
    {
        $output = trim($output);

        return [
            'exit_code' => $exitCode,
            'output' => 'Backup request-id: '.$requestId.($output === '' ? '' : "\n".$output),
            'request_id' => $requestId,
        ];
    }

    private function requestRoot(): string
    {
        if ($this->requestRootOverride !== null) {
            return rtrim($this->requestRootOverride, '/\\');
        }

        $dataPath = rtrim((string) env('DIS_DATA_PATH', '/opt/dis-data'), '/');

        return $dataPath.'/backup-requests';
    }

    private function newRequestId(): string
    {
        $requestId = $this->requestIdGenerator === null
            ? bin2hex(random_bytes(16))
            : ($this->requestIdGenerator)();

        if (! is_string($requestId) || preg_match('/^[a-f0-9]{32}$/', $requestId) !== 1) {
            throw new RuntimeException('Backup request id generator returned an invalid id.');
        }

        return $requestId;
    }

    private function now(): float
    {
        if ($this->monotonicClock !== null) {
            $value = ($this->monotonicClock)();
            if (! is_float($value) && ! is_int($value)) {
                throw new RuntimeException('Backup request clock returned an invalid value.');
            }

            return (float) $value;
        }

        return hrtime(true) / 1_000_000_000;
    }

    private function sleep(int $microseconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($microseconds);

            return;
        }

        usleep($microseconds);
    }

    private function timeoutLabel(int $timeoutSeconds): string
    {
        return $timeoutSeconds === 1 ? '1 seconde' : $timeoutSeconds.' seconden';
    }
}
