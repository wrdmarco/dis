<?php

use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const DEVELOPER_SETTING_KEY = 'developer.api_access';

    private const LEGACY_DEVELOPER_SETTING_KEY = 'developer.android_upload';

    public function up(): void
    {
        Schema::dropIfExists('app_versions');

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')->where('name', 'updates.manage')->delete();
        }

        if (! Schema::hasTable('system_settings')) {
            return;
        }

        DB::table('system_settings')
            ->whereIn('key', [
                'software.download.operator_android.source',
                'software.download.admin_android.source',
                'software.download.operator_ios.source',
                'software.download.admin_android.app_store_url',
            ])
            ->delete();
        DB::table('system_settings')
            ->where('key', 'like', 'updates.%.minimum_supported_version_code')
            ->delete();

        $this->migrateDeveloperSetting(
            self::LEGACY_DEVELOPER_SETTING_KEY,
            self::DEVELOPER_SETTING_KEY,
            removeAndroidUploadScope: true,
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('app_versions')) {
            Schema::create('app_versions', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('platform')->default('android')->index();
                $table->string('application_id')->default('nl.wrdmarco.dis')->index();
                $table->string('version_name');
                $table->unsignedInteger('version_code');
                $table->string('status')->index();
                $table->string('artifact_sha256')->nullable();
                $table->string('download_url')->nullable();
                $table->text('release_notes')->nullable();
                $table->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampsTz();
                $table->unique(['platform', 'application_id', 'version_code']);
            });
        }

        if (Schema::hasTable('permissions') && ! DB::table('permissions')->where('name', 'updates.manage')->exists()) {
            $now = now();
            DB::table('permissions')->insert([
                'id' => (string) Str::ulid(),
                'name' => 'updates.manage',
                'category' => 'update_management',
                'display_name' => 'App-updates beheren',
                'description' => 'Registreer Android/iOS versies en bepaal updatebeleid.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (Schema::hasTable('system_settings')) {
            $this->migrateDeveloperSetting(
                self::DEVELOPER_SETTING_KEY,
                self::LEGACY_DEVELOPER_SETTING_KEY,
                removeAndroidUploadScope: false,
            );
        }
    }

    private function migrateDeveloperSetting(string $sourceKey, string $targetKey, bool $removeAndroidUploadScope): void
    {
        $source = SystemSetting::query()->find($sourceKey);
        $target = SystemSetting::query()->find($targetKey);

        if ($target === null && $source !== null) {
            $target = SystemSetting::query()->create([
                'key' => $targetKey,
                'value' => $this->developerSettingValue($source->value, $removeAndroidUploadScope),
                'is_sensitive' => true,
                'updated_by' => $source->updated_by,
            ]);
        } elseif ($target !== null && $removeAndroidUploadScope) {
            $value = $this->developerSettingValue($target->value, true);
            if ($value !== $target->value) {
                $target->forceFill(['value' => $value])->save();
            }
        }

        if ($target !== null) {
            $source?->delete();
        }
    }

    private function developerSettingValue(mixed $value, bool $removeAndroidUploadScope): mixed
    {
        if (! $removeAndroidUploadScope || ! is_array($value) || ! is_array($value['scopes'] ?? null)) {
            return $value;
        }

        $value['scopes'] = array_values(array_filter(
            $value['scopes'],
            static fn (mixed $scope): bool => $scope !== 'android_upload',
        ));

        return $value;
    }
};
