<?php

namespace App\Services;

use App\Contracts\SpeechEngineClient;
use App\Models\SpeechModelInstallation;
use App\Repositories\SpeechModelInstallationRepository;
use Illuminate\Validation\ValidationException;

final class SpeechModelCatalog
{
    public function __construct(
        private readonly SpeechModelInstallationRepository $installations,
        private readonly SpeechEngineClient $engine,
    ) {}

    /** @return array<string, mixed> */
    public function model(string $id): array
    {
        $model = config('dis.speech.models.'.$id);
        if (! is_array($model)) {
            throw ValidationException::withMessages(['model' => ['Het gekozen spraakmodel staat niet in de servercatalogus.']]);
        }

        return ['id' => $id] + $model;
    }

    /** @return list<array<string, mixed>> */
    public function summaries(): array
    {
        $latest = $this->installations->latestPerCatalog();

        return collect((array) config('dis.speech.models', []))->map(function (mixed $model, string $id) use ($latest): array {
            $catalog = $this->model($id);
            /** @var SpeechModelInstallation|null $installation */
            $installation = $latest->get($id);
            $installation = $this->reconcile($installation);

            return [
                'id' => $id,
                'name' => (string) $catalog['name'],
                'description' => (string) $catalog['description'],
                'parameter_count' => (int) $catalog['parameter_count'],
                'download_bytes' => max(0, (int) $catalog['download_bytes']),
                'license_spdx' => (string) $catalog['license_spdx'],
                'commercial_use' => (bool) $catalog['commercial_use'],
                'quality_tier' => 'high_end',
                'supported_languages' => array_values((array) $catalog['supported_languages']),
                'capabilities' => (array) $catalog['capabilities'],
                'built_in_voice_available' => $this->builtInVoiceDesignRevision($id) !== null,
                'cpu' => (array) $catalog['cpu'],
                'status' => match ($installation?->status) {
                    'queued', 'downloading', 'processing', 'installing' => 'installing',
                    'installed' => 'installed',
                    'failed' => 'failed',
                    default => 'not_installed',
                },
                'progress_percent' => (int) ($installation?->progress_percent ?? 0),
                'error_code' => $installation?->error_code,
                'installed_revision' => $installation?->status === 'installed' ? $installation->revision : null,
            ];
        })->values()->all();
    }

    private function reconcile(?SpeechModelInstallation $installation): ?SpeechModelInstallation
    {
        if ($installation === null || ! in_array($installation->status, ['installing', 'installed'], true)) {
            return $installation;
        }
        try {
            $status = $this->engine->status((string) $installation->catalog_key);
        } catch (\Throwable) {
            // Engine reachability and installed-state integrity are separate.
            // A temporary daemon restart must not rewrite the durable state.
            return $installation;
        }
        $engineStatus = is_string($status['status'] ?? null) ? $status['status'] : null;
        $engineRevision = $status['installed_revision'] ?? $status['revision'] ?? null;
        $revisionMismatch = $engineStatus === 'installed'
            && (! is_string($engineRevision) || $engineRevision !== $installation->revision);
        $engineChecksum = $status['weights_sha256'] ?? null;
        $checksumMismatch = $engineStatus === 'installed'
            && (! is_string($engineChecksum)
                || ! hash_equals((string) $installation->weights_sha256, $engineChecksum));
        if ($installation->status === 'installing' && in_array($engineStatus, ['installing', 'downloading', 'processing'], true)) {
            $progress = is_numeric($status['progress_percent'] ?? null)
                ? max(0, min(99, (int) $status['progress_percent']))
                : (int) $installation->progress_percent;
            $installation->forceFill(['progress_percent' => $progress])->save();
        } elseif (($installation->status === 'installed'
                && in_array($engineStatus, ['not_installed', 'missing', 'failed'], true))
            || $revisionMismatch || $checksumMismatch
            || ($installation->status === 'installing' && $engineStatus === 'failed')) {
            $installation->forceFill([
                'status' => 'failed',
                'error_code' => match (true) {
                    $revisionMismatch || $checksumMismatch => 'installed_model_integrity_mismatch',
                    $installation->status === 'installing' => 'model_install_failed',
                    default => 'installed_model_missing',
                },
                'failed_at' => now(),
            ])->save();
        }

        return $installation->refresh();
    }

    /** @return array<string, mixed> */
    public function installable(string $id, bool $licenseConfirmed): array
    {
        $model = $this->model($id);
        if (! $licenseConfirmed) {
            throw ValidationException::withMessages(['license_confirmed' => ['Bevestig de commerciële modellicentie voor installatie.']]);
        }
        $revision = trim((string) ($model['revision'] ?? ''));
        $sha256 = strtolower(trim((string) ($model['weights_sha256'] ?? '')));
        if ($revision === '' || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1) {
            throw ValidationException::withMessages(['model' => ['De servercatalogus mist een vastgelegde modelrevision of SHA-256.']]);
        }
        if (($model['commercial_use'] ?? false) !== true) {
            throw ValidationException::withMessages(['model' => ['Dit model is niet voor commercieel gebruik toegestaan.']]);
        }

        return $model + ['revision' => $revision, 'weights_sha256' => $sha256];
    }

    public function builtInVoiceDesignRevision(string $id): ?string
    {
        $model = $this->model($id);
        $revision = trim((string) ($model['built_in_voice_design_revision'] ?? ''));

        return ($model['capabilities']['voice_design'] ?? false) === true && $revision !== '' ? $revision : null;
    }

    public function acceptsVoiceProfile(string $id): bool
    {
        $model = $this->model($id);

        return ($model['capabilities']['voice_clone'] ?? false) === true;
    }
}
