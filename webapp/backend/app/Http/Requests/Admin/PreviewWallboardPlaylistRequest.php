<?php

namespace App\Http\Requests\Admin;

use App\Models\WallboardPlaylist;
use App\Support\WallboardConfiguration;

final class PreviewWallboardPlaylistRequest extends WallboardPlaylistRequest
{
    private const DEMO_QUOTE = [
        'text' => 'Goede voorbereiding geeft elke vlucht een veilige start.',
        'author' => 'DIS DEMO',
    ];

    private const DEMO_FORECAST_LOCATION = 'Demolocatie (fictief)';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'data_mode' => $this->dataModeRules(),
            ...$this->configurationRules('required'),
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isDemoPreview()) {
            return;
        }

        $configuration = $this->input('configuration');
        if (! is_array($configuration) || ! is_array($configuration['pages'] ?? null)) {
            return;
        }

        foreach ($configuration['pages'] as &$page) {
            if (! is_array($page)
                || (array_key_exists('options', $page) && ! is_array($page['options']))) {
                continue;
            }

            $options = (array) ($page['options'] ?? []);
            if (($page['type'] ?? null) === 'quote' && $this->containsOnlyEmptyQuoteDrafts($options['quotes'] ?? null)) {
                $options['quotes'] = [self::DEMO_QUOTE];
            }

            if (($page['type'] ?? null) === 'news'
                && ($options['sources'] ?? null) === []
                && $this->optionIsMissingOrEmptyList($options, 'custom_sources')) {
                // Demo news never reads these sources, but the shared canonical
                // configuration still requires one safe source before fixtures run.
                $options['sources'] = [WallboardConfiguration::NEWS_SOURCES[0]];
                $options['custom_sources'] = [];
            }

            if (($page['type'] ?? null) === 'uav_forecast') {
                $locationMode = $options['location_mode'] ?? (
                    array_key_exists('location_label', $options) ? 'address' : null
                );
                $locationLabel = $options['location_label'] ?? null;
                if ($locationMode === 'address'
                    && (! array_key_exists('location_label', $options)
                        || $locationLabel === null
                        || (is_string($locationLabel) && trim($locationLabel) === ''))) {
                    $options['location_label'] = self::DEMO_FORECAST_LOCATION;
                }
            }

            $page['options'] = $options;
        }
        unset($page);

        if (is_array($configuration['ticker'] ?? null)) {
            // The demo ticker is supplied by WallboardDemoStateService. Discard
            // unfinished live source drafts so they cannot block that fixture.
            $configuration['ticker']['sources'] = [];
        }

        $this->merge(['configuration' => $configuration]);
    }

    private function isDemoPreview(): bool
    {
        if ($this->exists('data_mode')) {
            return $this->input('data_mode') === WallboardPlaylist::DATA_MODE_DEMO;
        }

        $playlist = $this->route('wallboardPlaylist');

        return $playlist instanceof WallboardPlaylist && $playlist->isDemo();
    }

    private function containsOnlyEmptyQuoteDrafts(mixed $quotes): bool
    {
        if ($quotes === null || $quotes === []) {
            return true;
        }
        if (! is_array($quotes) || ! array_is_list($quotes)) {
            return false;
        }

        foreach ($quotes as $quote) {
            if (! is_array($quote) || array_diff(array_keys($quote), ['text', 'author']) !== []) {
                return false;
            }

            $text = $quote['text'] ?? null;
            $author = $quote['author'] ?? null;
            if (($text !== null && ! is_string($text))
                || ($author !== null && ! is_string($author))
                || (is_string($text) && trim($text) !== '')
                || (is_string($author) && trim($author) !== '')) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $options */
    private function optionIsMissingOrEmptyList(array $options, string $key): bool
    {
        return ! array_key_exists($key, $options) || $options[$key] === [];
    }
}
