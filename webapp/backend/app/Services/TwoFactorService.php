<?php

namespace App\Services;

use App\Models\User;

final class TwoFactorService
{
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

