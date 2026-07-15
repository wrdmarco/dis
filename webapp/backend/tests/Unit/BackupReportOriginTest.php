<?php

namespace Tests\Unit;

use App\Services\BackupReportOrigin;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BackupReportOriginTest extends TestCase
{
    #[Test]
    public function report_subject_distinguishes_automatic_and_manual_backups(): void
    {
        self::assertSame('Automatische backup geslaagd', BackupReportOrigin::Automatic->subject(true));
        self::assertSame('Automatische backup mislukt', BackupReportOrigin::Automatic->subject(false));
        self::assertSame('Handmatige backup geslaagd', BackupReportOrigin::Manual->subject(true));
        self::assertSame('Handmatige backup mislukt', BackupReportOrigin::Manual->subject(false));
    }
}
