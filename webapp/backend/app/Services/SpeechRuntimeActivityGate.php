<?php

namespace App\Services;

use App\Contracts\SpeechEngineClient;
use App\Exceptions\SpeechEngineException;
use App\Jobs\RequeueSpeechPreparedPhrases;
use App\Models\DispatchRequest;
use App\Models\Incident;
use App\Models\SpeechModelInstallation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SpeechRuntimeActivityGate
{
    private const STATE_ID = 1;

    private const OPERATIONAL_INCIDENT_STATUSES = ['active', 'dispatching', 'in_progress'];

    public function __construct(private readonly SpeechEngineClient $engine) {}

    /**
     * Locks the singleton runtime row for an installation request.
     *
     * @return SpeechModelInstallation|null An already-running installation.
     */
    public function lockForInstallationRequest(): ?SpeechModelInstallation
    {
        $this->assertTransaction();
        $state = $this->lockedState();
        if ($this->hasRealOperationalActivity()) {
            throw ValidationException::withMessages([
                'model' => ['Een spraakmodel kan niet worden geïnstalleerd zolang een echt incident actief is.'],
            ]);
        }
        if ($state->active_installation_id === null) {
            return null;
        }

        $installation = SpeechModelInstallation::query()
            ->whereKey($state->active_installation_id)
            ->lockForUpdate()
            ->first();
        if ($installation !== null && $installation->status === 'installing') {
            return $installation;
        }
        if ($state->install_started_at !== null) {
            throw ValidationException::withMessages([
                'model' => ['De vorige modelinstallatie wordt nog veilig beëindigd.'],
            ]);
        }

        $this->clearLockedState();

        return null;
    }

    public function reserve(SpeechModelInstallation $installation): void
    {
        $this->assertTransaction();
        DB::table('speech_runtime_states')->where('id', self::STATE_ID)->update([
            'active_installation_id' => $installation->id,
            'active_model_id' => $installation->catalog_key,
            'install_claim_token' => null,
            'install_started_at' => null,
            'install_cancel_requested_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function claim(string $installationId, string $claimToken): bool
    {
        return DB::transaction(function () use ($installationId, $claimToken): bool {
            $state = $this->lockedState();
            $installation = SpeechModelInstallation::query()->whereKey($installationId)->lockForUpdate()->first();
            if ($installation === null || $installation->status !== 'installing'
                || (string) $state->active_installation_id !== $installationId
                || $state->install_claim_token !== null) {
                return false;
            }
            if ($this->hasRealOperationalActivity()) {
                $installation->forceFill([
                    'status' => 'failed',
                    'error_code' => 'model_install_blocked_active_incident',
                    'failed_at' => now(),
                ])->save();
                $this->clearLockedState();

                return false;
            }

            DB::table('speech_runtime_states')->where('id', self::STATE_ID)->update([
                'install_claim_token' => $claimToken,
                'install_started_at' => now(),
                'updated_at' => now(),
            ]);
            $installation->forceFill(['progress_percent' => 5, 'error_code' => null])->save();

            return true;
        });
    }

    public function claimIsActive(string $installationId, string $claimToken): bool
    {
        return DB::table('speech_runtime_states')
            ->where('id', self::STATE_ID)
            ->where('active_installation_id', $installationId)
            ->where('install_claim_token', $claimToken)
            ->whereNull('install_cancel_requested_at')
            ->exists()
            && SpeechModelInstallation::query()->whereKey($installationId)->where('status', 'installing')->exists();
    }

    public function progress(string $installationId, string $claimToken, int $percent): void
    {
        if (! $this->claimIsActive($installationId, $claimToken)) {
            return;
        }
        SpeechModelInstallation::query()->whereKey($installationId)->where('status', 'installing')->update([
            'progress_percent' => max(0, min(99, $percent)),
            'updated_at' => now(),
        ]);
    }

    public function complete(string $installationId, string $claimToken): bool
    {
        return DB::transaction(function () use ($installationId, $claimToken): bool {
            $state = $this->lockedState();
            $installation = SpeechModelInstallation::query()->whereKey($installationId)->lockForUpdate()->first();
            $matches = $installation !== null
                && $installation->status === 'installing'
                && (string) $state->active_installation_id === $installationId
                && hash_equals((string) $state->install_claim_token, $claimToken)
                && $state->install_cancel_requested_at === null;
            if ($matches) {
                $installation->forceFill([
                    'status' => 'installed',
                    'progress_percent' => 100,
                    'error_code' => null,
                    'installed_at' => now(),
                    'failed_at' => null,
                ])->save();
                DB::afterCommit(fn () => RequeueSpeechPreparedPhrases::dispatch(false));
            }
            if ((string) $state->active_installation_id === $installationId
                && hash_equals((string) $state->install_claim_token, $claimToken)) {
                $this->clearLockedState();
            }

            return $matches;
        });
    }

    public function fail(string $installationId, string $claimToken, string $errorCode): void
    {
        DB::transaction(function () use ($installationId, $claimToken, $errorCode): void {
            $state = $this->lockedState();
            $installation = SpeechModelInstallation::query()->whereKey($installationId)->lockForUpdate()->first();
            if ($installation?->status === 'installing') {
                $installation->forceFill([
                    'status' => 'failed',
                    'error_code' => $this->errorCode($errorCode),
                    'failed_at' => now(),
                ])->save();
            }
            if ((string) $state->active_installation_id === $installationId
                && hash_equals((string) $state->install_claim_token, $claimToken)) {
                $this->clearLockedState();
            }
        });
    }

    public function failWorker(string $installationId): void
    {
        DB::transaction(function () use ($installationId): void {
            $state = $this->lockedState();
            $installation = SpeechModelInstallation::query()->whereKey($installationId)->lockForUpdate()->first();
            if ($installation?->status === 'installing') {
                $installation->forceFill([
                    'status' => 'failed',
                    'error_code' => 'model_install_worker_failed',
                    'failed_at' => now(),
                ])->save();
            }
            if ((string) $state->active_installation_id === $installationId) {
                $this->clearLockedState();
            }
        });
    }

    public function releasePreemptedClaim(string $installationId, string $claimToken): void
    {
        DB::transaction(function () use ($installationId, $claimToken): void {
            $state = $this->lockedState();
            if ((string) $state->active_installation_id === $installationId
                && hash_equals((string) $state->install_claim_token, $claimToken)
                && $state->install_cancel_requested_at !== null) {
                $this->clearLockedState();
            }
        });
    }

    public function preemptForOperationalIncident(Incident $incident): void
    {
        if ((bool) $incident->is_test || ! in_array((string) $incident->status, self::OPERATIONAL_INCIDENT_STATUSES, true)) {
            return;
        }
        $this->preempt();
    }

    public function preemptForAlarm(Incident $incident): void
    {
        if (! (bool) $incident->is_test) {
            $this->preempt();
        }
    }

    private function preempt(): void
    {
        $work = function (): void {
            $state = $this->lockedState();
            if ($state->active_installation_id === null || $state->active_model_id === null) {
                return;
            }
            $installation = SpeechModelInstallation::query()
                ->whereKey($state->active_installation_id)
                ->lockForUpdate()
                ->first();
            if ($installation?->status === 'installing') {
                $installation->forceFill([
                    'status' => 'failed',
                    'error_code' => 'model_install_preempted_by_alarm',
                    'failed_at' => now(),
                ])->save();
            }
            if ($state->install_started_at === null) {
                $this->clearLockedState();

                return;
            }

            DB::table('speech_runtime_states')->where('id', self::STATE_ID)->update([
                'install_cancel_requested_at' => now(),
                'updated_at' => now(),
            ]);
            $modelId = (string) $state->active_model_id;
            DB::afterCommit(function () use ($modelId): void {
                try {
                    $this->engine->cancelInstall($modelId);
                } catch (Throwable $exception) {
                    Log::warning('Speech model cancellation failed after alarm commit.', [
                        'model_id' => $modelId,
                        'error_code' => $exception instanceof SpeechEngineException
                            ? $exception->errorCode
                            : 'engine_cancel_failed',
                    ]);
                }
            });
        };

        if (DB::transactionLevel() > 0) {
            $work();
        } else {
            DB::transaction($work);
        }
    }

    private function hasRealOperationalActivity(): bool
    {
        return Incident::query()
            ->where('is_test', false)
            ->whereIn('status', self::OPERATIONAL_INCIDENT_STATUSES)
            ->exists()
            || DispatchRequest::query()
                ->whereIn('status', ['sent', 'escalated'])
                ->whereHas('incident', fn ($query) => $query
                    ->where('is_test', false)
                    ->whereNotIn('status', ['resolved', 'cancelled']))
                ->exists();
    }

    private function lockedState(): object
    {
        $state = DB::table('speech_runtime_states')->where('id', self::STATE_ID)->lockForUpdate()->first();
        if ($state === null) {
            throw new \RuntimeException('Speech runtime state is unavailable.');
        }

        return $state;
    }

    private function clearLockedState(): void
    {
        DB::table('speech_runtime_states')->where('id', self::STATE_ID)->update([
            'active_installation_id' => null,
            'active_model_id' => null,
            'install_claim_token' => null,
            'install_started_at' => null,
            'install_cancel_requested_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Speech runtime state must be locked inside a database transaction.');
        }
    }

    private function errorCode(string $errorCode): string
    {
        return preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) === 1 ? $errorCode : 'model_install_failed';
    }
}
