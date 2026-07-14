<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class TwoFactorService
{
    public const REQUIRED_KEY = 'security.mfa_required';

    public const DEFAULT_REQUIRED = true;

    public function isRequired(): bool
    {
        return SystemSetting::boolean(self::REQUIRED_KEY, self::DEFAULT_REQUIRED);
    }

    public function isRequiredFor(User $user): bool
    {
        return $this->isRequired() || $user->canUseAdminApp();
    }

    public function generateSecret(int $length = 32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($index = 0; $index < $length; $index++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = $this->issuer();
        $account = trim($user->name) !== '' ? $user->name.' - '.$user->email : $user->email;
        $label = rawurlencode($issuer.':'.$account);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
    }

    private function issuer(): string
    {
        $issuer = trim((string) SystemSetting::string('security.mfa_issuer_name', 'D.I.S'));

        return $issuer !== '' ? $issuer : 'D.I.S';
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($index = 0; $index < $count; $index++) {
            $codes[] = Str::upper(Str::random(5).'-'.Str::random(5));
        }

        return $codes;
    }

    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        $normalized = preg_replace('/\s+/', '', $code);
        if (! is_string($normalized) || ! ctype_digit($normalized)) {
            return false;
        }

        $timestamp = time();
        for ($window = -1; $window <= 1; $window++) {
            if (hash_equals($this->totp($secret, $timestamp + ($window * 30)), str_pad($normalized, 6, '0', STR_PAD_LEFT))) {
                return true;
            }
        }

        return false;
    }

    public function verifyForLogin(User $user, string $code): bool
    {
        if ($this->verify($user, $code)) {
            $replayKey = 'mfa:totp-used:'.$user->id.':'.hash('sha256', preg_replace('/\s+/', '', $code) ?? $code);

            return Cache::add($replayKey, true, now()->addSeconds(90));
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $normalized = $this->normalizeRecoveryCode($code);
        if ($normalized === '') {
            return false;
        }

        return DB::transaction(function () use ($user, $normalized): bool {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->first();
            if ($lockedUser === null) {
                return false;
            }

            $recoveryCodes = is_array($lockedUser->two_factor_recovery_codes)
                ? $lockedUser->two_factor_recovery_codes
                : [];
            foreach ($recoveryCodes as $index => $candidate) {
                if (! is_string($candidate) || ! hash_equals($this->normalizeRecoveryCode($candidate), $normalized)) {
                    continue;
                }

                unset($recoveryCodes[$index]);
                $remainingCodes = array_values($recoveryCodes);
                $lockedUser->forceFill(['two_factor_recovery_codes' => $remainingCodes])->save();
                $user->forceFill(['two_factor_recovery_codes' => $remainingCodes])->syncOriginalAttribute('two_factor_recovery_codes');

                return true;
            }

            return false;
        });
    }

    private function totp(string $base32Secret, int $timestamp): string
    {
        $counter = intdiv($timestamp, 30);
        $binaryCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $this->base32Decode($base32Secret), true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        $bits = '';

        foreach (str_split($secret) as $char) {
            $position = strpos($alphabet, $char);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $binary = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $binary .= chr(bindec($byte));
            }
        }

        return $binary;
    }

    private function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($code)) ?? '');
    }
}
