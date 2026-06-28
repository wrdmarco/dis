<?php

namespace App\Jobs;

use App\Services\SystemUpdateStatusService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class RunSystemUpdate implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function handle(SystemUpdateStatusService $status): void
    {
        $root = base_path('../..');
        $script = $root.'/update.sh';
        if (! is_file($script)) {
            $status->append('Update script niet gevonden: '.$script);
            $status->finish(1);

            return;
        }

        $bash = is_file('/usr/bin/bash') ? '/usr/bin/bash' : '/bin/bash';
        $command = ['sudo', '-n', $bash, $script, '--skip-system'];
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
        while (true) {
            foreach ([1, 2] as $index) {
                while (($line = fgets($pipes[$index])) !== false) {
                    $line = trim($line);
                    if ($line !== '') {
                        $status->append($line);
                    }
                }
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
