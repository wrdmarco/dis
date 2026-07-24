<?php

namespace Tests\Feature;

use App\DTO\SpeechCacheEntryMetadata;
use App\Jobs\GenerateSpeechPreparedPhrase;
use App\Jobs\RequeueSpeechPreparedPhrases;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechPreparedPhrase;
use App\Models\SpeechVoiceProfile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Repositories\SpeechAudioCacheRepository;
use App\Services\SpeechAudioAssetGarbageCollector;
use App\Services\SpeechAudioPipeline;
use App\Services\SpeechCacheKeyService;
use App\Services\SpeechCacheMaintenanceService;
use App\Services\SpeechCachePruner;
use App\Services\SpeechPreparedPhraseService;
use App\Services\SpeechRuntimeReconciliationService;
use App\Services\SpeechSettingsService;
use App\Services\SpeechVoiceProfileService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class SpeechPreparedPhraseLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = storage_path('framework/testing/speech-prepared-'.str()->ulid());
        config()->set([
            'dis.speech.cache_root' => $this->runtimeRoot.DIRECTORY_SEPARATOR.'cache',
            'dis.speech.staging_root' => $this->runtimeRoot.DIRECTORY_SEPARATOR.'staging',
            'dis.speech.cache_hmac_key' => str_repeat('prepared-phrase-key-', 3),
            'dis.speech.audio_recipe_revision' => 'prepared-recipe-v1',
        ]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->runtimeRoot));
    }

    public function test_permissions_validation_postcode_normalization_deduplication_and_encryption(): void
    {
        Queue::fake();
        $settingsOnly = $this->user('prepared-settings@example.test', ['settings.manage']);
        $viewOnly = $this->user('prepared-view@example.test', [
            'settings.manage',
            'speech.cache.view',
        ]);
        $manager = $this->user('prepared-manager@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);

        $this->postJson('/api/admin/speech/preparations', [
            'kind' => 'postcode',
            'values' => ['3581cp'],
        ])->assertUnauthorized();
        $this->asAdminClient($settingsOnly)->getJson('/api/admin/speech/preparations')->assertForbidden();
        $this->asAdminClient($settingsOnly)->postJson('/api/admin/speech/preparations/search', [
            'search' => 'Utrecht',
        ])->assertForbidden();
        $this->asAdminClient($settingsOnly)->postJson('/api/admin/speech/preparations', [
            'kind' => 'postcode',
            'values' => ['3581cp'],
        ])->assertForbidden();
        $this->asAdminClient($viewOnly)->getJson('/api/admin/speech/preparations')->assertOk();
        $this->asAdminClient($viewOnly)->postJson('/api/admin/speech/preparations', [
            'kind' => 'postcode',
            'values' => ['3581cp'],
        ])->assertForbidden();

        foreach ([
            ['kind' => 'unknown', 'values' => ['Utrecht']],
            ['kind' => 'fixed_phrase', 'values' => ['Incident in {place}.']],
            ['kind' => 'fixed_phrase', 'values' => ['<speak>Alarm</speak>']],
            ['kind' => 'fixed_phrase', 'values' => ["Alarm\nnu"]],
            ['kind' => 'postcode', 'values' => ['0123 AA']],
        ] as $invalid) {
            $this->asAdminClient($manager)
                ->postJson('/api/admin/speech/preparations', $invalid)
                ->assertUnprocessable();
        }
        $this->asAdminClient($manager)->postJson('/api/admin/speech/preparations', [
            'kind' => 'residence',
            'values' => array_fill(0, 51, 'Utrecht'),
        ])->assertUnprocessable();

        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations', [
                'kind' => 'postcode',
                'values' => ['3581cp', '3581 CP'],
            ])
            ->assertStatus(202)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'postcode')
            ->assertJsonPath('data.0.value', '3581 CP')
            ->assertJsonPath('data.0.status', 'queued')
            ->assertJsonMissingPath('data.0.identity_hmac')
            ->assertJsonMissingPath('data.0.runtime_fingerprint_hmac')
            ->assertJsonMissingPath('data.0.cache_entry_id');
        Queue::assertPushed(GenerateSpeechPreparedPhrase::class, 1);
        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations', [
                'kind' => 'postcode',
                'values' => ['3581 CP'],
            ])
            ->assertStatus(202)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (string) SpeechPreparedPhrase::query()->sole()->id);
        Queue::assertPushed(GenerateSpeechPreparedPhrase::class, 1);

        foreach (['residence', 'province', 'fixed_phrase'] as $kind) {
            $this->asAdminClient($manager)
                ->postJson('/api/admin/speech/preparations', [
                    'kind' => $kind,
                    'values' => ['Utrecht', 'Utrecht'],
                ])
                ->assertStatus(202)
                ->assertJsonCount(1, 'data');
        }
        $this->assertDatabaseCount('speech_prepared_phrases', 4);
        $this->assertSame(
            ['fixed_phrase', 'postcode', 'province', 'residence'],
            SpeechPreparedPhrase::query()->orderBy('kind')->pluck('kind')->all(),
        );

        $postcode = SpeechPreparedPhrase::query()->where('kind', 'postcode')->sole();
        $rawText = (string) DB::table('speech_prepared_phrases')
            ->where('id', $postcode->id)
            ->value('display_text');
        $this->assertNotSame('3581 CP', $rawText);
        $this->assertStringNotContainsString('3581', $rawText);
        $this->assertSame(64, strlen((string) $postcode->identity_hmac));
        $this->assertStringNotContainsString(
            (string) $postcode->identity_hmac,
            (string) $response->getContent(),
        );

        $audit = AuditLog::query()->where('action', 'speech.preparation_created')->latest('created_at')->firstOrFail();
        $auditJson = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Utrecht', $auditJson);
        $this->assertStringNotContainsString('3581', $auditJson);
        $this->assertStringNotContainsString((string) $postcode->identity_hmac, $auditJson);
    }

    public function test_generation_pins_exact_segment_and_list_summary_and_audio_remain_private(): void
    {
        Queue::fake();
        $manager = $this->user('prepared-audio@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);
        $viewer = $this->user('prepared-audio-viewer@example.test', [
            'settings.manage',
            'speech.cache.view',
        ]);
        $model = $this->runtime($manager);

        $this->asAdminClient($manager)->postJson('/api/admin/speech/preparations', [
            'kind' => 'postcode',
            'values' => ['3581cp'],
        ])->assertStatus(202);
        $phrase = SpeechPreparedPhrase::query()->sole();
        $entry = $this->cacheHit($model, '3 5 8 1 C P', 1.0, 'POSTCODE-PREPARED-AUDIO');

        (new GenerateSpeechPreparedPhrase((string) $phrase->id))
            ->handle(app(SpeechPreparedPhraseService::class));

        $phrase->refresh();
        $entry->refresh();
        $this->assertSame('ready', $phrase->status);
        $this->assertSame(100, $phrase->progress_percent);
        $this->assertSame((string) $entry->id, (string) $phrase->cache_entry_id);
        $this->assertTrue($entry->is_pinned);
        $this->assertNull($entry->expires_at);
        $this->assertNotNull($entry->pinned_at);
        $this->assertSame('3 5 8 1 C P', $entry->display_text);

        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/search', [
                'kind' => 'postcode',
                'search' => '3581',
            ])
            ->assertOk();
        $list = $this->asAdminClient($viewer)
            ->postJson('/api/admin/speech/preparations/search', [
                'kind' => 'postcode',
                'status' => 'ready',
                'search' => '3581',
            ])
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $phrase->id)
            ->assertJsonPath('data.0.value', '3581 CP')
            ->assertJsonPath('data.0.progress_percent', 100)
            ->assertJsonPath('data.0.byte_size', (int) $entry->audioAsset->byte_size)
            ->assertJsonPath(
                'data.0.audio_url',
                '/api/admin/speech/preparations/'.(string) $phrase->id.'/audio',
            );
        $json = (string) $list->getContent();
        $this->assertStringNotContainsString((string) $phrase->identity_hmac, $json);
        $this->assertStringNotContainsString((string) $phrase->runtime_fingerprint_hmac, $json);
        $this->assertStringNotContainsString((string) $entry->cache_key, $json);
        $this->assertStringNotContainsString((string) $entry->audioAsset->storage_path, $json);
        $this->assertStringNotContainsString((string) $entry->audioAsset->content_sha256, $json);
        $searchAudit = AuditLog::query()
            ->where('action', 'http.privileged_request')
            ->where('target_type', 'api/admin/speech/preparations/search')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertStringNotContainsString(
            '3581',
            json_encode($searchAudit->metadata, JSON_THROW_ON_ERROR),
        );

        $this->asAdminClient($viewer)
            ->getJson('/api/admin/speech/preparations/summary')
            ->assertOk()
            ->assertJsonPath('data.counts.residence', 0)
            ->assertJsonPath('data.counts.province', 0)
            ->assertJsonPath('data.counts.postcode', 1)
            ->assertJsonPath('data.counts.fixed_phrase', 0)
            ->assertJsonPath('data.total_count', 1)
            ->assertJsonPath('data.ready_count', 1)
            ->assertJsonPath('data.pending_count', 0)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.disk_bytes', (int) $entry->audioAsset->byte_size);

        $url = '/api/admin/speech/preparations/'.$phrase->id.'/audio';
        Auth::forgetGuards();
        $this->withHeader('Authorization', '')->get($url, ['Accept' => 'audio/mp4'])
            ->assertUnauthorized();
        $this->asAdminClient($manager)->get($url, ['Accept' => 'audio/mp4'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeaderMissing('X-Content-SHA256');
        $this->asAdminClient($viewer)
            ->withHeaders(['If-None-Match' => '', 'Range' => 'bytes=0-9'])
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/'.(int) $entry->audioAsset->byte_size);
    }

    public function test_pinned_entries_survive_expiry_quota_and_generic_regeneration_invalidation(): void
    {
        config()->set('dis.speech.cache_quota_bytes', 268_435_456);
        $actor = $this->user('prepared-pin@example.test', ['settings.manage']);
        $model = $this->runtime($actor);
        $pinned = $this->readyPhrase(
            $actor,
            $model,
            'residence',
            'Utrecht',
            'Utrecht',
            'PINNED-EXPIRY-AUDIO',
            104_857_600,
        );
        $pinnedEntry = $pinned->cacheEntry;
        $pinnedEntry->forceFill(['expires_at' => now()->subMinute()])->save();

        $ordinaryAsset = SpeechAudioAsset::query()->create([
            'content_sha256' => hash('sha256', 'ordinary-quota-asset'),
            'storage_path' => 'objects/ab/'.hash('sha256', 'ordinary-quota-asset').'.m4a',
            'mime_type' => 'audio/mp4',
            'byte_size' => 209_715_200,
            'duration_ms' => 1000,
        ]);
        $ordinary = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'ordinary-quota-cache'),
            'category' => 'segment',
            'audio_asset_id' => $ordinaryAsset->id,
            'semantic_hmac' => hash('sha256', 'ordinary-quota-semantic'),
            'status' => 'ready',
            'is_pinned' => false,
            'last_used_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);

        app(SpeechCachePruner::class)->pruneExpiredAndQuota();
        $this->assertDatabaseHas('speech_cache_entries', ['id' => $pinnedEntry->id, 'is_pinned' => true]);
        $this->assertDatabaseMissing('speech_cache_entries', ['id' => $ordinary->id]);
        $this->assertDatabaseHas('speech_prepared_phrases', ['id' => $pinned->id, 'status' => 'ready']);

        $invalidate = function (): void {
            $this->invalidate('all');
        };
        $invalidate->call(app(SpeechCacheMaintenanceService::class));
        $this->assertDatabaseHas('speech_cache_entries', ['id' => $pinnedEntry->id, 'is_pinned' => true]);
        $this->assertDatabaseHas('speech_prepared_phrases', ['id' => $pinned->id]);
    }

    public function test_individual_delete_and_clear_are_audited_isolated_and_keep_active_assets_safe(): void
    {
        Queue::fake();
        $manager = $this->user('prepared-delete@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);
        $model = $this->runtime($manager);
        $protected = $this->readyPhrase(
            $manager,
            $model,
            'residence',
            'Utrecht',
            'Utrecht',
            'PROTECTED-PREPARED-AUDIO',
        );
        $unprotected = $this->readyPhrase(
            $manager,
            $model,
            'province',
            'Gelderland',
            'Gelderland',
            'UNPROTECTED-PREPARED-AUDIO',
        );
        $ordinary = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'ordinary-isolated-cache'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'ordinary-isolated-semantic'),
            'status' => 'ready',
            'is_pinned' => false,
            'expires_at' => now()->addDay(),
        ]);
        $manifest = $this->protectAssetWithManifest($manager, $model, $protected->cacheEntry->audioAsset);
        $protectedAssetId = (string) $protected->cacheEntry->audio_asset_id;
        $protectedCacheId = (string) $protected->cache_entry_id;
        $unprotectedAssetId = (string) $unprotected->cacheEntry->audio_asset_id;
        $unprotectedAssetPath = $this->runtimeRoot.DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                (string) $unprotected->cacheEntry->audioAsset->storage_path,
            );

        $this->asAdminClient($manager)
            ->deleteJson('/api/admin/speech/preparations/'.$protected->id)
            ->assertNoContent();
        $this->assertDatabaseMissing('speech_prepared_phrases', ['id' => $protected->id]);
        $this->assertDatabaseMissing('speech_cache_entries', ['id' => $protectedCacheId]);
        $this->assertDatabaseHas('speech_audio_assets', ['id' => $protectedAssetId]);
        $this->assertNull(SpeechAudioAsset::query()->findOrFail($protectedAssetId)->orphaned_at);
        $this->assertDatabaseHas('speech_manifests', ['id' => $manifest->id]);

        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/clear', ['confirmation' => 'wissen'])
            ->assertUnprocessable();
        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/clear', [
                'confirmation' => 'VOORBEREIDINGSCACHE LEGEN',
            ])
            ->assertOk()
            ->assertJsonPath('data.deleted_count', 1)
            ->assertJsonPath('data.cache_entries_removed', 1);
        $this->assertDatabaseCount('speech_prepared_phrases', 0);
        $this->assertDatabaseHas('speech_cache_entries', ['id' => $ordinary->id]);
        $this->assertDatabaseMissing('speech_cache_entries', ['id' => $unprotected->cache_entry_id]);
        $this->assertNotNull(SpeechAudioAsset::query()->findOrFail($unprotectedAssetId)->orphaned_at);
        $this->assertFileExists($unprotectedAssetPath);
        $this->assertSame(0, app(SpeechAudioAssetGarbageCollector::class)->collectExpired());
        SpeechAudioAsset::query()->whereKey($unprotectedAssetId)->update([
            'orphaned_at' => now()->subDays(2),
        ]);
        $this->assertSame(1, app(SpeechAudioAssetGarbageCollector::class)->collectExpired());
        $this->assertDatabaseMissing('speech_audio_assets', ['id' => $unprotectedAssetId]);
        $this->assertFileDoesNotExist($unprotectedAssetPath);

        $audit = AuditLog::query()
            ->where('action', 'speech.preparation_cache_cleared')
            ->latest('created_at')
            ->firstOrFail();
        $auditJson = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Utrecht', $auditJson);
        $this->assertStringNotContainsString('Gelderland', $auditJson);
        $this->assertStringNotContainsString((string) $unprotected->identity_hmac, $auditJson);
        $this->assertDatabaseHas('audit_logs', ['action' => 'speech.preparation_deleted']);
    }

    public function test_runtime_changes_requeue_and_rebuild_without_losing_registration(): void
    {
        Queue::fake();
        $manager = $this->user('prepared-runtime@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);
        $model = $this->runtime($manager);
        $phrase = $this->readyPhrase(
            $manager,
            $model,
            'fixed_phrase',
            'Open de app en bevestig ontvangst.',
            'Open de app en bevestig ontvangst.',
            'OLD-RUNTIME-PREPARED-AUDIO',
        );
        $oldEntry = $phrase->cacheEntry;

        app(SpeechSettingsService::class)->update(['speed' => 1.10], $manager);
        Queue::assertPushed(RequeueSpeechPreparedPhrases::class);
        (new RequeueSpeechPreparedPhrases)->handle(app(SpeechPreparedPhraseService::class));
        $this->assertSame('queued', $phrase->refresh()->status);
        $this->assertTrue($oldEntry->refresh()->is_pinned);
        $this->assertDatabaseCount('speech_prepared_phrases', 1);

        $newEntry = $this->cacheHit(
            $model,
            'Open de app en bevestig ontvangst.',
            1.10,
            'NEW-RUNTIME-PREPARED-AUDIO',
        );
        (new GenerateSpeechPreparedPhrase((string) $phrase->id))
            ->handle(app(SpeechPreparedPhraseService::class));
        $this->assertSame('ready', $phrase->refresh()->status);
        $this->assertSame((string) $newEntry->id, (string) $phrase->cache_entry_id);
        $this->assertTrue($newEntry->refresh()->is_pinned);
        $this->assertFalse($oldEntry->refresh()->is_pinned);
        $this->assertNotNull($oldEntry->expires_at);

        config()->set('dis.speech.audio_recipe_revision', 'prepared-recipe-v2');
        $this->assertSame(1, app(SpeechPreparedPhraseService::class)->requeueStale());
        $this->assertSame('queued', $phrase->refresh()->status);
        $this->assertDatabaseCount('speech_prepared_phrases', 1);
        $this->assertSame('Open de app en bevestig ontvangst.', $phrase->display_text);
    }

    public function test_one_item_can_be_forced_back_into_generation_without_losing_registration_or_pin(): void
    {
        Queue::fake();
        $manager = $this->user('prepared-regenerate@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);
        $model = $this->runtime($manager);
        $phrase = $this->readyPhrase(
            $manager,
            $model,
            'fixed_phrase',
            'Open de app en bevestig ontvangst.',
            'Open de app en bevestig ontvangst.',
            'MANUAL-REGENERATION-AUDIO',
        );
        $cacheEntry = $phrase->cacheEntry;

        $response = $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/'.$phrase->id.'/regenerate')
            ->assertStatus(202)
            ->assertJsonPath('data.id', (string) $phrase->id)
            ->assertJsonPath('data.value', 'Open de app en bevestig ontvangst.')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.progress_percent', 0)
            ->assertJsonPath(
                'data.audio_url',
                '/api/admin/speech/preparations/'.(string) $phrase->id.'/audio',
            );

        $this->assertDatabaseCount('speech_prepared_phrases', 1);
        $this->assertSame((string) $cacheEntry->id, (string) $phrase->refresh()->cache_entry_id);
        $cacheEntry->refresh();
        $this->assertTrue($cacheEntry->is_pinned);
        $this->assertNotNull($cacheEntry->pinned_at);
        $this->assertSame('ready', $cacheEntry->status);
        Queue::assertPushed(
            GenerateSpeechPreparedPhrase::class,
            fn (GenerateSpeechPreparedPhrase $job): bool => $job->phraseId === (string) $phrase->id
                && $job->forceRegeneration,
        );
        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/'.$phrase->id.'/regenerate')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');
        Queue::assertPushed(GenerateSpeechPreparedPhrase::class, 1);

        $audit = AuditLog::query()
            ->where('action', 'speech.preparation_regeneration_requested')
            ->latest('created_at')
            ->firstOrFail();
        $auditJson = json_encode($audit->metadata, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Open de app', $auditJson);
        $this->assertStringNotContainsString((string) $phrase->identity_hmac, $auditJson);
        $this->assertStringNotContainsString((string) $cacheEntry->cache_key, (string) $response->getContent());

        (new GenerateSpeechPreparedPhrase((string) $phrase->id, true))
            ->failed(new \RuntimeException('forced regeneration failed'));
        $this->assertSame('failed', $phrase->refresh()->status);
        $this->assertSame('ready', $cacheEntry->refresh()->status);
        $this->assertSame(
            '/api/admin/speech/preparations/'.(string) $phrase->id.'/audio',
            app(SpeechPreparedPhraseService::class)->payload($phrase)['audio_url'],
        );
        $this->asAdminClient($manager)
            ->get('/api/admin/speech/preparations/'.$phrase->id.'/audio', [
                'Accept' => 'audio/mp4',
            ])
            ->assertOk();
    }

    public function test_forced_publish_atomically_replaces_audio_and_defers_old_asset_collection(): void
    {
        $manager = $this->user('prepared-atomic-regenerate@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);
        $model = $this->runtime($manager);
        $phrase = $this->readyPhrase(
            $manager,
            $model,
            'fixed_phrase',
            'Open de app en bevestig ontvangst.',
            'Open de app en bevestig ontvangst.',
            'OLD-MANUAL-REGENERATION-AUDIO',
        );
        $entry = $phrase->cacheEntry;
        $oldAsset = $entry->audioAsset;
        $replacementBytes = 'NEW-MANUAL-REGENERATION-AUDIO';
        $replacementSha = hash('sha256', $replacementBytes);
        $replacementRelative = 'objects/'.substr($replacementSha, 0, 2).'/'.$replacementSha.'.m4a';
        $replacementPath = $this->runtimeRoot.DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $replacementRelative);
        File::ensureDirectoryExists(dirname($replacementPath));
        File::put($replacementPath, $replacementBytes);

        $published = app(SpeechAudioCacheRepository::class)->publish(
            (string) $entry->cache_key,
            'segment',
            null,
            app(SpeechCacheKeyService::class)->semantic('Open de app en bevestig ontvangst.'),
            $replacementSha,
            $replacementRelative,
            strlen($replacementBytes),
            1300,
            now()->addDay(),
            new SpeechCacheEntryMetadata(
                text: 'Open de app en bevestig ontvangst.',
                locale: 'nl-NL',
                modelCatalogKey: (string) $model->catalog_key,
                modelRevision: (string) $model->revision,
                voiceDesignRevision: (string) config(
                    'dis.speech.models.voxcpm2.built_in_voice_design_revision',
                ),
                audioRecipeRevision: (string) config('dis.speech.audio_recipe_revision'),
                speed: 1.0,
            ),
        );

        $this->assertSame((string) $entry->id, (string) $published->id);
        $this->assertSame('ready', $published->status);
        $this->assertTrue($published->is_pinned);
        $this->assertSame($replacementSha, (string) $published->audioAsset->content_sha256);
        $this->assertNotNull($oldAsset->refresh()->orphaned_at);
        $this->assertDatabaseHas('speech_prepared_phrases', [
            'id' => $phrase->id,
            'cache_entry_id' => $entry->id,
        ]);
        $this->assertFileExists(
            $this->runtimeRoot.DIRECTORY_SEPARATOR.'cache'
                .DIRECTORY_SEPARATOR.str_replace(
                    '/',
                    DIRECTORY_SEPARATOR,
                    (string) $oldAsset->storage_path,
                ),
        );
    }

    public function test_revoked_voice_and_missing_audio_fail_linked_registrations_without_losing_them(): void
    {
        Queue::fake();
        Storage::fake('local');
        $manager = $this->user('prepared-revocation@example.test', ['settings.manage']);
        $voice = SpeechVoiceProfile::query()->create([
            'name' => 'Ingetrokken stem',
            'locale' => 'nl-NL',
            'transcript' => 'Gecontroleerd stemfragment.',
            'consent_statement' => 'Toestemming bevestigd.',
            'consent_recorded_at' => now(),
            'sample_storage_path' => 'speech/voices/'.str()->ulid().'.enc',
            'sample_sha256' => str_repeat('b', 64),
            'sample_byte_size' => 4096,
            'reference_duration_ms' => 5000,
            'consent_version' => 1,
            'status' => 'ready',
            'created_by' => $manager->id,
        ]);
        Storage::disk('local')->put((string) $voice->sample_storage_path, 'encrypted-reference');
        $voiceAsset = SpeechAudioAsset::query()->create([
            'content_sha256' => hash('sha256', 'revoked-prepared-asset'),
            'storage_path' => 'objects/aa/'.hash('sha256', 'revoked-prepared-asset').'.m4a',
            'mime_type' => 'audio/mp4',
            'byte_size' => 2048,
            'duration_ms' => 1000,
        ]);
        $voiceEntry = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'revoked-prepared-cache'),
            'category' => 'segment',
            'audio_asset_id' => $voiceAsset->id,
            'voice_profile_id' => $voice->id,
            'semantic_hmac' => hash('sha256', 'revoked-prepared-semantic'),
            'status' => 'ready',
            'is_pinned' => true,
            'pinned_at' => now(),
        ]);
        $voicePhrase = SpeechPreparedPhrase::query()->create([
            'kind' => 'fixed_phrase',
            'identity_hmac' => hash('sha256', 'revoked-prepared-identity'),
            'display_text' => 'Open de app.',
            'status' => 'ready',
            'progress_percent' => 100,
            'cache_entry_id' => $voiceEntry->id,
            'runtime_fingerprint_hmac' => $voiceEntry->cache_key,
            'created_by' => $manager->id,
            'prepared_at' => now(),
        ]);

        app(SpeechVoiceProfileService::class)->delete($voice, $manager);
        $voicePhrase->refresh();
        $this->assertSame('failed', $voicePhrase->status);
        $this->assertSame(0, $voicePhrase->progress_percent);
        $this->assertSame('speech_voice_consent_revoked', $voicePhrase->error_code);
        $this->assertNull($voicePhrase->cache_entry_id);
        $this->assertNull($voicePhrase->prepared_at);

        $missingAsset = SpeechAudioAsset::query()->create([
            'content_sha256' => hash('sha256', 'missing-prepared-asset'),
            'storage_path' => 'objects/bb/'.hash('sha256', 'missing-prepared-asset').'.m4a',
            'mime_type' => 'audio/mp4',
            'byte_size' => 4096,
            'duration_ms' => 1000,
        ]);
        $missingEntry = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'missing-prepared-cache'),
            'category' => 'segment',
            'audio_asset_id' => $missingAsset->id,
            'semantic_hmac' => hash('sha256', 'missing-prepared-semantic'),
            'status' => 'ready',
            'is_pinned' => true,
            'pinned_at' => now(),
        ]);
        $missingPhrase = SpeechPreparedPhrase::query()->create([
            'kind' => 'province',
            'identity_hmac' => hash('sha256', 'missing-prepared-identity'),
            'display_text' => 'Utrecht',
            'status' => 'ready',
            'progress_percent' => 100,
            'cache_entry_id' => $missingEntry->id,
            'runtime_fingerprint_hmac' => $missingEntry->cache_key,
            'created_by' => $manager->id,
            'prepared_at' => now(),
        ]);

        app(SpeechRuntimeReconciliationService::class)->reconcile();
        $missingPhrase->refresh();
        $this->assertSame('failed', $missingPhrase->status);
        $this->assertSame(0, $missingPhrase->progress_percent);
        $this->assertSame('speech_audio_missing_after_restore', $missingPhrase->error_code);
        $this->assertNull($missingPhrase->cache_entry_id);
        $this->assertNull($missingPhrase->prepared_at);
        $this->assertDatabaseCount('speech_prepared_phrases', 2);
        Queue::assertPushed(
            RequeueSpeechPreparedPhrases::class,
            fn (RequeueSpeechPreparedPhrases $job): bool => $job->force === false,
        );
    }

    public function test_role_seeder_assigns_new_permissions_only_to_system_administrator_by_default(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        foreach (['speech.cache.view', 'speech.cache.manage'] as $name) {
            $permission = Permission::query()->where('name', $name)->firstOrFail();
            $roleNames = $permission->roles()->orderBy('name')->pluck('name')->all();
            $this->assertSame(['system-administrator'], $roleNames);
        }
    }

    public function test_weekly_test_alert_preset_uses_current_delivered_message_and_fixed_speech_template_lines(): void
    {
        Queue::fake();
        SystemSetting::query()->updateOrCreate(
            ['key' => 'test_alert.message'],
            [
                'value' => 'Dit is de vaste wekelijkse proefmelding. Bevestig deze proefalarmering met Ontvangen in de app.',
                'is_sensitive' => false,
            ],
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => 'speech.templates.test_ack'],
            [
                'value' => [
                    'Dit is een rustige proefalarmering.',
                    'Open de D.I.S.-app en bevestig ontvangst.',
                ],
                'is_sensitive' => true,
            ],
        );
        $settingsOnly = $this->user('preset-settings@example.test', ['settings.manage']);
        $cacheOnly = $this->user('preset-cache@example.test', ['speech.cache.manage']);
        $viewOnly = $this->user('preset-view@example.test', [
            'settings.manage',
            'speech.cache.view',
        ]);
        $manager = $this->user('preset-manager@example.test', [
            'settings.manage',
            'speech.cache.view',
            'speech.cache.manage',
        ]);

        $this->getJson('/api/admin/speech/preparations/presets')->assertUnauthorized();
        $this->asAdminClient($settingsOnly)
            ->getJson('/api/admin/speech/preparations/presets')
            ->assertForbidden();
        $this->asAdminClient($cacheOnly)
            ->getJson('/api/admin/speech/preparations/presets')
            ->assertForbidden();
        $this->asAdminClient($viewOnly)
            ->postJson('/api/admin/speech/preparations/presets/weekly_test_alert/prepare')
            ->assertForbidden();

        $expectedLines = [
            'Dit is de vaste wekelijkse proefmelding.',
            'Dit is een rustige proefalarmering.',
            'Open de D.I.S.-app en bevestig ontvangst.',
        ];
        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/preparations/presets')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', 'weekly_test_alert')
            ->assertJsonPath('data.0.label', 'Wekelijks proefalarm')
            ->assertJsonPath('data.0.preview_lines', $expectedLines)
            ->assertJsonPath('data.0.phrase_count', 3);

        $first = $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/presets/weekly_test_alert/prepare')
            ->assertStatus(202)
            ->assertJsonPath('data.preset.preview_lines', $expectedLines)
            ->assertJsonPath('data.preset.phrase_count', 3)
            ->assertJsonCount(3, 'data.preparations')
            ->assertJsonPath('data.preparations.0.kind', 'fixed_phrase')
            ->assertJsonPath('data.preparations.0.status', 'queued');

        $this->assertDatabaseCount('speech_prepared_phrases', 3);
        $this->assertSame(
            3,
            SpeechPreparedPhrase::query()
                ->where('kind', 'fixed_phrase')
                ->where('created_by', $manager->id)
                ->count(),
        );
        Queue::assertPushed(GenerateSpeechPreparedPhrase::class, 3);

        $second = $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/presets/weekly_test_alert/prepare')
            ->assertStatus(202)
            ->assertJsonCount(3, 'data.preparations');
        $this->assertSame(
            collect($first->json('data.preparations'))->pluck('id')->all(),
            collect($second->json('data.preparations'))->pluck('id')->all(),
        );
        $this->assertDatabaseCount('speech_prepared_phrases', 3);
        Queue::assertPushed(GenerateSpeechPreparedPhrase::class, 3);

        $storedCiphertext = json_encode(
            DB::table('speech_prepared_phrases')->pluck('display_text')->all(),
            JSON_THROW_ON_ERROR,
        );
        $auditJson = json_encode(
            AuditLog::query()
                ->whereIn('action', [
                    'speech.preparation_created',
                    'speech.preparation_preset_requested',
                ])
                ->pluck('metadata')
                ->all(),
            JSON_THROW_ON_ERROR,
        );
        foreach ($expectedLines as $line) {
            $this->assertStringNotContainsString($line, $storedCiphertext);
            $this->assertStringNotContainsString($line, $auditJson);
            $this->assertStringNotContainsString('{', $line);
            $this->assertStringNotContainsString('}', $line);
        }
        $presetAudit = AuditLog::query()
            ->where('action', 'speech.preparation_preset_requested')
            ->latest('created_at')
            ->firstOrFail();
        $this->assertSame('weekly_test_alert', $presetAudit->metadata['preset_id'] ?? null);
        $this->assertSame(3, $presetAudit->metadata['phrase_count'] ?? null);
        $this->assertSame(3, $presetAudit->metadata['queued_count'] ?? null);
    }

    public function test_weekly_test_alert_preset_is_live_deduplicated_and_unknown_presets_fail_closed(): void
    {
        Queue::fake();
        $manager = $this->user('preset-live@example.test', [
            'settings.manage',
            'speech.cache.manage',
        ]);
        SystemSetting::query()->updateOrCreate(
            ['key' => 'test_alert.message'],
            ['value' => 'Dezelfde vaste proefzin.', 'is_sensitive' => false],
        );
        SystemSetting::query()->updateOrCreate(
            ['key' => 'speech.templates.test_ack'],
            [
                'value' => [
                    'Dezelfde vaste proefzin.',
                    'Bevestig de proefalarmering in de app.',
                ],
                'is_sensitive' => true,
            ],
        );

        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/preparations/presets')
            ->assertOk()
            ->assertJsonPath('data.0.preview_lines', [
                'Dezelfde vaste proefzin.',
                'Bevestig de proefalarmering in de app.',
            ])
            ->assertJsonPath('data.0.phrase_count', 2);

        $messageSetting = SystemSetting::query()->findOrFail('test_alert.message');
        $messageSetting->value = 'Een nieuw ingesteld wekelijks proefalarm.';
        $messageSetting->save();
        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/preparations/presets')
            ->assertOk()
            ->assertJsonPath('data.0.preview_lines.0', 'Een nieuw ingesteld wekelijks proefalarm.');

        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/preparations/presets/onbekend/prepare')
            ->assertNotFound()
            ->assertJsonMissingPath('data');
        $this->assertDatabaseCount('speech_prepared_phrases', 0);
        Queue::assertNothingPushed();
    }

    private function runtime(User $actor): SpeechModelInstallation
    {
        $installation = SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => (string) config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => (string) config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed',
            'progress_percent' => 100,
            'requested_by' => $actor->id,
            'license_confirmed_at' => now(),
            'installed_at' => now(),
        ]);
        foreach ([
            'speech.enabled' => true,
            'speech.model_id' => 'voxcpm2',
            'speech.voice_profile_id' => null,
            'speech.speed' => 1.0,
            'speech.pre_generate_on_save' => true,
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], [
                'value' => $value,
                'is_sensitive' => false,
                'updated_by' => $actor->id,
            ]);
        }

        return $installation;
    }

    private function cacheHit(
        SpeechModelInstallation $model,
        string $spokenText,
        float $speed,
        string $bytes,
        ?int $reportedByteSize = null,
    ): SpeechCacheEntry {
        $cacheKey = app(SpeechAudioPipeline::class)->segmentCacheKey(
            $spokenText,
            $model,
            null,
            $speed,
            'segment',
        );
        $sha256 = hash('sha256', $bytes);
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        $path = $this->runtimeRoot.DIRECTORY_SEPARATOR.'cache'
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $bytes);
        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256,
            'storage_path' => $relative,
            'mime_type' => 'audio/mp4',
            'byte_size' => $reportedByteSize ?? strlen($bytes),
            'duration_ms' => 1200,
        ]);

        return SpeechCacheEntry::query()->create([
            'cache_key' => $cacheKey,
            'category' => 'segment',
            'audio_asset_id' => $asset->id,
            'semantic_hmac' => app(SpeechCacheKeyService::class)->semantic($spokenText),
            'display_text' => $spokenText,
            'locale' => 'nl-NL',
            'model_catalog_key' => $model->catalog_key,
            'model_revision' => $model->revision,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'audio_recipe_revision' => config('dis.speech.audio_recipe_revision'),
            'speed' => $speed,
            'status' => 'ready',
            'is_pinned' => false,
            'expires_at' => now()->addDay(),
        ])->load('audioAsset');
    }

    private function readyPhrase(
        User $actor,
        SpeechModelInstallation $model,
        string $kind,
        string $display,
        string $spoken,
        string $bytes,
        ?int $reportedByteSize = null,
    ): SpeechPreparedPhrase {
        $entry = $this->cacheHit($model, $spoken, 1.0, $bytes, $reportedByteSize);
        $entry->forceFill([
            'is_pinned' => true,
            'pinned_at' => now(),
            'expires_at' => null,
        ])->save();

        return SpeechPreparedPhrase::query()->create([
            'kind' => $kind,
            'identity_hmac' => app(SpeechCacheKeyService::class)->key(
                'prepared-phrase-identity',
                ['kind' => $kind, 'value' => $display],
            ),
            'display_text' => $display,
            'status' => 'ready',
            'progress_percent' => 100,
            'cache_entry_id' => $entry->id,
            'runtime_fingerprint_hmac' => $entry->cache_key,
            'created_by' => $actor->id,
            'prepared_at' => now(),
        ])->load('cacheEntry.audioAsset');
    }

    private function protectAssetWithManifest(
        User $actor,
        SpeechModelInstallation $model,
        SpeechAudioAsset $asset,
    ): SpeechManifest {
        $build = SpeechManifestBuild::query()->create([
            'phase' => 'attendance',
            'locale' => 'nl-NL',
            'model_installation_id' => $model->id,
            'voice_profile_id' => null,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'audio_recipe_revision' => config('dis.speech.audio_recipe_revision'),
            'speed' => 1.0,
            'template_checksum' => hash('sha256', 'prepared-template'),
            'context_hmac' => hash('sha256', 'prepared-context'),
            'source_fingerprint_hmac' => hash('sha256', 'prepared-source'),
            'rendered_lines' => ['Operationele alarmering.'],
            'status' => 'ready',
            'progress_percent' => 100,
            'finished_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return SpeechManifest::query()->create([
            'speech_manifest_build_id' => $build->id,
            'phase' => 'attendance',
            'locale' => 'nl-NL',
            'model_catalog_key' => $model->catalog_key,
            'model_revision' => $model->revision,
            'model_weights_sha256' => $model->weights_sha256,
            'voice_profile_id' => null,
            'voice_consent_version' => null,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'audio_recipe_revision' => config('dis.speech.audio_recipe_revision'),
            'speed' => 1.0,
            'template_checksum' => hash('sha256', 'prepared-template'),
            'context_hmac' => hash('sha256', 'prepared-context'),
            'manifest_sha256' => hash('sha256', 'prepared-manifest-'.$build->id),
            'audio_asset_id' => $asset->id,
            'segment_count' => 1,
            'duration_ms' => 1200,
            'expires_at' => now()->addHour(),
            'sealed_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function user(string $email, array $permissionNames): User
    {
        $user = User::query()->create([
            'name' => 'Speech Preparation Manager',
            'first_name' => 'Speech',
            'last_name' => 'Manager',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'speech-preparation-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Speech preparation test role',
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);
        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                [
                    'display_name' => $name,
                    'category' => 'system_configuration',
                    'description' => $name,
                ],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Speech preparation test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
