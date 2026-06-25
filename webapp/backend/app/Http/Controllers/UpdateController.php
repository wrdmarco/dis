<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Models\AppVersion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        $data = $this->androidUploadData($request);

        $file = $request->file('apk');
        abort_unless($file !== null && $file->isValid(), 422);
        abort_unless(strtolower($file->getClientOriginalExtension()) === 'apk', 422);

        $directory = 'android-apks';
        $filename = 'dis-'.$data['version_code'].'-'.$data['version_name'].'.apk';
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) ?: 'dis-'.$data['version_code'].'.apk';
        $path = $file->storeAs($directory, $filename, 'local');
        $absolutePath = Storage::disk('local')->path($path);
        $sha256 = hash_file('sha256', $absolutePath);

        if (($data['artifact_sha256'] ?? null) !== null && ! hash_equals(strtolower((string) $data['artifact_sha256']), $sha256)) {
            Storage::disk('local')->delete($path);

            return ApiResponse::error('apk_hash_mismatch', 'APK SHA-256 does not match metadata.', 422, [
                'expected' => strtolower((string) $data['artifact_sha256']),
                'actual' => $sha256,
            ]);
        }

        $version = AppVersion::query()->create([
            'platform' => 'android',
            'version_name' => $data['version_name'],
            'version_code' => $data['version_code'],
            'status' => $data['status'],
            'artifact_sha256' => $sha256,
            'download_url' => url('/api/updates/android/'.$path.'/download'),
            'release_notes' => $data['release_notes'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $version->update(['download_url' => url('/api/updates/android/'.$version->id.'/download')]);
        $this->auditService->record('updates.android_apk_uploaded', $version, $request->user(), [
            'artifact_path' => $path,
            'artifact_size_bytes' => $file->getSize(),
            'metadata_file' => $request->hasFile('metadata'),
        ]);

        return ApiResponse::success($version->refresh(), 201);
    }

    /**
     * @return array{version_name: string, version_code: int, status: string, artifact_sha256?: string|null, release_notes?: string|null}
     */
    private function androidUploadData(Request $request): array
    {
        if ($request->hasFile('metadata')) {
            $metadataFile = $request->file('metadata');
            abort_unless($metadataFile !== null && $metadataFile->isValid(), 422);

            $metadata = json_decode((string) file_get_contents($metadataFile->getRealPath()), true);
            if (! is_array($metadata)) {
                throw ValidationException::withMessages(['metadata' => ['Metadata file must contain valid JSON.']]);
            }

            return Validator::make($metadata, [
                'version_name' => ['required', 'string', 'max:80'],
                'version_code' => ['required', 'integer', 'min:1', 'unique:app_versions,version_code'],
                'status' => ['required', 'in:supported,deprecated,blocked'],
                'artifact_sha256' => ['nullable', 'string', 'size:64'],
                'release_notes' => ['nullable', 'string', 'max:10000'],
            ])->validate();
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
