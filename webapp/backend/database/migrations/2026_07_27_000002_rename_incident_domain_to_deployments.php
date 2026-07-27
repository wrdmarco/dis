<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This is the coordinated domain cut-over. Historical migrations deliberately
     * keep their original names; this migration moves an existing installation
     * forward without introducing temporary legacy routes or duplicate tables.
     *
     * @var array<string, string>
     */
    private const TABLE_RENAMES = [
        'incidents' => 'deployments',
        'incident_assignments' => 'deployment_assignments',
        'incident_status_history' => 'deployment_status_history',
        'incident_team' => 'deployment_team',
        'pilot_incident_reports' => 'pilot_deployment_reports',
        'incident_intake_workflow_revisions' => 'deployment_request_workflow_revisions',
        'incident_intake_dossiers' => 'deployment_requests',
        'incident_intake_mutations' => 'deployment_request_mutations',
        // This table was retired by a later historical migration. The guard in
        // renameTables keeps the cut-over valid for installations at either point.
        'incident_speech_preparations' => 'deployment_speech_preparations',
    ];

    /**
     * @var array<string, array<string, string>>
     */
    private const COLUMN_RENAMES = [
        'deployments' => [
            'intake_decision_valid' => 'deployment_request_decision_valid',
        ],
        'deployment_assignments' => [
            'incident_id' => 'deployment_id',
        ],
        'deployment_status_history' => [
            'incident_id' => 'deployment_id',
        ],
        'deployment_team' => [
            'incident_id' => 'deployment_id',
        ],
        'pilot_deployment_reports' => [
            'incident_id' => 'deployment_id',
        ],
        'dispatch_requests' => [
            'incident_id' => 'deployment_id',
        ],
        'asset_assignments' => [
            'incident_id' => 'deployment_id',
        ],
        'location_sharing_consents' => [
            'incident_id' => 'deployment_id',
        ],
        'location_updates' => [
            'incident_id' => 'deployment_id',
        ],
        'deployment_requests' => [
            'incident_id' => 'deployment_id',
        ],
        'deployment_request_mutations' => [
            'dossier_id' => 'deployment_request_id',
        ],
        'wallboards' => [
            'active_incident_playlist_id' => 'active_deployment_playlist_id',
        ],
        'deployment_speech_preparations' => [
            'incident_id' => 'deployment_id',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const PERMISSION_RENAMES = [
        'incidents.view' => 'deployments.view',
        'incidents.assigned.view' => 'deployments.assigned.view',
        'incidents.manage' => 'deployments.manage',
        'incidents.delete' => 'deployments.delete',
        'incidents.dispatch.view' => 'deployments.dispatch.view',
        'incidents.dispatch.manage' => 'deployments.dispatch.manage',
        'intakes.priority.override' => 'deployment-requests.priority.override',
    ];

    /**
     * @var array<string, string>
     */
    private const ROLE_RENAMES = [
        'incident-coordinator' => 'deployment-coordinator',
    ];

    /**
     * @var array<string, string>
     */
    private const SETTING_RENAMES = [
        'incident.timeline.app_visible_types' => 'deployment.timeline.app_visible_types',
        'incident.form_fields' => 'deployment.form_fields',
        'incident.form_layout' => 'deployment.form_layout',
    ];

    /**
     * Exact persisted machine values and keys. Human-entered prose is deliberately
     * not searched-and-replaced.
     *
     * @var array<string, string>
     */
    private const MACHINE_VALUE_RENAMES = [
        'App\\Models\\Incident' => 'App\\Models\\Deployment',
        'App\\Models\\IncidentIntakeDossier' => 'App\\Models\\DeploymentRequest',
        'App\\Models\\IncidentIntakeMutation' => 'App\\Models\\DeploymentRequestMutation',
        'App\\Models\\IncidentIntakeWorkflowRevision' => 'App\\Models\\DeploymentRequestWorkflowRevision',
        'App\\Models\\IncidentStatusHistory' => 'App\\Models\\DeploymentStatusHistory',
        'App\\Models\\PilotIncidentReport' => 'App\\Models\\PilotDeploymentReport',
        'incident-form' => 'deployment-form',
        'incident-intake-workflow' => 'deployment-request-workflow',
        'incident.changed' => 'deployment.changed',
        'incident.intake.changed' => 'deployment-request.changed',
        'incident_preannouncement' => 'deployment_preannouncement',
        'incident_preannouncement_cancelled' => 'deployment_preannouncement_cancelled',
        'incident_cancelled' => 'deployment_cancelled',
        'incident_id' => 'deployment_id',
        'incident_reference' => 'deployment_reference',
        'incident_title' => 'deployment_title',
        'incident_location' => 'deployment_location',
        'incident_status' => 'deployment_status',
        'incident_priority' => 'deployment_priority',
        'incident_type' => 'deployment_type',
        'incident_count' => 'deployment_count',
        'incident_counts' => 'deployment_counts',
        'incident_list' => 'deployment_list',
        'incident_override' => 'deployment_override',
        'incident_active' => 'deployment_active',
        'active_incident_playlist' => 'active_deployment_playlist',
        'active_incident_playlist_id' => 'active_deployment_playlist_id',
        'previous_active_incident_playlist_id' => 'previous_active_deployment_playlist_id',
        'active_incidents' => 'active_deployments',
        'open_incidents' => 'open_deployments',
        'test_incidents' => 'test_deployments',
        'historical_incidents' => 'historical_deployments',
        'recent_incidents' => 'recent_deployments',
        'total_incidents' => 'total_deployments',
        'show_incidents' => 'show_deployments',
        'show_active_incidents' => 'show_active_deployments',
        'show_test_incidents' => 'show_test_deployments',
        'show_historical_incidents' => 'show_historical_deployments',
        'show_incident_list' => 'show_deployment_list',
        'incidents' => 'deployments',
        'incident' => 'deployment',
        'dossier_id' => 'deployment_request_id',
        'dossier' => 'deployment_request',
        'dossiers' => 'deployment_requests',
        'intake_workflow_version' => 'deployment_request_workflow_version',
        'intake_decision_invalidated' => 'deployment_request_decision_invalidated',
        'section_incident' => 'section_deployment',
        'incident_details' => 'deployment_details',
        'promote' => 'prepare_deployment',
        'promoted' => 'prepared',
        'intake_dossiers.promoted' => 'deployment_requests.prepared',
        'dispatch.cancelled_after_intake_change' => 'dispatch.cancelled_after_deployment_request_change',
        'location.sharing_stopped_for_incident' => 'location.sharing_stopped_for_deployment',
        'developer.incident_dispatch_index_read' => 'developer.deployment_dispatch_index_read',
    ];

    /**
     * KPI identifiers are transformed only inside the two typed KPI option
     * fields. They must never be treated as generic prefixes because page IDs,
     * ticker source IDs and human-authored text can legitimately start with
     * "incidents_".
     *
     * @var array<string, string>
     */
    private const KPI_RENAMES = [
        'incidents_total' => 'deployments_total',
        'incidents_registered_total' => 'deployments_registered_total',
        'incidents_active' => 'deployments_active',
        'incidents_dispatching' => 'deployments_dispatching',
        'incidents_in_progress' => 'deployments_in_progress',
        'incidents_low' => 'deployments_low',
        'incidents_normal' => 'deployments_normal',
        'incidents_high' => 'deployments_high',
        'incidents_critical' => 'deployments_critical',
        'incidents_opened_today' => 'deployments_opened_today',
        'incidents_resolved_today' => 'deployments_resolved_today',
        'incidents_cancelled_today' => 'deployments_cancelled_today',
        'incidents_resolved_total' => 'deployments_resolved_total',
        'incidents_cancelled_total' => 'deployments_cancelled_total',
        'incidents_by_province' => 'deployments_by_province',
        'incidents_by_country' => 'deployments_by_country',
    ];

    /**
     * @var array<string, string>
     */
    private const FORM_LAYOUT_ITEM_RENAMES = [
        'section_incident' => 'section_deployment',
        'incident_details' => 'deployment_details',
    ];

    /**
     * Only product-default labels attached to a renamed form-layout item are
     * changed. User-authored labels elsewhere remain byte-for-byte intact.
     *
     * @var array<string, string>
     */
    private const FORM_LAYOUT_DEFAULT_LABEL_RENAMES = [
        'Sectie: incident' => 'Sectie: inzet',
        'Incident' => 'Inzet',
    ];

    /**
     * @var array<string, string>
     */
    private const MUTATION_KEY_RENAMES = [
        'dossier_id' => 'deployment_request_id',
        'dossier' => 'deployment_request',
        'incident_id' => 'deployment_id',
        'incident' => 'deployment',
        'intake_workflow_version' => 'deployment_request_workflow_version',
    ];

    /**
     * Audit metadata is application-authored at its top level. Nested values can
     * contain submitted answers or snapshots and are deliberately not traversed.
     *
     * @var array<string, string>
     */
    private const AUDIT_METADATA_KEY_RENAMES = [
        'incident_id' => 'deployment_id',
        'incident_reference' => 'deployment_reference',
        'incident_title' => 'deployment_title',
        'incident_location' => 'deployment_location',
        'incident_status' => 'deployment_status',
        'incident_priority' => 'deployment_priority',
        'incident_type' => 'deployment_type',
        'incident_count' => 'deployment_count',
        'incident_counts' => 'deployment_counts',
        'dossier_id' => 'deployment_request_id',
        'active_incident_playlist_id' => 'active_deployment_playlist_id',
        'previous_active_incident_playlist_id' => 'previous_active_deployment_playlist_id',
    ];

    /**
     * @var list<string>
     */
    private const AUDIT_METADATA_MACHINE_VALUE_KEYS = [
        'action',
        'audit_action',
        'channel',
        'error_code',
        'event',
        'event_type',
        'last_error_code',
        'message_type',
        'operation',
        'page_type',
        'safe_message_type',
        'setting_key',
        'status',
        'target_type',
        'type',
    ];

    /**
     * @var array<string, string>
     */
    private const PUSH_DATA_KEY_RENAMES = [
        'incident_id' => 'deployment_id',
        'incident_reference' => 'deployment_reference',
        'incident_title' => 'deployment_title',
        'incident_location' => 'deployment_location',
        'incident_status' => 'deployment_status',
        'incident_priority' => 'deployment_priority',
        'incident_type' => 'deployment_type',
        'incident_count' => 'deployment_count',
        'incident_counts' => 'deployment_counts',
    ];

    /**
     * @var array<string, string>
     */
    private const WALLBOARD_MAP_KEY_RENAMES = [
        'show_active_incidents' => 'show_active_deployments',
        'show_test_incidents' => 'show_test_deployments',
        'show_historical_incidents' => 'show_historical_deployments',
        'show_incident_list' => 'show_deployment_list',
    ];

    /**
     * Prefixes are restricted to established audit action namespaces.
     *
     * @var array<string, string>
     */
    private const MACHINE_PREFIX_RENAMES = [
        'incidents.' => 'deployments.',
        'incident_form.' => 'deployment_form.',
        'intake_dossiers.' => 'deployment_requests.',
        'intake_workflow.' => 'deployment_request_workflow.',
        'pilot_incident_report.' => 'pilot_deployment_report.',
    ];

    /**
     * PostgreSQL keeps constraint and index names when a table or column is
     * renamed. These replacements remove the stale identifiers as well.
     *
     * @var array<string, string>
     */
    private const POSTGRES_IDENTIFIER_RENAMES = [
        'incident_intake_workflow_revisions' => 'deployment_request_workflow_revisions',
        'incident_intake_dossiers' => 'deployment_requests',
        'incident_intake_mutations' => 'deployment_request_mutations',
        'incident_speech_preparations' => 'deployment_speech_preparations',
        'incident_speech_preparation' => 'deployment_speech_preparation',
        'pilot_incident_reports' => 'pilot_deployment_reports',
        'incident_status_history' => 'deployment_status_history',
        'incident_assignments' => 'deployment_assignments',
        'incident_team' => 'deployment_team',
        'active_incident_playlist_id' => 'active_deployment_playlist_id',
        'intake_decision_valid' => 'deployment_request_decision_valid',
        'dossier_id' => 'deployment_request_id',
        'incident_id' => 'deployment_id',
        'incidents' => 'deployments',
    ];

    public function up(): void
    {
        $this->renameTables(self::TABLE_RENAMES);
        $this->renameColumns(self::COLUMN_RENAMES);
        $this->renamePostgresIdentifiers(self::POSTGRES_IDENTIFIER_RENAMES);

        $this->renamePermissions(self::PERMISSION_RENAMES);
        $this->renameRoles(self::ROLE_RENAMES);
        $this->renamePermissionCategory('incident_management', 'deployment_management');
        $this->renameSettings(self::SETTING_RENAMES);
        $this->transformDeploymentRequestData(true);
        $this->transformAuditData(true);
        $this->transformPushData(true);
        $this->transformWallboardData(true);
    }

    public function down(): void
    {
        $this->assertCanonicalMutationsCanBeRolledBack();

        $this->transformWallboardData(false);
        $this->transformPushData(false);
        $this->transformAuditData(false);
        $this->transformDeploymentRequestData(false);
        $this->renameSettings($this->reversed(self::SETTING_RENAMES));
        $this->renamePermissionCategory('deployment_management', 'incident_management');
        $this->renameRoles($this->reversed(self::ROLE_RENAMES));
        $this->renamePermissions($this->reversed(self::PERMISSION_RENAMES));

        $this->renamePostgresIdentifiers($this->reversed(self::POSTGRES_IDENTIFIER_RENAMES));
        $this->renameColumns($this->reverseColumnRenames());
        $this->renameTables(array_reverse($this->reversed(self::TABLE_RENAMES), true));
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameTables(array $renames): void
    {
        foreach ($renames as $from => $to) {
            if (! Schema::hasTable($from)) {
                continue;
            }

            if (Schema::hasTable($to)) {
                throw new RuntimeException("Cannot rename table {$from}: {$to} already exists.");
            }

            Schema::rename($from, $to);
        }
    }

    /**
     * @param  array<string, array<string, string>>  $renames
     */
    private function renameColumns(array $renames): void
    {
        foreach ($renames as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $from => $to) {
                if (! Schema::hasColumn($tableName, $from)) {
                    continue;
                }

                if (Schema::hasColumn($tableName, $to)) {
                    throw new RuntimeException("Cannot rename {$tableName}.{$from}: {$to} already exists.");
                }

                Schema::table($tableName, function (Blueprint $table) use ($from, $to): void {
                    $table->renameColumn($from, $to);
                });
            }
        }
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renamePermissions(array $renames): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($renames as $from => $to) {
            $source = DB::table('permissions')->where('name', $from)->first();
            if ($source === null) {
                continue;
            }

            $target = DB::table('permissions')->where('name', $to)->first();
            if ($target !== null) {
                throw new RuntimeException(
                    "Cannot rename permission {$from} to {$to}: both records exist.",
                );
            }

            DB::table('permissions')->where('id', $source->id)->update([
                'name' => $to,
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameRoles(array $renames): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach ($renames as $from => $to) {
            $source = DB::table('roles')->where('name', $from)->first();
            if ($source === null) {
                continue;
            }

            $target = DB::table('roles')->where('name', $to)->first();
            if ($target !== null) {
                throw new RuntimeException(
                    "Cannot rename role {$from} to {$to}: both records exist.",
                );
            }

            DB::table('roles')->where('id', $source->id)->update([
                'name' => $to,
                'updated_at' => Carbon::now(),
            ]);
        }
    }

    private function renamePermissionCategory(string $from, string $to): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        DB::table('permissions')->where('category', $from)->update([
            'category' => $to,
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renameSettings(array $renames): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        foreach ($renames as $from => $to) {
            $source = DB::table('system_settings')->where('key', $from)->first();
            if ($source === null) {
                continue;
            }

            if (DB::table('system_settings')->where('key', $to)->exists()) {
                throw new RuntimeException(
                    "Cannot rename system setting {$from} to {$to}: both records exist.",
                );
            }

            DB::table('system_settings')->where('key', $from)->update([
                'key' => $to,
                'updated_at' => Carbon::now(),
            ]);
        }

        $layoutKey = $renames['incident.form_layout'] ?? 'incident.form_layout';
        $layout = DB::table('system_settings')->where('key', $layoutKey)->first();
        if ($layout !== null) {
            $decoded = $this->decodeJson($layout->value);
            $transformed = $this->transformFormLayout(
                $decoded,
                $this->isForwardMap($renames),
            );
            $value = $transformed === $decoded && is_string($layout->value)
                ? $layout->value
                : $this->encodeJson($transformed);
            if ($value !== $layout->value) {
                DB::table('system_settings')->where('key', $layoutKey)->update([
                    'value' => $value,
                    'updated_at' => Carbon::now(),
                ]);
            }
        }
    }

    private function transformDeploymentRequestData(bool $forward): void
    {
        if (Schema::hasTable('deployment_requests') && Schema::hasColumn('deployment_requests', 'status')) {
            DB::table('deployment_requests')
                ->where('status', $forward ? 'promoted' : 'prepared')
                ->update(['status' => $forward ? 'prepared' : 'promoted']);
        }

        if (! Schema::hasTable('deployment_request_mutations')) {
            return;
        }

        DB::table('deployment_request_mutations')
            ->select(['id', 'operation', 'response_payload'])
            ->orderBy('id')
            ->chunkById(200, function ($mutations) use ($forward): void {
                foreach ($mutations as $mutation) {
                    $payload = $this->decodeJson($mutation->response_payload);
                    $payload = $this->transformMutationPayload($payload, $forward);
                    $operation = (string) $mutation->operation;

                    if ($forward && $operation === 'promote' && is_array($payload)) {
                        // The hash was calculated with the historical operation name.
                        // Runtime replay support uses this marker without exposing it.
                        $payload['request_hash_version'] = 1;
                        $operation = 'prepare_deployment';
                    } elseif (! $forward && $operation === 'prepare_deployment' && is_array($payload)
                        && ($payload['request_hash_version'] ?? null) === 1) {
                        unset($payload['request_hash_version']);
                        $operation = 'promote';
                    }

                    DB::table('deployment_request_mutations')->where('id', $mutation->id)->update([
                        'operation' => $operation,
                        'response_payload' => $this->encodeJson($payload),
                    ]);
                }
            }, 'id');
    }

    private function transformAuditData(bool $forward): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        foreach (['action', 'target_type'] as $column) {
            DB::table('audit_logs')
                ->select($column)
                ->whereNotNull($column)
                ->distinct()
                ->orderBy($column)
                ->get()
                ->each(function (object $row) use ($column, $forward): void {
                    $current = (string) $row->{$column};
                    $next = $this->transformMachineString($current, $forward);
                    if ($current !== $next) {
                        DB::table('audit_logs')->where($column, $current)->update([$column => $next]);
                    }
                });
        }

        DB::table('audit_logs')
            ->select(['id', 'metadata'])
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($forward): void {
                foreach ($rows as $row) {
                    $metadata = $this->decodeJson($row->metadata);
                    $transformed = $this->transformAuditMetadata($metadata, $forward);
                    if ($transformed === $metadata) {
                        continue;
                    }

                    DB::table('audit_logs')->where('id', $row->id)->update([
                        'metadata' => $this->encodeJson($transformed),
                    ]);
                }
            }, 'id');
    }

    private function transformPushData(bool $forward): void
    {
        $this->transformDispatchPushOutbox($forward);
        $this->transformScalarMachineColumn('push_delivery_logs', 'message_type', $forward);
        $this->transformScalarMachineColumn('push_delivery_logs', 'error_code', $forward);
        $this->transformScalarMachineColumn('push_queue_work_items', 'safe_message_type', $forward);
        $this->transformScalarMachineColumn('push_queue_work_items', 'error_code', $forward);
    }

    private function transformWallboardData(bool $forward): void
    {
        $changedPlaylistIds = $this->transformWallboardConfigurations(
            'wallboard_playlists',
            'version',
            $forward,
        );
        $changedWallboardIds = $this->transformWallboardConfigurations(
            'wallboards',
            'config_version',
            $forward,
        );

        if ($changedPlaylistIds === [] || ! Schema::hasTable('wallboards')) {
            return;
        }

        $linked = DB::table('wallboards')->where(function ($query) use ($changedPlaylistIds): void {
            $query->whereIn('playlist_id', $changedPlaylistIds);
            if (Schema::hasColumn('wallboards', 'active_deployment_playlist_id')) {
                $query->orWhereIn('active_deployment_playlist_id', $changedPlaylistIds);
            } elseif (Schema::hasColumn('wallboards', 'active_incident_playlist_id')) {
                $query->orWhereIn('active_incident_playlist_id', $changedPlaylistIds);
            }
        });
        if ($changedWallboardIds !== []) {
            $linked->whereNotIn('id', $changedWallboardIds);
        }

        $updates = [
            'config_version' => DB::raw('config_version + 1'),
            'updated_at' => Carbon::now(),
        ];
        if (Schema::hasColumn('wallboards', 'refresh_version')) {
            $updates['refresh_version'] = DB::raw('refresh_version + 1');
        }
        $linked->update($updates);

        // These are derived caches. Their fingerprints refer to the pre-cut-over
        // playlist JSON, so regeneration is safer than serving stale content.
        if (Schema::hasTable('wallboard_content_snapshots')) {
            DB::table('wallboard_content_snapshots')
                ->whereIn('playlist_id', $changedPlaylistIds)
                ->delete();
        }
    }

    /**
     * @return list<string>
     */
    private function transformWallboardConfigurations(string $table, string $versionColumn, bool $forward): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'configuration')) {
            return [];
        }

        $changedIds = [];
        DB::table($table)
            ->select(['id', 'configuration'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $versionColumn, $forward, &$changedIds): void {
                foreach ($rows as $row) {
                    $decoded = $this->decodeJson($row->configuration);
                    $transformed = $this->transformWallboardConfiguration($decoded, $forward);
                    if ($transformed === $decoded) {
                        continue;
                    }

                    $updates = [
                        'configuration' => $this->encodeJson($transformed),
                        'updated_at' => Carbon::now(),
                    ];
                    if (Schema::hasColumn($table, $versionColumn)) {
                        $updates[$versionColumn] = DB::raw($versionColumn.' + 1');
                    }
                    if ($table === 'wallboards' && Schema::hasColumn('wallboards', 'refresh_version')) {
                        $updates['refresh_version'] = DB::raw('refresh_version + 1');
                    }

                    DB::table($table)->where('id', $row->id)->update($updates);
                    $changedIds[] = (string) $row->id;
                }
            }, 'id');

        return array_values(array_unique($changedIds));
    }

    private function transformScalarMachineColumn(string $table, string $column, bool $forward): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->select($column)
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->get()
            ->each(function (object $row) use ($table, $column, $forward): void {
                $current = (string) $row->{$column};
                $next = $this->transformMachineString($current, $forward);
                if ($current !== $next) {
                    DB::table($table)->where($column, $current)->update([$column => $next]);
                }
            });
    }

    private function transformFormLayout(mixed $value, bool $forward): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $keyRenames = $forward
            ? self::FORM_LAYOUT_ITEM_RENAMES
            : $this->reversed(self::FORM_LAYOUT_ITEM_RENAMES);
        $labelRenames = $forward
            ? self::FORM_LAYOUT_DEFAULT_LABEL_RENAMES
            : $this->reversed(self::FORM_LAYOUT_DEFAULT_LABEL_RENAMES);

        foreach ($value as $index => $item) {
            if (! is_array($item) || ! is_string($item['key'] ?? null)) {
                continue;
            }

            $currentKey = $item['key'];
            $nextKey = $keyRenames[$currentKey] ?? $currentKey;
            if ($nextKey === $currentKey) {
                continue;
            }

            $item['key'] = $nextKey;
            if (is_string($item['label'] ?? null)) {
                $item['label'] = $labelRenames[$item['label']] ?? $item['label'];
            }
            $value[$index] = $item;
        }

        return $value;
    }

    private function transformMutationPayload(mixed $value, bool $forward): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $payload = $this->renameKnownKeys(
            $value,
            self::MUTATION_KEY_RENAMES,
            $forward,
            'deployment request mutation payload',
        );
        $payload = $this->transformDeploymentRequestRecord($payload, $forward);
        $requestKey = $forward ? 'deployment_request' : 'dossier';
        if (is_array($payload[$requestKey] ?? null)) {
            $payload[$requestKey] = $this->transformDeploymentRequestRecord(
                $payload[$requestKey],
                $forward,
            );
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $record
     * @return array<mixed>
     */
    private function transformDeploymentRequestRecord(array $record, bool $forward): array
    {
        $record = $this->renameKnownKeys(
            $record,
            [
                'incident_id' => 'deployment_id',
                'incident' => 'deployment',
                'intake_workflow_version' => 'deployment_request_workflow_version',
            ],
            $forward,
            'deployment request response',
        );

        $fromStatus = $forward ? 'promoted' : 'prepared';
        $toStatus = $forward ? 'prepared' : 'promoted';
        if (($record['status'] ?? null) === $fromStatus) {
            $record['status'] = $toStatus;
        }

        return $record;
    }

    private function transformAuditMetadata(mixed $value, bool $forward): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $metadata = $this->renameKnownKeys(
            $value,
            self::AUDIT_METADATA_KEY_RENAMES,
            $forward,
            'audit metadata',
        );
        foreach (['role', 'role_name'] as $key) {
            if (is_string($metadata[$key] ?? null)) {
                $metadata[$key] = $this->transformExactString(
                    $metadata[$key],
                    self::ROLE_RENAMES,
                    $forward,
                );
            }
        }
        foreach (['permission', 'permission_name'] as $key) {
            if (is_string($metadata[$key] ?? null)) {
                $metadata[$key] = $this->transformExactString(
                    $metadata[$key],
                    self::PERMISSION_RENAMES,
                    $forward,
                );
            }
        }
        foreach (['permissions', 'required_permissions'] as $key) {
            if (! is_array($metadata[$key] ?? null)) {
                continue;
            }

            $metadata[$key] = array_map(
                fn (mixed $permission): mixed => is_string($permission)
                    ? $this->transformExactString($permission, self::PERMISSION_RENAMES, $forward)
                    : $permission,
                $metadata[$key],
            );
        }
        if (is_array($metadata['keys'] ?? null)) {
            $metadata['keys'] = array_map(
                fn (mixed $setting): mixed => is_string($setting)
                    ? $this->transformExactString($setting, self::SETTING_RENAMES, $forward)
                    : $setting,
                $metadata['keys'],
            );
        }
        if (is_string($metadata['metric'] ?? null)) {
            $metadata['metric'] = $this->transformExactString(
                $metadata['metric'],
                self::KPI_RENAMES,
                $forward,
            );
        }
        if (($metadata['category'] ?? null) === ($forward ? 'incident_management' : 'deployment_management')) {
            $metadata['category'] = $forward ? 'deployment_management' : 'incident_management';
        }
        foreach (self::AUDIT_METADATA_MACHINE_VALUE_KEYS as $key) {
            if (! is_string($metadata[$key] ?? null)) {
                continue;
            }

            $metadata[$key] = $this->transformMachineString($metadata[$key], $forward);
        }

        return $metadata;
    }

    private function transformDispatchPushOutbox(bool $forward): void
    {
        if (! Schema::hasTable('dispatch_push_outbox')) {
            return;
        }

        $requiredColumns = [
            'id',
            'deduplication_key',
            'dispatch_request_id',
            'fcm_token_id',
            'message_type',
            'data',
            'last_error_code',
        ];
        foreach ($requiredColumns as $column) {
            if (! Schema::hasColumn('dispatch_push_outbox', $column)) {
                return;
            }
        }

        DB::table('dispatch_push_outbox')
            ->select($requiredColumns)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($forward): void {
                foreach ($rows as $row) {
                    $updates = [];
                    $currentMessageType = (string) $row->message_type;
                    $nextMessageType = $this->transformMachineString($currentMessageType, $forward);
                    if ($nextMessageType !== $currentMessageType) {
                        $nextDeduplicationKey = hash('sha256', implode('|', [
                            (string) $row->dispatch_request_id,
                            (string) $row->fcm_token_id,
                            $nextMessageType,
                        ]));
                        $collision = DB::table('dispatch_push_outbox')
                            ->where('deduplication_key', $nextDeduplicationKey)
                            ->where('id', '!=', $row->id)
                            ->value('id');
                        if ($collision !== null) {
                            throw new RuntimeException(
                                "Cannot transform push outbox row {$row->id}: canonical deduplication key collides with {$collision}.",
                            );
                        }

                        $updates['message_type'] = $nextMessageType;
                        $updates['deduplication_key'] = $nextDeduplicationKey;
                    }

                    $data = $this->decodeJson($row->data);
                    $nextData = $this->transformPushPayload($data, $forward);
                    if ($nextData !== $data) {
                        $updates['data'] = $this->encodeJson($nextData);
                    }

                    if ($row->last_error_code !== null) {
                        $lastErrorCode = (string) $row->last_error_code;
                        $nextLastErrorCode = $this->transformMachineString($lastErrorCode, $forward);
                        if ($nextLastErrorCode !== $lastErrorCode) {
                            $updates['last_error_code'] = $nextLastErrorCode;
                        }
                    }

                    if ($updates !== []) {
                        DB::table('dispatch_push_outbox')->where('id', $row->id)->update($updates);
                    }
                }
            }, 'id');
    }

    private function transformPushPayload(mixed $value, bool $forward): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $payload = $this->renameKnownKeys(
            $value,
            self::PUSH_DATA_KEY_RENAMES,
            $forward,
            'push data',
        );
        foreach (['type', 'message_type'] as $key) {
            if (is_string($payload[$key] ?? null)) {
                $payload[$key] = $this->transformMachineString($payload[$key], $forward);
            }
        }

        return $payload;
    }

    private function transformWallboardConfiguration(mixed $value, bool $forward): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $configuration = $this->renameKnownKeys(
            $value,
            ['incident_override' => 'deployment_override'],
            $forward,
            'wallboard configuration',
        );

        if (is_array($configuration['map'] ?? null)) {
            $configuration['map'] = $this->renameKnownKeys(
                $configuration['map'],
                self::WALLBOARD_MAP_KEY_RENAMES,
                $forward,
                'wallboard map configuration',
            );
        }

        if (! is_array($configuration['pages'] ?? null)) {
            return $configuration;
        }

        foreach ($configuration['pages'] as $index => $page) {
            if (! is_array($page)) {
                continue;
            }

            if (is_string($page['type'] ?? null)) {
                $page['type'] = $this->transformExactString(
                    $page['type'],
                    ['incident_list' => 'deployment_list'],
                    $forward,
                );
            }

            if (is_array($page['options'] ?? null)) {
                $page['options'] = $this->transformWallboardPageOptions(
                    $page['options'],
                    $forward,
                );
            }
            $configuration['pages'][$index] = $page;
        }

        return $configuration;
    }

    /**
     * @param  array<mixed>  $options
     * @return array<mixed>
     */
    private function transformWallboardPageOptions(array $options, bool $forward): array
    {
        $options = $this->renameKnownKeys(
            $options,
            ['show_test_incidents' => 'show_test_deployments'],
            $forward,
            'wallboard page options',
        );

        if (is_array($options['visible_metrics'] ?? null)) {
            $options['visible_metrics'] = array_map(
                fn (mixed $metric): mixed => is_string($metric)
                    ? $this->transformExactString($metric, self::KPI_RENAMES, $forward)
                    : $metric,
                $options['visible_metrics'],
            );
        }

        if (is_array($options['metric_visualizations'] ?? null)) {
            $options['metric_visualizations'] = $this->renameKnownKeys(
                $options['metric_visualizations'],
                self::KPI_RENAMES,
                $forward,
                'wallboard KPI visualizations',
            );
        }

        return $options;
    }

    /**
     * @param  array<mixed>  $value
     * @param  array<string, string>  $renames
     * @return array<mixed>
     */
    private function renameKnownKeys(
        array $value,
        array $renames,
        bool $forward,
        string $context,
    ): array {
        $map = $forward ? $renames : $this->reversed($renames);
        foreach ($map as $from => $to) {
            if (array_key_exists($from, $value) && array_key_exists($to, $value)) {
                throw new RuntimeException(
                    "Cannot rename {$from} to {$to} in {$context}: both keys exist.",
                );
            }
        }

        $transformed = [];
        foreach ($value as $key => $item) {
            $nextKey = is_string($key) ? ($map[$key] ?? $key) : $key;
            $transformed[$nextKey] = $item;
        }

        return $transformed;
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function transformExactString(string $value, array $renames, bool $forward): string
    {
        $map = $forward ? $renames : $this->reversed($renames);

        return $map[$value] ?? $value;
    }

    private function decodeJson(mixed $json): mixed
    {
        if (is_string($json)) {
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return json_decode(
            json_encode($json, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    private function transformMachineString(string $value, bool $forward): string
    {
        $exact = $forward
            ? self::MACHINE_VALUE_RENAMES
            : $this->reversed(self::MACHINE_VALUE_RENAMES);
        if (array_key_exists($value, $exact)) {
            return $exact[$value];
        }

        $prefixes = $forward
            ? self::MACHINE_PREFIX_RENAMES
            : $this->reversed(self::MACHINE_PREFIX_RENAMES);
        foreach ($prefixes as $from => $to) {
            if (str_starts_with($value, $from)) {
                return $to.substr($value, strlen($from));
            }
        }

        return $value;
    }

    private function assertCanonicalMutationsCanBeRolledBack(): void
    {
        if (! Schema::hasTable('deployment_request_mutations')) {
            return;
        }

        DB::table('deployment_request_mutations')
            ->where('operation', 'prepare_deployment')
            ->select(['id', 'response_payload'])
            ->orderBy('id')
            ->chunkById(200, function ($mutations): void {
                foreach ($mutations as $mutation) {
                    $payload = $this->decodeJson($mutation->response_payload);
                    if (! is_array($payload) || ($payload['request_hash_version'] ?? null) !== 1) {
                        throw new RuntimeException(
                            'Cannot roll back the deployment domain migration after canonical prepare-deployment mutations were written.',
                        );
                    }
                }
            }, 'id');
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function renamePostgresIdentifiers(array $renames): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $constraints = DB::select(<<<'SQL'
            SELECT relation.relname AS table_name, constraint_entry.conname AS identifier_name
            FROM pg_constraint AS constraint_entry
            INNER JOIN pg_class AS relation ON relation.oid = constraint_entry.conrelid
            INNER JOIN pg_namespace AS namespace_entry ON namespace_entry.oid = relation.relnamespace
            WHERE namespace_entry.nspname = current_schema()
            ORDER BY relation.relname, constraint_entry.conname
        SQL);

        foreach ($constraints as $constraint) {
            $from = (string) $constraint->identifier_name;
            $to = $this->replaceIdentifierFragments($from, $renames);
            if ($from === $to) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE %s RENAME CONSTRAINT %s TO %s',
                $this->quotePostgresIdentifier((string) $constraint->table_name),
                $this->quotePostgresIdentifier($from),
                $this->quotePostgresIdentifier($to),
            ));
        }

        // Constraint-backed indexes may already have followed their constraint.
        // Re-read the catalog and rename only the remaining stale index names.
        $indexes = DB::select(<<<'SQL'
            SELECT indexname AS identifier_name
            FROM pg_indexes
            WHERE schemaname = current_schema()
            ORDER BY indexname
        SQL);

        foreach ($indexes as $index) {
            $from = (string) $index->identifier_name;
            $to = $this->replaceIdentifierFragments($from, $renames);
            if ($from === $to) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER INDEX %s RENAME TO %s',
                $this->quotePostgresIdentifier($from),
                $this->quotePostgresIdentifier($to),
            ));
        }
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function replaceIdentifierFragments(string $identifier, array $renames): string
    {
        uksort($renames, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return str_replace(array_keys($renames), array_values($renames), $identifier);
    }

    private function quotePostgresIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    private function reversed(array $map): array
    {
        /** @var array<string, string> $reversed */
        $reversed = array_flip($map);

        return $reversed;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function reverseColumnRenames(): array
    {
        $renames = [];
        foreach (array_reverse(self::COLUMN_RENAMES, true) as $table => $columns) {
            $renames[$table] = $this->reversed($columns);
        }

        return $renames;
    }

    /**
     * @param  array<string, string>  $renames
     */
    private function isForwardMap(array $renames): bool
    {
        return array_key_exists('incident.form_layout', $renames);
    }
};
