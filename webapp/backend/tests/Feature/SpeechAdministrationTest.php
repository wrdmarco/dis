<?php

namespace Tests\Feature;

use App\Contracts\SpeechEngineClient;
use App\Exceptions\SpeechEngineException;
use App\Jobs\GenerateDispatchSpeechManifest;
use App\Jobs\GenerateSpeechPreview;
use App\Jobs\InstallSpeechModel;
use App\Jobs\PrewarmIncidentSpeech;
use App\Jobs\RegenerateSpeechCache;
use App\Jobs\SendFcmNotification;
use App\Models\DispatchPushOutbox;
use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\Incident;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SpeechAudioAsset;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechManifestBuild;
use App\Models\SpeechModelInstallation;
use App\Models\SpeechPreview;
use App\Models\SpeechVoiceProfile;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\DispatchPushOutboxService;
use App\Services\SpeechAudioPipeline;
use App\Services\SpeechCachePruner;
use App\Services\SpeechDispatchGateService;
use App\Services\SpeechExclusiveFileWriter;
use App\Services\SpeechManifestGenerationService;
use App\Services\SpeechModelCatalog;
use App\Services\SpeechModelInstallationService;
use App\Services\SpeechPreparedPhrasePresetService;
use App\Services\SpeechRuntimeActivityGate;
use App\Services\SpeechRuntimeReconciliationService;
use App\Services\SpeechSettingsService;
use App\Services\SpeechTemplateService;
use App\Services\TestAlertSpeechContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SpeechAdministrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $runtimeRoot = storage_path('framework/testing/speech-runtime-'.str()->ulid());
        config()->set([
            'dis.speech.models.chatterbox_multilingual_v3.revision' => 'fixed-revision-v3',
            'dis.speech.models.chatterbox_multilingual_v3.weights_sha256' => str_repeat('a', 64),
            'dis.speech.models.chatterbox_multilingual_v3.download_bytes' => 123456,
            'dis.speech.audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'dis.speech.cache_hmac_key' => str_repeat('test-speech-key-', 3),
            'dis.speech.staging_root' => $runtimeRoot.DIRECTORY_SEPARATOR.'staging',
            'dis.speech.cache_root' => $runtimeRoot.DIRECTORY_SEPARATOR.'cache',
        ]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($runtimeRoot));
    }

    public function test_admin_root_requires_permission_and_returns_the_strict_catalog_contract(): void
    {
        $this->getJson('/api/admin/speech')->assertUnauthorized();
        $viewer = $this->user('speech-viewer@example.test', []);
        $this->asAdminClient($viewer)->getJson('/api/admin/speech')->assertForbidden();

        $manager = $this->user('speech-manager@example.test', ['settings.manage']);
        $this->asAdminClient($manager)->getJson('/api/admin/speech')
            ->assertOk()
            ->assertJsonPath('data.settings.enabled', false)
            ->assertJsonPath('data.settings.speed', 1)
            ->assertJsonPath('data.models.0.quality_tier', 'high_end')
            ->assertJsonPath('data.models.0.status', 'not_installed')
            ->assertJsonPath('data.models.0.built_in_voice_available', false)
            ->assertJsonPath('data.models.1.id', 'voxcpm2')
            ->assertJsonPath('data.models.1.built_in_voice_available', true)
            ->assertJsonPath('data.voice_profiles', [])
            ->assertJsonStructure(['data' => [
                'settings' => ['enabled', 'model_id', 'voice_profile_id', 'speed', 'pre_generate_on_save', 'templates'],
                'template_definitions', 'models', 'voice_profiles',
                'cache' => ['segment_count', 'composite_count', 'hit_count', 'miss_count', 'disk_bytes', 'quota_bytes', 'pending_count', 'failed_count', 'last_pruned_at', 'active_job'],
            ]]);
    }

    public function test_model_install_uses_only_the_pinned_catalog_and_runs_on_the_speech_queue(): void
    {
        Queue::fake();
        $manager = $this->user('speech-install@example.test', ['settings.manage']);

        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/models/chatterbox_multilingual_v3/install', ['license_confirmed' => true])
            ->assertStatus(202)
            ->assertJsonPath('data.model.status', 'installing')
            ->assertJsonPath('data.model.progress_percent', 0);

        $installation = SpeechModelInstallation::query()->sole();
        $this->assertSame('fixed-revision-v3', $installation->revision);
        $this->assertSame(str_repeat('a', 64), $installation->weights_sha256);
        Queue::assertPushed(InstallSpeechModel::class, fn (InstallSpeechModel $job): bool => $job->installationId === $installation->id
            && $job->connection === 'redis'
            && $job->queue === 'speech');

        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/models/chatterbox_multilingual_v3/install', ['license_confirmed' => false])
            ->assertUnprocessable();
        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/models/untrusted_model/install', ['license_confirmed' => true])
            ->assertUnprocessable();
    }

    public function test_real_operational_activity_blocks_install_and_preempts_a_claimed_install_after_commit(): void
    {
        Queue::fake();
        $engine = new SpeechEngineClientFake;
        $engine->cancelThrows = true;
        app()->instance(SpeechEngineClient::class, $engine);
        $manager = $this->user('speech-install-gate@example.test', ['settings.manage']);

        $active = Incident::query()->create([
            'reference' => 'INC-INSTALL-BLOCK', 'title' => 'Echte inzet', 'priority' => 'high',
            'status' => 'active', 'is_test' => false, 'created_by' => $manager->id, 'opened_at' => now(),
        ]);
        $this->asAdminClient($manager)
            ->postJson('/api/admin/speech/models/chatterbox_multilingual_v3/install', ['license_confirmed' => true])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['model']]]);
        $this->assertDatabaseCount('speech_model_installations', 0);

        $active->forceFill(['status' => 'resolved', 'closed_at' => now()])->save();
        $installation = app(SpeechModelInstallationService::class)
            ->start('chatterbox_multilingual_v3', true, $manager);
        $claimToken = (string) str()->ulid();
        $this->assertTrue(app(SpeechRuntimeActivityGate::class)->claim((string) $installation->id, $claimToken));

        $incident = Incident::query()->create([
            'reference' => 'INC-INSTALL-PREEMPT', 'title' => 'Nieuw alarm', 'priority' => 'high',
            'status' => 'active', 'is_test' => false, 'created_by' => $manager->id, 'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id, 'requested_by' => $manager->id,
            'status' => 'sent', 'priority' => 'high', 'message' => 'Alarm',
        ]);
        $plan = DB::transaction(fn (): array => app(SpeechDispatchGateService::class)
            ->prepare($dispatch, $incident, now()));

        $this->assertFalse($plan['delayed']);
        $this->assertSame('queued_for_push', $dispatch->refresh()->send_status);
        $this->assertSame(1, $engine->cancelCalls);
        $this->assertSame('failed', $installation->refresh()->status);
        $this->assertSame('model_install_preempted_by_alarm', $installation->error_code);
    }

    public function test_install_worker_polls_async_engine_and_requires_exact_installed_integrity(): void
    {
        Queue::fake();
        config()->set('dis.speech.install_poll_interval_seconds', 1);
        $engine = new SpeechEngineClientFake;
        $engine->installResponses = [[
            'status' => 'installing', 'stage' => 'downloading', 'progress_percent' => 37,
        ]];
        $engine->statusResponses = [[
            'status' => 'installed', 'progress_percent' => 100,
            'installed_revision' => 'fixed-revision-v3', 'weights_sha256' => str_repeat('a', 64),
        ]];
        app()->instance(SpeechEngineClient::class, $engine);
        $manager = $this->user('speech-install-poll@example.test', ['settings.manage']);
        $installation = app(SpeechModelInstallationService::class)
            ->start('chatterbox_multilingual_v3', true, $manager);

        (new InstallSpeechModel((string) $installation->id))->handle(
            $engine,
            app(SpeechModelCatalog::class),
            app(SpeechRuntimeActivityGate::class),
        );

        $this->assertSame(1, $engine->installCalls);
        $this->assertSame(1, $engine->statusCalls);
        $this->assertSame('installed', $installation->refresh()->status);
        $this->assertSame(100, $installation->progress_percent);
        $this->assertNull(DB::table('speech_runtime_states')->where('id', 1)->value('active_installation_id'));
    }

    public function test_long_cpu_generation_is_bounded_resumable_and_never_changes_the_ten_second_alarm_gate(): void
    {
        $jobs = [
            new GenerateDispatchSpeechManifest((string) str()->ulid()),
            new GenerateSpeechPreview((string) str()->ulid()),
            new PrewarmIncidentSpeech((string) str()->ulid()),
            new RegenerateSpeechCache((string) str()->ulid()),
        ];
        foreach ($jobs as $job) {
            $this->assertSame(64_800, $job->timeout);
            $this->assertSame(3, $job->tries);
            $this->assertSame([60, 300], $job->backoff);
            $this->assertSame('speech', $job->queue);
        }
        $this->assertSame(14_400, (int) config('dis.speech.synthesis_timeout_seconds'));
        $this->assertGreaterThan(64_800, (int) config('queue.connections.speech.retry_after'));
        $this->assertSame(10, (int) config('dis.speech.release_gate_seconds'));
    }

    public function test_staging_writer_never_overwrites_an_existing_ulid_path(): void
    {
        $root = storage_path('framework/testing/speech-exclusive-'.str()->ulid());
        File::ensureDirectoryExists($root);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $path = $root.DIRECTORY_SEPARATOR.str()->ulid().'.job.json';
        $writer = app(SpeechExclusiveFileWriter::class);
        $writer->write($path, 'first');

        try {
            $writer->write($path, 'second');
            $this->fail('An exclusive speech path was overwritten.');
        } catch (\RuntimeException) {
            $this->assertSame('first', File::get($path));
        }
    }

    public function test_settings_and_preview_use_an_installed_model_and_ready_voice_without_free_text(): void
    {
        Queue::fake();
        $manager = $this->user('speech-preview@example.test', ['settings.manage']);
        [$installation, $profile] = $this->runtime($manager);

        $this->asAdminClient($manager)->patchJson('/api/admin/speech/settings', [
            'enabled' => true,
            'model_id' => 'chatterbox_multilingual_v3',
            'voice_profile_id' => $profile->id,
            'speed' => 1.1,
            'pre_generate_on_save' => true,
            'templates' => [
                'availability' => ['Mogelijke inzet in {place}.'],
                'attendance' => ['Alarm voor {title}.', '{street} {house_number}, {postcode} {place}.'],
                'test_ack' => ['Dit is een vaste proefalarmering.'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.settings.enabled', true)
            ->assertJsonPath('data.settings.model_id', 'chatterbox_multilingual_v3')
            ->assertJsonPath('data.settings.voice_profile_id', $profile->id)
            ->assertJsonPath('data.models.0.status', 'installed');
        $storedTemplate = (string) DB::table('system_settings')
            ->where('key', 'speech.templates.attendance')->value('value');
        $this->assertStringNotContainsString('Alarm voor', $storedTemplate);
        $this->assertStringNotContainsString('{street}', $storedTemplate);
        $settingsAudit = (string) DB::table('audit_logs')
            ->where('action', 'speech.settings_updated')->value('metadata');
        $this->assertStringNotContainsString('Alarm voor', $settingsAudit);
        $this->assertStringNotContainsString('{street}', $settingsAudit);

        $this->asAdminClient($manager)->postJson('/api/admin/speech/previews', [
            'phase' => 'attendance',
            'text' => 'Dit mag nooit naar de engine.',
        ])->assertUnprocessable();
        $response = $this->asAdminClient($manager)->postJson('/api/admin/speech/previews', [
            'phase' => 'attendance',
        ])->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.progress_percent', 0)
            ->assertJsonPath('data.phase', 'attendance');
        $previewId = $response->json('data.id');
        Queue::assertPushed(GenerateSpeechPreview::class, fn (GenerateSpeechPreview $job): bool => $job->previewId === $previewId && $job->queue === 'speech');
        $this->assertDatabaseHas('speech_manifest_builds', [
            'model_installation_id' => $installation->id,
            'voice_profile_id' => $profile->id,
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'status' => 'queued',
        ]);
    }

    public function test_manifest_generation_seals_the_current_audio_recipe_and_rejects_legacy_queued_builds(): void
    {
        $manager = $this->user('speech-recipe@example.test', ['settings.manage']);
        $installation = SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $manager->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);
        $engine = new SpeechEngineClientFake;
        $engine->writeSyntheticWave = true;
        app()->instance(SpeechEngineClient::class, $engine);
        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === config('dis.speech.ffmpeg_binary')) {
                if (in_array('null', $command, true)) {
                    return Process::result(errorOutput: json_encode([
                        'input_i' => '-24.00',
                        'input_tp' => '-4.00',
                        'input_lra' => '2.00',
                        'input_thresh' => '-34.00',
                        'target_offset' => '0.00',
                    ], JSON_THROW_ON_ERROR));
                }
                $output = end($command);
                $this->assertIsString($output);
                File::put($output, str_repeat('RECIPE-M4A-CONTENT-', 20));

                return Process::result();
            }

            return Process::result(output: json_encode([
                'streams' => [['codec_type' => 'audio', 'codec_name' => 'aac']],
                'format' => ['duration' => '1.25', 'format_name' => 'mov,mp4,m4a,3gp,3g2,mj2'],
            ], JSON_THROW_ON_ERROR));
        });
        $buildAttributes = [
            'phase' => 'test_ack', 'locale' => 'nl-NL',
            'model_installation_id' => $installation->id, 'voice_profile_id' => null,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'speed' => 1.0, 'template_checksum' => str_repeat('1', 64),
            'context_hmac' => str_repeat('2', 64), 'rendered_lines' => ['Dit is een proefalarm.'],
            'status' => 'queued', 'progress_percent' => 0, 'expires_at' => now()->addHour(),
        ];
        $legacy = SpeechManifestBuild::query()->create($buildAttributes + [
            'audio_recipe_revision' => 'legacy-segmented-v1',
            'source_fingerprint_hmac' => str_repeat('3', 64),
        ]);

        try {
            app(SpeechManifestGenerationService::class)->generate($legacy);
            $this->fail('Een queued build uit een oud audiorecept mag niet worden gegenereerd.');
        } catch (SpeechEngineException $exception) {
            $this->assertSame('speech_audio_recipe_changed', $exception->errorCode);
        }
        $this->assertSame(0, $engine->synthesizeCalls);
        $this->assertSame('queued', $legacy->refresh()->status);
        $this->assertDatabaseMissing('speech_manifests', ['speech_manifest_build_id' => $legacy->id]);

        $current = SpeechManifestBuild::query()->create($buildAttributes + [
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'source_fingerprint_hmac' => str_repeat('4', 64),
        ]);
        $manifest = app(SpeechManifestGenerationService::class)->generate($current);

        $this->assertSame(1, $engine->synthesizeCalls);
        $this->assertSame('ready', $current->refresh()->status);
        $this->assertSame('consistent-speaker-loudness-v3', $manifest->audio_recipe_revision);
        $this->assertSame(
            hash('sha256', $current->id.'|consistent-speaker-loudness-v3|'.$manifest->audioAsset->content_sha256),
            $manifest->manifest_sha256,
        );
        $this->assertDatabaseHas('speech_manifests', [
            'id' => $manifest->id,
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
        ]);
    }

    public function test_phase_allowlists_keep_full_addresses_out_of_preannouncement_context(): void
    {
        $templates = app(SpeechTemplateService::class);
        $incident = new Incident([
            'title' => 'Zoekactie',
            'location_label' => 'Maliebaan 12, 3581 CP Utrecht',
        ]);

        $this->assertSame([
            'place' => 'Utrecht',
            'province' => 'onbekende provincie',
        ], $templates->contextForIncident('availability', $incident));
        $attendance = $templates->contextForIncident('attendance', $incident);
        $this->assertSame('Maliebaan', $attendance['street']);
        $this->assertSame('12', $attendance['house_number']);
        $this->assertSame('3 5 8 1 C P', $attendance['postcode']);
        $this->assertSame('Utrecht', $attendance['place']);
        $this->assertSame('onbekende provincie', $attendance['province']);

        $this->expectException(ValidationException::class);
        $templates->validate('availability', ['Mogelijke inzet aan {street}.']);
    }

    public function test_templates_reject_markup_entities_malformed_tokens_and_empty_rendered_lines(): void
    {
        $templates = app(SpeechTemplateService::class);
        foreach (['<speak>Alarm</speak>', '<b>Alarm</b>', '&lt;speak&gt;', 'Alarm {{title}}', 'Alarm { title }'] as $unsafe) {
            try {
                $templates->validate('attendance', [$unsafe]);
                $this->fail('Unsafe speech template was accepted: '.$unsafe);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->expectException(ValidationException::class);
        $templates->render('availability', ['{place}'], ['place' => '']);
    }

    public function test_voice_upload_is_stream_verified_duration_bounded_and_encrypted_at_rest(): void
    {
        Storage::fake('local');
        $canonical = 'RIFF'.str_repeat("\0", 249_996);
        Process::fake(function (PendingProcess $process) use ($canonical) {
            $command = is_array($process->command) ? $process->command : [];
            if (($command[0] ?? null) === config('dis.speech.ffmpeg_binary')) {
                $output = end($command);
                $this->assertIsString($output);
                File::put($output, $canonical);

                return Process::result();
            }

            return Process::result(output: json_encode([
                'streams' => [[
                    'codec_type' => 'audio',
                    'codec_name' => 'pcm_s16le',
                    'sample_rate' => '24000',
                    'channels' => 1,
                    'sample_fmt' => 's16',
                ]],
                'format' => ['duration' => '5.2', 'format_name' => 'wav'],
            ], JSON_THROW_ON_ERROR));
        });
        $manager = $this->user('speech-voice@example.test', ['settings.manage']);
        $sample = str_repeat('VOICE-REFERENCE-BYTES-', 100);
        $upload = UploadedFile::fake()->createWithContent('browser-reference.webm', $sample);

        $response = $this->asAdminClient($manager)->post('/api/admin/speech/voice-profiles', [
            'name' => 'Operationele stem',
            'locale' => 'nl-NL',
            'transcript' => 'Dit is het gecontroleerde stemfragment.',
            'consent_confirmed' => '1',
            'audio' => $upload,
        ], ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.reference_duration_seconds', 5.2);

        $profile = SpeechVoiceProfile::query()->findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($profile->sample_storage_path);
        $encrypted = Storage::disk('local')->get($profile->sample_storage_path);
        $this->assertStringNotContainsString($sample, $encrypted);
        $decoded = base64_decode(Crypt::decryptString($encrypted), true);
        $this->assertSame($canonical, $decoded);
        $this->assertSame(hash('sha256', $canonical), $profile->sample_sha256);
        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && ($process->command[0] ?? null) === config('dis.speech.ffmpeg_binary')
            && in_array('pcm_s16le', $process->command, true)
            && in_array('24000', $process->command, true));
        $this->assertStringNotContainsString('gecontroleerde stemfragment', (string) $profile->getRawOriginal('transcript'));
        $voiceAudit = (string) DB::table('audit_logs')
            ->where('action', 'speech.voice_profile_created')->value('metadata');
        $this->assertStringNotContainsString('gecontroleerde stemfragment', $voiceAudit);
    }

    public function test_voxcpm_can_use_the_pinned_built_in_dutch_female_voice_but_chatterbox_requires_a_profile(): void
    {
        Queue::fake();
        $manager = $this->user('speech-built-in@example.test', ['settings.manage']);
        SpeechModelInstallation::query()->create([
            'catalog_key' => 'chatterbox_multilingual_v3',
            'revision' => config('dis.speech.models.chatterbox_multilingual_v3.revision'),
            'weights_sha256' => config('dis.speech.models.chatterbox_multilingual_v3.weights_sha256'),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $manager->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);

        $this->asAdminClient($manager)->patchJson('/api/admin/speech/settings', [
            'enabled' => true,
            'model_id' => 'chatterbox_multilingual_v3',
            'voice_profile_id' => null,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['voice_profile_id']]]);

        SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $manager->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);
        $this->asAdminClient($manager)->patchJson('/api/admin/speech/settings', [
            'enabled' => true,
            'model_id' => 'voxcpm2',
            'voice_profile_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.settings.voice_profile_id', null);

        $preview = $this->asAdminClient($manager)->postJson('/api/admin/speech/previews', [
            'phase' => 'test_ack',
        ])->assertAccepted();
        $previewModel = SpeechPreview::query()->findOrFail($preview->json('data.id'));
        $this->assertDatabaseHas('speech_manifest_builds', [
            'id' => $previewModel->speech_manifest_build_id,
            'voice_profile_id' => null,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
        ]);
    }

    public function test_voice_profile_compatibility_remains_server_authoritative(): void
    {
        Queue::fake();
        $manager = $this->user('speech-profile-capability@example.test', ['settings.manage']);
        [, $profile] = $this->runtime($manager);
        SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $manager->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);
        config()->set('dis.speech.models.voxcpm2.capabilities.voice_clone', false);

        $this->asAdminClient($manager)->patchJson('/api/admin/speech/settings', [
            'enabled' => true,
            'model_id' => 'voxcpm2',
            'voice_profile_id' => $profile->id,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['voice_profile_id']]]);

        $this->asAdminClient($manager)->patchJson('/api/admin/speech/settings', [
            'enabled' => true,
            'model_id' => 'voxcpm2',
            'voice_profile_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.settings.voice_profile_id', null);
    }

    public function test_persisted_incompatible_profile_fails_closed_across_runtime_generation_and_dispatch(): void
    {
        Queue::fake();
        $manager = $this->user('speech-profile-fail-closed@example.test', ['settings.manage']);
        [, $profile] = $this->runtime($manager);
        $installation = SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $manager->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);
        foreach ([
            'speech.enabled' => true,
            'speech.model_id' => 'voxcpm2',
            'speech.voice_profile_id' => $profile->id,
            'speech.speed' => 1.0,
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], [
                'value' => $value, 'is_sensitive' => false, 'updated_by' => $manager->id,
            ]);
        }
        config()->set('dis.speech.models.voxcpm2.capabilities.voice_clone', false);

        try {
            app(SpeechSettingsService::class)->selectedRuntime();
            $this->fail('Een opgeslagen incompatibel stemprofiel mag geen geldige runtime opleveren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('voice_profile_id', $exception->errors());
        }

        try {
            app(SpeechAudioPipeline::class)->segmentCacheKey('Veilige proefmelding.', $installation, $profile, 1.0);
            $this->fail('De audiopipeline mag een incompatibel stemprofiel niet accepteren.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('speech_voice_profile_unsupported', $exception->getMessage());
        }

        $build = SpeechManifestBuild::query()->create([
            'phase' => 'test_ack', 'locale' => 'nl-NL',
            'model_installation_id' => $installation->id, 'voice_profile_id' => $profile->id,
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'speed' => 1.0, 'template_checksum' => str_repeat('1', 64),
            'context_hmac' => str_repeat('2', 64), 'source_fingerprint_hmac' => str_repeat('3', 64),
            'rendered_lines' => ['Veilige proefmelding.'], 'status' => 'queued',
            'progress_percent' => 0, 'expires_at' => now()->addHour(),
        ]);
        try {
            app(SpeechManifestGenerationService::class)->generate($build);
            $this->fail('Manifestgeneratie mag een incompatibel stemprofiel niet gebruiken.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('speech_configuration_missing', $exception->getMessage());
        }

        $incident = Incident::query()->create([
            'reference' => 'INC-SPEECH-INVALID-PROFILE', 'title' => 'Veilige fallback',
            'priority' => 'high', 'status' => 'active', 'is_test' => false,
            'created_by' => $manager->id, 'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id, 'requested_by' => $manager->id,
            'status' => 'sent', 'priority' => 'high', 'message' => 'Open de app.',
        ]);

        $plan = app(SpeechDispatchGateService::class)->prepare($dispatch, $incident, now());

        $this->assertFalse($plan['delayed']);
        $this->assertNull($plan['build_id']);
        $this->assertSame('queued_for_push', $dispatch->refresh()->send_status);
        $this->assertDatabaseMissing('speech_manifest_builds', ['dispatch_request_id' => $dispatch->id]);
    }

    public function test_real_dispatch_gets_an_atomic_ten_second_speech_gate_but_disabled_speech_is_immediate(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-22 12:00:00'));
        $manager = $this->user('speech-gate@example.test', ['settings.manage']);
        [, $profile] = $this->runtime($manager);
        $this->settings($manager, $profile);
        $incident = Incident::query()->create([
            'reference' => 'INC-SPEECH-1', 'title' => 'Zoekactie', 'description' => null,
            'priority' => 'high', 'status' => 'active', 'is_test' => false,
            'location_label' => 'Maliebaan 12, 3581 CP Utrecht', 'created_by' => $manager->id,
            'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id, 'requested_by' => $manager->id,
            'status' => 'sent', 'priority' => 'high', 'message' => 'Test',
        ]);
        $token = FcmToken::query()->create([
            'user_id' => $manager->id, 'device_id' => 'speech-gate-device',
            'token' => 'speech-gate-token', 'token_hash' => hash('sha256', 'speech-gate-token'),
            'platform' => 'android', 'client_type' => 'operator', 'is_active' => true, 'last_seen_at' => now(),
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id, 'user_id' => $manager->id,
            'response_status' => 'pending', 'notified_at' => now(),
        ]);

        $plan = null;
        DB::transaction(function () use ($dispatch, $incident, $token, &$plan): void {
            $plan = app(SpeechDispatchGateService::class)->prepare($dispatch, $incident, now());
            app(DispatchPushOutboxService::class)->store(
                dispatchRequestId: (string) $dispatch->id,
                fcmTokenId: (string) $token->id,
                messageType: 'dispatch_request',
                title: 'Alarm',
                body: 'Open de app.',
                data: ['type' => 'dispatch_request'],
                availableAt: $plan['deadline'],
                releaseReason: 'speech_deadline',
            );
        });
        $this->assertTrue($plan['delayed']);
        $this->assertSame(10.0, now()->diffInSeconds($plan['deadline']));
        $this->assertSame('preparing_speech', $dispatch->refresh()->send_status);
        $this->assertDatabaseHas('speech_manifest_builds', [
            'dispatch_request_id' => $dispatch->id,
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3',
            'status' => 'queued',
        ]);
        $encryptedLines = (string) DB::table('speech_manifest_builds')
            ->where('dispatch_request_id', $dispatch->id)->value('rendered_lines');
        $this->assertStringNotContainsString('Maliebaan', $encryptedLines);
        $this->assertStringNotContainsString('3581', $encryptedLines);
        $this->assertStringNotContainsString('Zoekactie', $encryptedLines);
        $outbox = DispatchPushOutbox::query()->where('dispatch_request_id', $dispatch->id)->sole();
        $this->assertSame('speech_deadline', $outbox->release_reason);
        $this->assertArrayNotHasKey('speech_manifest_id', $outbox->data);
        app(DispatchPushOutboxService::class)->flushPending(100, (string) $dispatch->id);
        Queue::assertNotPushed(SendFcmNotification::class);
        Carbon::setTestNow(now()->addSeconds(10));
        app(DispatchPushOutboxService::class)->flushPending(100, (string) $dispatch->id);
        Queue::assertPushed(SendFcmNotification::class, fn (SendFcmNotification $job): bool => $job->dispatchPushOutboxId === $outbox->id
            && ! array_key_exists('speech_manifest_id', $job->data));

        SystemSetting::query()->updateOrCreate(['key' => 'speech.enabled'], ['value' => false]);
        $immediate = DispatchRequest::query()->create([
            'incident_id' => $incident->id, 'requested_by' => $manager->id,
            'status' => 'sent', 'priority' => 'high', 'message' => 'Test 2',
        ]);
        $plan = app(SpeechDispatchGateService::class)->prepare($immediate, $incident, now());
        $this->assertFalse($plan['delayed']);
        $this->assertSame('queued_for_push', $immediate->refresh()->send_status);
        $this->assertNull($immediate->send_release_deadline);
        Carbon::setTestNow();
    }

    public function test_test_alert_ready_audio_uses_the_fixed_preset_and_strict_recipient_phase_authorization(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-07-22 13:00:00'));
        try {
            $manager = $this->user('test-alert-speech-manager@example.test', ['settings.manage']);
            $pilot = $this->operator('test-alert-speech-pilot@example.test');
            $outsider = $this->operator('test-alert-speech-outsider@example.test');
            [$installation, $profile] = $this->runtime($manager);
            $this->settings($manager, $profile);
            SystemSetting::query()->updateOrCreate(['key' => 'test_alert.message'], [
                'value' => 'Vaste wekelijkse controle. Bevestig deze proefalarmering met Ontvangen in de app.',
                'is_sensitive' => false,
                'updated_by' => $manager->id,
            ]);
            SystemSetting::query()->updateOrCreate(['key' => 'speech.templates.test_ack'], [
                'value' => [
                    'Dit is een rustige proefalarmering.',
                    'Open de D.I.S.-app en bevestig ontvangst.',
                ],
                'is_sensitive' => true,
                'updated_by' => $manager->id,
            ]);
            $speechContent = app(TestAlertSpeechContentService::class);
            $incident = Incident::query()->create([
                'reference' => 'TEST-SPEECH-READY',
                'title' => 'Proefalarmering',
                'description' => $speechContent->deliveredMessage(),
                'priority' => 'normal',
                'status' => 'active',
                'is_test' => true,
                'created_by' => $manager->id,
                'opened_at' => now(),
            ]);
            $dispatch = DispatchRequest::query()->create([
                'incident_id' => $incident->id,
                'requested_by' => $manager->id,
                'status' => 'sent',
                'priority' => 'normal',
                'message' => $speechContent->deliveredMessage(),
                'sent_at' => now(),
            ]);
            DispatchRecipient::query()->create([
                'dispatch_request_id' => $dispatch->id,
                'user_id' => $pilot->id,
                'response_status' => 'pending',
                'notified_at' => now(),
            ]);
            $token = FcmToken::query()->create([
                'user_id' => $pilot->id,
                'device_id' => 'test-alert-speech-device',
                'token' => 'test-alert-speech-token',
                'token_hash' => hash('sha256', 'test-alert-speech-token'),
                'platform' => 'ios',
                'client_type' => 'operator',
                'is_active' => true,
                'last_seen_at' => now(),
            ]);

            $plan = null;
            DB::transaction(function () use ($dispatch, $incident, $token, &$plan): void {
                $plan = app(SpeechDispatchGateService::class)->prepare($dispatch, $incident, now());
                app(DispatchPushOutboxService::class)->store(
                    dispatchRequestId: (string) $dispatch->id,
                    fcmTokenId: (string) $token->id,
                    messageType: 'dispatch_request',
                    title: 'D.I.S proefalarmering',
                    body: (string) $dispatch->message,
                    data: [
                        'type' => 'dispatch_request',
                        'action_mode' => SpeechTemplateService::PHASE_TEST_ACK,
                        'is_test' => 'true',
                        'dispatch_id' => (string) $dispatch->id,
                    ],
                    availableAt: $plan['deadline'],
                    releaseReason: 'speech_deadline',
                );
            });
            $this->assertTrue($plan['delayed']);
            app(SpeechDispatchGateService::class)->queueAfterCommit($plan['build_id']);
            $build = SpeechManifestBuild::query()->findOrFail($plan['build_id']);
            $expectedLines = [
                'Vaste wekelijkse controle.',
                'Dit is een rustige proefalarmering.',
                'Open de D.I.S.-app en bevestig ontvangst.',
            ];
            $this->assertSame(SpeechTemplateService::PHASE_TEST_ACK, $build->phase);
            $this->assertSame($expectedLines, $build->rendered_lines);
            $this->assertSame(
                $expectedLines,
                app(SpeechPreparedPhrasePresetService::class)->all()[0]['preview_lines'],
            );
            $this->assertSame($speechContent->checksum($expectedLines), $build->template_checksum);
            Queue::assertPushed(
                GenerateDispatchSpeechManifest::class,
                fn (GenerateDispatchSpeechManifest $job): bool => $job->buildId === $build->id,
            );
            Queue::assertNotPushed(SendFcmNotification::class);

            $bytes = str_repeat('TEST-ALERT-M4A-CONTENT-', 100);
            $sha256 = hash('sha256', $bytes);
            $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
            $path = (string) config('dis.speech.cache_root')
                .DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $bytes);
            $asset = SpeechAudioAsset::query()->create([
                'content_sha256' => $sha256,
                'storage_path' => $relative,
                'mime_type' => 'audio/mp4',
                'byte_size' => strlen($bytes),
                'duration_ms' => 1800,
            ]);
            $build->forceFill([
                'status' => 'ready',
                'progress_percent' => 100,
                'finished_at' => now(),
            ])->save();
            $manifest = SpeechManifest::query()->create([
                'speech_manifest_build_id' => $build->id,
                'dispatch_request_id' => $dispatch->id,
                'phase' => SpeechTemplateService::PHASE_TEST_ACK,
                'locale' => 'nl-NL',
                'model_catalog_key' => $installation->catalog_key,
                'model_revision' => $installation->revision,
                'model_weights_sha256' => $installation->weights_sha256,
                'voice_profile_id' => $profile->id,
                'voice_consent_version' => $profile->consent_version,
                'voice_design_revision' => null,
                'audio_recipe_revision' => (string) $build->audio_recipe_revision,
                'speed' => (float) $build->speed,
                'template_checksum' => $build->template_checksum,
                'context_hmac' => $build->context_hmac,
                'manifest_sha256' => hash('sha256', 'test-ready-'.$build->id),
                'audio_asset_id' => $asset->id,
                'segment_count' => count($expectedLines),
                'duration_ms' => 1800,
                'expires_at' => now()->addHour(),
                'sealed_at' => now(),
                'created_at' => now(),
            ]);

            app(SpeechDispatchGateService::class)->releaseReady($build, $manifest);
            $outbox = DispatchPushOutbox::query()
                ->where('dispatch_request_id', $dispatch->id)
                ->sole();
            $this->assertSame($manifest->id, $outbox->speech_manifest_id);
            $this->assertSame('speech_ready', $outbox->release_reason);
            $this->assertSame(SpeechTemplateService::PHASE_TEST_ACK, $outbox->data['speech_phase'] ?? null);
            Queue::assertPushed(
                SendFcmNotification::class,
                fn (SendFcmNotification $job): bool => $job->dispatchPushOutboxId === $outbox->id
                    && ($job->data['speech_manifest_id'] ?? null) === $manifest->id
                    && ($job->data['speech_phase'] ?? null) === SpeechTemplateService::PHASE_TEST_ACK,
            );

            $url = '/api/speech/manifests/'.$manifest->id.'/audio';
            $this->get($url, ['Accept' => 'audio/mp4'])->assertUnauthorized();
            $this->asAdminClient($manager)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
            $this->asOperatorClient($outsider)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
            $this->asOperatorClient($pilot)->get($url, ['Accept' => 'audio/mp4'])
                ->assertOk()
                ->assertHeader('Content-Type', 'audio/mp4')
                ->assertHeader('ETag', '"'.$sha256.'"');

            $mismatched = $outbox->data;
            $mismatched['action_mode'] = SpeechTemplateService::PHASE_ATTENDANCE;
            $outbox->forceFill(['data' => $mismatched])->save();
            $this->asOperatorClient($pilot)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
            $mismatched['action_mode'] = SpeechTemplateService::PHASE_TEST_ACK;
            $mismatched['is_test'] = 'false';
            $outbox->forceFill(['data' => $mismatched])->save();
            $this->asOperatorClient($pilot)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_revoking_a_voice_immediately_withdraws_ready_previews_and_derived_cache_bytes(): void
    {
        $manager = $this->user('speech-revoke@example.test', ['settings.manage']);
        [$installation, $profile] = $this->runtime($manager);
        $bytes = str_repeat('REVOKED-VOICE-M4A-', 100);
        $sha256 = hash('sha256', $bytes);
        $root = storage_path('framework/testing/speech-revoke-'.str()->ulid());
        config()->set('dis.speech.cache_root', $root);
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($file));
        File::put($file, $bytes);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256, 'storage_path' => $relative,
            'mime_type' => 'audio/mp4', 'byte_size' => strlen($bytes), 'duration_ms' => 1200,
        ]);
        $build = SpeechManifestBuild::query()->create([
            'phase' => 'test_ack', 'locale' => 'nl-NL', 'model_installation_id' => $installation->id,
            'voice_profile_id' => $profile->id,
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3', 'speed' => 1.0,
            'template_checksum' => str_repeat('1', 64), 'context_hmac' => str_repeat('2', 64),
            'source_fingerprint_hmac' => str_repeat('4', 64), 'rendered_lines' => ['Proefalarm.'],
            'status' => 'ready', 'progress_percent' => 100, 'finished_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        $manifest = SpeechManifest::query()->create([
            'speech_manifest_build_id' => $build->id, 'phase' => 'test_ack', 'locale' => 'nl-NL',
            'model_catalog_key' => $installation->catalog_key, 'model_revision' => $installation->revision,
            'model_weights_sha256' => $installation->weights_sha256, 'voice_profile_id' => $profile->id,
            'voice_consent_version' => 1,
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3', 'speed' => 1.0,
            'template_checksum' => str_repeat('1', 64), 'context_hmac' => str_repeat('2', 64),
            'manifest_sha256' => hash('sha256', 'revoke-'.$build->id),
            'audio_asset_id' => $asset->id, 'segment_count' => 1, 'duration_ms' => 1200,
            'expires_at' => now()->addHour(), 'sealed_at' => now(), 'created_at' => now(),
        ]);
        $preview = SpeechPreview::query()->create([
            'requested_by' => $manager->id, 'phase' => 'test_ack', 'status' => 'ready',
            'progress_percent' => 100, 'rendered_lines' => ['Proefalarm.'],
            'speech_manifest_build_id' => $build->id, 'speech_manifest_id' => $manifest->id,
            'audio_asset_id' => $asset->id, 'expires_at' => now()->addHour(), 'ready_at' => now(),
        ]);
        SpeechCacheEntry::query()->create([
            'cache_key' => str_repeat('5', 64), 'category' => 'composite',
            'audio_asset_id' => $asset->id, 'voice_profile_id' => $profile->id,
            'semantic_hmac' => str_repeat('6', 64), 'status' => 'ready', 'expires_at' => now()->addHour(),
        ]);

        $this->asAdminClient($manager)
            ->deleteJson('/api/admin/speech/voice-profiles/'.$profile->id)
            ->assertNoContent();

        $this->assertSame('failed', $preview->refresh()->status);
        $this->assertSame('speech_voice_consent_revoked', $preview->error_code);
        $this->assertDatabaseMissing('speech_cache_entries', ['voice_profile_id' => $profile->id]);
        $this->assertFileDoesNotExist($file);
        $this->asAdminClient($manager)
            ->get('/api/admin/speech/previews/'.$preview->id.'/audio', ['Accept' => 'audio/mp4'])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'speech_voice_consent_revoked');
    }

    public function test_ready_preview_audio_supports_browser_metadata_and_range_reads(): void
    {
        $manager = $this->user('speech-preview-audio@example.test', ['settings.manage']);
        $bytes = str_repeat('PREVIEW-M4A-CONTENT-', 100);
        $sha256 = hash('sha256', $bytes);
        $root = (string) config('dis.speech.cache_root');
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $bytes);
        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256,
            'storage_path' => $relative,
            'mime_type' => 'audio/mp4',
            'byte_size' => strlen($bytes),
            'duration_ms' => 1500,
        ]);
        $preview = SpeechPreview::query()->create([
            'requested_by' => $manager->id,
            'phase' => 'availability',
            'status' => 'ready',
            'progress_percent' => 100,
            'rendered_lines' => ['Voorwaarschuwing voor Utrecht.'],
            'audio_asset_id' => $asset->id,
            'expires_at' => now()->addHour(),
            'ready_at' => now(),
        ]);
        $url = '/api/admin/speech/previews/'.$preview->id.'/audio';

        $this->asAdminClient($manager)->get($url, ['Accept' => 'audio/mp4'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertHeader('X-Content-SHA256', $sha256);
        $this->asAdminClient($manager)
            ->withHeaders(['If-None-Match' => '', 'Range' => 'bytes=0-9'])
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/'.strlen($bytes));
    }

    public function test_ready_preview_audio_can_be_read_from_a_non_writable_cache(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX cache permissions are enforced only on Linux production hosts.');
        }

        $manager = $this->user('speech-preview-read-only-cache@example.test', ['settings.manage']);
        $bytes = str_repeat('READ-ONLY-PREVIEW-M4A-', 100);
        $sha256 = hash('sha256', $bytes);
        $root = (string) config('dis.speech.cache_root');
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        $path = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $bytes);
        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256,
            'storage_path' => $relative,
            'mime_type' => 'audio/mp4',
            'byte_size' => strlen($bytes),
            'duration_ms' => 1500,
        ]);
        $preview = SpeechPreview::query()->create([
            'requested_by' => $manager->id,
            'phase' => 'availability',
            'status' => 'ready',
            'progress_percent' => 100,
            'rendered_lines' => ['Voorwaarschuwing voor Utrecht.'],
            'audio_asset_id' => $asset->id,
            'expires_at' => now()->addHour(),
            'ready_at' => now(),
        ]);

        try {
            chmod($path, 0440);
            chmod(dirname($path), 0550);
            chmod(dirname(dirname($path)), 0550);
            chmod($root, 0550);
            clearstatcache(true, $root);
            if (is_writable($root)) {
                $this->markTestSkipped('The current POSIX test user can still write to a mode 0550 cache.');
            }
            $this->assertTrue(is_readable($root));
            $this->assertTrue(is_readable($path));

            $this->asAdminClient($manager)
                ->withHeaders(['If-None-Match' => '', 'Range' => 'bytes=0-9'])
                ->get('/api/admin/speech/previews/'.$preview->id.'/audio', ['Accept' => 'audio/mp4'])
                ->assertStatus(206)
                ->assertHeader('Content-Type', 'audio/mp4')
                ->assertHeader('Content-Range', 'bytes 0-9/'.strlen($bytes));
        } finally {
            chmod($root, 0770);
            chmod(dirname(dirname($path)), 0770);
            chmod(dirname($path), 0770);
            chmod($path, 0660);
            clearstatcache(true, $root);
        }
    }

    public function test_restore_reconciliation_fails_closed_for_unverified_models_and_missing_audio(): void
    {
        Queue::fake();
        $engine = new SpeechEngineClientFake;
        $engine->statusResponses = [['status' => 'not_installed', 'progress_percent' => 0]];
        app()->instance(SpeechEngineClient::class, $engine);
        $manager = $this->user('speech-restore@example.test', ['settings.manage']);
        [$installation] = $this->runtime($manager);
        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => str_repeat('a', 64),
            'storage_path' => 'objects/aa/'.str_repeat('a', 64).'.m4a',
            'mime_type' => 'audio/mp4', 'byte_size' => 4096, 'duration_ms' => 1200,
        ]);
        $preview = SpeechPreview::query()->create([
            'requested_by' => $manager->id, 'phase' => 'test_ack', 'status' => 'ready',
            'progress_percent' => 100, 'rendered_lines' => ['Proefalarm.'],
            'audio_asset_id' => $asset->id, 'expires_at' => now()->addHour(), 'ready_at' => now(),
        ]);

        $result = app(SpeechRuntimeReconciliationService::class)->reconcile();

        $this->assertSame(1, $result['models_invalidated']);
        $this->assertSame(1, $result['audio_invalidated']);
        $this->assertFalse($result['regeneration_queued']);
        $this->assertSame('failed', $installation->refresh()->status);
        $this->assertSame('installed_model_unverified_after_restore', $installation->error_code);
        $this->assertSame('failed', $preview->refresh()->status);
        $this->assertSame('speech_audio_missing_after_restore', $preview->error_code);
    }

    public function test_stale_runtime_cleanup_only_removes_allowlisted_old_regular_files(): void
    {
        $root = storage_path('framework/testing/speech-staging-prune-'.str()->ulid());
        File::ensureDirectoryExists($root);
        config()->set('dis.speech.staging_root', $root);
        config()->set('dis.speech.cache_root', $root.DIRECTORY_SEPARATOR.'cache');
        $old = $root.DIRECTORY_SEPARATOR.(string) str()->ulid().'.job.json';
        $fresh = $root.DIRECTORY_SEPARATOR.(string) str()->ulid().'.reference';
        $unknown = $root.DIRECTORY_SEPARATOR.'do-not-delete.txt';
        File::put($old, '{}');
        File::put($fresh, 'reference');
        File::put($unknown, 'operator data');
        touch($old, now()->subHours(25)->getTimestamp());
        touch($unknown, now()->subDays(2)->getTimestamp());
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));

        app(SpeechCachePruner::class)->pruneStaleRuntimeFiles();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh);
        $this->assertFileExists($unknown);
    }

    public function test_mobile_manifest_audio_requires_the_current_recipient_and_phase_and_supports_http_cache_semantics(): void
    {
        Queue::fake();
        $actor = $this->user('speech-audio-actor@example.test', ['settings.manage']);
        $pilot = $this->operator('speech-audio-pilot@example.test');
        $outsider = $this->operator('speech-audio-outsider@example.test');
        $installation = SpeechModelInstallation::query()->create([
            'catalog_key' => 'voxcpm2',
            'revision' => config('dis.speech.models.voxcpm2.revision'),
            'weights_sha256' => config('dis.speech.models.voxcpm2.weights_sha256'),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $actor->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);
        $incident = Incident::query()->create([
            'reference' => 'INC-SPEECH-AUDIO', 'title' => 'Audio contract',
            'priority' => 'high', 'status' => 'active', 'is_test' => false,
            'created_by' => $actor->id, 'opened_at' => now(),
        ]);
        $dispatch = DispatchRequest::query()->create([
            'incident_id' => $incident->id, 'requested_by' => $actor->id,
            'status' => 'sent', 'priority' => 'high', 'message' => 'Open de app.',
            'sent_at' => now(), 'send_status' => 'queued_for_push',
        ]);
        DispatchRecipient::query()->create([
            'dispatch_request_id' => $dispatch->id, 'user_id' => $pilot->id,
            'response_status' => 'pending', 'notified_at' => now(),
        ]);
        $token = FcmToken::query()->create([
            'user_id' => $pilot->id, 'device_id' => 'speech-audio-device',
            'token' => 'speech-audio-token', 'token_hash' => hash('sha256', 'speech-audio-token'),
            'platform' => 'ios', 'client_type' => 'operator', 'is_active' => true, 'last_seen_at' => now(),
        ]);
        $bytes = str_repeat('M4A-INTEGRITY-CONTENT-', 100);
        $sha256 = hash('sha256', $bytes);
        $root = storage_path('framework/testing/speech-mobile-'.str()->ulid());
        config()->set('dis.speech.cache_root', $root);
        $relative = 'objects/'.substr($sha256, 0, 2).'/'.$sha256.'.m4a';
        File::ensureDirectoryExists(dirname($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative)));
        File::put($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative), $bytes);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $asset = SpeechAudioAsset::query()->create([
            'content_sha256' => $sha256, 'storage_path' => $relative,
            'mime_type' => 'audio/mp4', 'byte_size' => strlen($bytes), 'duration_ms' => 1500,
        ]);
        $build = SpeechManifestBuild::query()->create([
            'dispatch_request_id' => $dispatch->id, 'phase' => 'attendance', 'locale' => 'nl-NL',
            'model_installation_id' => $installation->id, 'voice_profile_id' => null,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3', 'speed' => 1.0,
            'template_checksum' => str_repeat('1', 64), 'context_hmac' => str_repeat('2', 64),
            'source_fingerprint_hmac' => str_repeat('3', 64),
            'rendered_lines' => ['Versleutelde alarmering.'], 'status' => 'ready',
            'progress_percent' => 100, 'release_deadline' => now()->addSeconds(10),
            'finished_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        $manifest = SpeechManifest::query()->create([
            'speech_manifest_build_id' => $build->id, 'dispatch_request_id' => $dispatch->id,
            'phase' => 'attendance', 'locale' => 'nl-NL',
            'model_catalog_key' => 'voxcpm2', 'model_revision' => $installation->revision,
            'model_weights_sha256' => $installation->weights_sha256,
            'voice_profile_id' => null, 'voice_consent_version' => null,
            'voice_design_revision' => config('dis.speech.models.voxcpm2.built_in_voice_design_revision'),
            'audio_recipe_revision' => 'consistent-speaker-loudness-v3', 'speed' => 1.0,
            'template_checksum' => str_repeat('1', 64), 'context_hmac' => str_repeat('2', 64),
            'manifest_sha256' => hash('sha256', 'manifest-'.$build->id),
            'audio_asset_id' => $asset->id, 'segment_count' => 1, 'duration_ms' => 1500,
            'expires_at' => now()->addHour(), 'sealed_at' => now(), 'created_at' => now(),
        ]);
        DispatchPushOutbox::query()->create([
            'deduplication_key' => hash('sha256', 'speech-mobile-'.$dispatch->id),
            'dispatch_request_id' => $dispatch->id, 'fcm_token_id' => $token->id,
            'message_type' => 'dispatch_request', 'title' => 'Alarm', 'body' => 'Open de app.',
            'data' => [
                'type' => 'dispatch_request',
                'action_mode' => 'attendance',
                'is_test' => 'false',
                'speech_manifest_id' => 'stale-value',
                'speech_phase' => 'stale-phase',
                'speech_manifest_url' => 'https://invalid.example/audio',
                'speech_manifest_version' => '0',
                'speech_locale' => 'nl-BE',
            ],
            'available_at' => now()->addSeconds(10), 'release_reason' => 'speech_deadline',
        ]);
        app(SpeechDispatchGateService::class)->releaseReady($build, $manifest);
        $url = '/api/speech/manifests/'.$manifest->id.'/audio';
        $speechPushData = DispatchPushOutbox::query()->where('speech_manifest_id', $manifest->id)->sole()->data;
        $this->assertSame((string) $manifest->id, $speechPushData['speech_manifest_id'] ?? null);
        $this->assertSame('attendance', $speechPushData['speech_phase'] ?? null);
        $this->assertArrayNotHasKey('speech_manifest_url', $speechPushData);
        $this->assertArrayNotHasKey('speech_manifest_version', $speechPushData);
        $this->assertArrayNotHasKey('speech_locale', $speechPushData);

        $this->get($url, ['Accept' => 'audio/mp4'])->assertUnauthorized();
        $this->asAdminClient($actor)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
        $this->asOperatorClient($outsider)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
        $this->asOperatorClient($pilot)->get($url, ['Accept' => 'audio/mp4'])
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/mp4')
            ->assertHeader('Accept-Ranges', 'bytes')
            ->assertHeader('ETag', '"'.$sha256.'"')
            ->assertHeader('X-Content-SHA256', $sha256)
            ->assertHeader('Digest', 'sha-256='.base64_encode(hex2bin($sha256)));
        $this->asOperatorClient($pilot)->withHeader('If-None-Match', '"'.$sha256.'"')
            ->get($url, ['Accept' => 'audio/mp4'])->assertStatus(304);
        $this->asOperatorClient($pilot)->withHeaders(['If-None-Match' => '', 'Range' => 'bytes=0-9'])
            ->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 0-9/'.strlen($bytes));
        $this->asOperatorClient($pilot)->withHeaders(['If-None-Match' => '', 'Range' => 'bytes='.strlen($bytes).'-'])
            ->get($url, ['Accept' => 'audio/mp4'])->assertStatus(416);

        DB::table('speech_manifests')->where('id', $manifest->id)->update(['phase' => 'availability']);
        $this->asOperatorClient($pilot)->get($url, ['Accept' => 'audio/mp4'])->assertForbidden();
        DB::table('speech_manifests')->where('id', $manifest->id)->update([
            'phase' => 'attendance', 'expires_at' => now()->subSecond(),
        ]);
        $this->asOperatorClient($pilot)->get($url, ['Accept' => 'audio/mp4'])
            ->assertStatus(410)
            ->assertJsonPath('error.code', 'speech_manifest_expired');
    }

    /** @return array{SpeechModelInstallation, SpeechVoiceProfile} */
    private function runtime(User $actor): array
    {
        $installation = SpeechModelInstallation::query()->create([
            'catalog_key' => 'chatterbox_multilingual_v3',
            'revision' => 'fixed-revision-v3',
            'weights_sha256' => str_repeat('a', 64),
            'status' => 'installed', 'progress_percent' => 100,
            'requested_by' => $actor->id, 'license_confirmed_at' => now(), 'installed_at' => now(),
        ]);
        $profile = SpeechVoiceProfile::query()->create([
            'name' => 'Nederlandse stem', 'locale' => 'nl-NL',
            'transcript' => 'Dit is het gecontroleerde stemfragment.',
            'consent_statement' => 'Toestemming bevestigd.', 'consent_recorded_at' => now(),
            'sample_storage_path' => 'speech/voices/'.str()->ulid().'.enc',
            'sample_sha256' => str_repeat('b', 64), 'sample_byte_size' => 4096,
            'reference_duration_ms' => 5000, 'consent_version' => 1,
            'status' => 'ready', 'created_by' => $actor->id,
        ]);

        return [$installation, $profile];
    }

    private function settings(User $actor, SpeechVoiceProfile $profile): void
    {
        foreach ([
            'speech.enabled' => true,
            'speech.model_id' => 'chatterbox_multilingual_v3',
            'speech.voice_profile_id' => $profile->id,
            'speech.speed' => 1.0,
            'speech.pre_generate_on_save' => true,
        ] as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], [
                'value' => $value, 'is_sensitive' => false, 'updated_by' => $actor->id,
            ]);
        }
    }

    private function user(string $email, array $permissionNames): User
    {
        $user = User::query()->create([
            'name' => 'Speech Manager', 'first_name' => 'Speech', 'last_name' => 'Manager',
            'email' => $email, 'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active', 'two_factor_enabled' => true, 'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'speech-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Speech test role', 'can_use_operator_app' => false, 'can_use_admin_app' => true,
        ]);
        foreach ($permissionNames as $name) {
            $permission = Permission::query()->firstOrCreate(
                ['name' => $name],
                ['display_name' => $name, 'category' => 'system_configuration', 'description' => $name],
            );
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function operator(string $email): User
    {
        $user = User::query()->create([
            'name' => 'Speech Pilot', 'first_name' => 'Speech', 'last_name' => 'Pilot',
            'email' => $email, 'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active', 'push_enabled' => true,
            'two_factor_enabled' => true, 'two_factor_confirmed_at' => now(),
        ]);
        $role = Role::query()->create([
            'name' => 'speech-operator-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Speech operator role', 'can_use_operator_app' => true, 'can_use_admin_app' => false,
        ]);
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asAdminClient(User $user): static
    {
        $token = $user->createToken('Speech test', ['*', 'client:web'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    private function asOperatorClient(User $user): static
    {
        $token = $user->createToken('Speech operator test', ['*', 'client:operator'], now()->addHour())->plainTextToken;
        Auth::forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token);
    }
}

final class SpeechEngineClientFake implements SpeechEngineClient
{
    /** @var list<array<string, mixed>> */
    public array $installResponses = [];

    /** @var list<array<string, mixed>> */
    public array $statusResponses = [];

    public int $installCalls = 0;

    public int $statusCalls = 0;

    public int $cancelCalls = 0;

    public int $synthesizeCalls = 0;

    public bool $cancelThrows = false;

    public bool $writeSyntheticWave = false;

    public function health(): array
    {
        return ['status' => 'ok', 'ready' => true];
    }

    public function install(string $modelId, array $model): array
    {
        $this->installCalls++;

        return array_shift($this->installResponses) ?? [
            'status' => 'installed',
            'progress_percent' => 100,
            'installed_revision' => $model['revision'],
            'weights_sha256' => $model['weights_sha256'],
        ];
    }

    public function cancelInstall(string $modelId): array
    {
        $this->cancelCalls++;
        if ($this->cancelThrows) {
            throw new SpeechEngineException('engine_unavailable');
        }

        return ['status' => 'failed', 'error_code' => 'installation_cancelled_for_alarm'];
    }

    public function status(string $modelId): array
    {
        $this->statusCalls++;

        return array_shift($this->statusResponses) ?? ['status' => 'not_installed', 'progress_percent' => 0];
    }

    public function synthesize(string $modelId, string $jobBasename, string $outputBasename): array
    {
        $this->synthesizeCalls++;
        if ($this->writeSyntheticWave) {
            $root = (string) config('dis.speech.staging_root');
            File::ensureDirectoryExists($root);
            File::put($root.DIRECTORY_SEPARATOR.$outputBasename, 'RIFF'.pack('V', 132).'WAVE'.str_repeat("\0", 128));
        }

        return ['duration_ms' => 1000];
    }
}
