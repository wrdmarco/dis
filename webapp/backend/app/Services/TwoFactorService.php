<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

final class TwoFactorService
{
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
        $issuer = config('app.name', 'D.I.S');
        $label = rawurlencode($issuer.':'.$user->email);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => 6,
            'period' => 30,
        ], '', '&', PHP_QUERY_RFC3986);

        return "otpauth://totp/{$label}?{$query}";
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
}
