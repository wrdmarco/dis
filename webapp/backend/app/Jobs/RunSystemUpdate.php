<?php

namespace App\Jobs;

use App\Services\SystemUpdateStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunSystemUpdate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;
    private const PROCESS_TIMEOUT_SECONDS = 2700;
    private const HEARTBEAT_SECONDS = 15;

    public function __construct(public readonly bool $updateSystem = false) {}

    public function handle(SystemUpdateStatusService $status): void
    {
        $root = realpath(base_path('../..')) ?: base_path('../..');
        $script = $root.'/update.sh';
        if (! is_file($script)) {
            $status->append('Update script niet gevonden: '.$script);
            $status->finish(1);

            return;
        }

        $updateCommand = is_file('/usr/local/bin/update') ? '/usr/local/bin/update' : (realpath($script) ?: $script);
        $command = ['sudo', '-n', $updateCommand];
        if (! $this->updateSystem) {
            $command[] = '--skip-system';
        }
        $status->append($this->updateSystem ? 'Updatecommando gestart met systeemupdates.' : 'Updatecommando gestart zonder systeemupdates.');
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $root);
        if (! is_resource($process)) {
            $status->append('Updateproces kon niet worden gestart.');
            $status->finish(1);

            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $exitCode = 1;
        $startedAt = time();
        $lastOutputAt = $startedAt;
        while (true) {
            foreach ([1, 2] as $index) {
                while (($line = fgets($pipes[$index])) !== false) {
                    $line = trim($line);
                    if ($line !== '') {
                        $lastOutputAt = time();
                        $status->append($line);
                    }
                }
            }

            if (time() - $startedAt > self::PROCESS_TIMEOUT_SECONDS) {
                $status->append('Updateproces duurde te lang en is afgebroken.');
                proc_terminate($process);
                $exitCode = 124;
                break;
            }

            if (time() - $lastOutputAt >= self::HEARTBEAT_SECONDS) {
                $lastOutputAt = time();
                $status->append('Update draait nog; wachten op uitvoer.');
            }

            $processStatus = proc_get_status($process);
            if (($processStatus['running'] ?? false) !== true) {
                $exitCode = is_int($processStatus['exitcode'] ?? null) ? (int) $processStatus['exitcode'] : 1;
                break;
            }

            usleep(250_000);
        }

        foreach ([1, 2] as $index) {
            while (($line = fgets($pipes[$index])) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    $status->append($line);
                }
            }
            fclose($pipes[$index]);
        }

        $closeCode = proc_close($process);
        if ($exitCode === -1 && $closeCode !== -1) {
            $exitCode = $closeCode;
        }
        $status->finish($exitCode);
    }
}
