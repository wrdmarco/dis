<?php

namespace App\Services;

use App\Contracts\WallboardContentProvider;
use App\Models\Wallboard;
use App\Models\WallboardContentSnapshot;
use App\Models\WallboardPlaylist;
use App\Repositories\WallboardContentSnapshotRepository;
use App\Repositories\WallboardPlaylistRepository;
use App\Support\WallboardConfiguration;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;
use UnexpectedValueException;

final class WallboardContentSnapshotService
{
    public function __construct(
        private readonly WallboardContentSnapshotRepository $snapshots,
        private readonly WallboardPlaylistRepository $playlists,
        private readonly WallboardPlaylistResolver $playlistResolver,
        private readonly WallboardContentProvider $provider,
    ) {}

    /** @return array{playlists: int, snapshots: int, failures: int} */
    public function refreshAll(): array
    {
        $result = ['playlists' => 0, 'snapshots' => 0, 'failures' => 0];
        $this->playlists->chunkForContentRefresh(function ($playlists) use (&$result): void {
            foreach ($playlists as $playlist) {
                $result['playlists']++;
                foreach (WallboardContentSnapshot::KINDS as $kind) {
                    if ($this->refreshKind((string) $playlist->id, $kind)) {
                        $result['snapshots']++;
                    } else {
                        $result['failures']++;
                    }
                }
            }
        });

        return $result;
    }

    /** @return array{snapshots: int, failures: int} */
    public function refreshPlaylist(WallboardPlaylist $playlist): array
    {
        $result = ['snapshots' => 0, 'failures' => 0];
        foreach (WallboardContentSnapshot::KINDS as $kind) {
            if ($this->refreshKind((string) $playlist->getKey(), $kind)) {
                $result['snapshots']++;
            } else {
                $result['failures']++;
            }
        }

        return $result;
    }

    /** @return array{revision: int, pages: array<string, mixed>, generated_at: string|null} */
    public function news(Wallboard $wallboard): array
    {
        $content = $this->content($wallboard, WallboardContentSnapshot::KIND_NEWS);

        return [
            'revision' => $content['revision'],
            'pages' => $content['payload']['pages'],
            'generated_at' => $content['payload']['generated_at'],
        ];
    }

    /** @return array{revision: int, items: list<array<string, mixed>>} */
    public function ticker(Wallboard $wallboard): array
    {
        $content = $this->content($wallboard, WallboardContentSnapshot::KIND_TICKER);

        return [
            'revision' => $content['revision'],
            'items' => $content['payload']['items'],
        ];
    }

    /** @return array{static: string, news: string, ticker: string} */
    public function contentVersions(Wallboard $wallboard): array
    {
        $configuration = $this->playlistResolver->resolve($wallboard);
        $playlistId = $this->playlistId($wallboard);
        $revisions = collect();
        if ($playlistId !== null) {
            $revisions = $this->snapshots->forPlaylist($playlistId)
                ->keyBy(fn (WallboardContentSnapshot $snapshot): string => (string) $snapshot->kind)
                ->map(fn (WallboardContentSnapshot $snapshot): int => (int) $snapshot->revision);
        }

        return [
            'static' => 's:'.(int) $wallboard->config_version,
            'news' => (int) $revisions->get(WallboardContentSnapshot::KIND_NEWS, 0)
                .':'.$this->configFingerprint($configuration, WallboardContentSnapshot::KIND_NEWS),
            'ticker' => (int) $revisions->get(WallboardContentSnapshot::KIND_TICKER, 0)
                .':'.$this->configFingerprint($configuration, WallboardContentSnapshot::KIND_TICKER),
        ];
    }

    /** @return array{revision: int, payload: array<string, mixed>} */
    private function content(Wallboard $wallboard, string $kind): array
    {
        $configuration = $this->playlistResolver->resolve($wallboard);
        $fingerprint = $this->configFingerprint($configuration, $kind);
        $playlistId = $this->playlistId($wallboard);
        if ($playlistId === null) {
            try {
                return [
                    'revision' => 0,
                    'payload' => $this->buildPayload($configuration, $kind),
                ];
            } catch (Throwable) {
                return ['revision' => 0, 'payload' => $this->emptyPayload($kind)];
            }
        }

        $snapshot = $this->snapshots->find($playlistId, $kind);
        if (! $snapshot instanceof WallboardContentSnapshot
            || ! hash_equals((string) $snapshot->config_fingerprint, $fingerprint)) {
            $this->refreshKind($playlistId, $kind);
            $snapshot = $this->snapshots->find($playlistId, $kind);
        }

        if (! $snapshot instanceof WallboardContentSnapshot) {
            return ['revision' => 0, 'payload' => $this->emptyPayload($kind)];
        }

        try {
            return [
                'revision' => (int) $snapshot->revision,
                'payload' => $this->validatedPayload((array) $snapshot->payload, $kind),
            ];
        } catch (Throwable) {
            return ['revision' => 0, 'payload' => $this->emptyPayload($kind)];
        }
    }

