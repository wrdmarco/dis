<?php

namespace App\Services;

use RuntimeException;

final class SpeechCacheKeyService
{
    /** @param array<string, mixed> $components */
    public function key(string $category, array $components): string
    {
        ksort($components);

        return hash_hmac('sha256', json_encode([
            'version' => (int) config('dis.speech.cache_key_version', 1),
            'category' => $category,
            'components' => $components,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $this->secret());
    }

    public function semantic(string $text): string
    {
        return $this->key('semantic', ['text' => trim($text)]);
    }

    private function secret(): string
    {
        $configured = trim((string) config('dis.speech.cache_hmac_key', ''));
        if ($configured === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('SPEECH_CACHE_HMAC_KEY is required in production.');
            }
            $configured = (string) config('app.key');
        }
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if (is_string($decoded) && strlen($decoded) >= 32) {
                return $decoded;
            }
        }
        if (strlen($configured) < 32) {
            throw new RuntimeException('Speech cache HMAC key must contain at least 32 bytes.');
        }

        return $configured;
    }
}
