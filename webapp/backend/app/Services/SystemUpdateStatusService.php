<?php

namespace App\Services;

use App\Events\SystemUpdateStatusChanged;
use App\Support\ApiDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

final class SystemUpdateStatusService
{
    private const CACHE_KEY = 'system.update.status';
    private const LOG_LIMIT = 120;
    private const STALE_AFTER_MINUTES = 60;
    private const NO_OUTPUT_STALE_MINUTES = 5;

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        $status = Cache::get(self::CACHE_KEY, [
            'state' => 'idle',
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'message' => 'Geen update actief.',
            'log' => [],
            'runner_log_offset' => $this->runnerLogSize(),
            'runner_pid' => null,
            'runner_unit' => null,
            'last_log_at' => null,
            'reboot_required' => $this->rebootRequired(),
        ]);

        if (is_array($status) && ($status['state'] ?? null) === 'running') {
            $status = $this->syncRunnerLog($status);
        }

        if (is_array($status) && ($status['state'] ?? null) === 'running' && $this->hasSuccessfulCompletionLine($status)) {
            $status = $this->successfulStatus($status);
            $this->store($status);
        }

        if (is_array($status) && $this->isDeadRunningProcess($status)) {
            $status = $this->releaseDeadProcess($status);
            $this->store($status);
        }

        if (is_array($status) && $this->isSilentRunningStatus($status)) {
            $status = $this->releaseSilentProcess($status);
            $this->store($status);
        }

        if (is_array($status) && $this->isStaleRunningStatus($status)) {
            $status = $this->releaseStaleProcess($status);
            $this->store($status);
        }

