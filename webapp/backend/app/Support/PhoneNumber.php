<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class PhoneNumber
{
    public static function normalize(mixed $value, ?string $country, string $field = 'phone_number', bool $allowLocalWithoutCountry = false): ?string
    {
        $phone = trim((string) ($value ?? ''));
        if ($phone === '') {
            return null;
        }

        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';
        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        if (! str_starts_with($phone, '+')) {
            $callingCode = ProfileLocation::callingCode($country);
            if ($callingCode === null) {
                if ($allowLocalWithoutCountry && preg_match('/^[0-9]{6,20}$/', $phone) === 1) {
                    return $phone;
                }

                throw ValidationException::withMessages([
                    $field => ['Gebruik een internationaal telefoonnummer met landcode, bijvoorbeeld +31612345678.'],
                ]);
            }

            $digits = ltrim($phone, '0');
            $phone = $callingCode.$digits;
        }

        if (! self::looksInternational($phone)) {
            throw ValidationException::withMessages([
                $field => ['Gebruik een geldig internationaal telefoonnummer, bijvoorbeeld +31612345678.'],
            ]);
        }

        return $phone;
    }

    public static function looksInternational(mixed $phoneNumber): bool
    {
        return is_string($phoneNumber) && preg_match('/^\+[1-9][0-9]{6,14}$/', trim($phoneNumber)) === 1;
    }
}
