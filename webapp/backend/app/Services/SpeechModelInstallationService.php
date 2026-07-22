<?php

namespace App\Services;

use App\Jobs\InstallSpeechModel;
use App\Models\SpeechModelInstallation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpeechModelInstallationService
{
    public function __construct(
        private readonly SpeechModelCatalog $catalog,
        private readonly AuditService $audit,
        private readonly SpeechRuntimeActivityGate $runtime,
    ) {}

    public function start(string $modelId, bool $licenseConfirmed, User $actor): SpeechModelInstallation
    {
        $catalog = $this->catalog->installable($modelId, $licenseConfirmed);
        $installation = DB::transaction(function () use ($modelId, $catalog, $actor): SpeechModelInstallation {
            $active = $this->runtime->lockForInstallationRequest();
            if ($active !== null) {
                if ($active->catalog_key === $modelId
                    && $active->revision === $catalog['revision']
                    && hash_equals((string) $active->weights_sha256, (string) $catalog['weights_sha256'])) {
                    DB::afterCommit(fn () => InstallSpeechModel::dispatch((string) $active->id));

                    return $active;
                }

                throw ValidationException::withMessages([
                    'model' => ['Er wordt al een ander spraakmodel geïnstalleerd.'],
                ]);
            }
            $installation = SpeechModelInstallation::query()
                ->where('catalog_key', $modelId)
                ->where('revision', $catalog['revision'])
                ->where('weights_sha256', $catalog['weights_sha256'])
                ->lockForUpdate()->first();
            if ($installation?->status === 'installed') {
                return $installation;
            }
            if ($installation === null) {
                $installation = SpeechModelInstallation::query()->create([
                    'catalog_key' => $modelId,
                    'revision' => $catalog['revision'],
                    'weights_sha256' => $catalog['weights_sha256'],
                    'status' => 'installing',
                    'progress_percent' => 0,
                    'requested_by' => $actor->id,
                    'license_confirmed_at' => now(),
                ]);
            } else {
                $installation->forceFill([
                    'status' => 'installing', 'progress_percent' => 0, 'error_code' => null,
                    'requested_by' => $actor->id, 'license_confirmed_at' => now(),
                    'failed_at' => null, 'installed_at' => null,
                ])->save();
            }
            $this->runtime->reserve($installation);
            $this->audit->record('speech.model_install_requested', $installation, $actor, [
                'catalog_key' => $modelId,
                'revision' => $catalog['revision'],
                'weights_sha256_prefix' => substr((string) $catalog['weights_sha256'], 0, 12),
                'license_spdx' => $catalog['license_spdx'],
            ]);
            DB::afterCommit(fn () => InstallSpeechModel::dispatch((string) $installation->id));

            return $installation;
        });

        return $installation->refresh();
    }
}
