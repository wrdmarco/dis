<?php

namespace Tests\Support;

use App\Contracts\WallboardContentProvider;
use RuntimeException;

final class MutableWallboardContentProvider implements WallboardContentProvider
{
    public bool $failNews = false;

    public bool $failTicker = false;

    /** @var array{pages: array<string, mixed>, generated_at: string} */
    public array $newsPayload = [
        'pages' => [
            'news' => [
                'items' => [[
                    'id' => 'news-1',
                    'source' => 'ndt',
                    'source_label' => 'Nationaal Drone Team',
                    'title' => 'Veilig nieuws',
                    'excerpt' => 'Korte veilige samenvatting.',
                    'url' => 'https://nationaaldroneteam.nl/nieuws/veilig',
                    'image_url' => null,
                    'published_at' => '2026-07-19T08:00:00+00:00',
                ]],
                'fallback_used' => false,
                'lookback_days' => 7,
            ],
        ],
        'generated_at' => '2026-07-19T10:00:00+00:00',
    ];

    /** @var array{items: list<array<string, mixed>>} */
    public array $tickerPayload = [
        'items' => [[
            'source_id' => 'intern',
            'source_type' => 'internal',
            'source_label' => 'Melding',
            'text' => 'Operationeel bericht',
        ]],
    ];

    public function news(array $pages): array
    {
        unset($pages);
        if ($this->failNews) {
            throw new RuntimeException('Synthetic news failure.');
        }

        return $this->newsPayload;
    }

    public function ticker(array $configuration): array
    {
        unset($configuration);
        if ($this->failTicker) {
            throw new RuntimeException('Synthetic ticker failure.');
        }

        return $this->tickerPayload;
    }
}
