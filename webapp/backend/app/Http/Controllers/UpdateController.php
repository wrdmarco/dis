<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AppVersion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

final class UpdateController extends Controller
{
    public function __construct(private readonly AuditService $auditService) {}

    public function androidCurrent(Request $request): JsonResponse
    {
        $versionCode = (int) $request->integer('version_code', 0);
        $blocked = AppVersion::query()->where('platform', 'android')->where('version_code', $versionCode)->where('status', 'blocked')->exists();
        $latest = AppVersion::query()->where('platform', 'android')->whereIn('status', ['supported', 'deprecated'])->orderByDesc('version_code')->first();

        return ApiResponse::success([
            'update_required' => $blocked,
            'latest' => $latest,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::paginated(AppVersion::query()->where('platform', 'android')->orderByDesc('version_code')->paginate((int) $request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $version = AppVersion::query()->create($request->validate([
            'version_name' => ['required', 'string', 'max:80'],
            'version_code' => ['required', 'integer', 'min:1', 'unique:app_versions,version_code'],
            'status' => ['required', 'in:supported,deprecated,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'download_url' => ['nullable', 'url', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ]) + ['platform' => 'android', 'created_by' => $request->user()?->id]);
        $this->auditService->record('updates.android_created', $version, $request->user());

        return ApiResponse::success($version, 201);
    }

    public function uploadAndroid(Request $request): JsonResponse
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
        $filename = 'dis-'.$data['version_code'].'-'.$data['version_name'].'.apk';
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
            'version_code' => $data['version_code'],
        ], [
            'platform' => 'android',
            'version_name' => $data['version_name'],
            'status' => $data['status'],
            'artifact_sha256' => $sha256,
            'download_url' => url('/api/updates/android/'.$path.'/download'),
            'release_notes' => $data['release_notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $version->update(['download_url' => url('/api/updates/android/'.$version->id.'/download')]);
        $this->auditService->record('updates.android_apk_uploaded', $version, $request->user(), [
            'artifact_path' => $path,
            'artifact_size_bytes' => $apkSize,
            'release_zip' => $request->hasFile('release_zip'),
        ]);

        if (($zipUpload['apk_path'] ?? null) !== null) {
            @unlink((string) $zipUpload['apk_path']);
        }

        return ApiResponse::success($version->refresh(), 201);
    }

    /**
     * @return array{data: array{version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null}, apk_path: string, apk_size: int}|array{}
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

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                $base = basename(str_replace('\\', '/', $name));

                if (strtolower(pathinfo($base, PATHINFO_EXTENSION)) === 'apk') {
                    $apkEntries[] = $name;
                }

                if ($base === 'metadata.json') {
                    $metadataEntry = $name;
                }
            }

            if (count($apkEntries) !== 1 || $metadataEntry === null) {
                throw ValidationException::withMessages(['release_zip' => ['Release ZIP must contain one APK and metadata.json.']]);
            }

            $metadata = json_decode($this->stripUtf8Bom((string) $zip->getFromName($metadataEntry)), true);
            if (! is_array($metadata)) {
                throw ValidationException::withMessages(['metadata' => ['metadata.json must contain valid JSON.']]);
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
     * @return array{version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null}
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

        return $request->validate([
            'version_name' => ['required', 'string', 'max:80'],
            'version_code' => ['required', 'integer', 'min:1', 'unique:app_versions,version_code'],
            'status' => ['required', 'in:supported,deprecated,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
            'apk' => ['required', 'file', 'max:512000'],
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null}
     */
    private function validateAndroidMetadata(array $metadata): array
    {
        return Validator::make($metadata, [
            'version_name' => ['required', 'string', 'max:80'],
            'version_code' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:supported,deprecated,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ])->validate();
    }

    private function stripUtf8Bom(string $value): string
    {
        return str_starts_with($value, "\xEF\xBB\xBF") ? substr($value, 3) : $value;
    }

    public function downloadAndroid(AppVersion $version): BinaryFileResponse
    {
        abort_unless($version->platform === 'android' && $version->download_url !== null, 404);

        $filename = 'dis-'.$version->version_code.'-'.$version->version_name.'.apk';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'dis.apk';
        $path = 'android-apks/'.$filename;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->download(Storage::disk('local')->path($path), $filename, [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    }

    public function update(Request $request, AppVersion $version): JsonResponse
    {
        $version->update($request->validate([
            'status' => ['sometimes', 'in:supported,deprecated,blocked'],
            'artifact_sha256' => ['nullable', 'string', 'size:64'],
            'download_url' => ['nullable', 'url', 'max:2048'],
            'release_notes' => ['nullable', 'string', 'max:10000'],
        ]));
        $this->auditService->record('updates.android_updated', $version, $request->user());

        return ApiResponse::success($version->refresh());
    }
}