    private function refreshKind(string $playlistId, string $kind): bool
    {
        $this->assertKind($kind);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $playlist = $this->playlists->findForContentRefresh($playlistId);
            if (! $playlist instanceof WallboardPlaylist) {
                return false;
            }

            $configuration = WallboardConfiguration::normalize((array) $playlist->configuration);
            $fingerprint = $this->configFingerprint($configuration, $kind);
            try {
                $payload = $this->buildPayload($configuration, $kind);
            } catch (Throwable) {
                try {
                    $this->snapshots->markChecked($playlistId, $kind, CarbonImmutable::now());
                } catch (Throwable) {
                    // The caller receives a generic failure; configured feed URLs
                    // and transport details are deliberately never logged here.
                }

                return false;
            }

            try {
                $stored = DB::transaction(function () use (
                    $playlistId,
                    $kind,
                    $fingerprint,
                    $payload,
                ): ?bool {
                    $lockedPlaylist = $this->playlists->lockPlaylist($playlistId);
                    $currentConfiguration = WallboardConfiguration::normalize(
                        (array) $lockedPlaylist->configuration,
                    );
                    if (! hash_equals(
                        $this->configFingerprint($currentConfiguration, $kind),
                        $fingerprint,
                    )) {
                        return null;
                    }

                    $now = CarbonImmutable::now();
                    $snapshot = $this->snapshots->lock($playlistId, $kind);
                    if (! $snapshot instanceof WallboardContentSnapshot) {
                        $this->snapshots->insert(
                            $playlistId,
                            $kind,
                            $fingerprint,
                            1,
                            $payload,
                            $now,
                            $now,
                        );

                        return true;
                    }

                    $changes = [
                        'config_fingerprint' => $fingerprint,
                        'checked_at' => $now,
                    ];
                    if (! hash_equals(
                        $this->comparablePayloadHash((array) $snapshot->payload, $kind),
                        $this->comparablePayloadHash($payload, $kind),
                    )) {
                        $changes['revision'] = $this->nextRevision((int) $snapshot->revision);
                        $changes['payload'] = $payload;
                        $changes['updated_at'] = $now;
                    }
                    $this->snapshots->update($playlistId, $kind, $changes);

                    return true;
                }, 3);
            } catch (Throwable) {
                return false;
            }

            if ($stored !== null) {
                return $stored;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    private function buildPayload(array $configuration, string $kind): array
    {
        $payload = match ($kind) {
            WallboardContentSnapshot::KIND_NEWS => $this->provider->news(
                array_values((array) ($configuration['pages'] ?? [])),
            ),
            WallboardContentSnapshot::KIND_TICKER => $this->provider->ticker(
                (array) ($configuration['ticker'] ?? []),
            ),
            default => throw new UnexpectedValueException('Unsupported wallboard content kind.'),
        };

        return $this->validatedPayload($payload, $kind);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatedPayload(array $payload, string $kind): array
    {
        if ($kind === WallboardContentSnapshot::KIND_NEWS) {
            if (! is_array($payload['pages'] ?? null)
                || ! is_string($payload['generated_at'] ?? null)
                || trim($payload['generated_at']) === '') {
                throw new UnexpectedValueException('Invalid wallboard news payload.');
            }

            return [
                'pages' => $payload['pages'],
                'generated_at' => $payload['generated_at'],
            ];
        }

        if ($kind === WallboardContentSnapshot::KIND_TICKER) {
            $items = $payload['items'] ?? null;
            if (! is_array($items)
                || ! array_is_list($items)
                || collect($items)->contains(fn (mixed $item): bool => ! is_array($item))) {
                throw new UnexpectedValueException('Invalid wallboard ticker payload.');
            }

            return ['items' => $items];
        }

        throw new UnexpectedValueException('Unsupported wallboard content kind.');
    }

    /** @return array<string, mixed> */
    private function emptyPayload(string $kind): array
    {
        return match ($kind) {
            WallboardContentSnapshot::KIND_NEWS => ['pages' => [], 'generated_at' => null],
            WallboardContentSnapshot::KIND_TICKER => ['items' => []],
            default => throw new UnexpectedValueException('Unsupported wallboard content kind.'),
        };
    }

    /** @param array<string, mixed> $configuration */
    private function configFingerprint(array $configuration, string $kind): string
    {
        $relevantConfiguration = match ($kind) {
            WallboardContentSnapshot::KIND_NEWS => collect((array) ($configuration['pages'] ?? []))
                ->filter(fn (mixed $page): bool => is_array($page) && ($page['type'] ?? null) === 'news')
                ->values()
                ->all(),
            WallboardContentSnapshot::KIND_TICKER => (array) ($configuration['ticker'] ?? []),
            default => throw new UnexpectedValueException('Unsupported wallboard content kind.'),
        };

        return $this->hash($relevantConfiguration);
    }

    /** @param array<string, mixed> $payload */
    private function comparablePayloadHash(array $payload, string $kind): string
    {
        $validated = $this->validatedPayload($payload, $kind);
        if ($kind === WallboardContentSnapshot::KIND_NEWS) {
            unset($validated['generated_at']);
        }

        return $this->hash($validated);
    }

    private function hash(mixed $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new UnexpectedValueException('Wallboard content cannot be fingerprinted.', 0, $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function nextRevision(int $revision): int
    {
        if ($revision < 0 || $revision === PHP_INT_MAX) {
            throw new UnexpectedValueException('Wallboard content revision is invalid.');
        }

        return $revision + 1;
    }

    private function playlistId(Wallboard $wallboard): ?string
    {
        return is_string($wallboard->playlist_id) && $wallboard->playlist_id !== ''
            ? $wallboard->playlist_id
            : null;
    }

    private function assertKind(string $kind): void
    {
        if (! in_array($kind, WallboardContentSnapshot::KINDS, true)) {
            throw new UnexpectedValueException('Unsupported wallboard content kind.');
        }
    }
}
