<?php

namespace Tests\Feature;

use App\Support\WallboardMediaVideoProgressParser;
use PHPUnit\Framework\TestCase;

final class WallboardMediaVideoProgressParserTest extends TestCase
{
    public function test_it_parses_incremental_ffmpeg_progress_without_exceeding_99(): void
    {
        $parser = new WallboardMediaVideoProgressParser(20);

        self::assertSame([], $parser->consume("frame=1\nout_time_us=1"));
        self::assertSame([5], $parser->consume("000000\nprogress=continue\n"));
        self::assertSame([50, 99], $parser->consume(
            "out_time_us=10000000\nout_time_us=25000000\nprogress=continue\nprogress=end\n",
        ));
    }

    public function test_it_ignores_invalid_and_non_monotonic_progress(): void
    {
        $parser = new WallboardMediaVideoProgressParser(10);

        self::assertSame([50], $parser->consume(
            "out_time_us=-1\nout_time_us=not-a-number\nout_time_us=5000000\n",
        ));
        self::assertSame([], $parser->consume("out_time_us=1000000\n"));
        self::assertSame([99], $parser->consume("out_time_us=999999999999999999\n"));
    }
}
