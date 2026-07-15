<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ScheduledBackupConfigurationTest extends TestCase
{
    #[Test]
    public function scheduled_backup_does_not_block_other_scheduler_tasks_or_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $candidate): bool => str_contains($candidate->command ?? '', 'dis:run-scheduled-backup'));

        self::assertInstanceOf(Event::class, $event);
        self::assertSame('* * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(30, $event->expiresAt);
        self::assertTrue($event->runInBackground);
    }
}
