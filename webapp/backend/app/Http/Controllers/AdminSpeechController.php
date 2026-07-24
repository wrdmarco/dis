<?php

namespace App\Http\Controllers;

use App\Http\Requests\Speech\CreateSpeechPreviewRequest;
use App\Http\Requests\Speech\IndexSpeechCacheEntriesRequest;
use App\Http\Requests\Speech\InstallSpeechModelRequest;
use App\Http\Requests\Speech\RegenerateSpeechCacheRequest;
use App\Http\Requests\Speech\StoreSpeechVoiceProfileRequest;
use App\Http\Requests\Speech\UpdateSpeechSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DispatchPushOutbox;
use App\Models\SpeechCacheEntry;
use App\Models\SpeechManifest;
use App\Models\SpeechPreview;
use App\Models\SpeechVoiceProfile;
use App\Services\SpeechAudioPipeline;
use App\Services\SpeechCacheContentService;
use App\Services\SpeechCacheMaintenanceService;
use App\Services\SpeechModelCatalog;
use App\Services\SpeechModelInstallationService;
use App\Services\SpeechPreviewService;
use App\Services\SpeechSettingsService;
use App\Services\SpeechTemplateService;
use App\Services\SpeechVoiceProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AdminSpeechController extends Controller
{
    public function __construct(
        private readonly SpeechSettingsService $settings,
        private readonly SpeechModelCatalog $catalog,
        private readonly SpeechModelInstallationService $models,
        private readonly SpeechVoiceProfileService $voices,
        private readonly SpeechPreviewService $previews,
        private readonly SpeechCacheMaintenanceService $cache,
        private readonly SpeechCacheContentService $cacheContent,
        private readonly SpeechAudioPipeline $audio,
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->settings->status());
    }

    public function update(UpdateSpeechSettingsRequest $request): JsonResponse
    {
        return ApiResponse::success($this->settings->update($request->validated(), $request->user()));
    }

    public function install(InstallSpeechModelRequest $request, string $modelId): JsonResponse
    {
        $this->models->start($modelId, $request->boolean('license_confirmed'), $request->user());
        $model = collect($this->catalog->summaries())->firstWhere('id', $modelId);

        return ApiResponse::success(['model' => $model], 202);
    }

    public function storeVoiceProfile(StoreSpeechVoiceProfileRequest $request): JsonResponse
    {
        $profile = $this->voices->create(
            $request->string('name')->toString(),
            $request->string('locale')->toString(),
            $request->string('transcript')->toString(),
            $request->file('audio'),
            $request->user(),
        );

        return ApiResponse::success($this->settings->voiceProfile($profile), 201);
    }

    public function destroyVoiceProfile(Request $request, SpeechVoiceProfile $voiceProfile): Response
    {
        $this->voices->delete($voiceProfile, $request->user());

        return response()->noContent();
    }

    public function createPreview(CreateSpeechPreviewRequest $request): JsonResponse
    {
        $preview = $this->previews->create($request->string('phase')->toString(), $request->user());

        return ApiResponse::success($this->previews->payload($preview), 202);
    }

    public function preview(SpeechPreview $preview): JsonResponse
    {
        return ApiResponse::success($this->previews->payload($preview->refresh()));
    }

    public function previewAudio(Request $request, SpeechPreview $preview): Response
    {
        $preview->refresh()->load(['audioAsset', 'manifest.voiceProfile']);
        if ($preview->expires_at?->isPast() === true) {
            return ApiResponse::error('speech_preview_expired', 'Deze spraakpreview is verlopen.', 410);
        }
        if (in_array($preview->status, ['queued', 'processing'], true)) {
            return ApiResponse::success($this->previews->payload($preview), 202);
        }
        if ($preview->error_code === 'speech_voice_consent_revoked'
            || ($preview->manifest?->voice_profile_id !== null
                && ($preview->manifest->voiceProfile === null
                    || (int) $preview->manifest->voiceProfile->consent_version !== (int) $preview->manifest->voice_consent_version
                    || $preview->manifest->voiceProfile->status !== 'ready'))) {
            return ApiResponse::error('speech_voice_consent_revoked', 'Deze spraakpreview is ingetrokken.', 410);
        }
        if ($preview->status !== 'ready' || $preview->audioAsset === null) {
            return ApiResponse::error('speech_preview_failed', 'De spraakpreview is niet beschikbaar.', 410, [
                'error_code' => $preview->error_code,
            ]);
        }

        try {
            $path = $this->audio->verifiedAssetPath($preview->audioAsset);
        } catch (\Throwable) {
            return ApiResponse::error('speech_preview_expired', 'Deze spraakpreview is niet meer beschikbaar.', 410);
        }

        $sha256 = (string) $preview->audioAsset->content_sha256;

        return $this->audioResponse($request, $path, '"'.$sha256.'"', $sha256);
    }

    public function cacheEntries(IndexSpeechCacheEntriesRequest $request): JsonResponse
    {
        return ApiResponse::paginated($this->cacheContent->paginate($request->validated()));
    }

    public function cacheEntryAudio(Request $request, SpeechCacheEntry $speechCacheEntry): Response
    {
        $audio = $this->cacheContent->audio($speechCacheEntry);

        return $this->audioResponse(
            $request,
            $audio['path'],
            $audio['etag'],
            immutable: false,
        );
    }

    public function regenerateCache(RegenerateSpeechCacheRequest $request): JsonResponse
    {
        $job = $this->cache->start($request->string('scope')->toString(), $request->user());

        return ApiResponse::success(['job' => [
            'id' => (string) $job->id,
            'scope' => (string) $job->scope,
            'status' => (string) $job->status,
            'progress_percent' => (int) $job->progress_percent,
            'error_code' => $job->error_code,
            'created_at' => $job->created_at?->toIso8601String(),
            'finished_at' => null,
        ]], 202);
    }

    public function manifestAudio(Request $request, SpeechManifest $manifest): Response
    {
        $manifest->load(['audioAsset', 'build', 'dispatchRequest.incident', 'voiceProfile']);
        if ($manifest->expires_at?->isPast() === true || $manifest->audioAsset === null) {
            return ApiResponse::error('speech_manifest_expired', 'Dit spraakbericht is niet meer beschikbaar.', 410);
        }
        if ($manifest->voice_profile_id !== null
            && ($manifest->voiceProfile === null
                || (int) $manifest->voiceProfile->consent_version !== (int) $manifest->voice_consent_version
                || $manifest->voiceProfile->status !== 'ready')) {
            return ApiResponse::error('speech_voice_consent_revoked', 'Dit spraakbericht is ingetrokken.', 410);
        }
        $user = $request->user();
        $recipient = $manifest->dispatch_request_id !== null && $user !== null
            && $manifest->dispatchRequest?->recipients()->where('user_id', $user->id)->exists();
        $incident = $manifest->dispatchRequest?->incident;
        $expectedPhase = (bool) $incident?->is_test
            ? SpeechTemplateService::PHASE_TEST_ACK
            : SpeechTemplateService::PHASE_ATTENDANCE;
        $attached = $recipient && DispatchPushOutbox::query()
            ->where('dispatch_request_id', $manifest->dispatch_request_id)
            ->where('speech_manifest_id', $manifest->id)
            ->whereHas('fcmToken', fn ($query) => $query->where('user_id', $user->id))
            ->get(['data'])
            ->contains(function (DispatchPushOutbox $outbox) use ($expectedPhase, $incident): bool {
                $data = (array) $outbox->data;

                return ($data['action_mode'] ?? null) === $expectedPhase
                    && ($data['speech_phase'] ?? null) === $expectedPhase
                    && ($data['is_test'] ?? null) === ((bool) $incident?->is_test ? 'true' : 'false');
            });
        $deliverable = $manifest->dispatchRequest !== null
            && $incident !== null
            && $manifest->phase === $expectedPhase
            && $manifest->build !== null
            && (string) $manifest->build->dispatch_request_id === (string) $manifest->dispatch_request_id
            && $manifest->build->phase === $expectedPhase
            && in_array($manifest->dispatchRequest->status, ['sent', 'escalated'], true)
            && ! in_array($incident->status, ['resolved', 'cancelled'], true);
        abort_unless($recipient && $attached && $deliverable, 403);

        try {
            $path = $this->audio->verifiedAssetPath($manifest->audioAsset);
        } catch (\Throwable) {
            return ApiResponse::error('speech_manifest_expired', 'Dit spraakbericht is niet meer beschikbaar.', 410);
        }

        $sha256 = (string) $manifest->audioAsset->content_sha256;

        return $this->audioResponse($request, $path, '"'.$sha256.'"', $sha256);
    }

    private function audioResponse(
        Request $request,
        string $path,
        string $etag,
        ?string $contentSha256 = null,
        bool $immutable = true,
    ): Response {
        if (preg_match('/^"[A-Za-z0-9._-]{1,180}"$/D', $etag) !== 1) {
            throw new \RuntimeException('Speech audio entity tag is invalid.');
        }

        $size = @filesize($path);
        if (! is_int($size) || $size < 1) {
            throw new \RuntimeException('Speech audio size is invalid.');
        }
        $headers = [
            'Content-Type' => 'audio/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => $immutable
                ? 'private, max-age=3600, immutable'
                : 'no-store, private',
            'ETag' => $etag,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="speech.m4a"',
        ];
        if ($contentSha256 !== null) {
            $digestBytes = preg_match('/^[a-f0-9]{64}$/D', $contentSha256) === 1
                ? hex2bin($contentSha256)
                : false;
            if (! is_string($digestBytes)) {
                throw new \RuntimeException('Speech audio digest is invalid.');
            }
            $headers['X-Content-SHA256'] = $contentSha256;
            $headers['Digest'] = 'sha-256='.base64_encode($digestBytes);
        }
        $range = trim((string) $request->header('Range', ''));
        if ($range !== '' && ! $this->rangeIsSatisfiable($range, $size)) {
            return response('', 416, $headers + ['Content-Range' => 'bytes */'.$size]);
        }

        $response = response()->file($path, $headers);
        $response->isNotModified($request);

        return $response;
    }

    private function rangeIsSatisfiable(string $range, int $size): bool
    {
        if (preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches) !== 1
            || ($matches[1] === '' && $matches[2] === '')) {
            return false;
        }
        if ($matches[1] === '') {
            return (int) $matches[2] > 0;
        }

        $start = (int) $matches[1];
        $end = $matches[2] === '' ? $size - 1 : (int) $matches[2];

        return $start < $size && $end >= $start;
    }
}
