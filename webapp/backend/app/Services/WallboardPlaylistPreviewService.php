<?php

namespace App\Services;

use App\Models\Wallboard;
use App\Models\WallboardPlaylist;
use App\Support\ApiDateTime;
use App\Support\WallboardConfiguration;

final class WallboardPlaylistPreviewService
{
    public function __construct(
        private readonly WallboardStateService $stateService,
        private readonly WallboardContentSnapshotService $contentSnapshots,
        private readonly WallboardMediaStateService $mediaStateService,
        private readonly WallboardForecastService $forecastService,
        private readonly WallboardDemoStateService $demoStateService,
    ) {}

    /**
     * @param  array<string, mixed>  $conceptConfiguration
     * @return array<string, mixed>
     */
    public function state(
        WallboardPlaylist $playlist,
        array $conceptConfiguration,
        ?string $dataMode = null,
    ): array {
        $dataMode = in_array($dataMode, WallboardPlaylist::DATA_MODES, true)
            ? $dataMode
            : (in_array($playlist->data_mode, WallboardPlaylist::DATA_MODES, true)
                ? (string) $playlist->data_mode
                : WallboardPlaylist::DATA_MODE_LIVE);
        $configuration = WallboardConfiguration::normalize(
            $conceptConfiguration,
            (array) $playlist->configuration,
        );
        $media = $this->mediaStateService->preview($configuration);
        $configuration = $media['configuration'];
        $wallboard = $this->previewWallboard($playlist, $configuration);
        $isDemo = $dataMode === WallboardPlaylist::DATA_MODE_DEMO;
        $runtime = $isDemo
            ? $this->demoStateService->runtime($wallboard, $configuration, includeMaintenance: false)
            : $this->stateService->previewRuntime($wallboard, $configuration);
        $news = $isDemo
            ? $this->demoStateService->news($configuration, (int) $playlist->version)
            : $this->contentSnapshots->news($wallboard, $configuration, null);
        $ticker = $isDemo
            ? $this->demoStateService->ticker($configuration, (int) $playlist->version)
            : $this->contentSnapshots->ticker($wallboard, $configuration, null);

        return [
            'generated_at' => $runtime['generated_at'],
            'maintenance' => null,
            'wallboard' => [
                'id' => (string) $playlist->id,
                'name' => (string) $playlist->name,
                'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
                'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
                'data_mode' => $dataMode,
                'configuration' => $configuration,
                'config_version' => (int) $playlist->version,
                'control_version' => 0,
                'refresh_version' => 0,
                'runtime_playlist_id' => (string) $playlist->id,
                'runtime_playlist_version' => (int) $playlist->version,
                'active_incident_playlist' => false,
                'display' => $runtime['display'],
                'updated_at' => ApiDateTime::dateTime($playlist->updated_at),
            ],
            'operational_summary' => $runtime['operational_summary'],
            'kpi' => $runtime['kpi'],
            'ticker' => ['items' => $ticker['items']],
            'news' => [
                'pages' => $this->adminNewsImagePages($news['pages']),
                'generated_at' => $news['generated_at'] ?? $runtime['generated_at'],
            ],
            'media' => ['photo_pages' => $media['photo_pages']],
            'forecast' => ['pages' => $isDemo
                ? $this->demoStateService->forecast($configuration)
                : $this->forecastService->pages($configuration)],
            'calendar' => $runtime['calendar'],
            'map' => $runtime['map'],
        ];
    }

    /** @param array<string, mixed> $configuration */
    private function previewWallboard(WallboardPlaylist $playlist, array $configuration): Wallboard
    {
        $wallboard = new Wallboard;
        $wallboard->forceFill([
            'id' => (string) $playlist->id,
            'name' => (string) $playlist->name,
            'layout' => Wallboard::LAYOUT_FULLSCREEN_MAP,
            'display_profile' => Wallboard::DISPLAY_PROFILE_AUTO,
            'configuration' => $configuration,
            'config_version' => (int) $playlist->version,
            'control_version' => 0,
            'refresh_version' => 0,
            'manual_page_id' => null,
            'rotation_started_at' => now(),
            'is_enabled' => true,
            'created_at' => $playlist->created_at ?? now(),
            'updated_at' => $playlist->updated_at ?? now(),
        ]);

        return $wallboard;
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    private function adminNewsImagePages(array $pages): array
    {
        foreach ($pages as &$page) {
            if (! is_array($page) || ! is_array($page['items'] ?? null)) {
                continue;
            }
            foreach ($page['items'] as &$item) {
                if (! is_array($item)) {
                    continue;
                }
                $imageUrl = $item['image_url'] ?? null;
                $item['image_url'] = is_string($imageUrl)
                    && preg_match('#^/api/wallboard/news-images/([a-f0-9]{64})$#D', $imageUrl, $matches) === 1
                        ? '/api/admin/wallboard-news-images/'.$matches[1]
                        : null;
            }
            unset($item);
        }
        unset($page);

        return $pages;
    }
}
