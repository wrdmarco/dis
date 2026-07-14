<?php

namespace App\Providers;

use App\Mail\MicrosoftGraphTransport;
use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->ensureRuntimeStorage();
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->applyManagedSettings();
        $this->registerMailTransports();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(1200)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('authenticated', function (Request $request): array {
            $isWriteRequest = ! $request->isMethodSafe();
            $subject = (string) ($request->user()?->getAuthIdentifier() ?: $request->ip());

            return [
                Limit::perMinute($isWriteRequest ? 120 : 600)->by('authenticated:subject:'.$subject),
                Limit::perMinute($isWriteRequest ? 240 : 1200)->by('authenticated:ip:'.$request->ip()),
            ];
        });
        RateLimiter::for('mobile-public', fn (Request $request) => Limit::perMinute(6000)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request): array => [
            Limit::perMinute(20)->by('login:ip:'.$request->ip()),
            Limit::perMinute(10)->by('login:account:'.$this->accountRateLimitKey($request)),
        ]);
        RateLimiter::for('setup', fn (Request $request) => Limit::perMinute(5)->by('setup:ip:'.$request->ip()));
        RateLimiter::for('mobile-pairing', fn (Request $request): array => [
            Limit::perMinute(20)->by('mobile-pairing:ip:'.$request->ip()),
            Limit::perMinute(10)->by('mobile-pairing:code:'.hash('sha256', (string) $request->input('code', 'missing'))),
        ]);
        RateLimiter::for('two-factor', fn (Request $request): array => [
            Limit::perMinute(20)->by('two-factor:ip:'.$request->ip()),
            Limit::perMinute(6)->by('two-factor:subject:'.$this->authenticationSubjectKey($request)),
        ]);
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(10)->by('password-reset:ip:'.$request->ip()),
            Limit::perMinute(5)->by('password-reset:account:'.$this->accountRateLimitKey($request)),
        ]);
        RateLimiter::for('push-token', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('dispatch-response', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('developer-upload', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('developer-update', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));
        RateLimiter::for('developer-logs', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }

    private function ensureRuntimeStorage(): void
    {
        $directories = [
            storage_path('app'),
            storage_path('framework/cache'),
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            storage_path('tmp'),
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0770, true);
            }
            @chmod($directory, 0770);
        }

        if (is_dir(storage_path('tmp')) && is_writable(storage_path('tmp'))) {
            putenv('TMPDIR='.storage_path('tmp'));
            putenv('TEMP='.storage_path('tmp'));
            putenv('TMP='.storage_path('tmp'));
        }
    }

    private function accountRateLimitKey(Request $request): string
    {
        $email = mb_strtolower(trim((string) $request->input('email', '')));
        $identifier = $email === '' ? 'missing|'.$request->ip() : $email;

        return hash('sha256', $identifier);
    }

    private function authenticationSubjectKey(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();
        if (is_string($userId) && $userId !== '') {
            return hash('sha256', 'user|'.$userId);
        }

        if ($request->hasSession()) {
            return hash('sha256', 'session|'.$request->session()->getId());
        }

        return hash('sha256', 'ip|'.$request->ip());
    }

    private function applyManagedSettings(): void
    {
        try {
            if (! Schema::hasTable('system_settings')) {
                return;
            }

            Config::set('mail.default', SystemSetting::string('mail.mailer', config('mail.default')));
            Config::set('mail.mailers.smtp.host', SystemSetting::string('mail.host', config('mail.mailers.smtp.host')));
            Config::set('mail.mailers.smtp.port', SystemSetting::integer('mail.port', (int) config('mail.mailers.smtp.port')));
            Config::set('mail.mailers.smtp.encryption', SystemSetting::string('mail.encryption', config('mail.mailers.smtp.encryption')));
            Config::set('mail.mailers.smtp.username', SystemSetting::string('mail.username', config('mail.mailers.smtp.username')));
            Config::set('mail.mailers.smtp.password', SystemSetting::string('mail.password', config('mail.mailers.smtp.password')));
            Config::set('mail.mailers.microsoft365.tenant_id', SystemSetting::string('mail.microsoft365_tenant_id', config('mail.mailers.microsoft365.tenant_id')));
            Config::set('mail.mailers.microsoft365.client_id', SystemSetting::string('mail.microsoft365_client_id', config('mail.mailers.microsoft365.client_id')));
            Config::set('mail.mailers.microsoft365.client_secret', SystemSetting::string('mail.microsoft365_client_secret', config('mail.mailers.microsoft365.client_secret')));
            Config::set('mail.mailers.microsoft365.sender', SystemSetting::string('mail.microsoft365_sender', config('mail.mailers.microsoft365.sender')));
            Config::set('mail.from.address', SystemSetting::string('mail.from_address', config('mail.from.address')));
            Config::set('mail.from.name', SystemSetting::string('mail.from_name', config('mail.from.name')));
        } catch (Throwable) {
            return;
        }
    }

    private function registerMailTransports(): void
    {
        Mail::extend('microsoft365', fn (array $config = []): MicrosoftGraphTransport => new MicrosoftGraphTransport(
            (string) ($config['tenant_id'] ?? ''),
            (string) ($config['client_id'] ?? ''),
            (string) ($config['client_secret'] ?? ''),
            (string) ($config['sender'] ?? ''),
        ));
    }
}
