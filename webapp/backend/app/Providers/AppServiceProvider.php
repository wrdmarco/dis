<?php

namespace App\Providers;

use App\Mail\MicrosoftGraphTransport;
use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Throwable;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->applyManagedSettings();
        $this->registerMailTransports();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(1200)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('mobile-public', fn (Request $request) => Limit::perMinute(6000)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(60)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('push-token', fn (Request $request) => Limit::perMinute(300)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('dispatch-response', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('developer-upload', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('developer-update', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));
        RateLimiter::for('developer-logs', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
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
