<?php

namespace Tests\Feature;

use App\Models\FcmToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PushSessionMigrationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_forces_previously_disabled_users_without_active_push_unavailable(): void
    {
        $migration = require database_path(
            'migrations/2026_07_24_000009_enforce_unique_active_push_provider_tokens.php',
        );
        $migration->down();
        $migrationRestored = false;

        try {
            $previouslyDisabled = $this->user('previously-disabled', false);
            $this->insertAvailabilityStatus($previouslyDisabled, 'available', true);

            $alreadyUnavailable = $this->user('already-unavailable', false);
            $this->insertAvailabilityStatus($alreadyUnavailable, 'unavailable', false);

            $activePushUser = $this->user('active-push', true);
            $activeSession = $activePushUser->createToken(
                'Active push migration session',
                ['*', 'client:operator'],
                now()->addHour(),
            )->accessToken;
            FcmToken::query()->create([
                'user_id' => $activePushUser->id,
                'device_id' => 'active-push-device',
                'token' => 'active-push-provider-token',
                'token_hash' => hash('sha256', 'active-push-provider-token'),
                'personal_access_token_id' => $activeSession->id,
                'platform' => 'android',
                'client_type' => 'operator',
                'is_active' => true,
                'last_seen_at' => now(),
            ]);

            $orphanedPushUser = $this->user('orphaned-push', true);
            $this->insertAvailabilityStatus($orphanedPushUser, 'available', true);
            $orphanedToken = FcmToken::query()->create([
                'user_id' => $orphanedPushUser->id,
                'device_id' => 'orphaned-push-device',
                'token' => 'orphaned-push-provider-token',
                'token_hash' => hash('sha256', 'orphaned-push-provider-token'),
                'personal_access_token_id' => null,
                'platform' => 'android',
                'client_type' => 'operator',
                'is_active' => true,
                'last_seen_at' => now(),
            ]);

            $legacyAdminOnlyUser = $this->user('legacy-admin-only', true);
            $this->insertAvailabilityStatus($legacyAdminOnlyUser, 'available', true);
            $legacyAdminSession = $legacyAdminOnlyUser->createToken(
                'Legacy admin migration session',
                ['*', 'client:admin'],
                now()->addHour(),
            )->accessToken;
            $legacyAdminToken = FcmToken::query()->create([
                'user_id' => $legacyAdminOnlyUser->id,
                'device_id' => 'legacy-admin-only-device',
                'token' => 'legacy-admin-only-provider-token',
                'token_hash' => hash('sha256', 'legacy-admin-only-provider-token'),
                'personal_access_token_id' => $legacyAdminSession->id,
                'platform' => 'android',
                'client_type' => 'admin',
                'is_active' => true,
                'last_seen_at' => now(),
            ]);

            $migration->up();
            $migrationRestored = true;

            $this->assertFalse($previouslyDisabled->refresh()->push_enabled);
            $this->assertSame('unavailable', $this->latestStatus($previouslyDisabled));
            $this->assertSame(
                1,
                DB::table('availability_statuses')
                    ->where('user_id', $alreadyUnavailable->id)
                    ->count(),
            );
            $this->assertTrue($activePushUser->refresh()->push_enabled);
            $this->assertNull($this->latestStatus($activePushUser));
            $this->assertFalse($orphanedToken->refresh()->is_active);
            $this->assertFalse($orphanedPushUser->refresh()->push_enabled);
            $this->assertSame('unavailable', $this->latestStatus($orphanedPushUser));
            $this->assertTrue($legacyAdminToken->refresh()->is_active);
            $this->assertFalse($legacyAdminOnlyUser->refresh()->push_enabled);
            $this->assertSame('unavailable', $this->latestStatus($legacyAdminOnlyUser));
        } finally {
            if (! $migrationRestored) {
                $migration->up();
            }
        }
    }

    private function user(string $suffix, bool $pushEnabled): User
    {
        return User::query()->create([
            'name' => 'Push migration '.$suffix,
            'first_name' => 'Push',
            'last_name' => 'Migration '.$suffix,
            'email' => "push-migration-{$suffix}@example.test",
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'push_enabled' => $pushEnabled,
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function insertAvailabilityStatus(User $user, string $status, bool $isAvailable): void
    {
        DB::table('availability_statuses')->insert([
            'id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'status' => $status,
            'is_available' => $isAvailable,
            'is_system_applied' => ! $isAvailable,
            'effective_at' => now()->subMinute(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function latestStatus(User $user): ?string
    {
        return DB::table('availability_statuses')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('status');
    }
}
