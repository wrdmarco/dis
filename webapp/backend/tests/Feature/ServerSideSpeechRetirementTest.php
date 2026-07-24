<?php

namespace Tests\Feature;

use App\Models\DispatchPushOutbox;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

final class ServerSideSpeechRetirementTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const RETIRED_TABLES = [
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

    public function test_fresh_schema_and_routes_have_no_server_side_speech_surface(): void
    {
        foreach (self::RETIRED_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), $table.' must be retired.');
        }

        $this->assertFalse(Schema::hasColumn('dispatch_push_outbox', 'speech_manifest_id'));
        $this->assertFalse(Schema::hasColumn('dispatch_push_outbox', 'release_reason'));
        $this->assertFalse(Schema::hasColumn('dispatch_requests', 'send_release_deadline'));
        $this->assertFalse(Schema::hasColumn('test_alert_schedule_runs', 'speech_lines'));
        $this->assertFalse(Schema::hasColumn('test_alert_schedule_runs', 'template_checksum'));
        $this->assertDatabaseMissing('permissions', ['name' => 'speech.cache.view']);
        $this->assertDatabaseMissing('permissions', ['name' => 'speech.cache.manage']);

        $speechRoutes = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->filter(static fn (string $uri): bool => str_starts_with($uri, 'api/admin/speech')
                || str_starts_with($uri, 'api/speech/'));
        $this->assertSame([], $speechRoutes->values()->all());
    }

    public function test_retirement_releases_legacy_notifications_and_removes_legacy_state(): void
    {
        $this->restoreLegacyColumns();
        [$dispatch, $outbox] = $this->legacyDelayedNotification();
        $this->insertLegacyQueueRowsAndSettings();

        $migration = require database_path(
            'migrations/2026_07_24_000011_retire_server_side_speech.php',
        );
        $migration->up();

        $dispatchState = DB::table('dispatch_requests')->where('id', $dispatch->id)->first();
        $this->assertSame('queued_for_push', $dispatchState?->send_status);
        $this->assertNotNull($dispatchState?->send_queued_at);
        $this->assertNotNull($dispatchState?->send_released_at);

        $outboxState = DB::table('dispatch_push_outbox')->where('id', $outbox->id)->first();
        $this->assertNotNull($outboxState);
        $releasedAt = strtotime((string) $outboxState->available_at);
        $this->assertLessThan($outbox->available_at->getTimestamp(), $releasedAt);
        $this->assertLessThanOrEqual(now()->addSeconds(5)->getTimestamp(), $releasedAt);
        $payload = json_decode((string) $outboxState->data, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('attendance', $payload['action_mode'] ?? null);
        $this->assertSame('incident_title', $payload['push.template.title_key'] ?? null);
        foreach ([
            'speech_manifest_id',
            'speech_phase',
            'speech_manifest_url',
            'speech_manifest_version',
            'speech_locale',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }

        $this->assertDatabaseMissing('jobs', ['queue' => 'speech']);
        $this->assertDatabaseMissing('failed_jobs', ['queue' => 'speech']);
        $this->assertDatabaseHas('jobs', ['queue' => 'push']);
        $this->assertDatabaseHas('failed_jobs', ['queue' => 'push']);
        $this->assertDatabaseMissing('system_settings', ['key' => 'speech.enabled']);
        $this->assertDatabaseHas('system_settings', ['key' => 'test_alert.message']);
        $this->assertDatabaseMissing('permissions', ['name' => 'speech.cache.view']);
        $this->assertDatabaseHas('permissions', ['name' => 'system.health.view']);
        $this->assertFalse(Schema::hasColumn('dispatch_push_outbox', 'speech_manifest_id'));
        $this->assertFalse(Schema::hasColumn('dispatch_push_outbox', 'release_reason'));
        $this->assertFalse(Schema::hasColumn('dispatch_requests', 'send_release_deadline'));
        $this->assertFalse(Schema::hasColumn('test_alert_schedule_runs', 'speech_lines'));
        $this->assertFalse(Schema::hasColumn('test_alert_schedule_runs', 'template_checksum'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('forward-only');
        $migration->down();
    }

    private function restoreLegacyColumns(): void
    {
        Schema::table('dispatch_requests', function (Blueprint $table): void {
            $table->timestampTz('send_release_deadline')->nullable()->index();
        });
        Schema::table('dispatch_push_outbox', function (Blueprint $table): void {
            $table->ulid('speech_manifest_id')->nullable();
            $table->string('release_reason', 40)->nullable();
            $table->index(
                ['speech_manifest_id', 'delivered_at', 'cancelled_at'],
                'dispatch_outbox_speech_pending_idx',
            );
        });
        Schema::table('test_alert_schedule_runs', function (Blueprint $table): void {
            $table->text('speech_lines')->nullable();
            $table->char('template_checksum', 64)->nullable();
        });
    }

    /** @return array{DispatchRequest, DispatchPushOutbox} */
    private function legacyDelayedNotification(): array
    {
        $user = User::query()->create([
            'name' => 'Speech retirement test',
            'first_name' => 'Speech',
            'last_name' => 'Retirement',
            'email' => 'speech-retirement@example.test',
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => true,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'RETIRE-SPEECH-001',
            'title' => 'Retirement fixture',
            'priority' => 'normal',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id,
            'requested_by' => $user->id,
            'status' => 'sent',
            'priority' => 'normal',
            'message' => 'Retirement fixture',
            'sent_at' => now(),
            'send_status' => 'preparing_speech',
        ]);
        DB::table('dispatch_requests')->where('id', $dispatch->id)->update([
            'send_release_deadline' => now()->addMinutes(5),
        ]);
        $providerToken = 'retirement-provider-token';
        $token = FcmToken::query()->create([
            'user_id' => $user->id,
            'device_id' => 'retirement-device',
            'token' => $providerToken,
            'token_hash' => hash('sha256', $providerToken),
            'platform' => 'android',
            'client_type' => 'operator',
            'is_active' => true,
        ]);
        $outbox = DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'retirement-outbox'),
            'dispatch_request_id' => $dispatch->id,
            'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request',
            'title' => 'Retirement fixture',
            'body' => 'Retirement fixture',
            'data' => [
                'action_mode' => 'attendance',
                'push.template.title_key' => 'incident_title',
                'speech_manifest_id' => (string) Str::ulid(),
                'speech_phase' => 'attendance',
                'speech_manifest_url' => '/api/speech/manifests/legacy',
                'speech_manifest_version' => '1',
                'speech_locale' => 'nl-NL',
            ],
            'available_at' => now()->addMinutes(5),
        ]);
        DB::table('dispatch_push_outbox')->where('id', $outbox->id)->update([
            'speech_manifest_id' => (string) Str::ulid(),
            'release_reason' => 'speech_deadline',
        ]);

        return [$dispatch, $outbox];
    }

    private function insertLegacyQueueRowsAndSettings(): void
    {
        foreach (['speech', 'push'] as $queue) {
            DB::table('jobs')->insert([
                'queue' => $queue,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->getTimestamp(),
                'created_at' => now()->getTimestamp(),
            ]);
            DB::table('failed_jobs')->insert([
                'id' => (string) Str::uuid(),
                'uuid' => (string) Str::uuid(),
                'connection' => 'database',
                'queue' => $queue,
                'payload' => '{}',
                'exception' => 'fixture',
                'failed_at' => now(),
            ]);
        }

        DB::table('system_settings')->insert([
            [
                'key' => 'speech.enabled',
                'value' => json_encode(true, JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'test_alert.message',
                'value' => json_encode('Blijft behouden', JSON_THROW_ON_ERROR),
                'is_sensitive' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        Permission::query()->firstOrCreate(
            ['name' => 'speech.cache.view'],
            [
                'display_name' => 'Speech cache view',
                'category' => 'system_configuration',
            ],
        );
        Permission::query()->firstOrCreate(
            ['name' => 'system.health.view'],
            [
                'display_name' => 'System health view',
                'category' => 'system_configuration',
            ],
        );
    }
}
