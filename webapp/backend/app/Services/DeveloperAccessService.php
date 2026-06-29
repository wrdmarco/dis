<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class DeveloperAccessService
{
    public const SCOPE_ANDROID_UPLOAD = 'android_upload';
    public const SCOPE_SYSTEM_UPDATE = 'system_update';
    public const SCOPE_LOGS_READ = 'logs_read';

    public const SCOPES = [
        self::SCOPE_ANDROID_UPLOAD,
        self::SCOPE_SYSTEM_UPDATE,
        self::SCOPE_LOGS_READ,
    ];

    public function __construct(
        private readonly AuditService $auditService,
    ) {}

    public function authorize(Request $request, string $scope): void
    {
        $providedKey = (string) $request->header('X-DIS-Developer-Key', '');
        $setting = SystemSetting::query()->find('developer.android_upload');
        $value = is_array($setting?->value) ? $setting->value : [];
        $expectedHash = $value['key_hash'] ?? null;

        if (($value['enabled'] ?? false) !== true || ! is_string($expectedHash) || $expectedHash === '') {
            $this->deny($request, $scope, 403, 'Developer API is disabled.', 'disabled');
        }

        if ($providedKey === '' || ! hash_equals($expectedHash, hash('sha256', $providedKey))) {
            $this->deny($request, $scope, 401, 'Invalid developer API key.', 'invalid_key');
        }

        if ($this->isExpired($value['expires_at'] ?? null)) {
            $this->deny($request, $scope, 403, 'Developer API key expired.', 'expired');
        }

        if (! $this->ipAllowed($request, $value['allowed_ips'] ?? [])) {
            $this->deny($request, $scope, 403, 'Developer API key is not allowed from this IP address.', 'ip_not_allowed');
        }

        if (! in_array($scope, $this->configuredScopes($value), true)) {
            $this->deny($request, $scope, 403, 'Developer API key does not allow this action.', 'scope_denied');
        }
    }

    /**
     * @param array<string, mixed> $value
     * @return list<string>
     */
    public function configuredScopes(array $value): array
    {
        $scopes = $value['scopes'] ?? null;
        if (! is_array($scopes)) {
            return self::SCOPES;
        }

        return array_values(array_intersect(self::SCOPES, array_filter($scopes, 'is_string')));
    }

    public function isExpired(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return false;
        }

        try {
            return Carbon::parse($expiresAt)->isPast();
        } catch (\Throwable) {
            return true;
        }
    }

    public function isAllowedIpPattern(string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return false;
        }

        if (! str_contains($pattern, '/')) {
            return filter_var($pattern, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = array_pad(explode('/', $pattern, 2), 2, null);
        if (! is_string($address) || ! is_string($prefix) || ! ctype_digit($prefix)) {
            return false;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }

        $maxPrefix = strlen($packed) * 8;
        $prefixLength = (int) $prefix;

        return $prefixLength >= 0 && $prefixLength <= $maxPrefix;
    }

    /**
     * @param mixed $patterns
     */
    private function ipAllowed(Request $request, mixed $patterns): bool
    {
        if (! is_array($patterns) || $patterns === []) {
            return true;
        }

        $ip = $request->ip();
        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            if ($this->ipMatches(trim($pattern), $ip)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatches(string $pattern, string $ip): bool
    {
        if ($pattern === '') {
            return false;
        }

        if (! str_contains($pattern, '/')) {
            return hash_equals($pattern, $ip);
        }

        [$range, $prefix] = array_pad(explode('/', $pattern, 2), 2, null);
        if (! is_string($range) || ! is_string($prefix) || ! ctype_digit($prefix)) {
            return false;
        }

        $rangePacked = inet_pton($range);
        $ipPacked = inet_pton($ip);
        if ($rangePacked === false || $ipPacked === false || strlen($rangePacked) !== strlen($ipPacked)) {
            return false;
        }

        $prefixLength = (int) $prefix;
        $totalBits = strlen($rangePacked) * 8;
        if ($prefixLength < 0 || $prefixLength > $totalBits) {
            return false;
        }

        $fullBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if ($fullBytes > 0 && substr($rangePacked, 0, $fullBytes) !== substr($ipPacked, 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($rangePacked[$fullBytes]) & $mask) === (ord($ipPacked[$fullBytes]) & $mask);
    }

    private function deny(Request $request, string $scope, int $status, string $message, string $reason): never
    {
        $this->auditService->record('developer.api_denied', SystemSetting::class, null, [
            'scope' => $scope,
            'reason' => $reason,
        ], null, $request);

        throw new HttpResponseException(response()->json([
            'message' => $message,
        ], $status));
    }
}
