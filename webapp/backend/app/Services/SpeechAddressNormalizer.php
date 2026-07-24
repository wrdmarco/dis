<?php

namespace App\Services;

final class SpeechAddressNormalizer
{
    /** @return array{street:string,house_number:string,postcode:string,place:string} */
    public function parts(?string $location): array
    {
        $value = $this->plain($location);
        $postcode = '';
        $place = '';
        if (preg_match('/\b([1-9][0-9]{3})\s*([A-Z]{2})\b\s*(.*)$/iu', $value, $match) === 1) {
            $postcode = $this->postcode($match[1].$match[2]);
            $place = $this->plain(trim($match[3], ' ,'));
            $value = $this->plain(trim(substr($value, 0, (int) strpos($value, $match[0])), ' ,'));
        } elseif (str_contains($value, ',')) {
            $pieces = array_values(array_filter(array_map(
                fn (string $piece): string => $this->plain($piece),
                explode(',', $value),
            )));
            $value = array_shift($pieces) ?? '';
            $place = end($pieces) ?: '';
        }

        $street = $value;
        $houseNumber = '';
        if (preg_match('/^(.*?)[\s,]+([0-9]+(?:\s*[A-Z])?(?:[-\/]?[0-9A-Z]+)?)$/iu', $value, $match) === 1) {
            $street = $this->plain($match[1]);
            $houseNumber = $this->houseNumber($match[2]);
        }

        return [
            'street' => $street,
            'house_number' => $houseNumber,
            'postcode' => $postcode,
            'place' => $place,
        ];
    }

    public function postcode(?string $postcode): string
    {
        $display = $this->displayPostcode($postcode);
        $compact = str_replace(' ', '', $display);
        if (preg_match('/^([1-9][0-9]{3})([A-Z]{2})$/D', $compact, $match) !== 1) {
            return $this->plain($postcode);
        }

        return implode(' ', mb_str_split($match[1].$match[2]));
    }

    public function displayPostcode(?string $postcode): string
    {
        $value = $this->plain($postcode);
        $compact = strtoupper((string) preg_replace('/\s+/u', '', $value));
        if (preg_match('/^([1-9][0-9]{3})([A-Z]{2})$/D', $compact, $match) !== 1) {
            return $value;
        }

        return $match[1].' '.$match[2];
    }

    public function houseNumber(?string $houseNumber): string
    {
        $value = strtoupper($this->plain($houseNumber));

        return trim((string) preg_replace('/(?<=\d)(?=[A-Z])|(?<=[A-Z])(?=\d)/u', ' ', $value));
    }

    public function plain(?string $value): string
    {
        $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', trim((string) $value));

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }
}
