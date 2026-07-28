<?php

namespace Tests\Feature;

use App\Events\UserNotificationCreated;
use App\Events\UserNotificationsChanged;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Certification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserCertification;
use App\Models\UserNotification;
use App\Services\UserNotificationService;
use App\Services\WebSessionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const WEB_ORIGIN = 'https://dis.example.test';

    public function test_inbox_and_read_actions_are_strictly_scoped_to_the_authenticated_user(): void
    {
        $owner = $this->user('Eigen Gebruiker', 'notification-owner@example.test');
        $other = $this->user('Andere Gebruiker', 'notification-other@example.test');
        $ownUnread = $this->notification($owner, 'Eigen melding', 'own-unread');
        $this->notification($owner, 'Al gelezen', 'own-read', now());
        $otherUnread = $this->notification($other, 'Melding van een ander', 'other-unread');

        $this->getJson('/api/notifications')->assertUnauthorized();

        $this->asWebClient($owner)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonCount(1, 'data.notifications')
            ->assertJsonPath('data.notifications.0.id', $ownUnread->id)
            ->assertJsonPath('data.notifications.0.title', 'Eigen melding')
            ->assertJsonMissing(['title' => 'Melding van een ander']);

        $this->asWebClient($owner)
            ->patchJson("/api/notifications/{$otherUnread->id}/read")
            ->assertNotFound();
        $this->assertNull($otherUnread->refresh()->read_at);

        $this->asWebClient($owner)
            ->patchJson("/api/notifications/{$ownUnread->id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $ownUnread->id);
        $firstReadAt = $ownUnread->refresh()->read_at;
        $this->assertNotNull($firstReadAt);
        $this->asWebClient($owner)
            ->patchJson("/api/notifications/{$ownUnread->id}/read")
            ->assertOk();
        $this->assertTrue($ownUnread->refresh()->read_at?->equalTo($firstReadAt));

        $this->notification($owner, 'Tweede eigen melding', 'own-second');
        $this->notification($owner, 'Derde eigen melding', 'own-third');

        $this->asWebClient($owner)
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 2);
        $this->asWebClient($owner)
            ->patchJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked_read', 0);

        $this->assertSame(
            0,
            UserNotification::query()
                ->where('user_id', $owner->id)
                ->whereNull('read_at')
                ->count(),
        );
        $this->assertNull($otherUnread->refresh()->read_at);
    }

    public function test_every_unread_notification_remains_reachable_through_inbox_pages(): void
    {
        $owner = $this->user('Offline Gebruiker', 'offline-notifications@example.test');

        foreach (range(1, 31) as $index) {
            $notification = $this->notification(
                $owner,
                sprintf('Bewaarde melding %02d', $index),
                'offline-page-'.$index,
            );
            $notification->forceFill([
                'occurred_at' => now()->subMinutes($index),
            ])->save();
        }

        $firstPage = $this->asWebClient($owner)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 31)
            ->assertJsonPath('data.current_page', 1)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.next_page', 2)
            ->assertJsonCount(30, 'data.notifications');

        $secondPage = $this->asWebClient($owner)
            ->getJson('/api/notifications?page=2')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 31)
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonPath('data.last_page', 2)
            ->assertJsonPath('data.next_page', null)
            ->assertJsonCount(1, 'data.notifications');

        $ids = collect($firstPage->json('data.notifications'))
            ->concat($secondPage->json('data.notifications'))
            ->pluck('id');
        $this->assertCount(31, $ids);
        $this->assertCount(31, $ids->unique());

        $this->asWebClient($owner)
            ->getJson('/api/notifications?page=0')
            ->assertUnprocessable();
    }

    public function test_native_tokens_and_users_view_cannot_access_another_users_web_inbox_channel(): void
    {
        $owner = $this->user(
            'Kanaal Eigenaar',
            'notification-channel-owner@example.test',
            ['users.view'],
        );
        $other = $this->user('Kanaal Andere', 'notification-channel-other@example.test');
        $operatorRole = Role::query()->create([
            'name' => 'notification-operator-role',
            'display_name' => 'Notification operator role',
            'description' => null,
            'can_use_operator_app' => true,
            'can_use_admin_app' => false,
        ]);
        $owner->roles()->attach($operatorRole->id, ['created_at' => now()]);
        $token = $owner->createToken('Operator Android', ['client:operator'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/notifications')
            ->assertForbidden();

        $channel = Broadcast::getChannels()->get('user-notifications.{userId}');
        $this->assertIsCallable($channel);
        $this->assertFalse($channel($owner, (string) $other->id));
        $this->assertTrue($channel($owner, (string) $owner->id));
    }

    public function test_due_reminders_target_only_certification_owners_and_current_asset_assignees(): void
    {
        $this->travelTo(now()->setDate(2026, 7, 28)->setTime(9, 0));
        Event::fake([UserNotificationCreated::class, UserNotificationsChanged::class]);

        $alice = $this->user('Alice Eigenaar', 'alice-notifications@example.test');
        $bob = $this->user('Bob Eigenaar', 'bob-notifications@example.test');
        $certification = Certification::query()->create([
            'code' => 'NOTIFY-CERT',
            'name' => 'Operationeel certificaat',
            'description' => null,
            'is_required_for_dispatch' => true,
            'warning_days_before_expiry' => 30,
        ]);
        $aliceCertification = UserCertification::query()->create([
            'user_id' => $alice->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addDays(10)->toDateString(),
            'certificate_number' => 'ALICE-CERT',
            'status' => 'active',
        ]);
        UserCertification::query()->create([
            'user_id' => $bob->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->subDay()->toDateString(),
            'certificate_number' => 'BOB-CERT',
            'status' => 'active',
        ]);
        UserCertification::query()->create([
            'user_id' => $alice->id,
            'certification_id' => $certification->id,
            'issued_at' => now()->subYear()->toDateString(),
            'expires_at' => now()->addDays(3)->toDateString(),
            'certificate_number' => 'ALICE-INACTIVE-CERT',
            'status' => 'revoked',
        ]);
        $aliceAsset = $this->assignedAsset($alice, 'ALICE-ASSET', now()->addDays(5)->toDateString());
        $this->assignedAsset($bob, 'BOB-ASSET', now()->addDays(7)->toDateString());
        Asset::query()->create([
            'asset_tag' => 'UNASSIGNED-ASSET',
            'name' => 'Onverdeelde asset',
            'type' => 'equipment',
            'status' => 'ready',
            'serial_number' => null,
            'maintenance_due_at' => now()->addDays(2)->toDateString(),
            'notes' => null,
        ]);
        $this->assignedAsset($alice, 'RETIRED-ASSET', now()->addDays(2)->toDateString())
            ->update(['status' => 'retired']);

        $service = $this->app->make(UserNotificationService::class);
        $first = $service->syncDueReminders();

        $this->assertSame(['active' => 4, 'created' => 4, 'removed' => 0], $first);
        $this->assertSame(2, UserNotification::query()->where('user_id', $alice->id)->count());
        $this->assertSame(2, UserNotification::query()->where('user_id', $bob->id)->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $alice->id,
            'source_type' => 'user_certification',
            'source_id' => $aliceCertification->id,
            'action_url' => '/profile?section=certifications&certification='.$aliceCertification->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $alice->id,
            'source_type' => 'asset',
            'source_id' => $aliceAsset->id,
            'action_url' => '/profile?section=assets&asset='.$aliceAsset->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $bob->id,
            'type' => UserNotification::TYPE_CERTIFICATION_EXPIRED,
        ]);
        Event::assertDispatched(
            UserNotificationCreated::class,
            fn (UserNotificationCreated $event): bool => $event->userId === (string) $alice->id,
        );
        Event::assertDispatched(
            UserNotificationCreated::class,
            fn (UserNotificationCreated $event): bool => $event->userId === (string) $bob->id,
        );

        $second = $service->syncDueReminders();
        $this->assertSame(['active' => 4, 'created' => 0, 'removed' => 0], $second);
        $this->assertSame(4, UserNotification::query()->count());

        $aliceCertification->update(['expires_at' => now()->addDays(60)->toDateString()]);
        AssetAssignment::query()->create([
            'asset_id' => $aliceAsset->id,
            'deployment_id' => null,
            'user_id' => $bob->id,
            'assigned_by' => $alice->id,
            'assigned_at' => now()->addMinute(),
            'released_at' => null,
        ]);

        $third = $service->syncDueReminders();
        $this->assertSame(3, $third['active']);
        $this->assertSame(1, $third['created']);
        $this->assertSame(0, $third['removed']);
        $this->assertSame(2, UserNotification::query()->where('user_id', $alice->id)->count());
        $this->assertSame(3, UserNotification::query()->where('user_id', $bob->id)->count());

        $this->asWebClient($alice)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonCount(2, 'data.notifications');

        UserNotification::query()
            ->where('user_id', $alice->id)
            ->update([
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        $fourth = $service->syncDueReminders();
        $this->assertSame(['active' => 3, 'created' => 0, 'removed' => 2], $fourth);
        $this->assertSame(0, UserNotification::query()->where('user_id', $alice->id)->count());
        Event::assertDispatched(
            UserNotificationsChanged::class,
            fn (UserNotificationsChanged $event): bool => $event->userId === (string) $alice->id,
        );
    }

    public function test_product_request_status_updates_are_stored_for_the_requesters_next_login(): void
    {
        Event::fake([UserNotificationCreated::class]);
        $requester = $this->user(
            'Renske Requester',
            'requester-notifications@example.test',
            ['product-requests.create', 'product-requests.update-own', 'product-requests.view'],
        );
        $handler = $this->user(
            'Henk Handler',
            'handler-notifications@example.test',
            ['product-requests.resolve', 'product-requests.view'],
        );
        $observer = $this->user(
            'Oscar Observer',
            'observer-notifications@example.test',
            ['product-requests.view'],
        );

        $requestId = (string) $this->asWebClient($requester)
            ->postJson('/api/product-requests', [
                'type' => 'bug',
                'title' => 'Agenda opent niet',
                'description' => 'Mijn eigen agendaverzoek kan niet worden geopend.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$requestId}/status", [
                'status' => 'in_progress',
                'lock_version' => 1,
            ])
            ->assertOk();

        $notification = UserNotification::query()->sole();
        $this->assertSame($requester->id, $notification->user_id);
        $this->assertSame(UserNotification::TYPE_PRODUCT_REQUEST_STATUS, $notification->type);
        $this->assertSame('/verzoeken?tab=mine&request='.$requestId, $notification->action_url);
        $this->assertStringContainsString('Agenda opent niet', $notification->message);
        $this->assertSame(0, UserNotification::query()->where('user_id', $handler->id)->count());
        $this->assertSame(0, UserNotification::query()->where('user_id', $observer->id)->count());
        Event::assertDispatched(
            UserNotificationCreated::class,
            fn (UserNotificationCreated $event): bool => $event->userId === (string) $requester->id,
        );
        $this->assertSame(['created' => true], (new UserNotificationCreated((string) $requester->id))->broadcastWith());

        $this->asWebClient($handler)
            ->patchJson("/api/product-requests/{$requestId}/status", [
                'status' => 'resolved',
                'resolution_note' => 'Interne afhandeling SECRET-STATUS-NOTE.',
                'lock_version' => 2,
            ])
            ->assertOk();
        $this->assertSame(2, UserNotification::query()->where('user_id', $requester->id)->count());
        $this->assertFalse(
            UserNotification::query()
                ->where('user_id', $requester->id)
                ->get()
                ->contains(fn (UserNotification $item): bool => str_contains($item->message, 'SECRET-STATUS-NOTE')),
        );

        $this->asWebClient($requester)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.notifications.0.action_url', '/verzoeken?tab=mine&request='.$requestId);

        $this->asWebClient($observer)
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0)
            ->assertJsonCount(0, 'data.notifications');
    }

    public function test_reminder_sync_is_scheduled_once_per_cluster_without_overlap(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains($event->command ?? '', 'dis:sync-user-notifications'),
        );

        $this->assertNotNull($event);
        $this->assertSame('*/15 * * * *', $event->expression);
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame(10, $event->expiresAt);
    }

    private function assignedAsset(User $user, string $tag, string $maintenanceDueAt): Asset
    {
        $asset = Asset::query()->create([
            'asset_tag' => $tag,
            'name' => 'Asset '.$tag,
            'type' => 'equipment',
            'status' => 'ready',
            'serial_number' => null,
            'maintenance_due_at' => $maintenanceDueAt,
            'notes' => null,
        ]);
        AssetAssignment::query()->create([
            'asset_id' => $asset->id,
            'deployment_id' => null,
            'user_id' => $user->id,
            'assigned_by' => $user->id,
            'assigned_at' => now(),
            'released_at' => null,
        ]);

        return $asset;
    }

    private function notification(
        User $user,
        string $title,
        string $deduplicationSeed,
        mixed $readAt = null,
    ): UserNotification {
        return UserNotification::query()->create([
            'user_id' => $user->id,
            'type' => UserNotification::TYPE_PRODUCT_REQUEST_STATUS,
            'tone' => 'info',
            'title' => $title,
            'message' => 'Persoonlijke melding voor de test.',
            'action_url' => '/verzoeken',
            'source_type' => 'product_request',
            'source_id' => (string) str()->ulid(),
            'deduplication_key' => hash('sha256', $deduplicationSeed),
            'occurred_at' => now(),
            'read_at' => $readAt,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function user(string $name, string $email, array $permissions = []): User
    {
        $user = User::query()->create([
            'name' => $name,
            'first_name' => str($name)->before(' ')->toString(),
            'last_name' => str($name)->after(' ')->toString(),
            'email' => $email,
            'password' => Hash::make('Test-password-123!'),
            'account_status' => 'active',
            'two_factor_enabled' => true,
            'two_factor_confirmed_at' => now(),
        ]);

        if ($permissions === []) {
            return $user;
        }

        $role = Role::query()->create([
            'name' => 'notification-test-'.str()->lower((string) str()->ulid()),
            'display_name' => 'Notification test role',
            'description' => null,
            'can_use_operator_app' => false,
            'can_use_admin_app' => true,
        ]);

        foreach ($permissions as $permissionName) {
            $permission = Permission::query()->where('name', $permissionName)->firstOrFail();
            $role->permissions()->attach($permission->id, ['created_at' => now()]);
        }
        $user->roles()->attach($role->id, ['created_at' => now()]);

        return $user;
    }

    private function asWebClient(User $user): static
    {
        config([
            'app.url' => self::WEB_ORIGIN,
            'session.trusted_origins' => [self::WEB_ORIGIN],
            'sanctum.stateful' => ['dis.example.test'],
        ]);

        Auth::forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->defaultHeaders = [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];
        $this->serverVariables = [];

        $timestamp = now()->getTimestamp();
        $csrfToken = hash('sha256', 'notification-browser-session-'.$user->id);

        return $this->actingAs($user, 'web')
            ->withSession([
                '_token' => $csrfToken,
                WebSessionService::KEY_AUTHENTICATED_AT => $timestamp,
                WebSessionService::KEY_LAST_ACTIVITY_AT => $timestamp,
                WebSessionService::KEY_AUTH_VERSION => (int) $user->auth_session_version,
            ])
            ->withHeaders([
                'Accept' => 'application/json',
                'Origin' => self::WEB_ORIGIN,
                'Referer' => self::WEB_ORIGIN.'/',
                'Sec-Fetch-Site' => 'same-origin',
                'X-CSRF-TOKEN' => $csrfToken,
                'X-Requested-With' => 'XMLHttpRequest',
            ])
            ->withServerVariables([
                'HTTP_HOST' => 'dis.example.test',
                'HTTPS' => 'on',
            ]);
    }
}
