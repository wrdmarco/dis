<?php

namespace Tests\Feature;

use App\Http\Requests\Admin\StoreWallboardPlaylistRequest;
use App\Http\Requests\Admin\StoreWallboardRequest;
use App\Http\Requests\Admin\UpdateWallboardPlaylistRequest;
use App\Http\Requests\Admin\UpdateWallboardRequest;
use App\Models\CalendarEvent;
use App\Models\Team;
use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Services\WallboardStateService;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class WallboardCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_calendar_page_uses_the_bounded_default_and_all_admin_contracts_accept_it(): void
    {
        $page = $this->calendarPage([]);
        $configuration = WallboardConfiguration::normalize(['pages' => [$page]]);

        $this->assertContains('calendar', WallboardConfiguration::PAGE_TYPES);
        $this->assertSame(
            WallboardConfiguration::DEFAULT_CALENDAR_MAX_ITEMS,
            $configuration['pages'][0]['options']['max_items'],
        );

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            $validated = $this->validateRequest($request, [
                ...$basePayload,
                'configuration' => ['pages' => [$page]],
            ]);

            $this->assertSame('calendar', $validated['configuration']['pages'][0]['type']);
        }
    }

    #[DataProvider('invalidCalendarOptionsProvider')]
    public function test_calendar_page_options_fail_closed(array $options, string $errorKey): void
    {
        $page = $this->calendarPage($options);

        try {
            WallboardConfiguration::normalize(['pages' => [$page]]);
            $this->fail('Ongeldige kalenderconfiguratie had niet mogen normaliseren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($errorKey, $exception->errors());
        }

        foreach ($this->requestContracts() as [$request, $basePayload]) {
            try {
                $this->validateRequest($request, [
                    ...$basePayload,
                    'configuration' => ['pages' => [$page]],
                ]);
                $this->fail('Ongeldige kalenderconfiguratie had niet door requestvalidatie mogen komen.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($errorKey, $exception->errors());
            }
        }
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function invalidCalendarOptionsProvider(): iterable
    {
        yield 'nul items' => [['max_items' => 0], 'configuration.pages.0.options.max_items'];
        yield 'te veel items' => [['max_items' => 13], 'configuration.pages.0.options.max_items'];
        yield 'geen geheel getal' => [['max_items' => '6'], 'configuration.pages.0.options.max_items'];
        yield 'vreemde optie' => [['max_items' => 6, 'show_test_incidents' => true], 'configuration.pages.0.options'];
    }

    public function test_state_queries_once_and_returns_only_bounded_current_and_upcoming_calendar_items(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        $team = Team::query()->create([
            'code' => 'OCP',
            'name' => 'Open Categorie Piloten',
            'type' => 'base',
            'is_operational' => true,
        ]);

        $ongoing = $this->event([
            'title' => 'Lopende oefening',
            'type' => 'exercise',
            'starts_at' => now()->subHours(2),
            'ends_at' => now()->addHour(),
            'location_label' => 'Commando Centrum',
            'description' => 'Oefening met operationele briefing.',
        ]);
        $teamOnly = $this->event([
            'title' => 'Vertrouwelijke teambriefing',
            'type' => 'meeting',
            'starts_at' => now()->subHours(3),
            'ends_at' => now()->addHours(2),
            'location_label' => 'Niet openbaar',
            'description' => 'Alleen voor het toegewezen team.',
            'team_id' => $team->id,
        ]);
        $upcoming = $this->event([
            'title' => 'Training morgen',
            'type' => 'training',
            'starts_at' => now()->addDay(),
            'ends_at' => null,
            'location_label' => null,
            'description' => null,
        ]);
        $this->event([
            'title' => 'Latere vergadering',
            'type' => 'meeting',
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ]);
        $this->event([
            'title' => 'Afgelopen evenement',
            'type' => 'other',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subHour(),
        ]);
        $this->event([
            'title' => 'Afgelopen moment',
            'type' => 'other',
            'starts_at' => now()->subHour(),
            'ends_at' => null,
        ]);
        $deleted = $this->event([
            'title' => 'Verwijderd toekomstig evenement',
            'type' => 'other',
            'starts_at' => now()->addMinutes(30),
            'ends_at' => null,
        ]);
        $deleted->delete();

        $wallboard = $this->wallboard([
            $this->calendarPage(['max_items' => 2], 'calendar-main'),
            $this->calendarPage(['max_items' => 1], 'calendar-compact'),
        ]);
        $calendarQueries = 0;
        DB::listen(function ($query) use (&$calendarQueries): void {
            if (str_contains(strtolower($query->sql), 'calendar_events')) {
                $calendarQueries++;
            }
        });

        $service = app(WallboardStateService::class);
        $state = $service->state($wallboard);

        $this->assertSame(1, $calendarQueries);
        $this->assertSame('2026-07-20T10:00:00+02:00', $state['calendar']['generated_at']);
        $this->assertSame(
            [(string) $ongoing->id, (string) $upcoming->id],
            array_column($state['calendar']['pages']['calendar-main']['items'], 'id'),
        );
        $this->assertNotContains(
            (string) $teamOnly->id,
            array_column($state['calendar']['pages']['calendar-main']['items'], 'id'),
        );
        $this->assertSame(
            [(string) $ongoing->id],
            array_column($state['calendar']['pages']['calendar-compact']['items'], 'id'),
        );

        $ongoingPayload = $state['calendar']['pages']['calendar-main']['items'][0];
        $this->assertSame('2026-07-20T08:00:00+02:00', $ongoingPayload['starts_at']);
        $this->assertSame('2026-07-20T11:00:00+02:00', $ongoingPayload['ends_at']);
        $this->assertSame('Commando Centrum', $ongoingPayload['location_label']);
        $this->assertSame('Oefening met operationele briefing.', $ongoingPayload['description']);
        $this->assertNull($ongoingPayload['team']);
        $this->assertArrayNotHasKey('created_by_name', $ongoingPayload);
        $this->assertArrayNotHasKey('team_id', $ongoingPayload);
    }

    public function test_calendar_remains_live_and_does_not_change_static_or_content_version_contracts(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 10:00:00', 'Europe/Amsterdam'));
        $wallboard = $this->wallboard([$this->calendarPage(['max_items' => 6])]);
        $service = app(WallboardStateService::class);

        $static = $service->staticContent($wallboard);
        $control = $service->control($wallboard);

        $this->assertArrayNotHasKey('calendar', $static);
        $this->assertSame(['static', 'news', 'ticker'], array_keys($control['content_versions']));
        $this->assertSame([], $service->live($wallboard)['calendar']['pages']['calendar']['items']);

        $event = $this->event([
            'title' => 'Nieuwe briefing',
            'type' => 'meeting',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
        ]);

        $this->assertSame(
            (string) $event->id,
            $service->live($wallboard)['calendar']['pages']['calendar']['items'][0]['id'],
        );
        $this->assertSame(['static', 'news', 'ticker'], array_keys($service->control($wallboard)['content_versions']));
    }

    /** @return list<array{0: FormRequest, 1: array<string, int|string>}> */
    private function requestContracts(): array
    {
        return [
            [new StoreWallboardRequest, ['name' => 'Kalenderwallboard']],
            [new UpdateWallboardRequest, ['expected_config_version' => 1]],
            [new StoreWallboardPlaylistRequest, ['name' => 'Kalenderplaylist']],
            [new UpdateWallboardPlaylistRequest, ['expected_version' => 1]],
        ];
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function calendarPage(array $options, string $id = 'calendar'): array
    {
        return [
            'id' => $id,
            'name' => 'Kalender',
            'type' => 'calendar',
            'duration_seconds' => 30,
            'options' => $options,
        ];
    }

    /** @param list<array<string, mixed>> $pages */
    private function wallboard(array $pages): Wallboard
    {
        $configuration = WallboardConfiguration::normalize(['pages' => $pages]);
        $playlist = WallboardPlaylist::query()->create([
            'name' => 'Kalenderplaylist',
            'configuration' => $configuration,
            'version' => 1,
        ]);

        return Wallboard::query()->create([
            'name' => 'Kalenderwallboard',
            'playlist_id' => $playlist->id,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
            'configuration' => $configuration,
            'config_version' => 1,
            'rotation_started_at' => now(),
            'is_enabled' => true,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function event(array $attributes): CalendarEvent
    {
        return CalendarEvent::query()->create([
            'title' => 'Kalenderitem',
            'type' => 'other',
            'starts_at' => now()->addHour(),
            'ends_at' => null,
            'location_label' => null,
            'description' => null,
            'team_id' => null,
            ...$attributes,
        ]);
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
