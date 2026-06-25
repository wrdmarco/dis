<?php

namespace App\Services;

use App\Events\SystemUpdateStatusChanged;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SystemUpdateStatusService
{
    private const CACHE_KEY = 'system.update.status';
    private const LOG_LIMIT = 120;

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return Cache::get(self::CACHE_KEY, [
            'state' => 'idle',
            'started_at' => null,
            'finished_at' => null,
            'exit_code' => null,
            'message' => 'Geen update actief.',
            'log' => [],
        ]);
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
}
