<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Validation\Rules\Password;

final class PasswordPolicy
{
    public const MIN_LENGTH_KEY = 'security.password_min_length';
    public const MIXED_CASE_KEY = 'security.password_requires_mixed_case';
    public const NUMBERS_KEY = 'security.password_requires_numbers';
    public const SYMBOLS_KEY = 'security.password_requires_symbols';
    public const UNCOMPROMISED_KEY = 'security.password_uncompromised';

    public const DEFAULT_MIN_LENGTH = 14;
    public const DEFAULT_REQUIRES_MIXED_CASE = true;
    public const DEFAULT_REQUIRES_NUMBERS = true;
    public const DEFAULT_REQUIRES_SYMBOLS = true;
    public const DEFAULT_UNCOMPROMISED = true;

    public function rule(): Password
    {
        $rule = Password::min($this->minimumLength());

        if ($this->requiresMixedCase()) {
            $rule->mixedCase();
        }

        if ($this->requiresNumbers()) {
            $rule->numbers();
        }

        if ($this->requiresSymbols()) {
            $rule->symbols();
        }

        if ($this->rejectsCompromisedPasswords()) {
            $rule->uncompromised();
        }

        return $rule;
    }

    public function minimumLength(): int
    {
        return max(8, min(128, SystemSetting::integer(self::MIN_LENGTH_KEY, self::DEFAULT_MIN_LENGTH)));
    }

    public function requiresMixedCase(): bool
    {
        return SystemSetting::boolean(self::MIXED_CASE_KEY, self::DEFAULT_REQUIRES_MIXED_CASE);
    }

    public function requiresNumbers(): bool
    {
        return SystemSetting::boolean(self::NUMBERS_KEY, self::DEFAULT_REQUIRES_NUMBERS);
    }

    public function requiresSymbols(): bool
    {
        return SystemSetting::boolean(self::SYMBOLS_KEY, self::DEFAULT_REQUIRES_SYMBOLS);
    }

    public function rejectsCompromisedPasswords(): bool
    {
        return SystemSetting::boolean(self::UNCOMPROMISED_KEY, self::DEFAULT_UNCOMPROMISED);
    }
}
