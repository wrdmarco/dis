<?php

namespace App\Support;

final class ProfileLocation
{
    /**
     * @return list<string>
     */
    public static function countryCodes(): array
    {
        return array_keys(self::countries());
    }

    /**
     * @return array<string, string>
     */
    public static function countries(): array
    {
        return [
            'NL' => 'Nederland',
            'BE' => 'Belgie',
            'DE' => 'Duitsland',
            'FR' => 'Frankrijk',
            'LU' => 'Luxemburg',
        ];
    }

    /**
     * @return list<string>
     */
    public static function regionsFor(?string $country): array
    {
        return self::regions()[strtoupper(trim((string) $country))] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function regions(): array
    {
        return [
            'NL' => [
                'Drenthe',
                'Flevoland',
                'Friesland',
                'Gelderland',
                'Groningen',
                'Limburg',
                'Noord-Brabant',
                'Noord-Holland',
                'Overijssel',
                'Utrecht',
                'Zeeland',
                'Zuid-Holland',
            ],
            'BE' => [
                'Antwerpen',
                'Henegouwen',
                'Limburg',
                'Luik',
                'Luxemburg',
                'Namen',
                'Oost-Vlaanderen',
                'Vlaams-Brabant',
                'Waals-Brabant',
                'West-Vlaanderen',
                'Brussels Hoofdstedelijk Gewest',
            ],
        ];
    }

    public static function countryName(?string $country): ?string
    {
        $code = strtoupper(trim((string) $country));

        return self::countries()[$code] ?? null;
    }

    public static function callingCode(?string $country): ?string
    {
        return [
            'NL' => '+31',
            'BE' => '+32',
            'DE' => '+49',
            'FR' => '+33',
            'LU' => '+352',
        ][strtoupper(trim((string) $country))] ?? null;
    }

    public static function countryFromLocationLabel(?string $locationLabel): ?string
    {
        $label = mb_strtolower(trim((string) $locationLabel));
        if ($label === '') {
            return null;
        }

        if (str_contains($label, 'netherlands') || str_contains($label, 'nederland')) {
            return 'NL';
        }

        if (str_contains($label, 'belg')) {
            return 'BE';
        }

        if (str_contains($label, 'deutschland') || str_contains($label, 'duitsland') || str_contains($label, 'germany')) {
            return 'DE';
        }

        if (str_contains($label, 'france') || str_contains($label, 'frankrijk')) {
            return 'FR';
        }

        if (str_contains($label, 'luxemb')) {
            return 'LU';
        }

        return null;
    }

    public static function countryFromCoordinates(mixed $latitude, mixed $longitude): ?string
    {
        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        $lat = (float) $latitude;
        $lon = (float) $longitude;
        $boxes = [
            'LU' => [49.4, 50.3, 5.6, 6.6],
            'BE' => [49.4, 51.6, 2.5, 6.5],
            'NL' => [50.7, 53.7, 3.2, 7.3],
            'DE' => [47.0, 55.2, 5.5, 15.5],
            'FR' => [41.0, 51.5, -5.5, 9.8],
        ];

        $matches = [];
        foreach ($boxes as $country => [$minLat, $maxLat, $minLon, $maxLon]) {
            if ($lat >= $minLat && $lat <= $maxLat && $lon >= $minLon && $lon <= $maxLon) {
                $matches[] = $country;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }
}
