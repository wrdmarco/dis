<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const SPEECH_PAYLOAD_KEYS = [
        'speech_manifest_id',
        'speech_phase',
        'speech_manifest_url',
        'speech_manifest_version',
        'speech_locale',
    ];

    /** @var list<string> */
    private const SPEECH_PERMISSIONS = [
        'speech.cache.view',
        'speech.cache.manage',
    ];

    /** @var list<string> */
    private const SPEECH_TABLES = [
        'incident_speech_preparations',
        'speech_prepared_phrases',
        'speech_previews',
        'speech_manifest_segments',
        'speech_manifests',
        'speech_manifest_builds',
        'speech_cache_entries',
        'speech_runtime_states',
        'speech_audio_assets',
        'speech_voice_profiles',
        'speech_model_installations',
        'speech_cache_jobs',
        'speech_cache_counters',
    ];

    public function up(): void
    {
        $this->releasePendingNotifications();
        $this->removeQueuedSpeechWork();
        $this->removeSpeechSettingsAndPermissions();
        $this->dropSpeechColumns();

        foreach (self::SPEECH_TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        throw new LogicException(
            'Server-side speech retirement is forward-only; restore a database backup to reverse it.',
        );
    }

    private function releasePendingNotifications(): void
    {
        if (Schema::hasTable('dispatch_requests')
            && Schema::hasColumn('dispatch_requests', 'send_status')) {
            $updates = [
                'send_status' => 'queued_for_push',
            ];
            if (Schema::hasColumn('dispatch_requests', 'send_queued_at')) {
                $updates['send_queued_at'] = DB::raw('COALESCE(send_queued_at, sent_at, CURRENT_TIMESTAMP)');
            }
            if (Schema::hasColumn('dispatch_requests', 'send_released_at')) {
                $updates['send_released_at'] = DB::raw('COALESCE(send_released_at, CURRENT_TIMESTAMP)');
            }

            DB::table('dispatch_requests')
                ->where('send_status', 'preparing_speech')
                ->update($updates);
        }

        if (! Schema::hasTable('dispatch_push_outbox')) {
            return;
        }

        $hasManifestColumn = Schema::hasColumn('dispatch_push_outbox', 'speech_manifest_id');
        $hasReleaseReasonColumn = Schema::hasColumn('dispatch_push_outbox', 'release_reason');
        $speechPayloadPredicate = implode(
            ' OR ',
            array_map(
                static fn (string $key): string => "jsonb_exists(data::jsonb, '{$key}')",
                self::SPEECH_PAYLOAD_KEYS,
            ),
        );

        DB::table('dispatch_push_outbox')
            ->whereNull('delivered_at')
            ->whereNull('cancelled_at')
            ->where(function ($query) use (
                $hasManifestColumn,
                $hasReleaseReasonColumn,
                $speechPayloadPredicate,
            ): void {
                if ($hasManifestColumn) {
                    $query->whereNotNull('speech_manifest_id');
                }
                if ($hasReleaseReasonColumn) {
                    $method = $hasManifestColumn ? 'orWhereIn' : 'whereIn';
                    $query->{$method}('release_reason', ['speech_deadline', 'speech_ready']);
                }

                $method = ($hasManifestColumn || $hasReleaseReasonColumn) ? 'orWhereRaw' : 'whereRaw';
                $query->{$method}('('.$speechPayloadPredicate.')');
            })
            ->update(['available_at' => DB::raw('CURRENT_TIMESTAMP')]);

        $strippedPayload = '('.implode(
            ' - ',
            array_merge(
                ['COALESCE(data::jsonb, \'{}\'::jsonb)'],
                array_map(
                    static fn (string $key): string => "'{$key}'",
                    self::SPEECH_PAYLOAD_KEYS,
                ),
            ),
        ).')::json';
        DB::table('dispatch_push_outbox')
            ->whereRaw('('.$speechPayloadPredicate.')')
            ->update(['data' => DB::raw($strippedPayload)]);
    }

    private function removeQueuedSpeechWork(): void
    {
        if (Schema::hasTable('jobs')) {
            DB::table('jobs')->where('queue', 'speech')->delete();
        }
        if (Schema::hasTable('failed_jobs')) {
            DB::table('failed_jobs')->where('queue', 'speech')->delete();
        }
    }

    private function removeSpeechSettingsAndPermissions(): void
    {
        if (Schema::hasTable('system_settings')) {
            DB::table('system_settings')->where('key', 'like', 'speech.%')->delete();
        }
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('name', self::SPEECH_PERMISSIONS)
            ->pluck('id');
        if (Schema::hasTable('permission_role')) {
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        }
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }

    private function dropSpeechColumns(): void
    {
        if (Schema::hasTable('dispatch_push_outbox')) {
            DB::statement('DROP INDEX IF EXISTS dispatch_outbox_speech_pending_idx');
            DB::statement(
                'ALTER TABLE dispatch_push_outbox '
                .'DROP CONSTRAINT IF EXISTS dispatch_push_outbox_speech_manifest_id_foreign',
            );
            $this->dropExistingColumns('dispatch_push_outbox', [
                'speech_manifest_id',
                'release_reason',
            ]);
        }

        if (Schema::hasTable('dispatch_requests')) {
            DB::statement('DROP INDEX IF EXISTS dispatch_requests_send_release_deadline_index');
            $this->dropExistingColumns('dispatch_requests', ['send_release_deadline']);
        }

        $this->dropExistingColumns('test_alert_schedule_runs', [
            'speech_lines',
            'template_checksum',
        ]);
    }

    /** @param list<string> $columns */
    private function dropExistingColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $existing = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn($table, $column),
        ));
        if ($existing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($existing): void {
            $blueprint->dropColumn($existing);
        });
    }
};
