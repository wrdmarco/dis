<?php

namespace Tests\Feature;

use App\DTO\SpeechCacheEntryMetadata;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechManifestSegment;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechPreview;
use App\Models\SpeechVoiceProfile;
use App\Models\User;
use App\Repositories\SpeechAudioCacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SpeechCacheContentTest extends TestCase
{
    use RefreshDatabase;

    private string $runtimeRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runtimeRoot = storage_path('framework/testing/speech-cache-content-'.str()->ulid());
        config()->set([
            'dis.speech.cache_root' => $this->runtimeRoot.DIRECTORY_SEPARATOR.'cache',
            'dis.speech.staging_root' => $this->runtimeRoot.DIRECTORY_SEPARATOR.'staging',
            'dis.speech.cache_hmac_key' => str_repeat('cache-content-test-', 3),
            'dis.speech.audio_recipe_revision' => 'consistent-speaker-loudness-v3',
        ]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($this->runtimeRoot));
    }

    public function test_management_listing_is_authorized_paginated_filterable_and_private(): void
    {
        $manager = $this->user('speech-cache-manager@example.test', [
            'settings.manage',
            'incidents.view',
        ]);
        $settingsManager = $this->user(
            'speech-cache-settings-only@example.test',
            ['settings.manage'],
        );
        $viewer = $this->user('speech-cache-viewer@example.test', []);
        $voice = $this->voice($manager);
        $asset = $this->asset('CACHE-LIST-AUDIO');
        $text = 'Alarm voor Damrak 12 in Amsterdam.';
        $cacheKey = hash('sha256', 'private-cache-key');
        $semanticHmac = hash('sha256', 'private-semantic-hmac');
        $entry = SpeechCacheEntry::query()->create([
            'cache_key' => $cacheKey,
            'category' => 'composite',
            'audio_asset_id' => $asset->id,
            'voice_profile_id' => $voice->id,
            'semantic_hmac' => $semanticHmac,
            'display_text' => $text,
            'locale' => 'nl-NL',
            'model_catalog_key' => 'chatterbox_multilingual_v3',
            'model_revision' => 'fixed-cache-model-revision',
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'speed' => 1.10,
            'synthesis_duration_ms' => 4825,
            'status' => 'ready',
            'hit_count' => 7,
            'last_used_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
        ]);
        SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'failed-cache-key'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'failed-semantic'),
            'display_text' => 'Niet geslaagd fragment.',
            'status' => 'failed',
            'error_code' => 'engine_failed',
            'expires_at' => now()->addDay(),
        ]);
        $expired = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'expired-cache-key'),
            'category' => 'segment',
            'audio_asset_id' => $asset->id,
            'semantic_hmac' => hash('sha256', 'expired-semantic'),
            'display_text' => 'Verlopen cachefragment.',
            'status' => 'ready',
            'expires_at' => now()->subSecond(),
        ]);

        $storedText = (string) DB::table('speech_cache_entries')
            ->where('id', $entry->id)
            ->value('display_text');
        $this->assertNotSame($text, $storedText);
        $this->assertStringNotContainsString('Damrak', $storedText);

        $this->getJson('/api/admin/speech/cache/entries')->assertUnauthorized();
        $this->asAdminClient($viewer)
            ->getJson('/api/admin/speech/cache/entries')
            ->assertForbidden();
        $this->asAdminClient($settingsManager)
            ->getJson('/api/admin/speech/cache/entries')
            ->assertForbidden();
        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?category=invalid')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['category']]]);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?category%5B0%5D=composite')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['category']]]);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?per_page=101')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['per_page']]]);

        $response = $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?category=composite&status=ready&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $entry->id)
            ->assertJsonPath('data.0.text', $text)
            ->assertJsonPath('data.0.text_available', true)
            ->assertJsonPath('data.0.text_source', 'cache')
            ->assertJsonPath('data.0.category', 'composite')
            ->assertJsonPath('data.0.status', 'ready')
            ->assertJsonPath('data.0.model_id', 'chatterbox_multilingual_v3')
            ->assertJsonPath('data.0.model_name', 'Chatterbox Multilingual V3')
            ->assertJsonPath('data.0.model_revision', 'fixed-cache-model-revision')
            ->assertJsonPath('data.0.voice_type', 'profile')
            ->assertJsonPath('data.0.voice_name', 'Rustige Nederlandse stem')
            ->assertJsonPath('data.0.voice_revision', 'consent-v2')
            ->assertJsonPath('data.0.locale', 'nl-NL')
            ->assertJsonPath('data.0.speed', 1.1)
            ->assertJsonPath('data.0.hit_count', 7)
            ->assertJsonPath('data.0.byte_size', (int) $asset->byte_size)
            ->assertJsonPath('data.0.duration_ms', 1350)
            ->assertJsonPath('data.0.synthesis_duration_ms', 4825)
            ->assertJsonPath('data.0.audio_available', true)
            ->assertJsonPath(
                'data.0.audio_url',
                '/api/admin/speech/cache/entries/'.(string) $entry->id.'/audio',
            )
            ->assertJsonMissingPath('data.0.cache_key')
            ->assertJsonMissingPath('data.0.semantic_hmac')
            ->assertJsonMissingPath('data.0.storage_path')
            ->assertJsonMissingPath('data.0.content_sha256')
            ->assertJsonMissingPath('data.0.voice_profile_id');

        $json = $response->getContent();
        $this->assertIsString($json);
        $this->assertStringNotContainsString($cacheKey, $json);
        $this->assertStringNotContainsString($semanticHmac, $json);
        $this->assertStringNotContainsString((string) $asset->storage_path, $json);
        $this->assertStringNotContainsString((string) $asset->content_sha256, $json);
        $this->assertStringNotContainsString((string) $manager->id, $json);

        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?search=damrak')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $entry->id);
        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?status=expired')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (string) $expired->id)
            ->assertJsonPath('data.0.status', 'expired')
            ->assertJsonPath('data.0.synthesis_duration_ms', null)
            ->assertJsonPath('data.0.audio_available', false)
            ->assertJsonPath('data.0.audio_url', null);
    }

    public function test_encrypted_text_search_refuses_an_unbounded_cache_scan(): void
    {
        $manager = $this->user('speech-cache-search-limit@example.test', [
            'settings.manage',
            'incidents.view',
        ]);
        $now = now();
        $rows = [];
        for ($index = 0; $index < 501; $index++) {
            $rows[] = [
                'id' => (string) str()->ulid(),
                'cache_key' => hash('sha256', 'bounded-search-cache-'.$index),
                'category' => 'segment',
                'semantic_hmac' => hash('sha256', 'bounded-search-semantic-'.$index),
                'status' => 'ready',
                'hit_count' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('speech_cache_entries')->insert($chunk);
        }

        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?search=utrecht')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonStructure(['error' => ['details' => ['search']]]);

        $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?per_page=20')
            ->assertOk()
            ->assertJsonPath('meta.total', 501)
            ->assertJsonCount(20, 'data');
    }

    public function test_cache_hits_keep_complete_encrypted_metadata_stable_and_only_backfill_legacy_nulls(): void
    {
        $entry = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'stable-metadata-cache'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'stable-metadata-semantic'),
            'display_text' => 'Bestaande versleutelde tekst.',
            'locale' => 'nl-NL',
            'model_catalog_key' => 'voxcpm2',
            'model_revision' => 'stable-model-revision',
            'voice_design_revision' => 'stable-voice-revision',
            'audio_recipe_revision' => 'stable-audio-revision',
            'speed' => 1.0,
            'status' => 'ready',
        ]);
        $rawBefore = (string) DB::table('speech_cache_entries')
            ->where('id', $entry->id)
            ->value('display_text');
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower((string) $query->sql);
        });
        $repository = app(SpeechAudioCacheRepository::class);
        $repository->recordHit($entry, new SpeechCacheEntryMetadata(
            text: 'Deze tekst mag bestaande metadata niet vervangen.',
            locale: 'nl-BE',
            modelCatalogKey: 'ander-model',
            modelRevision: 'andere-revision',
            voiceDesignRevision: 'andere-stem',
            audioRecipeRevision: 'ander-recept',
            speed: 1.15,
        ));

        $entry->refresh();
        $rawAfter = (string) DB::table('speech_cache_entries')
            ->where('id', $entry->id)
            ->value('display_text');
        $this->assertSame($rawBefore, $rawAfter);
        $this->assertSame('Bestaande versleutelde tekst.', $entry->display_text);
        $this->assertSame('stable-model-revision', $entry->model_revision);
        $this->assertSame(1, $entry->hit_count);
        $this->assertFalse(collect($queries)->contains(
            static fn (string $query): bool => str_contains($query, 'for update'),
        ));

        $legacy = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'legacy-hit-cache'),
            'category' => 'segment',
            'semantic_hmac' => hash('sha256', 'legacy-hit-semantic'),
            'status' => 'ready',
        ]);
        $metadata = new SpeechCacheEntryMetadata(
            text: 'Eenmalig aangevulde legacytekst.',
            locale: 'nl-NL',
            modelCatalogKey: 'voxcpm2',
            modelRevision: 'backfill-model-revision',
            voiceDesignRevision: 'backfill-voice-revision',
            audioRecipeRevision: 'backfill-audio-revision',
            speed: 1.05,
        );
        $repository->recordHit($legacy, $metadata);

        $legacy->refresh();
        $this->assertSame('Eenmalig aangevulde legacytekst.', $legacy->display_text);
        $this->assertSame('backfill-model-revision', $legacy->model_revision);
        $this->assertSame('backfill-voice-revision', $legacy->voice_design_revision);
        $this->assertSame(1, $legacy->hit_count);
        $rawBackfill = (string) DB::table('speech_cache_entries')
            ->where('id', $legacy->id)
            ->value('display_text');
        $this->assertNotSame($metadata->text, $rawBackfill);
        $this->assertStringNotContainsString('legacytekst', $rawBackfill);
    }

    public function test_legacy_entries_recover_exact_encrypted_manifest_and_preview_context(): void
    {
        $manager = $this->user('speech-cache-legacy@example.test', [
            'settings.manage',
            'incidents.view',
        ]);
        $model = $this->model($manager);
        $segmentAsset = $this->asset('LEGACY-SEGMENT-AUDIO');
        $segmentCacheKey = hash('sha256', 'legacy-segment-cache');
        $build = $this->build(
            $model,
            ['Melding aan de Oudegracht in Utrecht.'],
            hash('sha256', 'legacy-manifest-source'),
        );
        $manifest = SpeechManifest::query()->create([
            'speech_manifest_build_id' => $build->id,
            'phase' => 'attendance',
            'locale' => 'nl-NL',
            'model_catalog_key' => 'voxcpm2',
            'model_revision' => 'legacy-model-revision',
            'model_weights_sha256' => str_repeat('a', 64),
            'voice_design_revision' => 'legacy-built-in-voice-v2',
            'audio_recipe_revision' => 'legacy-audio-recipe-v2',
            'speed' => 0.95,
            'template_checksum' => str_repeat('1', 64),
            'context_hmac' => str_repeat('2', 64),
            'manifest_sha256' => hash('sha256', 'legacy-manifest'),
            'audio_asset_id' => $segmentAsset->id,
            'segment_count' => 1,
            'duration_ms' => 1350,
            'expires_at' => now()->addDay(),
            'sealed_at' => now(),
            'created_at' => now(),
        ]);
        SpeechManifestSegment::query()->create([
            'speech_manifest_id' => $manifest->id,
            'sequence' => 0,
            'semantic_key' => 'line_1',
            'text' => 'Melding aan de Oudegracht in Utrecht.',
            'text_hmac' => hash('sha256', 'legacy-segment-text'),
            'cache_key' => $segmentCacheKey,
            'audio_asset_id' => $segmentAsset->id,
            'duration_ms' => 1350,
            'created_at' => now(),
        ]);
        $legacySegment = SpeechCacheEntry::query()->create([
            'cache_key' => $segmentCacheKey,
            'category' => 'segment',
            'audio_asset_id' => $segmentAsset->id,
            'semantic_hmac' => hash('sha256', 'legacy-segment-semantic'),
            'status' => 'ready',
            'expires_at' => now()->addDay(),
        ]);

        $previewAsset = $this->asset('LEGACY-PREVIEW-AUDIO');
        $previewBuild = $this->build(
            $model,
            ['Voorwaarschuwing in Haarlem.', 'Open de app en geef aan of je komt.'],
            hash('sha256', 'legacy-preview-source'),
        );
        SpeechPreview::query()->create([
            'requested_by' => $manager->id,
            'phase' => 'availability',
            'status' => 'ready',
            'progress_percent' => 100,
            'rendered_lines' => ['Voorwaarschuwing in Haarlem.', 'Open de app en geef aan of je komt.'],
            'speech_manifest_build_id' => $previewBuild->id,
            'audio_asset_id' => $previewAsset->id,
            'expires_at' => now()->addDay(),
            'ready_at' => now(),
        ]);
        $legacyPreview = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'legacy-preview-cache'),
            'category' => 'composite',
            'audio_asset_id' => $previewAsset->id,
            'semantic_hmac' => hash('sha256', 'legacy-preview-semantic'),
            'status' => 'ready',
            'expires_at' => now()->addDay(),
        ]);

        $response = $this->asAdminClient($manager)
            ->getJson('/api/admin/speech/cache/entries?per_page=20')
            ->assertOk();
        $items = collect($response->json('data'))->keyBy('id');

        $segment = $items->get((string) $legacySegment->id);
        $this->assertIsArray($segment);
        $this->assertSame('Melding aan de Oudegracht in Utrecht.', $segment['text']);
        $this->assertSame('manifest', $segment['text_source']);
        $this->assertSame('voxcpm2', $segment['model_id']);
        $this->assertSame('legacy-model-revision', $segment['model_revision']);
        $this->assertSame('legacy-built-in-voice-v2', $segment['voice_revision']);
        $this->assertSame(0.95, $segment['speed']);

        $preview = $items->get((string) $legacyPreview->id);
        $this->assertIsArray($preview);
        $this->assertSame(
            "Voorwaarschuwing in Haarlem.\nOpen de app en geef aan of je komt.",
            $preview['text'],
        );
        $this->assertSame('preview', $preview['text_source']);
        $this->assertSame('voxcpm2', $preview['model_id']);
        $this->assertSame('test-model-revision', $preview['model_revision']);
        $this->assertSame('nl-NL', $preview['locale']);
    }

    public function test_cache_audio_supports_authenticated_range_reads_without_internal_hash_headers(): void
    {
        $manager = $this->user('speech-cache-audio@example.test', [
            'settings.manage',
            'incidents.view',
        ]);
        $settingsManager = $this->user(
            'speech-cache-audio-settings-only@example.test',
            ['settings.manage'],
        );
        $viewer = $this->user('speech-cache-audio-viewer@example.test', []);
        $bytes = str_repeat('CACHE-AUDIO-RANGE-', 100);
        $asset = $this->asset($bytes);
        $entry = SpeechCacheEntry::query()->create([
            'cache_key' => hash('sha256', 'cache-audio-range-entry'),
            'category' => 'segment',
            'audio_asset_id' => $asset->id,
            'semantic_hmac' => hash('sha256', 'cache-audio-range-semantic'),
            'display_text' => 'Rustig afspeelbaar cachefragment.',
            'status' => 'ready',
            'expires_at' => now()->addHour(),
        ]);
        $url = '/api/admin/speech/cache/entries/'.$entry->id.'/audio';

        $this->get($url, ['Accept' => 'audio/mp4'])->assertUnauthorized();
        $this->asAdminClient($viewer)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
        $this->asAdminClient($settingsManager)
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertForbidden();
        $response = $this->asAdminClient($manager)
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeaderMissing('X-Content-SHA256')
            ->assertHeaderMissing('Digest');
        $etag = (string) $response->headers->get('ETag');
        $this->assertMatchesRegularExpression('/^"speech-cache-[a-f0-9]{64}"$/D', $etag);
        $this->assertStringNotContainsString((string) $asset->content_sha256, $etag);
        $this->assertStringNotContainsString((string) $entry->id, $etag);

        $this->asAdminClient($manager)
            ->withHeader('If-None-Match', $etag)
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(304);
        $this->asAdminClient($manager)
            ->withHeaders(['If-None-Match' => '', 'Range' => 'bytes=0-9'])
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/'.strlen($bytes))
            ->assertHeaderMissing('X-Content-SHA256')
            ->assertHeaderMissing('Digest');
        $this->asAdminClient($manager)
            ->withHeaders(['If-None-Match' => '', 'Range' => 'bytes='.strlen($bytes).'-'])
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(416);

        $replacementBytes = str_repeat('CACHE-AUDIO-REGENERATED-', 80);
        $replacementAsset = $this->asset($replacementBytes);
        $entry->forceFill(['audio_asset_id' => $replacementAsset->id])->save();
        $regenerated = $this->asAdminClient($manager)
            ->withHeaders(['If-None-Match' => $etag, 'Range' => ''])
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertOk();
        $regeneratedEtag = (string) $regenerated->headers->get('ETag');
        $this->assertNotSame($etag, $regeneratedEtag);
        $this->assertMatchesRegularExpression('/^"speech-cache-[a-f0-9]{64}"$/D', $regeneratedEtag);
        $this->assertStringNotContainsString((string) $replacementAsset->content_sha256, $regeneratedEtag);

        $entry->forceFill(['expires_at' => now()->subSecond()])->save();
        $this->asAdminClient($manager)
            ->withHeaders(['If-None-Match' => '', 'Range' => ''])
            ->getJson($url)
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'request_failed');
    }

    private function model(User $actor): SpeechModelInstallation
    {
        return SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => 'test-model-revision',
            'weights_sha256' => str_repeat('a', 64),
            'status' => 'installed',
            'progress_percent' => 100,
            'requested_by' => $actor->id,
            'license_confirmed_at' => now(),
            'installed_at' => now(),
        ]);
    }

    /** @param list<string> $lines */
    private function build(
        SpeechModelInstallation $model,
        array $lines,
        string $sourceFingerprint,
    ): SpeechManifestBuild {
        return SpeechManifestBuild::query()->create([
            'phase' => 'attendance',
            'locale' => 'nl-NL',
            'model_installation_id' => $model->id,
            'voice_design_revision' => 'test-built-in-voice-revision',
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'speed' => 1.0,
            'template_checksum' => str_repeat('3', 64),
            'context_hmac' => str_repeat('4', 64),
            'source_fingerprint_hmac' => $sourceFingerprint,
            'rendered_lines' => $lines,
            'status' => 'ready',
            'progress_percent' => 100,
            'finished_at' => now(),
            'expires_at' => now()->addDay(),
        ]);
    }

    private function voice(User $actor): SpeechVoiceProfile
    {
        return SpeechVoiceProfile::query()->create([
            'name' => 'Rustige Nederlandse stem',
            'locale' => 'nl-NL',
            'transcript' => 'Gecontroleerd stemfragment.',
            'consent_statement' => 'Toestemming bevestigd.',
            'consent_recorded_at' => now(),
            'sample_storage_path' => 'speech/voices/'.str()->ulid().'.enc',
            'sample_sha256' => str_repeat('b', 64),
            'sample_byte_size' => 4096,
            'reference_duration_ms' => 5000,
            'consent_version' => 2,
            'status' => 'ready',
            'created_by' => $actor->id,
        ]);
    }

    private function asset(string $bytes): SpeechAudioAsset
    {
        $sha256 = hash('sha256', $bytes);
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        $path = rtrim((string) config('dis.speech.cache_root'), '/\\')
            .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $bytes);

        return SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256,
            'storage_path' => $relative,
            'mime_type' => 'audio/mp4',
            'byte_size' => strlen($bytes),
            'duration_ms' => 1350,
        ]);
    }

    /** @param list<string> $permissionNames */
    private function user(string $email, array $permissionNames): User
    {
        $user = User::query()->create([
            'name' => 'Speech Cache Manager',
            'first_name' => 'Speech',
            'last_name' => 'Cache',
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'speech-cache-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Speech cache test role',
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
        $token = $user->createToken('Speech cache test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}
