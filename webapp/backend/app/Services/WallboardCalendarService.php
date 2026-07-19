<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Repositories\CalendarEventRepository;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;

final class WallboardCalendarService
{
    public function __construct(
        private readonly CalendarEventRepository $events,
    ) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{generated_at: string, pages: array<string, array{items: list<array<string, mixed>>}>}
     */
    public function pages(array $configuration): array
    {
        $generatedAt = ApiDateTime::now();
        $pages = collect((array) ($configuration['pages'] ?? []))
            ->filter(fn (mixed $page): bool => is_array($page)
                && ($page['type'] ?? null) === 'calendar')
            ->values();
        if ($pages->isEmpty()) {
            return ['generated_at' => $generatedAt, 'pages' => []];
        }

        $limits = $pages->mapWithKeys(function (array $page): array {
            $options = is_array($page['options'] ?? null) ? $page['options'] : [];

            return [(string) $page['id'] => (int) ($options['max_items'] ?? WallboardConfiguration::DEFAULT_CALENDAR_MAX_ITEMS)];
        });
        $items = $this->events
            ->currentAndUpcoming(now(), (int) $limits->max())
            ->map(fn (CalendarEvent $event): array => $this->payload($event))
            ->values();

        return [
            'generated_at' => $generatedAt,
            'pages' => $limits
                ->map(fn (int $limit): array => [
                    'items' => $items->take($limit)->values()->all(),
                ])
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(CalendarEvent $event): array
    {
        return [
            'id' => (string) $event->id,
            'title' => (string) $event->title,
            'type' => (string) $event->type,
            'starts_at' => ApiDateTime::dateTime($event->starts_at),
            'ends_at' => ApiDateTime::dateTime($event->ends_at),
            'location_label' => $event->location_label,
            'description' => $event->description,
            'team' => null,
        ];
    }
}
