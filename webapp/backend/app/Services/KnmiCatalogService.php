<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class KnmiCatalogService
{
    private const SEARCH_ENDPOINT = 'https://dataplatform.knmi.nl/api/3/action/package_search';

    private const CATALOG_URL = 'https://dataplatform.knmi.nl/';

    private const DATASET_URL_PREFIX = 'https://dataplatform.knmi.nl/dataset/';

    private const CACHE_PREFIX = 'weather:knmi:catalog:v1:';

    private const FRESH_TTL_SECONDS = 900;

    private const STALE_TTL_SECONDS = 604800;

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array<string, int|null>,
     *     filters: array<string, list<array<string, mixed>>>,
     *     catalog: array<string, bool|string|null>
     * }
     */
    public function search(
        ?string $query = null,
        int $page = 1,
        int $perPage = 20,
        ?string $status = null,
        ?string $license = null,
    ): array {
        $query = $this->nullableTrimmed($query);
        if ($query !== null) {
            $query = preg_replace('/[\x00-\x1F\x7F]/u', '', Str::limit($query, 120, ''));
            $query = $this->nullableTrimmed($query);
        }
        $page = max(1, min(1000, $page));
        $perPage = max(1, min(50, $perPage));
        $status = $this->nullableTrimmed($status);
        $status = in_array($status, ['ongoing', 'completed'], true) ? $status : null;
        $license = $this->nullableTrimmed($license);
        $license = $license !== null
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/', $license) === 1
                ? $license
                : null;
        $identity = hash('sha256', json_encode([
            'query' => $query,
            'page' => $page,
            'per_page' => $perPage,
            'status' => $status,
            'license' => $license,
        ], JSON_THROW_ON_ERROR));
        $freshKey = self::CACHE_PREFIX.'fresh:'.$identity;
        $staleKey = self::CACHE_PREFIX.'stale:'.$identity;

        $fresh = $this->cacheGet($freshKey);
        if ($this->isCatalogResponse($fresh)) {
            return $fresh;
        }

        try {
            $catalog = $this->retrieve(
                query: $query,
                page: $page,
                perPage: $perPage,
                status: $status,
                license: $license,
            );
            $this->cachePut($freshKey, $catalog, self::FRESH_TTL_SECONDS);
            $this->cachePut($staleKey, $catalog, self::STALE_TTL_SECONDS);

            return $catalog;
        } catch (Throwable $exception) {
            Log::warning('KNMI catalog retrieval failed.', [
                'exception_class' => $exception::class,
            ]);
        }

        $stale = $this->cacheGet($staleKey);
        if ($this->isCatalogResponse($stale)) {
            $stale['catalog']['cache_state'] = 'stale';
            $stale['catalog']['warning'] = 'De live KNMI-catalogus is tijdelijk niet bereikbaar. De laatst bekende catalogusgegevens worden getoond.';

            return $stale;
        }

        return $this->unavailable(
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array<string, int|null>,
     *     filters: array<string, list<array<string, mixed>>>,
     *     catalog: array<string, bool|string|null>
     * }
     */
    private function retrieve(
        ?string $query,
        int $page,
        int $perPage,
        ?string $status,
        ?string $license,
    ): array {
        $offset = ($page - 1) * $perPage;
        $parameters = [
            'rows' => $perPage,
            'start' => $offset,
            'fq' => $this->filterQuery($status, $license),
            'facet.field' => json_encode(['license_id'], JSON_THROW_ON_ERROR),
            'facet.limit' => 50,
            'facet.mincount' => 1,
        ];
        if ($query !== null) {
            $parameters['q'] = $query;
        }

        $response = Http::acceptJson()
            ->connectTimeout(2)
            ->timeout(5)
            ->get(self::SEARCH_ENDPOINT, $parameters);

        if (! $response->successful()) {
            throw new \RuntimeException('KNMI catalog returned an unsuccessful response.');
        }

        $payload = $response->json();
        if (! is_array($payload) || ($payload['success'] ?? false) !== true) {
            throw new \RuntimeException('KNMI catalog returned an invalid response.');
        }
        $result = $payload['result'] ?? null;
        if (! is_array($result) || ! is_array($result['results'] ?? null)) {
            throw new \RuntimeException('KNMI catalog response has no result collection.');
        }

        $items = [];
        foreach ($result['results'] as $dataset) {
            if (! is_array($dataset)) {
                continue;
            }
            $item = $this->compactDataset($dataset);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        $total = max(0, is_numeric($result['count'] ?? null) ? (int) $result['count'] : 0);
        $count = count($items);

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'from' => $count === 0 ? null : $offset + 1,
                'to' => $count === 0 ? null : $offset + $count,
            ],
            'filters' => [
                'statuses' => $this->statusOptions(),
                'licenses' => $this->licenseOptions($result),
            ],
            'catalog' => [
                'available' => true,
                'cache_state' => 'fresh',
                'fetched_at' => CarbonImmutable::now('UTC')->format(DateTimeInterface::ATOM),
                'source_url' => self::CATALOG_URL,
                'warning' => null,
            ],
        ];
    }

    private function filterQuery(?string $status, ?string $license): string
    {
        $filters = ['organization:knmi'];

        if ($status === 'ongoing') {
            $filters[] = 'extras_Status:onGoing';
        } elseif ($status === 'completed') {
            $filters[] = 'extras_Status:completed';
        }
        if ($license !== null) {
            $filters[] = sprintf('license_id:"%s"', $license);
        }

        return implode(' AND ', $filters);
    }

    /** @return array<string, mixed>|null */
    private function compactDataset(array $dataset): ?array
    {
        $key = $this->nullableTrimmed($dataset['name'] ?? null);
        if ($key === null || preg_match('/\A[a-z0-9][a-z0-9_-]{0,199}\z/', $key) !== 1) {
            return null;
        }
        $extras = $this->extras($dataset['extras'] ?? null);
        $title = $this->nullableTrimmed($dataset['title'] ?? null)
            ?? $this->nullableTrimmed($extras['Dataset name'] ?? null)
            ?? $key;
        $datasetName = $this->nullableTrimmed($extras['Dataset name'] ?? null) ?? $key;
        $version = $this->nullableTrimmed($extras['Dataset version'] ?? null)
            ?? $this->nullableTrimmed($dataset['version'] ?? null);
        $licenseId = $this->nullableTrimmed($dataset['license_id'] ?? null);
        $licenseTitle = $this->nullableTrimmed($dataset['license_title'] ?? null) ?? $licenseId;

        return [
            'key' => $key,
            'title' => Str::limit($title, 180, '…'),
            'dataset' => Str::limit($datasetName, 180, '…'),
            'version' => $version === null ? null : Str::limit($version, 40, '…'),
            'description' => $this->description($dataset['notes'] ?? null),
            'status' => $this->datasetStatus($extras['Status'] ?? null),
            'license_id' => $licenseId,
            'license_title' => $licenseTitle === null ? null : Str::limit($licenseTitle, 120, '…'),
            'is_open' => ($dataset['isopen'] ?? false) === true,
            'formats' => $this->formats($extras['File formats'] ?? null, $dataset['resources'] ?? null),
            'topics' => $this->topics($dataset['groups'] ?? null),
            'publication_at' => $this->timestamp($extras['Publication timestamp'] ?? null),
            'metadata_updated_at' => $this->timestamp($dataset['metadata_modified'] ?? null),
            'source_url' => self::DATASET_URL_PREFIX.$key,
        ];
    }

    /** @return array<string, string> */
    private function extras(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $extras = [];
        foreach ($value as $extra) {
            if (! is_array($extra)) {
                continue;
            }
            $key = $this->nullableTrimmed($extra['key'] ?? null);
            $extraValue = $this->nullableTrimmed($extra['value'] ?? null);
            if ($key !== null && $extraValue !== null) {
                $extras[$key] = $extraValue;
            }
        }

        return $extras;
    }

    private function description(mixed $value): ?string
    {
        $description = $this->nullableTrimmed($value);
        if ($description === null) {
            return null;
        }
        $description = html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $description = preg_replace('/\s+/u', ' ', $description);

        return is_string($description) && trim($description) !== ''
            ? Str::limit(trim($description), 360, '…')
            : null;
    }

    private function datasetStatus(mixed $value): string
    {
        $status = strtolower((string) preg_replace('/[^a-z]/i', '', (string) $value));

        return match ($status) {
            'ongoing' => 'ongoing',
            'completed' => 'completed',
            default => 'unknown',
        };
    }

    /** @return list<string> */
    private function formats(mixed $extraValue, mixed $resources): array
    {
        $formats = [];
        if (is_string($extraValue)) {
            $decoded = json_decode($extraValue, true);
            if (is_array($decoded)) {
                $formats = $decoded;
            } elseif (trim($extraValue) !== '') {
                $formats = preg_split('/\s*,\s*/', trim($extraValue)) ?: [];
            }
        }
        if ($formats === [] && is_array($resources)) {
            foreach ($resources as $resource) {
                if (is_array($resource)) {
                    $formats[] = $resource['format'] ?? null;
                }
            }
        }

        return $this->compactStringList($formats, 8, 40);
    }

    /** @return list<string> */
    private function topics(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        return $this->compactStringList(
            array_map(
                static fn (mixed $group): mixed => is_array($group)
                    ? ($group['display_name'] ?? $group['title'] ?? null)
                    : null,
                $groups,
            ),
            8,
            80,
        );
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function compactStringList(array $values, int $limit, int $maximumLength): array
    {
        $result = [];
        foreach ($values as $value) {
            $item = $this->nullableTrimmed($value);
            if ($item === null) {
                continue;
            }
            $item = Str::limit($item, $maximumLength, '…');
            $result[strtolower($item)] = $item;
            if (count($result) >= $limit) {
                break;
            }
        }

        return array_values($result);
    }

    private function timestamp(mixed $value): ?string
    {
        $timestamp = $this->nullableTrimmed($value);
        if ($timestamp === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp, 'UTC')->utc()->format(DateTimeInterface::ATOM);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{value: string, label: string}> */
    private function statusOptions(): array
    {
        return [
            ['value' => 'ongoing', 'label' => 'Actueel/lopend'],
            ['value' => 'completed', 'label' => 'Afgerond/archief'],
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{value: string, label: string, count: int}>
     */
    private function licenseOptions(array $result): array
    {
        $items = $result['search_facets']['license_id']['items'] ?? null;
        if (! is_array($items)) {
            return [];
        }

        $licenses = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $value = $this->nullableTrimmed($item['name'] ?? null);
            if ($value === null || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.+_-]{0,79}\z/', $value) !== 1) {
                continue;
            }
            $label = $this->nullableTrimmed($item['display_name'] ?? null) ?? $value;
            $licenses[] = [
                'value' => $value,
                'label' => Str::limit($label, 120, '…'),
                'count' => max(0, is_numeric($item['count'] ?? null) ? (int) $item['count'] : 0),
            ];
        }

        return $licenses;
    }

    /**
     * @return array{
     *     items: list<array<string, mixed>>,
     *     pagination: array<string, int|null>,
     *     filters: array<string, list<array<string, mixed>>>,
     *     catalog: array<string, bool|string|null>
     * }
     */
    private function unavailable(
        int $page,
        int $perPage,
    ): array {
        return [
            'items' => [],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
                'from' => null,
                'to' => null,
            ],
            'filters' => [
                'statuses' => $this->statusOptions(),
                'licenses' => [],
            ],
            'catalog' => [
                'available' => false,
                'cache_state' => 'unavailable',
                'fetched_at' => null,
                'source_url' => self::CATALOG_URL,
                'warning' => 'De KNMI-catalogus is tijdelijk niet bereikbaar. De operationele databronnen blijven afzonderlijk beschikbaar.',
            ],
        ];
    }

    private function nullableTrimmed(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function cacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable $exception) {
            Log::warning('KNMI catalog cache read failed.', [
                'exception_class' => $exception::class,
            ]);

            return null;
        }
    }

    /** @param array<string, mixed> $value */
    private function cachePut(string $key, array $value, int $ttlSeconds): void
    {
        try {
            Cache::put($key, $value, now()->addSeconds($ttlSeconds));
        } catch (Throwable $exception) {
            Log::warning('KNMI catalog cache write failed.', [
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function isCatalogResponse(mixed $value): bool
    {
        return is_array($value)
            && is_array($value['items'] ?? null)
            && is_array($value['pagination'] ?? null)
            && is_array($value['filters'] ?? null)
            && is_array($value['catalog'] ?? null);
    }
}
