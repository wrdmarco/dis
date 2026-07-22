<?php

namespace App\Repositories;

use App\Models\SpeechModelInstallation;
use Illuminate\Database\Eloquent\Collection;

/** @extends BaseRepository<SpeechModelInstallation> */
final class SpeechModelInstallationRepository extends BaseRepository
{
    protected function modelClass(): string
    {
        return SpeechModelInstallation::class;
    }

    /** @return Collection<int, SpeechModelInstallation> */
    public function latestPerCatalog(): Collection
    {
        return SpeechModelInstallation::query()
            ->whereIn('id', SpeechModelInstallation::query()
                ->selectRaw('MAX(id)')
                ->groupBy('catalog_key'))
            ->get()
            ->keyBy('catalog_key');
    }

    public function installed(string $id): ?SpeechModelInstallation
    {
        return SpeechModelInstallation::query()->whereKey($id)->where('status', 'installed')->first();
    }

    public function installedForCatalog(string $catalogKey): ?SpeechModelInstallation
    {
        return SpeechModelInstallation::query()
            ->where('catalog_key', $catalogKey)
            ->where('status', 'installed')
            ->latest('installed_at')
            ->first();
    }
}
