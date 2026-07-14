<?php

namespace Tests\Feature;

use App\Services\DispatchService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DispatchLocationPlaceTest extends TestCase
{
    #[DataProvider('locationLabels')]
    public function test_it_extracts_the_place_from_supported_location_labels(string $locationLabel, string $expectedPlace): void
    {
        $service = $this->app->make(DispatchService::class);

        $this->assertSame($expectedPlace, $service->placeNameFromLocation($locationLabel));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function locationLabels(): iterable
    {
        yield 'screenshot address with separate postcode and city fields' => [
            "McDonald's, Botnische golf 1, 3446 CN, Woerden, Utrecht, Nederland",
            'Woerden',
        ];

        yield 'existing Dutch postcode and city in one field' => [
            'Dam 1, 1012 JS Amsterdam, Noord-Holland, Nederland',
            'Amsterdam',
        ];

        yield 'Dutch postcode with separated letters' => [
            'Botnische golf 1, 3446 C N, Woerden, Utrecht, Nederland',
            'Woerden',
        ];

        yield 'Dutch postcode split over address fields' => [
            'Botnische golf 1, 3446, C, N, Woerden, Utrecht, Nederland',
            'Woerden',
        ];

        yield 'compact Dutch postcode' => [
            'Dam 1, 1012JS Amsterdam, Noord-Holland, Nederland',
            'Amsterdam',
        ];

        yield 'Belgian postcode and city' => [
            'Grote Markt 1, 1000 Brussel, België',
            'Brussel',
        ];

        yield 'short Belgian place that resembles Dutch postcode letters' => [
            'Rue x, 3665 As, België',
            'As',
        ];

        yield 'Belgian postcode prefix without country field' => [
            'Rue x, BE-3665 As',
            'As',
        ];

        yield 'place before a separate Dutch postcode field' => [
            'Woerden, 3446 CN, Nederland',
            'Woerden',
        ];

        yield 'place before postcode takes precedence over a following province' => [
            'Woerden, 3446 CN, Utrecht, Nederland',
            'Woerden',
        ];

        yield 'existing place without address details' => [
            'Woerden',
            'Woerden',
        ];

        yield 'place with region and country but no postcode' => [
            'Woerden, Utrecht, Nederland',
            'Woerden',
        ];

        yield 'place name starting with a country code word' => [
            'De Bilt, Utrecht, Nederland',
            'De Bilt',
        ];
    }

    #[DataProvider('incompleteLocationLabels')]
    public function test_it_does_not_treat_postcode_letters_or_address_parts_as_a_place(?string $locationLabel): void
    {
        $service = $this->app->make(DispatchService::class);

        $this->assertNull($service->placeNameFromLocation($locationLabel));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function incompleteLocationLabels(): iterable
    {
        yield 'missing location' => [null];
        yield 'blank location' => ['   '];
        yield 'postcode without city' => ['Botnische golf 1, 3446 CN, Nederland'];
        yield 'postcode with separated letters without city' => ['3446 C N'];
    }
}
