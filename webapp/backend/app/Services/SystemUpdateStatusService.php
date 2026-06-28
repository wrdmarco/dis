<?php

namespace App\Services;

use App\Events\SystemUpdateStatusChanged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SystemUpdateStatusService
{
    private const CACHE_KEY = 'system.update.status';
    private const LOG_LIMIT = 120;
    private const STALE_AFTER_MINUTES = 5;

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
            'reboot_required' => $this->rebootRequired(),
        ]);

        if (is_array($status) && $this->isStaleRunningStatus($status)) {
            $log = is_array($status['log'] ?? null) ? $status['log'] : [];
            $log[] = 'Updateproces reageert niet meer en is automatisch vrijgegeven.';
            $status['state'] = 'failed';
            $status['finished_at'] = now()->toIso8601String();
            $status['exit_code'] = 124;
            $status['message'] = 'Updateproces reageert niet meer.';
            $status['log'] = array_slice($log, -self::LOG_LIMIT);
            $status['reboot_required'] = $this->rebootRequired();
            $this->store($status);
        }

        return is_array($status) ? $status : [
            'state' => 'idle',
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'message' => 'Geen update actief.',
            'log' => [],
            'reboot_required' => $this->rebootRequired(),
        ];
    }

    public function start(string $message): void
    {
        $this->store([
            'state' => 'running',
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'exit_code' => null,
            'message' => $message,
            'log' => [$message],
            'reboot_required' => $this->rebootRequired(),
        ]);
    }

    public function append(string $line): void
    {
        $status = $this->current();
        $log = is_array($status['log'] ?? null) ? $status['log'] : [];
        $log[] = $line;
        $status['log'] = array_slice($log, -self::LOG_LIMIT);
        $status['message'] = $line;
        $this->store($status);
    }

    public function finish(int $exitCode): void
    {
        $status = $this->current();
        $status['state'] = $exitCode === 0 ? 'succeeded' : 'failed';
        $status['finished_at'] = now()->toIso8601String();
        $status['exit_code'] = $exitCode;
        $status['message'] = $exitCode === 0 ? 'Update afgerond.' : 'Update mislukt.';
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
}
