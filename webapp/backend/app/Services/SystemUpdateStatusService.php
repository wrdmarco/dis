<?php

namespace App\Services;

use App\Events\SystemUpdateStatusChanged;
use App\Support\ApiDateTime;
use App\Support\SensitiveDataRedactor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Throwable;

final class SystemUpdateStatusService
{
    private const RUNNER_LOG_PATH = '/var/log/dis/system-update-runner.log';

    private const CACHE_KEY = 'system.update.status';

    private const LOG_LIMIT = 120;

    private const STALE_AFTER_MINUTES = 60;

    private const NO_OUTPUT_STALE_MINUTES = 5;

    private const PUBLIC_LOG_LINE_LIMIT = 1000;

    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
        private readonly SystemUpdateDurationEstimator $durationEstimator,
    ) {}

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
            'includes_system_updates' => false,
            'estimated_duration_seconds' => null,
            'estimated_completion_at' => null,
            'estimate_source' => null,
            'duration_recorded' => false,
        ]);

        if (is_array($status)) {
            $status = $this->sanitizeStatus($status);
        }

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
            'includes_system_updates' => false,
            'estimated_duration_seconds' => null,
            'estimated_completion_at' => null,
            'estimate_source' => null,
            'duration_recorded' => false,
        ];
    }

    /**
     * Return only the updater state that is safe and required by browser clients.
     *
     * @return array<string, mixed>
     */
    public function publicStatus(): array
    {
        return $this->publicPayload($this->current());
    }

    public function start(string $message, bool $includesSystemUpdates = false): void
    {
        $startedAt = now();
        $estimate = $this->durationEstimator->estimate($includesSystemUpdates);

        $this->store([
            'state' => 'running',
            'started_at' => ApiDateTime::dateTime($startedAt),
            'finished_at' => null,
            'exit_code' => null,
            'message' => $message,
            'log' => [$message],
            'runner_log_offset' => $this->runnerLogSize(),
            'runner_pid' => null,
            'runner_unit' => null,
            'last_log_at' => ApiDateTime::dateTime($startedAt),
            'reboot_required' => $this->rebootRequired(),
            'includes_system_updates' => $includesSystemUpdates,
            'estimated_duration_seconds' => $estimate['duration_seconds'],
            'estimated_completion_at' => ApiDateTime::dateTime($startedAt->copy()->addSeconds($estimate['duration_seconds'])),
            'estimate_source' => $estimate['source'],
            'duration_recorded' => false,
        ]);
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
        $line = $this->sanitizeLine($line);
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
        if ($exitCode === 0) {
            $status = $this->recordSuccessfulDuration($status);
        }
        if ($exitCode === 0 && $status['reboot_required'] === true) {
            $status['message'] = 'Update afgerond. Serverherstart vereist.';
            $log = is_array($status['log'] ?? null) ? $status['log'] : [];
            $log[] = 'Serverherstart vereist.';
            $status['log'] = array_slice($log, -self::LOG_LIMIT);
        }
        $this->store($status);
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function store(array $status): void
    {
        $status = $this->sanitizeStatus($status);
        Cache::put(self::CACHE_KEY, $status, now()->addDay());

        try {
            SystemUpdateStatusChanged::dispatch($this->publicPayload($status));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function rebootRequired(): bool
    {
        return is_file('/var/run/reboot-required') || is_file('/run/reboot-required');
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function syncRunnerLog(array $status): array
    {
        $path = self::RUNNER_LOG_PATH;
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
            $log[] = $this->sanitizeLine($line);
        }
        $status['log'] = array_slice($log, -self::LOG_LIMIT);
        $status['message'] = $lines === [] ? ($status['message'] ?? 'Update draait.') : $this->sanitizeLine((string) end($lines));
        $status['last_log_at'] = ApiDateTime::now();
        $this->store($status);

        return $status;
    }

    private function runnerLogSize(): int
    {
        $path = self::RUNNER_LOG_PATH;

        return is_file($path) ? (filesize($path) ?: 0) : 0;
    }

    /**
     * @param  array<string, mixed>  $status
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
     * @param  array<string, mixed>  $status
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
     * @param  array<string, mixed>  $status
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
     * @param  array<string, mixed>  $status
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
     * @param  array<string, mixed>  $status
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
     * @param  array<string, mixed>  $status
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

        $status = $this->recordSuccessfulDuration($status);

        return $status;
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function releaseDeadProcess(array $status): array
    {
        return $this->failedStatus($status, 'Updateproces is gestopt zonder afrondmelding en is automatisch vrijgegeven.', 1);
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function releaseSilentProcess(array $status): array
    {
        return $this->failedStatus($status, 'Updateproces gaf te lang geen uitvoer en is automatisch vrijgegeven.', 124);
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function releaseStaleProcess(array $status): array
    {
        return $this->failedStatus($status, 'Updateproces reageert niet meer en is automatisch vrijgegeven.', 124);
    }

    /**
     * @param  array<string, mixed>  $status
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

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function sanitizeStatus(array $status): array
    {
        if (is_string($status['message'] ?? null)) {
            $status['message'] = $this->sanitizeLine($status['message']);
        }

        $log = is_array($status['log'] ?? null) ? $status['log'] : [];
        $status['log'] = array_values(array_map(
            fn (string $line): string => $this->sanitizeLine($line),
            array_filter($log, 'is_string'),
        ));

        return $status;
    }

    private function sanitizeLine(string $line): string
    {
        $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $line) ?? '';
        $line = trim($this->redactor->redactString($line));

        if (preg_match('/(?:SQLSTATE\[|stack trace:|^\s*#\d+\s|\.(?:php|m?js):\d+)/i', $line) === 1) {
            return 'Interne updatefout. Raadpleeg de beveiligde serverlogs.';
        }

        $line = preg_replace('/(?<![A-Za-z0-9])(?:[A-Za-z]:\\\\|\\\\\\\\)[^\s\'\"]+/', '[PATH]', $line) ?? '';
        $line = preg_replace('~(?<![:A-Za-z0-9])/(?:opt|home|var|etc|usr|srv|tmp|run|root)(?:/[^\s\'\"]*)?~i', '[PATH]', $line) ?? '';
        $line = preg_replace('~(?<![A-Za-z0-9])(?:storage|vendor)/(?:[^\s\'\"]+)~i', '[PATH]', $line) ?? '';
        $line = trim($line);

        return $line === ''
            ? 'Update-uitvoer afgeschermd.'
            : mb_substr($line, 0, self::PUBLIC_LOG_LINE_LIMIT);
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function publicPayload(array $status): array
    {
        $state = is_string($status['state'] ?? null) && in_array($status['state'], ['idle', 'running', 'succeeded', 'failed'], true)
            ? $status['state']
            : 'idle';

        $estimatedCompletionAt = is_string($status['estimated_completion_at'] ?? null)
            ? $status['estimated_completion_at']
            : null;

        return [
            'state' => $state,
            'started_at' => is_string($status['started_at'] ?? null) ? $status['started_at'] : null,
            'finished_at' => is_string($status['finished_at'] ?? null) ? $status['finished_at'] : null,
            'exit_code' => is_int($status['exit_code'] ?? null) ? $status['exit_code'] : null,
            'message' => is_string($status['message'] ?? null) ? $this->sanitizeLine($status['message']) : null,
            'log' => is_array($status['log'] ?? null) ? array_values(array_filter($status['log'], 'is_string')) : [],
            'reboot_required' => (bool) ($status['reboot_required'] ?? false),
            'includes_system_updates' => (bool) ($status['includes_system_updates'] ?? false),
            'estimated_duration_seconds' => is_int($status['estimated_duration_seconds'] ?? null)
                ? $status['estimated_duration_seconds']
                : null,
            'estimated_completion_at' => $estimatedCompletionAt,
            'remaining_seconds' => $state === 'running'
                ? $this->remainingSeconds($estimatedCompletionAt)
                : ($estimatedCompletionAt === null ? null : 0),
            'estimate_source' => in_array(($status['estimate_source'] ?? null), ['historical', 'fallback'], true)
                ? $status['estimate_source']
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function recordSuccessfulDuration(array $status): array
    {
        if (($status['duration_recorded'] ?? false) === true
            || ! is_string($status['started_at'] ?? null)
            || ! is_string($status['finished_at'] ?? null)) {
            return $status;
        }

        try {
            $startedAt = Carbon::parse($status['started_at']);
            $finishedAt = Carbon::parse($status['finished_at']);
            $durationSeconds = $startedAt->diffInSeconds($finishedAt, false);
            if ($durationSeconds > 0) {
                $this->durationEstimator->recordSuccessfulRun(
                    (bool) ($status['includes_system_updates'] ?? false),
                    $durationSeconds,
                );
            }
            $status['duration_recorded'] = true;
        } catch (Throwable) {
            // Invalid legacy status data is ignored and never blocks recovery.
        }

        return $status;
    }

    private function remainingSeconds(?string $estimatedCompletionAt): ?int
    {
        if ($estimatedCompletionAt === null) {
            return null;
        }

        try {
            return max(0, now()->diffInSeconds(Carbon::parse($estimatedCompletionAt), false));
        } catch (Throwable) {
            return null;
        }
    }
}
