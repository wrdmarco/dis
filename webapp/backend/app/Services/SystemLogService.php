<?php

namespace App\Services;

use App\Repositories\SystemLogFileRepository;
use App\Support\SensitiveDataRedactor;

final class SystemLogService
{
    private const MAX_LINES = 1000;

    private const MAX_LINE_BYTES = 4096;

    public function __construct(
        private readonly SystemLogFileRepository $repository,
        private readonly SensitiveDataRedactor $redactor,
    ) {}

    /**
     * @return list<array{name: string, size_bytes: int, modified_at: string}>
     */
    public function files(): array
    {
        return array_map(
            static fn (array $file): array => [
                'name' => $file['name'],
                'size_bytes' => $file['size_bytes'],
                'modified_at' => $file['modified_at'],
            ],
            $this->repository->files(),
        );
    }

    public function latestSource(): ?string
    {
        return $this->repository->latestSource();
    }

    /**
     * @return array{
     *     name: string,
     *     size_bytes: int,
     *     modified_at: string,
     *     generation: string,
     *     checkpoint: string,
     *     cursor: int,
     *     reset: bool,
     *     reset_reason: string|null,
     *     truncated: bool,
     *     poll_after_ms: int,
     *     lines: list<string>
     * }|null
     */
    public function read(
        string $source,
        int $maxLines,
        ?int $cursor,
        ?string $generation,
        ?string $checkpoint,
        string $checkpointSubject,
    ): ?array {
        $chunk = $this->repository->read(
            $source,
            $cursor,
            $generation,
            $checkpoint,
            $checkpointSubject,
        );
        if ($chunk === null) {
            return null;
        }

        $lines = $this->safeLines($chunk['content']);
        $lineLimit = min(max($maxLines, 1), self::MAX_LINES);
        $lineTruncated = count($lines) > $lineLimit;
        if ($lineTruncated) {
            $lines = array_slice($lines, -$lineLimit);
        }

        return [
            'name' => $chunk['name'],
            'size_bytes' => $chunk['size_bytes'],
            'modified_at' => $chunk['modified_at'],
            'generation' => $chunk['generation'],
            'checkpoint' => $chunk['checkpoint'],
            'cursor' => $chunk['cursor'],
            'reset' => $chunk['reset'],
            'reset_reason' => $chunk['reset_reason'],
            'truncated' => $chunk['truncated'] || $lineTruncated,
            'poll_after_ms' => 2000,
            'lines' => $lines,
        ];
    }

    /**
     * @return list<string>
     */
    private function safeLines(string $content): array
    {
        if ($content === '') {
            return [];
        }

        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        $content = $this->stripTerminalControls($content);
        $content = $this->redactor->redactString($content);
        $content = $this->redactPrivateKeyFragments($content);
        $content = preg_replace([
            '/(Authorization:\s*Bearer\s+)[A-Za-z0-9._~+\/=-]+/i',
            '/(X-DIS-Developer-Key:\s*)\S+/i',
            '/((?:api[_-]?key|token|secret|password)[\'"\s:=]+)[^\'"\s,}]+/i',
        ], '$1[REDACTED]', $content) ?? '[REDACTED]';

        $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return array_values(array_map(function (string $line): string {
            $line = mb_convert_encoding($line, 'UTF-8', 'UTF-8');
            if (strlen($line) <= self::MAX_LINE_BYTES) {
                return $line;
            }

            return mb_strcut($line, 0, self::MAX_LINE_BYTES, 'UTF-8').' … [regel afgekapt]';
        }, $lines));
    }

    private function stripTerminalControls(string $content): string
    {
        $content = preg_replace(
            '/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|\x1B\\\\))/',
            '',
            $content,
        ) ?? '';

        return preg_replace([
            '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',
            '/\x{2028}|\x{2029}/u',
            '/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
        ], ['', ' ', ''], $content) ?? '';
    }

    private function redactPrivateKeyFragments(string $content): string
    {
        $label = '(?:RSA |EC |OPENSSH )?PRIVATE KEY';
        $lines = preg_split('/\r\n|\n|\r/', $content);
        if (! is_array($lines)) {
            return '[REDACTED PRIVATE KEY]';
        }

        $markers = [];
        foreach ($lines as $index => $line) {
            if (preg_match('/^[ \t]*-----(?:BEGIN|END) '.$label.'-----[ \t]*$/', $line) === 1) {
                $markers[$index] = true;
                $lines[$index] = '[REDACTED PRIVATE KEY]';
            } elseif (preg_match('/^[ \t]*(?:Proc-Type|DEK-Info):[^\r\n]*$/i', $line) === 1) {
                $lines[$index] = '[REDACTED PRIVATE KEY MATERIAL]';
            }
        }

        $lastContentIndex = count($lines) - 1;
        while ($lastContentIndex >= 0 && $lines[$lastContentIndex] === '') {
            $lastContentIndex--;
        }

        for ($index = 0; $index <= $lastContentIndex; $index++) {
            if (! $this->isBase64MaterialLine($lines[$index])) {
                continue;
            }

            $start = $index;
            $hasLongLine = false;
            while ($index <= $lastContentIndex && $this->isBase64MaterialLine($lines[$index])) {
                $hasLongLine = $hasLongLine || strlen(trim($lines[$index])) >= 32;
                $index++;
            }
            $end = $index - 1;
            $touchesMarker = isset($markers[$start - 1]) || isset($markers[$end + 1]);
            $touchesChunkBoundary = $start === 0 || $end === $lastContentIndex;

            if ($hasLongLine || $touchesMarker || $touchesChunkBoundary) {
                for ($lineIndex = $start; $lineIndex <= $end; $lineIndex++) {
                    $lines[$lineIndex] = '[REDACTED PRIVATE KEY MATERIAL]';
                }
            }
        }

        return implode("\n", $lines);
    }

    private function isBase64MaterialLine(string $line): bool
    {
        $value = trim($line);

        return strlen($value) >= 4
            && preg_match(
                '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/',
                $value,
            ) === 1;
    }
}
