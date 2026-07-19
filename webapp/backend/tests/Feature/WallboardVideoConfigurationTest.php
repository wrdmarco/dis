<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Support\WallboardConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardVideoConfigurationTest extends TestCase
{
    #[DataProvider('supportedVideoUrlProvider')]
    public function test_supported_video_urls_are_reduced_to_a_canonical_embed_url(
        string $input,
        string $expected,
    ): void {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page($input, 47)],
        ]);

        $this->assertContains('video', WallboardConfiguration::PAGE_TYPES);
        $this->assertSame(47, $configuration['pages'][0]['duration_seconds']);
        $this->assertSame(['url' => $expected], $configuration['pages'][0]['options']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function supportedVideoUrlProvider(): iterable
    {
        $youtubeEmbed = 'https://www.youtube.com/embed/dQw4w9WgXcQ';

        yield 'YouTube watch' => [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42',
            $youtubeEmbed,
        ];
        yield 'YouTube watch without www' => [
            'https://youtube.com/watch?v=dQw4w9WgXcQ',
            $youtubeEmbed,
        ];
        yield 'YouTube mobile watch' => [
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            $youtubeEmbed,
        ];
        yield 'YouTube short share link' => [
            'https://youtu.be/dQw4w9WgXcQ?si=promo',
            $youtubeEmbed,
        ];
        yield 'YouTube embed' => [
            'https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=0',
            $youtubeEmbed,
        ];
        yield 'Vimeo public link' => [
            'https://vimeo.com/76979871?share=copy',
            'https://player.vimeo.com/video/76979871',
        ];
        yield 'Vimeo player link' => [
            'https://player.vimeo.com/video/76979871?autoplay=0',
            'https://player.vimeo.com/video/76979871',
        ];
    }

    public function test_every_admin_configuration_request_accepts_the_video_contract(): void
    {
        $page = $this->page('https://youtu.be/dQw4w9WgXcQ', 90);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);

            $this->assertSame('video', $validated['configuration']['pages'][0]['type']);
            $this->assertSame(90, $validated['configuration']['pages'][0]['duration_seconds']);
            $this->assertSame(
                'https://youtu.be/dQw4w9WgXcQ',
                $validated['configuration']['pages'][0]['options']['url'],
            );
        }
    }

    #[DataProvider('invalidVideoUrlProvider')]
    public function test_normalization_and_every_admin_request_reject_unsafe_or_unknown_video_urls(
        mixed $url,
    ): void {
        $page = $this->page($url);
        $errorKey = 'configuration.pages.0.options.url';

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Een onveilige video-URL had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Een onveilige video-URL had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: mixed}> */
    public static function invalidVideoUrlProvider(): iterable
    {
        yield 'missing URL' => [null];
        yield 'URL is not a string' => [['https://vimeo.com/76979871']];
        yield 'plain HTTP' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'scheme relative' => ['//www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'arbitrary host' => ['https://videos.example.org/dQw4w9WgXcQ'];
        yield 'YouTube lookalike host' => ['https://www.youtube.com.attacker.example/watch?v=dQw4w9WgXcQ'];
        yield 'credentials in URL' => ['https://attacker@www.youtube.com/watch?v=dQw4w9WgXcQ'];
        yield 'non-standard port' => ['https://www.youtube.com:8443/watch?v=dQw4w9WgXcQ'];
        yield 'unsupported YouTube Shorts path' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ'];
        yield 'invalid YouTube id' => ['https://www.youtube.com/watch?v=too-short'];
        yield 'extra youtu.be path segment' => ['https://youtu.be/dQw4w9WgXcQ/embed'];
        yield 'non-numeric Vimeo id' => ['https://vimeo.com/not-a-video'];
        yield 'extra Vimeo path segment' => ['https://vimeo.com/76979871/attacker'];
        yield 'iframe markup' => ['<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'];
        yield 'script scheme' => ['javascript:alert(1)'];
    }

    public function test_video_page_rejects_arbitrary_embed_fields(): void
    {
        $page = $this->page('https://vimeo.com/76979871');
        $page['options']['embed_html'] = '<iframe src="https://vimeo.com/76979871"></iframe>';
        $errorKey = 'configuration.pages.0.options';

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Embed-HTML had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Embed-HTML had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    #[DataProvider('validDurationProvider')]
    public function test_video_page_preserves_the_existing_duration_boundaries(int $durationSeconds): void
    {
        $page = $this->page('https://vimeo.com/76979871', $durationSeconds);
        $configuration = WallboardConfiguration::normalize(['pages' => [$page]]);

        $this->assertSame($durationSeconds, $configuration['pages'][0]['duration_seconds']);

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);
            $this->assertSame($durationSeconds, $validated['configuration']['pages'][0]['duration_seconds']);
        }
    }

    /** @return iterable<string, array{0: int}> */
    public static function validDurationProvider(): iterable
    {
        yield 'minimum' => [5];
        yield 'maximum' => [3600];
    }

    #[DataProvider('invalidDurationProvider')]
    public function test_video_page_rejects_durations_outside_the_existing_contract(int $durationSeconds): void
    {
        $page = $this->page('https://vimeo.com/76979871', $durationSeconds);
        $errorKey = 'configuration.pages.0.duration_seconds';

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Een ongeldige videoduur had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Een ongeldige videoduur had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: int}> */
    public static function invalidDurationProvider(): iterable
    {
        yield 'below minimum' => [4];
        yield 'above maximum' => [3601];
    }

    /**
     * @return list<array{0: FormRequest, 1: array<string, int|string>}>
     */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Promoscherm']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Promoplaylist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function page(mixed $url, int $durationSeconds = 30): array
    {
        return [
            'id' => 'promo-video',
            'name' => 'Promovideo',
            'type' => 'video',
            'duration_seconds' => $durationSeconds,
            'options' => ['url' => $url],
        ];
    }

    /** @return array<string, mixed> */
    private function validateRequest(FormRequest $request, array $payload): array
    {
        $request->initialize($payload);
        $validator = Validator::make($request->all(), $request->rules());
        foreach ($request->after() as $callback) {
            $validator->after($callback);
        }

        return $validator->validate();
    }
}