        return is_array($status) ? $status : [
            'state' => 'idle',
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'message' => 'Geen update actief.',
            'log' => [],
            'runner_log_offset' => $this->runnerLogSize(),
            'runner_pid' => null,
            'runner_unit' => null,
            'last_log_at' => null,
            'reboot_required' => $this->rebootRequired(),
        ];
    }

    public function start(string $message): void
    {
        $this->store([
            'state' => 'running',
            'started_at' => ApiDateTime::now(),
            'finished_at' => null,
            'exit_code' => null,
            'message' => $message,
            'log' => [$message],
            'runner_log_offset' => $this->runnerLogSize(),
            'runner_pid' => null,
            'runner_unit' => null,
            'last_log_at' => ApiDateTime::now(),
            'reboot_required' => $this->rebootRequired(),
        ]);
    }

    public function markProcessStarted(int $pid): void
    {
        $status = $this->current();
        $status['runner_pid'] = $pid;
        $status['last_log_at'] = ApiDateTime::now();
        $this->store($status);
    }

    public function markSystemdUnitStarted(string $unit): void
    {
        $status = $this->current();
        $status['runner_unit'] = $unit;
        $status['last_log_at'] = ApiDateTime::now();
        $this->store($status);
    }

    public function append(string $line): void
    {
        $status = $this->current();
        $log = is_array($status['log'] ?? null) ? $status['log'] : [];
        $log[] = $line;
        $status['log'] = array_slice($log, -self::LOG_LIMIT);
        $status['message'] = $line;
        $status['last_log_at'] = ApiDateTime::now();
        if (($status['state'] ?? null) === 'running' && $this->isSuccessfulCompletionLine($line)) {
            $status = $this->successfulStatus($status);
        }
        $this->store($status);
    }

    public function finish(int $exitCode): void
    {
        $status = $this->current();
        $status['state'] = $exitCode === 0 ? 'succeeded' : 'failed';
        $status['finished_at'] = ApiDateTime::now();
        $status['exit_code'] = $exitCode;
        $status['message'] = $exitCode === 0 ? 'Update afgerond.' : 'Update mislukt.';
        $status['runner_pid'] = null;
        $status['runner_unit'] = null;
        $status['last_log_at'] = ApiDateTime::now();
        $status['reboot_required'] = $this->rebootRequired();
        if ($exitCode === 0 && $status['reboot_required'] === true) {
            $status['message'] = 'Update afgerond. Serverherstart vereist.';
            $log = is_array($status['log'] ?? null) ? $status['log'] : [];
            $log[] = 'Serverherstart vereist.';
            $status['log'] = array_slice($log, -self::LOG_LIMIT);
        }
        $this->store($status);
    }

    /**
     * @param array<string, mixed> $status
     */
    private function store(array $status): void
    {
        Cache::put(self::CACHE_KEY, $status, now()->addDay());

        try {
            SystemUpdateStatusChanged::dispatch($status);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function rebootRequired(): bool
    {
        return is_file('/var/run/reboot-required') || is_file('/run/reboot-required');
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function syncRunnerLog(array $status): array
    {
        $path = storage_path('logs/system-update-runner.log');
        if (! is_file($path) || ! is_readable($path)) {
            return $status;
        }

        $size = filesize($path) ?: 0;
        $offset = is_int($status['runner_log_offset'] ?? null) ? (int) $status['runner_log_offset'] : 0;
        if ($offset < 0 || $offset > $size) {
            $offset = 0;
        }

        if ($offset === $size) {
            return $status;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return $status;
        }

        try {
            fseek($handle, $offset);
            $content = (string) stream_get_contents($handle);
        } finally {
            fclose($handle);
        }

        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $content) ?: []),
            fn (string $line): bool => $line !== '',
        ));
        $status['runner_log_offset'] = $size;
        if ($lines === []) {
            $this->store($status);

            return $status;
        }

        $log = is_array($status['log'] ?? null) ? $status['log'] : [];
        foreach ($lines as $line) {
            $log[] = $line;
        }
        $status['log'] = array_slice($log, -self::LOG_LIMIT);
        $status['message'] = end($lines) ?: ($status['message'] ?? 'Update draait.');
        $status['last_log_at'] = ApiDateTime::now();
        $this->store($status);

        return $status;
    }

    private function runnerLogSize(): int
    {
        $path = storage_path('logs/system-update-runner.log');

        return is_file($path) ? (filesize($path) ?: 0) : 0;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function isStaleRunningStatus(array $status): bool
    {
        if (($status['state'] ?? null) !== 'running' || ! is_string($status['started_at'] ?? null)) {
            return false;
        }

        try {
            return Carbon::parse($status['started_at'])->lt(now()->subMinutes(self::STALE_AFTER_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function isSilentRunningStatus(array $status): bool
    {
        if (($status['state'] ?? null) !== 'running') {
            return false;
        }

        if ($this->hasAliveRunner($status)) {
            return false;
        }

        $lastLogAt = is_string($status['last_log_at'] ?? null) ? $status['last_log_at'] : ($status['started_at'] ?? null);
        if (! is_string($lastLogAt)) {
            return false;
        }

        try {
            return Carbon::parse($lastLogAt)->lt(now()->subMinutes(self::NO_OUTPUT_STALE_MINUTES));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function isDeadRunningProcess(array $status): bool
    {
        if (($status['state'] ?? null) !== 'running') {
            return false;
        }

        $pid = $status['runner_pid'] ?? null;
        if (is_int($pid) && $pid > 0) {
            return ! $this->processIsAlive($pid);
        }

        $unit = $status['runner_unit'] ?? null;
        if (is_string($unit) && $unit !== '') {
            return ! $this->systemdUnitIsActive($unit);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $status
     */
    private function hasSuccessfulCompletionLine(array $status): bool
    {
        $log = is_array($status['log'] ?? null) ? $status['log'] : [];

        foreach ($log as $line) {
            if (is_string($line) && $this->isSuccessfulCompletionLine($line)) {
                return true;
            }
        }

        return false;
    }

    private function isSuccessfulCompletionLine(string $line): bool
    {
        return str_contains($line, 'DIS system and application update completed.')
            || str_contains($line, 'Deployment finished');
    }

    private function processIsAlive(int $pid): bool
    {
        if (is_dir('/proc/'.$pid)) {
            $state = @file_get_contents('/proc/'.$pid.'/stat');
            if (is_string($state) && preg_match('/\)\s+Z\s+/', $state) === 1) {
                return false;
            }

            return true;
        }

        return function_exists('posix_kill') && @posix_kill($pid, 0);
    }

    /**
     * @param array<string, mixed> $status
     */
    private function hasAliveRunner(array $status): bool
    {
        $pid = $status['runner_pid'] ?? null;
        if (is_int($pid) && $pid > 0 && $this->processIsAlive($pid)) {
            return true;
        }

        $unit = $status['runner_unit'] ?? null;

        return is_string($unit) && $unit !== '' && $this->systemdUnitIsActive($unit);
    }

    private function systemdUnitIsActive(string $unit): bool
    {
        $systemctl = is_file('/usr/bin/systemctl') ? '/usr/bin/systemctl' : (is_file('/bin/systemctl') ? '/bin/systemctl' : null);
        if ($systemctl === null) {
            return false;
        }

        return Process::run([$systemctl, 'is-active', '--quiet', $unit])->successful();
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function successfulStatus(array $status): array
    {
        $status['state'] = 'succeeded';
        $status['finished_at'] = ApiDateTime::now();
        $status['exit_code'] = 0;
        $status['message'] = 'Update afgerond.';
        $status['runner_pid'] = null;
        $status['runner_unit'] = null;
        $status['last_log_at'] = ApiDateTime::now();
        $status['reboot_required'] = $this->rebootRequired();

        return $status;
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function releaseDeadProcess(array $status): array
    {
        return $this->failedStatus($status, 'Updateproces is gestopt zonder afrondmelding en is automatisch vrijgegeven.', 1);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function releaseSilentProcess(array $status): array
    {
        return $this->failedStatus($status, 'Updateproces gaf te lang geen uitvoer en is automatisch vrijgegeven.', 124);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function releaseStaleProcess(array $status): array
    {
        return $this->failedStatus($status, 'Updateproces reageert niet meer en is automatisch vrijgegeven.', 124);
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function failedStatus(array $status, string $message, int $exitCode): array
    {
        $log = is_array($status['log'] ?? null) ? $status['log'] : [];
        $log[] = $message;
        $status['state'] = 'failed';
        $status['finished_at'] = ApiDateTime::now();
        $status['exit_code'] = $exitCode;
        $status['message'] = $message;
        $status['log'] = array_slice($log, -self::LOG_LIMIT);
        $status['runner_pid'] = null;
        $status['runner_unit'] = null;
        $status['last_log_at'] = ApiDateTime::now();
        $status['reboot_required'] = $this->rebootRequired();

        return $status;
    }
}
