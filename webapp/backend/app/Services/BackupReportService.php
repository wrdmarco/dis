<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class BackupReportService
{
    public function sendSuccess(string $target, string $output): int
    {
        return $this->send('success', $target, 'Automatische backup geslaagd', $output);
    }

    public function sendFailed(string $target, int $exitCode, string $output): int
    {
        return $this->send('failed', $target, 'Automatische backup mislukt', "Exit code: {$exitCode}\n\n".$output);
    }

    private function send(string $result, string $target, string $subject, string $output): int
    {
        $sent = 0;
        $body = implode("\n", [
            $subject,
            '',
            'Doel: '.$this->targetLabel($target),
            'Tijd: '.now()->format('d-m-Y H:i:s'),
            '',
            'Uitvoer:',
            $this->trimOutput($output),
        ]);

        User::query()
            ->where('account_status', 'active')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->wantsBackupReport($result))
            ->each(function (User $user) use ($subject, $body, &$sent): void {
                try {
                    Mail::raw($body, fn ($message) => $message->to($user->email)->subject('D.I.S '.$subject));
                    $sent++;
                } catch (Throwable $exception) {
                    report($exception);
                }
            });

        return $sent;
    }

    private function targetLabel(string $target): string
    {
        return $target === 'samba' ? 'Samba share' : 'Lokaal';
    }

    private function trimOutput(string $output): string
    {
        $output = trim($output);
        if ($output === '') {
            return '-';
        }

        return mb_substr($output, 0, 4000);
    }
}
