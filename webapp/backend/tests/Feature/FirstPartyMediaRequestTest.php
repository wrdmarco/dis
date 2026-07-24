<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFirstPartyRequestsAreStateful;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class FirstPartyMediaRequestTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function mediaPaths(): array
    {
        $ulid = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        return [
            'wallboard asset' => ['/api/wallboard/media/'.$ulid],
            'wallboard news image' => ['/api/wallboard/news-images/'.str_repeat('a', 64)],
            'admin asset' => ['/api/admin/wallboard-media/assets/'.$ulid.'/content'],
            'admin thumbnail' => ['/api/admin/wallboard-media/assets/'.$ulid.'/thumbnail'],
            'operational precipitation radar atlas' => [
                '/api/operational-weather/radar/precipitation/20260724T120000Z-0123456789abcdef.png',
            ],
            'operational lightning radar atlas' => [
                '/api/operational-weather/radar/lightning/20260724T120000Z-fedcba9876543210.png',
            ],
            'wallboard precipitation radar atlas' => [
                '/api/wallboard/weather-radar/precipitation/20260724T120000Z-0123456789abcdef.png',
            ],
            'wallboard lightning radar atlas' => [
                '/api/wallboard/weather-radar/lightning/20260724T120000Z-fedcba9876543210.png',
            ],
        ];
    }

    #[DataProvider('mediaPaths')]
    public function test_same_origin_media_element_reads_are_treated_as_stateful(string $uri): void
    {
        foreach (['GET', 'HEAD'] as $method) {
            $request = Request::create($uri, $method, server: [
                'HTTP_SEC_FETCH_SITE' => 'same-origin',
            ]);

            self::assertTrue(EnsureFirstPartyRequestsAreStateful::fromFrontend($request));
        }
    }

    #[DataProvider('mediaPaths')]
    public function test_media_exception_rejects_cross_site_and_mutating_requests(string $uri): void
    {
        $crossSite = Request::create($uri, 'GET', server: [
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ]);
        self::assertFalse(EnsureFirstPartyRequestsAreStateful::fromFrontend($crossSite));

        $mutation = Request::create($uri, 'POST', server: [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);
        self::assertFalse(EnsureFirstPartyRequestsAreStateful::fromFrontend($mutation));
    }

    public function test_non_media_element_read_is_not_promoted_without_ajax_header(): void
    {
        $request = Request::create('/api/wallboard/state', 'GET', server: [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);

        self::assertFalse(EnsureFirstPartyRequestsAreStateful::fromFrontend($request));
    }

    /** @return array<string, array{string}> */
    public static function invalidRadarPaths(): array
    {
        return [
            'unknown radar kind' => [
                '/api/operational-weather/radar/unknown/20260724T120000Z-0123456789abcdef.png',
            ],
            'invalid snapshot' => [
                '/api/operational-weather/radar/precipitation/latest.png',
            ],
            'nested suffix' => [
                '/api/wallboard/weather-radar/lightning/20260724T120000Z-fedcba9876543210/atlas.png',
            ],
            'unrelated png route' => [
                '/api/admin/weather-radar/precipitation/20260724T120000Z-0123456789abcdef.png',
            ],
        ];
    }

    #[DataProvider('invalidRadarPaths')]
    public function test_radar_media_exception_is_limited_to_valid_atlas_routes(string $uri): void
    {
        $request = Request::create($uri, 'GET', server: [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
        ]);

        self::assertFalse(EnsureFirstPartyRequestsAreStateful::fromFrontend($request));
    }

    public function test_same_origin_ajax_requests_remain_stateful(): void
    {
        $request = Request::create('/api/wallboard/state', 'GET', server: [
            'HTTP_SEC_FETCH_SITE' => 'same-origin',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertTrue(EnsureFirstPartyRequestsAreStateful::fromFrontend($request));
    }
}
