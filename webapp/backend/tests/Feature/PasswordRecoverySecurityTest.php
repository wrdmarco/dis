<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordRecoveryLink;
use App\Mail\UserPasswordRecoveryMail;
use App\Models\User;
use App\Services\PasswordRecoveryService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

final class PasswordRecoverySecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_recovery_returns_the_same_response_and_queues_the_same_job_for_every_valid_email(): void
    {
        Queue::fake();
        $user = $this->user('known-recovery@example.test');

        $known = $this->postJson('/api/auth/password/forgot', ['email' => strtoupper($user->email)]);
        $unknown = $this->postJson('/api/auth/password/forgot', ['email' => 'unknown-recovery@example.test']);

        $known->assertOk()->assertExactJson([
            'data' => ['status' => 'password_reset_link_sent'],
        ]);
        $unknown->assertOk()->assertExactJson($known->json());

        $queuedEmails = [];
        Queue::assertPushed(SendPasswordRecoveryLink::class, function (SendPasswordRecoveryLink $job) use (&$queuedEmails): bool {
            $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
            $queuedEmails[] = $job->email;

            return true;
        });
        Queue::assertPushed(SendPasswordRecoveryLink::class, 2);
        $this->assertEqualsCanonicalizing([
            $user->email,
            'unknown-recovery@example.test',
        ], $queuedEmails);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_job_sends_a_recovery_mail_and_creates_a_token_for_an_active_account(): void
    {
        Mail::fake();
        config(['app.url' => 'https://dis.example.test']);
        $user = $this->user('active-recovery@example.test');

        $job = new SendPasswordRecoveryLink(strtoupper($user->email));
        $job->handle(app(PasswordRecoveryService::class));

        $recoveryUrl = null;
        Mail::assertSent(UserPasswordRecoveryMail::class, function (UserPasswordRecoveryMail $mail) use ($user, &$recoveryUrl): bool {
            $recoveryUrl = $mail->recoveryUrl;

            return $mail->hasTo($user->email);
        });
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertIsString($recoveryUrl);

        $fragment = (string) parse_url($recoveryUrl, PHP_URL_FRAGMENT);
        parse_str($fragment, $parameters);
        $this->assertSame('https://dis.example.test/register', strtok($recoveryUrl, '#'));
        $this->assertSame('recovery', $parameters['mode'] ?? null);
        $this->assertSame($user->email, $parameters['email'] ?? null);
        $this->assertTrue(Password::broker()->tokenExists($user, (string) ($parameters['token'] ?? '')));
    }

    public function test_public_recovery_is_rate_limited_and_recovers_after_the_decay_window(): void
    {
        Queue::fake();
        $email = 'rate-limited-recovery@example.test';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/password/forgot', ['email' => $email])->assertOk();
        }

        $this->postJson('/api/auth/password/forgot', ['email' => $email])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After');

        $this->travel(61)->seconds();
        $this->postJson('/api/auth/password/forgot', ['email' => $email])->assertOk();
        $this->travelBack();

        Queue::assertPushed(SendPasswordRecoveryLink::class, 6);
    }

    public function test_job_is_a_no_op_for_unknown_and_inactive_accounts(): void
    {
        Mail::fake();
        $inactive = $this->user('inactive-recovery@example.test', 'blocked');
        $service = app(PasswordRecoveryService::class);

        (new SendPasswordRecoveryLink('unknown-recovery@example.test'))->handle($service);
        (new SendPasswordRecoveryLink($inactive->email))->handle($service);

        Mail::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_failed_delivery_removes_the_token_and_leaves_the_job_retryable(): void
    {
        $user = $this->user('failed-recovery@example.test');
        $job = new SendPasswordRecoveryLink($user->email);
        Mail::shouldReceive('to')
            ->once()
            ->with($user->email)
            ->andThrow(new RuntimeException('Simulated mail transport failure.'));

        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->backoff);

        try {
            $job->handle(app(PasswordRecoveryService::class));
            $this->fail('The queue job must fail so the worker can retry delivery.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated mail transport failure.', $exception->getMessage());
        }

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_successful_password_reset_revokes_existing_sessions_and_access_tokens(): void
    {
        $user = $this->user('reset-revocation@example.test');
        $sessionId = 'password-reset-session';
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '192.0.2.15',
            'user_agent' => 'Security test',
            'payload' => 'serialized-session',
            'last_activity' => now()->getTimestamp(),
        ]);
        $accessToken = $user->createToken('Password reset security test');
        $resetToken = Password::broker()->createToken($user);

        $this->postJson('/api/auth/password/reset', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'New-secure-password-123!',
            'password_confirmation' => 'New-secure-password-123!',
        ])->assertOk()->assertJsonPath('data.status', 'password_reset');

        $this->assertTrue(Hash::check('New-secure-password-123!', (string) $user->refresh()->password));
        $this->assertSame(1, (int) $user->auth_session_version);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessToken->accessToken->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'auth.password_reset_sessions_revoked',
            'target_id' => $user->id,
        ]);
    }

    private function user(string $email, string $accountStatus = 'active'): User
    {
        return User::query()->create([
            'name' => 'Password Recovery Test',
            'first_name' => 'Password',
            'last_name' => 'Recovery Test',
            'email' => $email,
            'password' => Hash::make('Current-secure-password-123!'),
            'account_status' => $accountStatus,
        ]);
    }
}
