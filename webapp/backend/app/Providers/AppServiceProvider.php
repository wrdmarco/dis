<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Config;
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

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('mobile-public', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(15)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(15)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(3)->by($request->ip().'|'.$request->input('email')));
        RateLimiter::for('push-token', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('dispatch-response', fn (Request $request) => Limit::perMinute(30)->by($request->user()?->id ?: $request->ip()));
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
            Config::set('mail.from.address', SystemSetting::string('mail.from_address', config('mail.from.address')));
            Config::set('mail.from.name', SystemSetting::string('mail.from_name', config('mail.from.name')));
        } catch (Throwable) {
            return;
        }
    }
}
