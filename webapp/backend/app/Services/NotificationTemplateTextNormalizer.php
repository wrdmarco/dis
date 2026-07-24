<?php

namespace App\Services;

final class NotificationTemplateTextNormalizer
{
    public function displayPostcode(?string $postcode): string
    {
        $value = $this->plain($postcode);
        $compact = strtoupper((string) preg_replace('/\s+/u', '', $value));
        if (preg_match('/^([1-9][0-9]{3})([A-Z]{2})$/D', $compact, $match) !== 1) {
            return $value;
        }

        return $match[1].' '.$match[2];
    }

    public function plain(?string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim((string) $value));

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
