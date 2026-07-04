<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AppVersion;
use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Services\DeveloperAccessService;
use App\Support\MobileApiPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use ZipArchive;

final class UpdateController extends Controller
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly DeveloperAccessService $developerAccess,
    ) {}

    public function androidCurrent(Request $request): JsonResponse
    {
        $versionCode = (int) $request->integer('version_code', 0);
        $applicationId = $this->androidApplicationId($request);
        $notSupported = AppVersion::query()
            ->where('platform', 'android')
            ->where('application_id', $applicationId)
            ->where('version_code', $versionCode)
            ->whereIn('status', ['not_supported', 'blocked'])
            ->exists();
        $minimumSupportedVersionCode = SystemSetting::integer($this->minimumSupportedVersionCodeKey($applicationId), 1);
        $latest = AppVersion::query()
            ->where('platform', 'android')
            ->where('application_id', $applicationId)
            ->whereIn('status', ['supported', 'deprecated'])
            ->orderByDesc('version_code')
            ->first();

        return ApiResponse::success([
            'update_required' => $notSupported || ($versionCode > 0 && $versionCode < $minimumSupportedVersionCode),
            'latest' => MobileApiPayload::appVersion($latest),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(
            AppVersion::query()
                ->where('platform', 'android')
                ->when($request->input('application_id'), fn ($query, string $applicationId) => $query->where('application_id', $applicationId))
                ->orderByDesc('version_code')
                ->paginate((int) $request->integer('per_page', 25)),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'application_id' => ['nullable', 'string', 'max:190'],
            'version_name' => ['required', 'string', 'max:80'],
            'version_code' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:supported,deprecated,not_supported,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'download_url' => ['nullable', 'url', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ]);
        $data['application_id'] = $this->normalizeAndroidApplicationId($data['application_id'] ?? null);

        $version = AppVersion::query()->updateOrCreate([
            'platform' => 'android',
            'application_id' => $data['application_id'],
            'version_code' => $data['version_code'],
        ], $data + ['platform' => 'android', 'created_by' => $request->user()?->id]);
        $this->auditService->record('updates.android_created', $version, $request->user());

        return ApiResponse::success($version, 201);
    }

    public function uploadAndroid(Request $request): JsonResponse
    {
        return $this->storeAndroidUpload($request, 'admin');
    }

    public function developerUploadAndroid(Request $request): JsonResponse
    {
        $this->developerAccess->authorize($request, DeveloperAccessService::SCOPE_ANDROID_UPLOAD);

        return $this->storeAndroidUpload($request, 'developer_api');
    }

    private function storeAndroidUpload(Request $request, string $source): JsonResponse
    {
        $zipUpload = $this->androidZipUpload($request);
        $data = $zipUpload['data'] ?? $this->androidUploadData($request);

        $apkPath = $zipUpload['apk_path'] ?? null;
        $apkSize = $zipUpload['apk_size'] ?? null;

        if ($apkPath === null) {
            $file = $request->file('apk');
            if ($file === null || ! $file->isValid()) {
                throw ValidationException::withMessages(['apk' => ['Upload een geldig APK-bestand.']]);
            }
            if (strtolower($file->getClientOriginalExtension()) !== 'apk') {
                throw ValidationException::withMessages(['apk' => ['Het APK-bestand moet de extensie .apk hebben.']]);
            }
            $apkPath = $file->getRealPath();
            $apkSize = $file->getSize();
        }

        if (! is_string($apkPath) || ! is_file($apkPath)) {
            throw ValidationException::withMessages(['apk' => ['APK-bestand kon niet worden gelezen.']]);
        }

        $directory = 'android-apks';
        $applicationId = $data['application_id'];
        $filename = 'dis-'.$applicationId.'-'.$data['version_code'].'-'.$data['version_name'].'.apk';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'dis-'.$data['version_code'].'.apk';
        $path = $directory.'/'.$filename;
        Storage::disk('local')->put($path, (string) file_get_contents($apkPath));
        $absolutePath = Storage::disk('local')->path($path);
        $sha256 = hash_file('sha256', $absolutePath);

        if (($data['artifact_sha256'] ?? null) !== null && ! hash_equals(strtolower((string) $data['artifact_sha256']), $sha256)) {
            Storage::disk('local')->delete($path);
            if (($zipUpload['apk_path'] ?? null) !== null) {
                @unlink((string) $zipUpload['apk_path']);
            }

            return ApiResponse::error('apk_hash_mismatch', 'APK SHA-256 does not match metadata.', 422, [
                'expected' => strtolower((string) $data['artifact_sha256']),
                'actual' => $sha256,
            ]);
        }

        $version = AppVersion::query()->updateOrCreate([
            'platform' => 'android',
            'application_id' => $applicationId,
            'version_code' => $data['version_code'],
        ], [
            'platform' => 'android',
            'application_id' => $applicationId,
            'version_name' => $data['version_name'],
            'status' => $data['status'],
            'artifact_sha256' => $sha256,
            'download_url' => $this->androidDirectDownloadUrl($path),
            'release_notes' => $data['release_notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $version->update(['download_url' => $this->androidDirectDownloadUrl($path)]);
        $this->auditService->record('updates.android_apk_uploaded', $version, $request->user(), [
            'artifact_path' => $path,
            'artifact_size_bytes' => $apkSize,
            'release_zip' => $request->hasFile('release_zip'),
            'source' => $source,
        ]);

        $compatibilityChanges = $this->applyAndroidCompatibilityPolicy($data['minimum_supported_version_code'] ?? null, $version, $request);
        if ($compatibilityChanges['not_supported_versions'] > 0 || $compatibilityChanges['minimum_supported_version_code'] !== null) {
            $this->auditService->record('updates.android_compatibility_applied', $version, $request->user(), $compatibilityChanges);
        }

        $pruned = $this->pruneOldAndroidArtifacts($version->refresh());
        if ($pruned > 0) {
            $this->auditService->record('updates.android_artifacts_pruned', $version, $request->user(), ['artifact_count' => $pruned]);
        }

        if (($zipUpload['apk_path'] ?? null) !== null) {
            @unlink((string) $zipUpload['apk_path']);
        }

        return ApiResponse::success($version->refresh(), 201);
    }

    /**
     * @return array{data: array{application_id: string, version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null, minimum_supported_version_code?: int|null}, apk_path: string, apk_size: int}|array{}
     */
    private function androidZipUpload(Request $request): array
    {
        if (! $request->hasFile('release_zip')) {
            return [];
        }

        $request->validate([
            'release_zip' => ['required', File::types(['zip'])->max(512 * 1024)],
        ]);
        $file = $request->file('release_zip');
        if ($file === null || ! $file->isValid()) {
            throw ValidationException::withMessages(['release_zip' => ['Upload een geldig release ZIP-bestand.']]);
        }
        if (strtolower($file->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages(['release_zip' => ['Releasebestand moet een .zip-bestand zijn.']]);
        }

        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['release_zip' => ['Release ZIP could not be opened.']]);
        }

        try {
            $apkEntries = [];
            $metadataEntry = null;
            $compatibilityEntry = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $base = basename(str_replace('\\', '/', $name));

                if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) === 'apk') {
                    $apkEntries[] = $name;
                }

                if ($base === 'metadata.json') {
                    $metadataEntry = $name;
                }

                if ($base === 'compatibility.json') {
                    $compatibilityEntry = $name;
                }
            }

            if (count($apkEntries) !== 1 || $metadataEntry === null) {
                throw ValidationException::withMessages(['release_zip' => ['Release ZIP must contain one APK and metadata.json.']]);
            }

            $metadata = json_decode($this->stripUtf8Bom((string) $zip->getFromName($metadataEntry)), true);
            if (! is_array($metadata)) {
                throw ValidationException::withMessages(['metadata' => ['metadata.json must contain valid JSON.']]);
            }

            if ($compatibilityEntry !== null) {
                $compatibility = json_decode($this->stripUtf8Bom((string) $zip->getFromName($compatibilityEntry)), true);
                if (! is_array($compatibility)) {
                    throw ValidationException::withMessages(['compatibility' => ['compatibility.json must contain valid JSON.']]);
                }

                if (isset($compatibility['minimum_supported_version_code'])) {
                    $metadata['minimum_supported_version_code'] = $compatibility['minimum_supported_version_code'];
                }
            }

            $apkContents = $zip->getFromName($apkEntries[0]);
            if (! is_string($apkContents) || $apkContents === '') {
                throw ValidationException::withMessages(['apk' => ['APK in release ZIP is empty or unreadable.']]);
            }

            $tempApk = tempnam(sys_get_temp_dir(), 'dis-apk-');
            if ($tempApk === false) {
                throw ValidationException::withMessages(['apk' => ['Could not create temporary APK file.']]);
            }
            file_put_contents($tempApk, $apkContents);

            return [
                'data' => $this->validateAndroidMetadata($metadata),
                'apk_path' => $tempApk,
                'apk_size' => strlen($apkContents),
            ];
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{application_id: string, version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null, minimum_supported_version_code?: int|null}
     */
    private function androidUploadData(Request $request): array
    {
        if ($request->hasFile('metadata')) {
            $metadataFile = $request->file('metadata');
            abort_unless($metadataFile !== null && $metadataFile->isValid(), 422);

            $metadata = json_decode($this->stripUtf8Bom((string) file_get_contents($metadataFile->getRealPath())), true);
            if (! is_array($metadata)) {
                throw ValidationException::withMessages(['metadata' => ['Metadata file must contain valid JSON.']]);
            }

            return $this->validateAndroidMetadata($metadata);
        }

        $data = $request->validate([
            'application_id' => ['nullable', 'string', 'max:190'],
            'version_name' => ['required', 'string', 'max:80'],
            'version_code' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:supported,deprecated,not_supported,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'minimum_supported_version_code' => ['nullable', 'integer', 'min:1'],
            'apk' => ['required', 'file', 'max:512000'],
        ]);

        $data['application_id'] = $this->normalizeAndroidApplicationId($data['application_id'] ?? null);

        return $data;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{application_id: string, version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null, minimum_supported_version_code?: int|null}
     */
    private function validateAndroidMetadata(array $metadata): array
    {
        $data = Validator::make($metadata, [
            'application_id' => ['nullable', 'string', 'max:190'],
            'version_name' => ['required', 'string', 'max:80'],
            'version_code' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:supported,deprecated,not_supported,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'minimum_supported_version_code' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $data['application_id'] = $this->normalizeAndroidApplicationId($data['application_id'] ?? null);

        return $data;
    }

    private function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    private function androidApplicationId(Request $request): string
    {
        return $this->normalizeAndroidApplicationId($request->query('application_id'));
    }

    private function normalizeAndroidApplicationId(mixed $applicationId): string
    {
        $value = is_string($applicationId) ? trim($applicationId) : '';

        return $value !== '' ? $value : (string) config('dis.updates.android_application_id', 'nl.wrdmarco.dis');
    }

    private function minimumSupportedVersionCodeKey(string $applicationId): string
    {
        if ($applicationId === (string) config('dis.updates.android_application_id', 'nl.wrdmarco.dis')) {
            return 'updates.android.minimum_supported_version_code';
        }

        return 'updates.android.'.$applicationId.'.minimum_supported_version_code';
    }

    private function androidArtifactPath(AppVersion $version): string
    {
        $filename = 'dis-'.$version->application_id.'-'.$version->version_code.'-'.$version->version_name.'.apk';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'dis.apk';

        return 'android-apks/'.$filename;
    }

    private function androidDirectDownloadUrl(string $path): string
    {
        return url('/apk/'.basename($path));
    }

    private function pruneOldAndroidArtifacts(AppVersion $currentVersion): int
    {
        $currentPath = $this->androidArtifactPath($currentVersion);
        $deleted = 0;

        AppVersion::query()
            ->where('platform', 'android')
            ->where('application_id', $currentVersion->application_id)
            ->where('id', '!=', $currentVersion->id)
            ->whereNotNull('download_url')
            ->get()
            ->each(function (AppVersion $version) use ($currentPath, &$deleted): void {
                $path = $this->androidArtifactPath($version);
                if ($path !== $currentPath && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                    $deleted++;
                }

                $version->update(['download_url' => null]);
            });

        $currentPrefix = 'android-apks/dis-'.preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $currentVersion->application_id).'-';
        foreach (Storage::disk('local')->files('android-apks') as $path) {
            if (! str_ends_with(strtolower($path), '.apk') || $path === $currentPath) {
                continue;
            }

            if (! str_starts_with($path, $currentPrefix)) {
                continue;
            }

            Storage::disk('local')->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @return array{minimum_supported_version_code: int|null, not_supported_versions: int}
     */
    private function applyAndroidCompatibilityPolicy(mixed $minimumSupportedVersionCode, AppVersion $currentVersion, Request $request): array
    {
        if (! is_numeric($minimumSupportedVersionCode)) {
            return ['minimum_supported_version_code' => null, 'not_supported_versions' => 0];
        }

        $minimum = (int) $minimumSupportedVersionCode;
        SystemSetting::query()->updateOrCreate(
            ['key' => $this->minimumSupportedVersionCodeKey((string) $currentVersion->application_id)],
            ['value' => $minimum, 'is_sensitive' => false, 'updated_by' => $request->user()?->id],
        );

        $notSupportedVersions = AppVersion::query()
            ->where('platform', 'android')
            ->where('application_id', $currentVersion->application_id)
            ->where('version_code', '<', $minimum)
            ->where('status', '!=', 'not_supported')
            ->update(['status' => 'not_supported']);

        return [
            'minimum_supported_version_code' => $minimum,
            'not_supported_versions' => $notSupportedVersions,
        ];
    }

    public function downloadAndroid(AppVersion $version): RedirectResponse
    {
        abort_unless($version->platform === 'android' && $version->download_url !== null, 404);

        $path = $this->androidArtifactPath($version);
        $filename = basename($path);

        abort_unless(Storage::disk('local')->exists($path), 404);

        return redirect()->away($this->androidDirectDownloadUrl($path));
    }

    public function update(Request $request, AppVersion $version): JsonResponse
    {
        $version->update($request->validate([
            'status' => ['sometimes', 'in:supported,deprecated,not_supported,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'download_url' => ['nullable', 'url', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ]));
        $this->auditService->record('updates.android_updated', $version, $request->user());

        return ApiResponse::success($version->refresh());
    }
}
