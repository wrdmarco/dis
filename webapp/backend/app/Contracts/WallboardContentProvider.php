<?php

namespace App\Contracts;

interface WallboardContentProvider
{
    /**
     * @param  array<int, mixed>  $pages
     * @return array{pages: array<string, mixed>, generated_at: string}
     */
    public function news(array $pages): array;

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{items: list<array<string, mixed>>}
     */
    public function ticker(array $configuration): array;
}
