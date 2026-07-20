<?php

namespace App\Support;

use Stringable;
use Throwable;

final class SensitiveDataRedactor
{
    private const REDACTED = '[REDACTED]';

    /**
     * @var list<string>
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'authorization',
        'api_key',
        'cookie',
        'credential',
        'csrf',
        'developer_key',
        'xsrf',
        'mfa_code',
        'otp',
        'passphrase',
        'passwd',
        'password',
        'private_key',
        'recovery_code',
        'secret',
        'session_id',
        'sessionid',
        'token',
        'totp',
        'two_factor_code',
        'twofactorcode',
    ];

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public function redactArray(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = $this->redactValue($value, (string) $key);
        }

        return $values;
    }

    public function redactString(string $value): string
    {
        $patterns = [
            '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----.*?-----END (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/s' => '[REDACTED PRIVATE KEY]',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=:-]+/i' => '$1 '.self::REDACTED,
            '/((?:authorization|cookie|set-cookie|x-xsrf-token|x-csrf-token|x-dis-developer-key)\s*[:=]\s*)[^\r\n]+/i' => '$1'.self::REDACTED,
            '/\b([a-z][a-z0-9+.-]*:\/\/)[^\/@\s]+:[^@\s]+@/i' => '$1'.self::REDACTED.'@',
            '/\b((?=[A-Z0-9_.-]*(?:APP[_.-]?KEY|API[_.-]?KEY|ACCESS[_.-]?KEY|DEVELOPER[_.-]?KEY|PASSWORD|PASSWD|PASSPHRASE|PRIVATE[_.-]?KEY|SECRET|TOKEN|CREDENTIAL|DATABASE[_.-]?URL|REDIS[_.-]?URL|MAIL[_.-]?URL|DSN)[A-Z0-9_.-]*\s*[:=])[A-Z][A-Z0-9_.-]*\s*[:=]\s*)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s\r\n]+)/i' => '$1'.self::REDACTED,
            '/(?<![A-Za-z0-9_-])(--?(?:password|passwd|passphrase|secret|token|api[-_]?key|access[-_]?key|developer[-_]?key|private[-_]?key))(?:=|\s+)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s\r\n]+)/i' => '$1 '.self::REDACTED,
            '/([?&](?:access_token|refresh_token|token|password|secret|api[-_]?key|developer_key|csrf_token|xsrf_token|code)=)[^&\s]+/i' => '$1'.self::REDACTED,
            '/("(?:access_token|refresh_token|token|password|secret|api[-_]?key|csrf_token|xsrf_token|two_factor_code|totp|otp)"\s*:\s*")[^"]*(")/i' => '$1'.self::REDACTED.'$2',
            '/\b((?:__Host-)?dis_session|XSRF-TOKEN)=([^;\s]+)/i' => '$1='.self::REDACTED,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $value) ?? self::REDACTED;
    }

    private function redactValue(mixed $value, string $key): mixed
    {
        if ($this->isSensitiveKey($key)) {
            return self::REDACTED;
        }

        if (is_array($value)) {
            return $this->redactArray($value);
        }

        if ($value instanceof Throwable) {
            return [
                'exception' => $value::class,
                'message' => $this->redactString($value->getMessage()),
            ];
        }

        if ($value instanceof Stringable) {
            return $this->redactString((string) $value);
        }

        if (is_string($value)) {
            return $this->redactString($value);
        }

        if (is_object($value)) {
            return ['object' => $value::class];
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = mb_strtolower(str_replace(['-', '.', ' '], '_', $key));

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
