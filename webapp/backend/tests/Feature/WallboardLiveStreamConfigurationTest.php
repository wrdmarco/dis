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
use Tests\TestCase;

final class WallboardLiveStreamConfigurationTest extends TestCase
{
    public function test_live_stream_is_a_strict_page_type_without_client_options(): void
    {
        $configuration = WallboardConfiguration::normalize([
            'pages' => [$this->page()],
        ]);

        $this->assertContains('live_stream', WallboardConfiguration::PAGE_TYPES);
        $this->assertSame('live_stream', $configuration['pages'][0]['type']);
        $this->assertSame([], $configuration['pages'][0]['options']);

        $page = $this->page();
        $page['options']['server_url'] = 'rtmps://attacker.example.test:1936/live';

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Live-streaminstellingen horen uitsluitend uit serverconfiguratie te komen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('configuration.pages.0.options', $exception->errors());
        }
    }

    public function test_every_admin_configuration_request_accepts_only_the_server_configured_live_stream_page(): void
    {
        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$this->page()]],
            ]);

            $this->assertSame('live_stream', $validated['configuration']['pages'][0]['type']);
            $this->assertSame([], $validated['configuration']['pages'][0]['options']);

            $page = $this->page();
            $page['options']['stream_key'] = 'never-client-controlled';
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Een clientgestuurde live-streamcode had geweigerd moeten worden.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('configuration.pages.0.options', $exception->errors());
            }
        }
    }

    /** @return list<array{0: FormRequest, 1: array<string, int|string>}> */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Live-scherm']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Live-playlist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @return array<string, mixed> */
    private function page(): array
    {
        return [
            'id' => 'obs-live',
            'name' => 'OBS live',
            'type' => 'live_stream',
            'duration_seconds' => 30,
            'options' => [],
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
