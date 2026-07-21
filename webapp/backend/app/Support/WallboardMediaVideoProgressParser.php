<?php

namespace App\Support;

final class WallboardMediaVideoProgressParser
{
    private const MAX_BUFFER_BYTES = 16_384;

    private string $buffer = '';

    private int $lastProgress = -1;

    public function __construct(private readonly int $durationSeconds) {}

    /** @return list<int> */
    public function consume(string $chunk): array
    {
        if ($chunk === '') {
            return [];
        }

        $this->buffer .= str_replace("\r", "\n", $chunk);
        $lines = explode("\n", $this->buffer);
        $this->buffer = (string) array_pop($lines);
        if (strlen($this->buffer) > self::MAX_BUFFER_BYTES) {
            $this->buffer = substr($this->buffer, -self::MAX_BUFFER_BYTES);
        }
        $progress = [];

        foreach ($lines as $line) {
            $percentage = $this->percentage(trim($line));
            if ($percentage === null || $percentage <= $this->lastProgress) {
                continue;
            }

            $this->lastProgress = $percentage;
            $progress[] = $percentage;
        }

        return $progress;
    }

    private function percentage(string $line): ?int
    {
        if ($line === 'progress=end') {
            return 99;
        }
        if ($this->durationSeconds < 1
            || preg_match('/^out_time_us=([0-9]{1,18})$/D', $line, $matches) !== 1) {
            return null;
        }

        $elapsedMicroseconds = (int) $matches[1];
        $durationMicroseconds = $this->durationSeconds * 1_000_000;

        return min(99, max(0, (int) floor(($elapsedMicroseconds / $durationMicroseconds) * 100)));
    }
}
