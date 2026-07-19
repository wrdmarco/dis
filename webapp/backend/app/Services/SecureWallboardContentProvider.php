<?php

namespace App\Services;

use App\Contracts\WallboardContentProvider;

final class SecureWallboardContentProvider implements WallboardContentProvider
{
    public function __construct(
        private readonly WallboardNewsService $newsService,
        private readonly WallboardTickerService $tickerService,
    ) {}

    public function news(array $pages): array
    {
        return $this->newsService->pages($pages);
    }

    public function ticker(array $configuration): array
    {
        return ['items' => $this->tickerService->items($configuration)];
    }
}
