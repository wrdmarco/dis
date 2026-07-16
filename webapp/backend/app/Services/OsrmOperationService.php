<?php

namespace App\Services;

use App\Events\OsrmOperationStatusChanged;
use App\Exceptions\OsrmOperationConflictException;
use App\Exceptions\OsrmRequestPublicationException;
use App\Models\OsrmOperation;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\ApiDateTime;
use App\Support\SensitiveDataRedactor;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class OsrmOperationService
{
    private const REQUEST_VERSION = 1;

    private const APPROVED_SOURCE_URL = 'https://download.geofabrik.de/europe/netherlands-latest.osm.pbf';

    private const MAX_STATUS_BYTES = 65_536;

    private const MAX_LOG_BYTES = 8_388_608;

    private const MAX_LOG_LINE_BYTES = 16_384;

    private const MAX_PUBLIC_MESSAGE_BYTES = 1_000;

    private const QUEUED_STALE_MINUTES = 24 * 60;

    private const SETTING_ENABLED = 'routing.enabled';

    private const SETTING_SOURCE_URL = 'routing.osrm.source_url';

    private const SETTING_SOURCE_SHA256 = 'routing.osrm.source_sha256';

    private const SETTING_HEALTH_COORDINATE = 'routing.osrm.health_coordinate';

    public function __construct(
        private readonly SensitiveDataRedactor $redactor,
        private readonly AuditService $auditService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function managementStatus(): array
    {
        $this->recoverStaleQueuedOperation();
        $active = OsrmOperation::query()
            ->where('active_key', OsrmOperation::ACTIVE_KEY)
            ->latest('created_at')
            ->first();
        if ($active !== null) {
            $active = $this->sync($active);
        }

        $latest = OsrmOperation::query()->latest('created_at')->latest('id')->first();
        $runtime = $this->runtimeStatus();
        $enabled = SystemSetting::boolean(
            self::SETTING_ENABLED,
            (bool) config('dis.routing.enabled', false),
        );
        $sourceUrl = $this->configuredSourceUrl();
        $storedSourceUrl = SystemSetting::string(self::SETTING_SOURCE_URL);
        $storedCoordinate = $this->storedHealthCoordinate();
        $storedSha256 = $this->storedSha256();

        $installed = $runtime['installed'];
        $healthy = $runtime['healthy'];
        $dataset = $runtime['dataset'];
        $hasActiveDataset = is_array($dataset);
        $managedActiveDataset = $installed
            && $hasActiveDataset
            && $enabled
            && is_string($storedSourceUrl)
            && $sourceUrl !== null
            && hash_equals($sourceUrl, $storedSourceUrl)
            && $storedSha256 !== null
            && hash_equals($storedSha256, (string) $dataset['sha256'])
            && $storedCoordinate !== null
            && $runtime['health_coordinate'] !== null
            && $this->coordinatesMatch($storedCoordinate, $runtime['health_coordinate']);
        $state = match (true) {
            ! $installed => 'not_installed',
            ! $hasActiveDataset || ! $enabled => 'installed_inactive',
            $healthy => 'ready',
            default => 'degraded',
        };

        $blocker = null;
        $nextAction = null;
        if ($sourceUrl === null) {
            $blocker = [
                'code' => 'invalid_source_configuration',
                'message' => 'De vaste OSRM kaartbron is niet veilig geconfigureerd op de server.',
            ];
        } elseif ($active?->isActive() === true) {
            $blocker = [
                'code' => 'operation_active',
                'message' => 'Er draait al een OSRM-bewerking.',
            ];
        } else {
            $nextAction = $managedActiveDataset
                ? OsrmOperation::ACTION_UPDATE
                : OsrmOperation::ACTION_INSTALL_ACTIVATE;
        }

        return [
            'state' => $state,
            'installed' => $installed,
            'enabled' => $enabled,
            'healthy' => $healthy,
            'package' => $runtime['package'],
            'dataset' => $dataset,
            'configuration' => [
                'source_url' => $sourceUrl ?? '',
                'source_sha256' => $storedSha256,
                'health_coordinate' => $storedCoordinate,
            ],
            'next_action' => $nextAction,
            'blocker' => $blocker,
            'active_operation' => $active?->isActive() === true ? $this->summary($active) : null,
            'latest_operation' => $latest === null ? null : $this->summary($latest),
        ];
    }

    /**
     * @param  array{longitude: float|int|string, latitude: float|int|string}|null  $healthCoordinate
     */
    public function start(
        string $action,
        string $sourceSha256,
        ?array $healthCoordinate,
        User $actor,
        ?Request $request = null,
    ): OsrmOperation {
        if (! in_array($action, [OsrmOperation::ACTION_INSTALL_ACTIVATE, OsrmOperation::ACTION_UPDATE], true)) {
            throw new \InvalidArgumentException('Unsupported OSRM operation action.');
        }
        if (preg_match('/\A[a-f0-9]{64}\z/', $sourceSha256) !== 1) {
            throw new \InvalidArgumentException('Invalid OSRM source checksum.');
        }

        $status = $this->managementStatus();
        $nextAction = $status['next_action'] ?? null;
        if ($nextAction !== $action) {
            throw new OsrmOperationConflictException('De OSRM-status is gewijzigd. Laad de status opnieuw.');
        }

        $sourceUrl = $this->configuredSourceUrl();
        if ($sourceUrl === null) {
            throw new OsrmOperationConflictException('De vaste OSRM kaartbron is niet veilig geconfigureerd.');
        }

        if ($action === OsrmOperation::ACTION_UPDATE) {
            $coordinate = $this->storedHealthCoordinate();
            if ($coordinate === null || ! SystemSetting::boolean(self::SETTING_ENABLED, false)) {
                throw new OsrmOperationConflictException('OSRM is niet volledig geactiveerd; een update kan niet worden gestart.');
            }
            if (($status['state'] ?? null) === 'ready'
                && ($status['healthy'] ?? false) === true
                && hash_equals($this->storedSha256() ?? '', $sourceSha256)) {
                throw new OsrmOperationConflictException('Geef voor een kaartupdate de nieuwe SHA-256 van de bron op.');
            }
        } else {
            if ($healthCoordinate === null) {
                throw new \InvalidArgumentException('The initial OSRM health coordinate is required.');
            }
            $coordinate = [
                'longitude' => (float) $healthCoordinate['longitude'],
                'latitude' => (float) $healthCoordinate['latitude'],
            ];
        }

        $requestId = bin2hex(random_bytes(16));
        try {
            $operation = DB::transaction(function () use (
                $requestId,
                $action,
                $sourceUrl,
                $sourceSha256,
                $coordinate,
                $actor,
                $request,
            ): OsrmOperation {
                $operation = OsrmOperation::query()->create([
                    'request_id' => $requestId,
                    'action' => $action,
                    'state' => OsrmOperation::STATE_QUEUED,
                    'stage' => 'validating',
                    'active_key' => OsrmOperation::ACTIVE_KEY,
                    'message' => 'OSRM-bewerking staat klaar voor verwerking.',
                    'progress_percent' => null,
                    'source_url' => $sourceUrl,
                    'source_sha256' => $sourceSha256,
                    'health_longitude' => $coordinate['longitude'],
                    'health_latitude' => $coordinate['latitude'],
                    'actor_id' => $actor->id,
                    'actor_id_snapshot' => (string) $actor->id,
                ]);
                $this->auditService->record(
                    action: 'routing.osrm.operation_requested',
                    target: $operation,
                    actor: $actor,
                    metadata: [
                        'operation_action' => $operation->action,
                        'source_sha256' => $operation->source_sha256,
                    ],
                    request: $request,
                );

                return $operation;
            });
        } catch (QueryException $exception) {
            if (OsrmOperation::query()->where('active_key', OsrmOperation::ACTIVE_KEY)->exists()) {
                throw new OsrmOperationConflictException('Er draait al een OSRM-bewerking.', previous: $exception);
            }

            throw $exception;
        }

        try {
            $this->publishRequest($operation);
        } catch (Throwable $exception) {
            report($exception);
            $operation->forceFill([
                'state' => OsrmOperation::STATE_FAILED,
                'active_key' => null,
                'message' => 'De beveiligde OSRM request kon niet worden gepubliceerd.',
                'exit_code' => 1,
                'finished_at' => now(),
            ])->save();
            $this->broadcast($operation);

            throw new OsrmRequestPublicationException($operation);
        }

        $this->broadcast($operation);

        return $operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(OsrmOperation $operation): array
    {
        $operation->refresh();
        if (! $operation->isActive()) {
            throw new RuntimeException('The OSRM operation is no longer active.');
        }
        $configuredSourceUrl = $this->configuredSourceUrl();
        if ($configuredSourceUrl === null || ! hash_equals($configuredSourceUrl, (string) $operation->source_url)) {
            throw new RuntimeException('The configured OSRM source no longer matches the immutable operation.');
        }
        if ($operation->action === OsrmOperation::ACTION_UPDATE
            && ! SystemSetting::boolean(self::SETTING_ENABLED, false)) {
            throw new RuntimeException('OSRM is not enabled; an update payload cannot be issued.');
        }

        return [
            'version' => self::REQUEST_VERSION,
            'operation_id' => (string) $operation->id,
            'action' => (string) $operation->action,
            'actor_id' => (string) $operation->actor_id_snapshot,
            'source_url' => (string) $operation->source_url,
            'source_sha256' => (string) $operation->source_sha256,
            'health_coordinate' => [
                'longitude' => (float) $operation->health_longitude,
                'latitude' => (float) $operation->health_latitude,
            ],
        ];
    }

    public function markRunning(OsrmOperation $operation): OsrmOperation
    {
        $transitioned = false;
        $operation = DB::transaction(function () use ($operation, &$transitioned): OsrmOperation {
            $locked = OsrmOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->state === OsrmOperation::STATE_QUEUED) {
                $locked->forceFill([
                    'state' => OsrmOperation::STATE_RUNNING,
                    'message' => 'OSRM-bewerking is gestart.',
                    'started_at' => now(),
                ])->save();
                $transitioned = true;
            } elseif ($locked->state !== OsrmOperation::STATE_RUNNING) {
                throw new RuntimeException('The OSRM operation is no longer active.');
            }

            return $locked;
        });

        if ($transitioned) {
            $this->auditTransition($operation, 'routing.osrm.operation_started');
            $this->broadcast($operation);
        }

        return $operation;
    }

    public function failByRequestId(string $requestId, string $reason): OsrmOperation
    {
        if (preg_match('/\A[a-f0-9]{32}\z/', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid OSRM request id.');
        }
        $messages = [
            'rejected' => 'De beveiligde OSRM request is door de rootworker geweigerd.',
            'expired' => 'De beveiligde OSRM request is verlopen voordat deze kon starten.',
            'abandoned' => 'De OSRM rootworker is gestopt voordat de bewerking kon worden afgerond.',
        ];
        if (! isset($messages[$reason])) {
            throw new \InvalidArgumentException('Invalid OSRM request failure reason.');
        }

        $operation = OsrmOperation::query()->where('request_id', $requestId)->firstOrFail();

        return $this->failActiveOperation($operation, $messages[$reason], 1);
    }

    public function sync(OsrmOperation $operation): OsrmOperation
    {
        $snapshot = $this->operationSnapshot($operation);
        if ($snapshot === null) {
            return $operation->refresh();
        }
        if (in_array($snapshot['state'], [OsrmOperation::STATE_SUCCEEDED, OsrmOperation::STATE_FAILED], true)) {
            return $this->finish($operation, (int) ($snapshot['exit_code'] ?? 1), $snapshot);
        }

        $changed = false;
        $operation = DB::transaction(function () use ($operation, $snapshot, &$changed): OsrmOperation {
            $locked = OsrmOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! $locked->isActive()) {
                return $locked;
            }

            $updates = [
                'state' => $snapshot['state'],
                'stage' => $snapshot['stage'],
                'message' => $snapshot['message'],
                'progress_percent' => $snapshot['progress_percent'],
            ];
            if ($locked->started_at === null && $snapshot['started_at'] !== null) {
                $updates['started_at'] = Carbon::parse($snapshot['started_at']);
            }
            $changed = $this->hasChanged($locked, $updates);
            if ($changed) {
                $locked->forceFill($updates)->save();
            }

            return $locked;
        });

        if ($changed) {
            $this->broadcast($operation);
        }

        return $operation;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    public function finish(OsrmOperation $operation, int $exitCode, ?array $snapshot = null): OsrmOperation
    {
        $snapshot ??= $this->operationSnapshot($operation);
        $runtime = $this->runtimeStatus();
        $succeeded = $exitCode === 0
            && $snapshot !== null
            && $snapshot['state'] === OsrmOperation::STATE_SUCCEEDED
            && $snapshot['stage'] === 'completed'
            && ($snapshot['exit_code'] ?? null) === 0
            && is_string($snapshot['active_source_sha256'])
            && hash_equals((string) $operation->source_sha256, $snapshot['active_source_sha256'])
            && $runtime['healthy'] === true
            && is_array($runtime['dataset'])
            && hash_equals((string) $operation->source_sha256, (string) $runtime['dataset']['sha256']);

        $transitioned = false;
        $operation = DB::transaction(function () use ($operation, $snapshot, $exitCode, $succeeded, &$transitioned): OsrmOperation {
            $locked = OsrmOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! $locked->isActive()) {
                return $locked;
            }

            $message = match (true) {
                $succeeded => 'OSRM is geïnstalleerd, gezond en actief voor DIS.',
                ($snapshot['state'] ?? null) === OsrmOperation::STATE_FAILED => $snapshot['message']
                    ?? 'OSRM-bewerking mislukt. Raadpleeg de beveiligde serverlogs.',
                default => 'OSRM-bewerking mislukt omdat de actieve routering niet veilig kon worden bevestigd.',
            };
            $locked->forceFill([
                'state' => $succeeded ? OsrmOperation::STATE_SUCCEEDED : OsrmOperation::STATE_FAILED,
                'stage' => $succeeded ? 'completed' : ($snapshot['stage'] ?? $locked->stage),
                'active_key' => null,
                'message' => $this->sanitizeLine((string) $message),
                'progress_percent' => $succeeded ? 100 : ($snapshot['progress_percent'] ?? $locked->progress_percent),
                'exit_code' => $succeeded ? 0 : ($exitCode === 0 ? 1 : $exitCode),
                'started_at' => $locked->started_at ?? now(),
                'finished_at' => now(),
            ])->save();

            if ($succeeded) {
                $this->putSetting(self::SETTING_SOURCE_URL, (string) $locked->source_url, $locked);
                $this->putSetting(self::SETTING_SOURCE_SHA256, (string) $locked->source_sha256, $locked);
                $this->putSetting(self::SETTING_HEALTH_COORDINATE, [
                    'longitude' => (float) $locked->health_longitude,
                    'latitude' => (float) $locked->health_latitude,
                ], $locked);
                $this->putSetting(self::SETTING_ENABLED, true, $locked);
            }
            $transitioned = true;

            return $locked;
        });

        if ($transitioned) {
            $this->auditTransition(
                $operation,
                $operation->state === OsrmOperation::STATE_SUCCEEDED
                    ? 'routing.osrm.operation_succeeded'
                    : 'routing.osrm.operation_failed',
            );
            $this->broadcast($operation);
        }

        return $operation;
    }

    /**
     * @return array{operation: array<string, mixed>, lines: list<array<string, mixed>>, next_cursor: int}
     */
    public function feed(OsrmOperation $operation, int $after, int $limit): array
    {
        if ($operation->isActive()) {
            $operation = $this->sync($operation);
        } else {
            $operation->refresh();
        }
        $lines = $this->logLines($operation, max(0, $after), min(max(1, $limit), 200));

        return [
            'operation' => $this->summary($operation),
            'lines' => $lines,
            'next_cursor' => $lines === [] ? max(0, $after) : (int) end($lines)['seq'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(OsrmOperation $operation): array
    {
        return [
            'id' => (string) $operation->id,
            'action' => (string) $operation->action,
            'state' => (string) $operation->state,
            'stage' => (string) $operation->stage,
            'message' => $this->sanitizeLine((string) $operation->message),
            'started_at' => ApiDateTime::dateTime($operation->started_at),
            'finished_at' => ApiDateTime::dateTime($operation->finished_at),
            'exit_code' => $operation->exit_code,
        ];
    }

    private function publishRequest(OsrmOperation $operation): void
    {
        $root = $this->requestRoot();
        if (is_link($root) || ! is_dir($root) || ! is_writable($root)) {
            throw new RuntimeException('The protected OSRM request directory is unavailable.');
        }

        $temporary = $root.'/'.$operation->request_id.'.tmp';
        $pending = $root.'/'.$operation->request_id.'.pending';
        $payload = json_encode([
            'version' => self::REQUEST_VERSION,
            'operation_id' => (string) $operation->id,
            'action' => (string) $operation->action,
            'actor_id' => (string) $operation->actor_id_snapshot,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Exclusive OSRM request staging creation failed.');
        }
        $completed = false;
        try {
            $offset = 0;
            while ($offset < strlen($payload)) {
                $written = fwrite($handle, substr($payload, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('OSRM request staging could not be written completely.');
                }
                $offset += $written;
            }
            if (function_exists('fchmod') && ! fchmod($handle, 0600)) {
                throw new RuntimeException('OSRM request staging permissions could not be restricted.');
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('OSRM request staging could not be durably stored.');
            }
            $completed = true;
        } finally {
            fclose($handle);
            if (! $completed) {
                @unlink($temporary);
            }
        }

        if (file_exists($pending) || is_link($pending) || ! @rename($temporary, $pending)) {
            @unlink($temporary);
            throw new RuntimeException('OSRM request could not be atomically published.');
        }
    }

    private function recoverStaleQueuedOperation(): void
    {
        $stale = OsrmOperation::query()
            ->where('active_key', OsrmOperation::ACTIVE_KEY)
            ->where('state', OsrmOperation::STATE_QUEUED)
            ->where('created_at', '<', now()->subMinutes(self::QUEUED_STALE_MINUTES))
            ->first();
        if ($stale !== null) {
            $this->failActiveOperation(
                $stale,
                'De OSRM request is niet tijdig door de rootworker geclaimd.',
                124,
            );
        }
    }

    private function failActiveOperation(OsrmOperation $operation, string $message, int $exitCode): OsrmOperation
    {
        $transitioned = false;
        $operation = DB::transaction(function () use ($operation, $message, $exitCode, &$transitioned): OsrmOperation {
            $locked = OsrmOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if (! $locked->isActive()) {
                return $locked;
            }
            $locked->forceFill([
                'state' => OsrmOperation::STATE_FAILED,
                'active_key' => null,
                'message' => $this->sanitizeLine($message),
                'exit_code' => $exitCode,
                'started_at' => $locked->started_at,
                'finished_at' => now(),
            ])->save();
            $transitioned = true;

            return $locked;
        });

        if ($transitioned) {
            $this->auditTransition($operation, 'routing.osrm.operation_failed');
            $this->broadcast($operation);
        }

        return $operation;
    }

    /**
     * @return array{installed: bool, healthy: bool, package: array{version: string, verified_at: string|null}|null, dataset: array{sha256: string, imported_at: string|null}|null, health_coordinate: array{longitude: float, latitude: float}|null}
     */
    private function runtimeStatus(): array
    {
        $default = [
            'installed' => false,
            'healthy' => false,
            'package' => null,
            'dataset' => null,
            'health_coordinate' => null,
        ];
        $contents = $this->secureFileContents(
            (string) config('dis.routing.admin_status_path', '/var/log/dis/osrm-status.json'),
            self::MAX_STATUS_BYTES,
        );
        if ($contents === null) {
            return $default;
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $default;
        }
        if (! is_array($decoded) || ($decoded['version'] ?? null) !== self::REQUEST_VERSION) {
            return $default;
        }

        $package = $decoded['package'] ?? null;
        $package = is_array($package)
            && is_string($package['version'] ?? null)
            && strlen($package['version']) <= 200
            ? [
                'version' => $this->sanitizeLine($package['version']),
                'verified_at' => $this->validTimestamp($package['verified_at'] ?? null),
            ]
            : null;
        $dataset = $decoded['dataset'] ?? null;
        $dataset = is_array($dataset)
            && is_string($dataset['sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $dataset['sha256']) === 1
            ? [
                'sha256' => $dataset['sha256'],
                'imported_at' => $this->validTimestamp($dataset['imported_at'] ?? null),
            ]
            : null;
        $healthCoordinate = $dataset === null
            ? null
            : $this->parseRuntimeHealthCoordinate($decoded['dataset']['health_coordinate'] ?? null);

        return [
            'installed' => ($decoded['installed'] ?? false) === true && $package !== null,
            'healthy' => ($decoded['healthy'] ?? false) === true && $dataset !== null,
            'package' => $package,
            'dataset' => $dataset,
            'health_coordinate' => $healthCoordinate,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function operationSnapshot(OsrmOperation $operation): ?array
    {
        $contents = $this->secureFileContents(
            $this->resultsRoot().'/'.$operation->id.'.status.json',
            self::MAX_STATUS_BYTES,
        );
        if ($contents === null) {
            return null;
        }
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($decoded)
            || ($decoded['version'] ?? null) !== self::REQUEST_VERSION
            || ($decoded['operation_id'] ?? null) !== (string) $operation->id
            || ($decoded['action'] ?? null) !== (string) $operation->action
            || ! in_array($decoded['state'] ?? null, [
                OsrmOperation::STATE_QUEUED,
                OsrmOperation::STATE_RUNNING,
                OsrmOperation::STATE_SUCCEEDED,
                OsrmOperation::STATE_FAILED,
            ], true)
            || ! in_array($decoded['stage'] ?? null, OsrmOperation::STAGES, true)
            || ! is_string($decoded['message'] ?? null)) {
            return null;
        }

        $progress = $decoded['progress_percent'] ?? null;
        if ($progress !== null && (! is_int($progress) || $progress < 0 || $progress > 100)) {
            return null;
        }
        $exitCode = $decoded['exit_code'] ?? null;
        if ($exitCode !== null && (! is_int($exitCode) || $exitCode < 0 || $exitCode > 255)) {
            return null;
        }
        $activeSha = $decoded['active_source_sha256'] ?? null;
        if ($activeSha !== null && (! is_string($activeSha) || preg_match('/\A[a-f0-9]{64}\z/', $activeSha) !== 1)) {
            return null;
        }

        return [
            'state' => $decoded['state'],
            'stage' => $decoded['stage'],
            'message' => $this->sanitizeLine($decoded['message']),
            'progress_percent' => $progress,
            'started_at' => $this->validTimestamp($decoded['started_at'] ?? null),
            'finished_at' => $this->validTimestamp($decoded['finished_at'] ?? null),
            'exit_code' => $exitCode,
            'active_source_sha256' => $activeSha,
        ];
    }

    /**
     * @return list<array{seq: int, at: string, level: string, message: string}>
     */
    private function logLines(OsrmOperation $operation, int $after, int $limit): array
    {
        $contents = $this->secureFileContents(
            $this->resultsRoot().'/'.$operation->id.'.log.jsonl',
            self::MAX_LOG_BYTES,
        );
        if ($contents === null) {
            return [];
        }

        $lines = [];
        $seen = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $rawLine) {
            if ($rawLine === '' || strlen($rawLine) > self::MAX_LOG_LINE_BYTES) {
                continue;
            }
            try {
                $decoded = json_decode($rawLine, true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                continue;
            }
            $seq = is_array($decoded) && is_int($decoded['seq'] ?? null) ? $decoded['seq'] : 0;
            $timestamp = is_array($decoded) ? $this->validTimestamp($decoded['timestamp'] ?? null) : null;
            if (! is_array($decoded)
                || ($decoded['version'] ?? null) !== self::REQUEST_VERSION
                || $seq <= $after
                || $seq > 2_147_483_647
                || isset($seen[$seq])
                || $timestamp === null
                || ! in_array($decoded['stage'] ?? null, OsrmOperation::STAGES, true)
                || ! in_array($decoded['level'] ?? null, ['debug', 'info', 'warning', 'error'], true)
                || ! is_string($decoded['message'] ?? null)) {
                continue;
            }
            $seen[$seq] = true;
            $lines[] = [
                'seq' => $seq,
                'at' => $timestamp,
                'level' => $decoded['level'],
                'message' => $this->sanitizeLine($decoded['message']),
            ];
        }

        usort($lines, fn (array $left, array $right): int => $left['seq'] <=> $right['seq']);

        return array_slice($lines, 0, $limit);
    }

    private function configuredSourceUrl(): ?string
    {
        $url = trim((string) config('dis.routing.admin_pbf_url', ''));
        if ($url === ''
            || strlen($url) > 2_048
            || preg_match('/[\x00-\x20\x7f]/', $url) === 1
            || ! hash_equals(self::APPROVED_SOURCE_URL, $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! str_ends_with(strtolower((string) ($parts['path'] ?? '')), '.osm.pbf')) {
            return null;
        }

        return $url;
    }

    /**
     * @return array{longitude: float, latitude: float}|null
     */
    private function storedHealthCoordinate(): ?array
    {
        $coordinate = SystemSetting::value(self::SETTING_HEALTH_COORDINATE);
        if (! is_array($coordinate)
            || ! is_numeric($coordinate['longitude'] ?? null)
            || ! is_numeric($coordinate['latitude'] ?? null)) {
            return null;
        }
        $longitude = (float) $coordinate['longitude'];
        $latitude = (float) $coordinate['latitude'];
        if (! is_finite($longitude) || ! is_finite($latitude)
            || $longitude < -180 || $longitude > 180
            || $latitude < -90 || $latitude > 90) {
            return null;
        }

        return ['longitude' => $longitude, 'latitude' => $latitude];
    }

    private function storedSha256(): ?string
    {
        $sha256 = SystemSetting::string(self::SETTING_SOURCE_SHA256);

        return is_string($sha256) && preg_match('/\A[a-f0-9]{64}\z/', $sha256) === 1
            ? $sha256
            : null;
    }

    /**
     * @return array{longitude: float, latitude: float}|null
     */
    private function parseRuntimeHealthCoordinate(mixed $value): ?array
    {
        if (! is_string($value) || substr_count($value, ',') !== 1) {
            return null;
        }
        [$longitude, $latitude] = explode(',', $value, 2);
        if (! is_numeric($longitude) || ! is_numeric($latitude)) {
            return null;
        }
        $coordinate = [
            'longitude' => (float) $longitude,
            'latitude' => (float) $latitude,
        ];
        if (! is_finite($coordinate['longitude']) || ! is_finite($coordinate['latitude'])
            || $coordinate['longitude'] < -180 || $coordinate['longitude'] > 180
            || $coordinate['latitude'] < -90 || $coordinate['latitude'] > 90) {
            return null;
        }

        return $coordinate;
    }

    /**
     * @param  array{longitude: float, latitude: float}  $left
     * @param  array{longitude: float, latitude: float}  $right
     */
    private function coordinatesMatch(array $left, array $right): bool
    {
        return number_format($left['longitude'], 7, '.', '') === number_format($right['longitude'], 7, '.', '')
            && number_format($left['latitude'], 7, '.', '') === number_format($right['latitude'], 7, '.', '');
    }

    private function requestRoot(): string
    {
        return rtrim((string) config('dis.routing.admin_state_root'), '/\\').'/requests';
    }

    private function resultsRoot(): string
    {
        return rtrim((string) config('dis.routing.admin_state_root'), '/\\').'/results';
    }

    private function secureFileContents(string $path, int $maxBytes): ?string
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path)) {
            return null;
        }
        $stat = @lstat($path);
        if (! is_array($stat)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || ($stat['nlink'] ?? 0) !== 1
            || ($stat['size'] ?? 0) < 1
            || ($stat['size'] ?? 0) > $maxBytes
            || (PHP_OS_FAMILY !== 'Windows' && (($stat['mode'] ?? 0) & 0022) !== 0)) {
            return null;
        }
        if (app()->environment('production') && ($stat['uid'] ?? -1) !== 0) {
            return null;
        }
        $realPath = realpath($path);
        $realParent = realpath(dirname($path));
        if ($realPath === false || $realParent === false) {
            return null;
        }
        $expected = $realParent.DIRECTORY_SEPARATOR.basename($path);
        $samePath = DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($realPath, $expected) === 0
            : hash_equals($realPath, $expected);
        if (! $samePath) {
            return null;
        }

        $contents = @file_get_contents($realPath);

        return is_string($contents) && strlen($contents) === (int) $stat['size']
            ? $contents
            : null;
    }

    private function validTimestamp(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $value) !== 1) {
            return null;
        }
        try {
            return Carbon::parse($value)->toAtomString();
        } catch (Throwable) {
            return null;
        }
    }

    private function sanitizeLine(string $line): string
    {
        $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $line) ?? '';
        $line = trim($this->redactor->redactString($line));
        if (preg_match('/(?:SQLSTATE\[|stack trace:|^\s*#\d+\s|\.(?:php|m?js):\d+)/i', $line) === 1) {
            return 'Interne OSRM-fout. Raadpleeg de beveiligde serverlogs.';
        }
        $line = preg_replace('/(?<![A-Za-z0-9])(?:[A-Za-z]:\\\\|\\\\\\\\)[^\s\'\"]+/', '[PATH]', $line) ?? '';
        $line = preg_replace('~(?<![:A-Za-z0-9])/(?:opt|home|var|etc|usr|srv|tmp|run|root)(?:/[^\s\'\"]*)?~i', '[PATH]', $line) ?? '';
        $line = trim($line);

        return $line === ''
            ? 'OSRM-uitvoer afgeschermd.'
            : mb_substr($line, 0, self::MAX_PUBLIC_MESSAGE_BYTES);
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function hasChanged(OsrmOperation $operation, array $updates): bool
    {
        foreach ($updates as $key => $value) {
            if ($operation->getAttribute($key) != $value) {
                return true;
            }
        }

        return false;
    }

    private function putSetting(string $key, mixed $value, OsrmOperation $operation): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'is_sensitive' => false,
                'updated_by' => $operation->actor_id,
            ],
        );
    }

    private function auditTransition(OsrmOperation $operation, string $action): void
    {
        try {
            $actor = $operation->actor_id === null
                ? null
                : User::query()->find($operation->actor_id);
            $this->auditService->record(
                action: $action,
                target: $operation,
                actor: $actor,
                metadata: [
                    'operation_action' => $operation->action,
                    'state' => $operation->state,
                    'stage' => $operation->stage,
                    'exit_code' => $operation->exit_code,
                    'source_sha256' => $operation->source_sha256,
                ],
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function broadcast(OsrmOperation $operation): void
    {
        try {
            OsrmOperationStatusChanged::dispatch($this->summary($operation));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
