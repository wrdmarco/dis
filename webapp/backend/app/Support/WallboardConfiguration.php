<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

final class WallboardConfiguration
{
    public const DEFAULT_PAGE_ID = 'map';

    /** @var list<string> */
    public const PAGE_TYPES = ['map', 'incident_list', 'summary', 'message'];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'theme' => 'dark',
            'refresh_seconds' => 10,
            'rotation_enabled' => true,
            'pages' => [
                [
                    'id' => self::DEFAULT_PAGE_ID,
                    'name' => 'Kaart',
                    'type' => 'map',
                    'duration_seconds' => 30,
                    'options' => [],
                ],
            ],
            'incident_override' => [
                'enabled' => false,
                'page_id' => self::DEFAULT_PAGE_ID,
            ],
            'map' => [
                'show_active_incidents' => true,
                'show_test_incidents' => false,
                'show_live_locations' => true,
                'show_routes' => true,
                'show_command_centers' => true,
                'show_historical_incidents' => false,
                'show_summary' => true,
                'show_incident_list' => true,
                'show_route_legend' => true,
                'auto_fit' => true,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    public static function normalize(array $input, array $base = []): array
    {
        $normalized = array_replace_recursive(self::defaults(), $base, $input);

        // Numeric arrays must be replaced rather than recursively merged. Otherwise
        // removing or reordering pages can silently retain entries from the old list.
        if (array_key_exists('pages', $input)) {
            $normalized['pages'] = array_values((array) $input['pages']);
        } elseif (array_key_exists('pages', $base)) {
            $normalized['pages'] = array_values((array) $base['pages']);
        }

        $pages = array_values((array) ($normalized['pages'] ?? []));
        if ($pages === []) {
            throw ValidationException::withMessages([
                'configuration.pages' => ['Een wallboard heeft minimaal een pagina nodig.'],
            ]);
        }
        if (count($pages) > 20) {
            throw ValidationException::withMessages([
                'configuration.pages' => ['Een wallboard kan maximaal twintig pagina\'s bevatten.'],
            ]);
        }

        $pageIds = [];
        foreach ($pages as $index => $page) {
            if (! is_array($page)) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}" => ['De wallboardpagina is ongeldig.'],
                ]);
            }

            $pageId = (string) ($page['id'] ?? '');
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $pageId) !== 1 || isset($pageIds[$pageId])) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.id" => ['Elke wallboardpagina heeft een unieke pagina-ID nodig.'],
                ]);
            }
            $pageIds[$pageId] = true;

            $name = trim((string) ($page['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.name" => ['De paginanaam is verplicht en mag maximaal 120 tekens bevatten.'],
                ]);
            }

            $type = (string) ($page['type'] ?? '');
            if (! in_array($type, self::PAGE_TYPES, true)) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.type" => ['Dit wallboardpaginatype wordt niet ondersteund.'],
                ]);
            }

            $options = (array) ($page['options'] ?? []);
            $durationSeconds = (int) ($page['duration_seconds'] ?? 0);
            if ($durationSeconds < 5 || $durationSeconds > 3600) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.duration_seconds" => ['De zichtduur moet tussen 5 en 3600 seconden liggen.'],
                ]);
            }
            $allowedOptionKeys = match ($type) {
                'message' => ['body'],
                'incident_list', 'summary' => ['show_test_incidents'],
                default => [],
            };
            if (array_diff(array_keys($options), $allowedOptionKeys) !== []) {
                throw ValidationException::withMessages([
                    "configuration.pages.{$index}.options" => ['Deze pagina bevat opties die niet bij het gekozen paginatype horen.'],
                ]);
            }
            if ($type === 'message') {
                $body = trim((string) ($options['body'] ?? ''));
                if ($body === '' || mb_strlen($body) > 2000 || $body !== strip_tags($body)) {
                    throw ValidationException::withMessages([
                        "configuration.pages.{$index}.options.body" => ['Een berichtpagina heeft maximaal 2000 tekens platte tekst nodig.'],
                    ]);
                }
                $options = ['body' => $body];
            } elseif (in_array($type, ['incident_list', 'summary'], true)) {
                $options = ['show_test_incidents' => (bool) ($options['show_test_incidents'] ?? false)];
            } else {
                $options = [];
            }

            $pages[$index] = [
                'id' => $pageId,
                'name' => $name,
                'type' => $type,
                'duration_seconds' => $durationSeconds,
                'options' => $options,
            ];
        }

        $normalized['pages'] = $pages;

        $override = (array) ($normalized['incident_override'] ?? []);
        $overridePageId = (string) ($override['page_id'] ?? '');
        if (! isset($pageIds[$overridePageId])) {
            if (($override['enabled'] ?? false) === true) {
                throw ValidationException::withMessages([
                    'configuration.incident_override.page_id' => ['De incidentpagina moet naar een bestaande wallboardpagina verwijzen.'],
                ]);
            }

            $overridePageId = (string) $pages[0]['id'];
        }
        $normalized['incident_override'] = [
            'enabled' => (bool) ($override['enabled'] ?? false),
            'page_id' => $overridePageId,
        ];

        if (($normalized['map']['show_routes'] ?? false) === true
            && ($normalized['map']['show_live_locations'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'configuration.map.show_routes' => ['Routes vereisen dat live locaties zichtbaar zijn.'],
            ]);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public static function hasPage(array $configuration, string $pageId): bool
    {
        foreach ((array) ($configuration['pages'] ?? []) as $page) {
            if (is_array($page) && (string) ($page['id'] ?? '') === $pageId) {
                return true;
            }
        }

        return false;
    }
}
