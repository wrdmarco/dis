<?php

namespace App\Contracts;

interface SpeechEngineClient
{
    /** @return array<string, mixed> */
    public function health(): array;

    /** @param array<string, mixed> $model @return array<string, mixed> */
    public function install(string $modelId, array $model): array;

    /** @return array<string, mixed> */
    public function cancelInstall(string $modelId): array;

    /** @return array<string, mixed> */
    public function status(string $modelId): array;

    /** @return array<string, mixed> */
    public function synthesize(string $modelId, string $jobBasename, string $outputBasename): array;
}
