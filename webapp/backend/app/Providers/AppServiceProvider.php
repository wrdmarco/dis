<?php

namespace App\Providers;

use App\Contracts\DispatchNotificationQueue;
use App\Contracts\KnmiCloudForecastProvider;
use App\Contracts\KnmiPrecipitationOutlookProvider;
use App\Contracts\OperationalRadarProvider;
use App\Contracts\PushProvider;
use App\Contracts\QueueTransportMetrics;
use App\Contracts\RouteGeometryProvider;
use App\Contracts\RoutingProvider;
use App\Contracts\SpeechEngineClient;
use App\Contracts\WallboardContentProvider;
use App\Mail\MicrosoftGraphTransport;
use App\Models\PersonalAccessToken;
use App\Models\SystemSetting;
use App\Repositories\KnmiPrecipitationSnapshotRepository;
use App\Repositories\LaravelQueueTransportMetrics;
use App\Services\KnmiHarmonieCloudService;
use App\Services\KnmiPrecipitationOutlookService;
use App\Services\OperationalRadarService;
use App\Services\PushProviderClient;
use App\Services\QueuedDispatchNotificationQueue;
use App\Services\Routing\OsrmRoutingProvider;
use App\Services\Routing\RouteGeometryService;
use App\Services\Routing\RoutingService;
use App\Services\SecureWallboardContentProvider;
use App\Services\SelfHostedSpeechEngineClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
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
    public function register(): void
    {
        $this->app->bind(DispatchNotificationQueue::class, QueuedDispatchNotificationQueue::class);
        $this->app->bind(PushProvider::class, PushProviderClient::class);
        $this->app->bind(QueueTransportMetrics::class, LaravelQueueTransportMetrics::class);
        $this->app->singleton(SpeechEngineClient::class, SelfHostedSpeechEngineClient::class);
        $this->app->bind(WallboardContentProvider::class, SecureWallboardContentProvider::class);
        $this->app->singleton(KnmiCloudForecastProvider::class, KnmiHarmonieCloudService::class);
        $this->app->singleton(KnmiPrecipitationSnapshotRepository::class);
        $this->app->singleton(KnmiPrecipitationOutlookProvider::class, KnmiPrecipitationOutlookService::class);
        $this->app->singleton(OperationalRadarProvider::class, OperationalRadarService::class);

        $this->app->singleton(RoutingProvider::class, fn ($app): RoutingProvider => new OsrmRoutingProvider(
            http: $app->make(HttpFactory::class),
            baseUrl: (string) config('dis.routing.osrm.base_url', ''),
            profile: (string) config('dis.routing.osrm.profile', 'driving'),
            connectTimeoutSeconds: (int) config('dis.routing.osrm.connect_timeout_seconds', 1),
            timeoutSeconds: (int) config('dis.routing.osrm.timeout_seconds', 3),
            batchSize: (int) config('dis.routing.osrm.batch_size', 50),
            allowedHosts: array_values(array_filter(array_map(
                static fn (string $host): string => trim($host),
                explode(',', (string) config('dis.routing.osrm.allowed_hosts', '127.0.0.1,localhost,::1')),
            ))),
            geometryMaxRoutes: (int) config('dis.routing.osrm.geometry_max_routes', 25),
            geometryConcurrency: (int) config('dis.routing.osrm.geometry_concurrency', 10),
        ));

        $this->app->singleton(RouteGeometryProvider::class, function ($app): RouteGeometryProvider {
            $provider = $app->make(RoutingProvider::class);

            return $provider instanceof RouteGeometryProvider
                ? $provider
                : throw new \LogicException('The configured routing provider does not support route geometry.');
        });

        $this->app->singleton(RoutingService::class, fn ($app): RoutingService => new RoutingService(
            provider: $app->make(RoutingProvider::class),
            cache: $app->make(CacheFactory::class)->store(),
            enabled: $this->managedRoutingEnabled()
                && (string) config('dis.routing.provider', 'osrm') === 'osrm',
            cacheTtlSeconds: (int) config('dis.routing.cache_ttl_seconds', 900),
            failureCacheTtlSeconds: (int) config('dis.routing.failure_cache_ttl_seconds', 15),
            fallbackSpeedKmh: (float) config('dis.routing.fallback_speed_kmh', 60),
        ));

        $this->app->singleton(RouteGeometryService::class, fn ($app): RouteGeometryService => new RouteGeometryService(
            provider: $app->make(RouteGeometryProvider::class),
            enabled: $this->managedRoutingEnabled()
                && (string) config('dis.routing.provider', 'osrm') === 'osrm',
        ));
    }

    public function boot(): void
    {
        $this->ensureRuntimeStorage();
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        $this->applyManagedSettings();
        $this->registerMailTransports();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(1200)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('authenticated', function (Request $request): array {
            $isWriteRequest = ! $request->isMethodSafe();
            $requestClass = $isWriteRequest ? 'write' : 'read';

            return $this->authenticatedClientLimits(
                request: $request,
                scope: 'authenticated:'.$requestClass,
                perClient: $isWriteRequest ? 120 : 600,
                perUser: $isWriteRequest ? 240 : 1200,
            );
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
        RateLimiter::for('wallboard-pairing-start', fn (Request $request): array => [
            Limit::perMinute(6)->by('wallboard-pairing-start:ip:'.$request->ip()),
            Limit::perHour(30)->by('wallboard-pairing-start-hour:ip:'.$request->ip()),
        ]);
        RateLimiter::for('wallboard-pairing-status', fn (Request $request): array => [
            Limit::perMinute(120)->by('wallboard-pairing-status:credential:'.hash(
                'sha256',
                (string) $request->cookie('__Host-dis_wallboard_pairing', 'missing'),
            )),
            Limit::perMinute(240)->by('wallboard-pairing-status:ip:'.$request->ip()),
        ]);
        RateLimiter::for('wallboard-pairing-approve', function (Request $request): array {
            $actor = hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'));
            $code = $this->wallboardPairingCodeKey($request);

            return [
                Limit::perMinute(10)->by('wallboard-pairing-approve:actor:'.$actor),
                Limit::perMinute(5)->by('wallboard-pairing-approve:actor-code:'.$actor.':'.$code),
                Limit::perMinute(20)->by('wallboard-pairing-approve:code:'.$code),
            ];
        });
        RateLimiter::for('wallboard-read', function (Request $request): array {
            $session = $request->attributes->get('wallboard.session');
            $wallboard = $request->attributes->get('wallboard');
            $sessionId = is_object($session) && isset($session->id) ? (string) $session->id : 'missing';
            $wallboardId = is_object($wallboard) && isset($wallboard->id) ? (string) $wallboard->id : 'missing';

            return [
                Limit::perMinute(120)->by('wallboard-read:session:'.hash('sha256', $sessionId)),
                Limit::perMinute(600)->by('wallboard-read:wallboard:'.hash('sha256', $wallboardId)),
            ];
        });
        RateLimiter::for('wallboard-control', function (Request $request): array {
            $session = $request->attributes->get('wallboard.session');
            $wallboard = $request->attributes->get('wallboard');
            $sessionId = is_object($session) && isset($session->id) ? (string) $session->id : 'missing';
            $wallboardId = is_object($wallboard) && isset($wallboard->id) ? (string) $wallboard->id : 'missing';

            return [
                Limit::perMinute(90)->by('wallboard-control:session:'.hash('sha256', $sessionId)),
                Limit::perMinute(300)->by('wallboard-control:wallboard:'.hash('sha256', $wallboardId)),
            ];
        });
        RateLimiter::for('wallboard-media-read', function (Request $request): array {
            $session = $request->attributes->get('wallboard.session');
            $wallboard = $request->attributes->get('wallboard');
            $sessionId = is_object($session) && isset($session->id) ? (string) $session->id : 'missing';
            $wallboardId = is_object($wallboard) && isset($wallboard->id) ? (string) $wallboard->id : 'missing';

            return [
                Limit::perMinute(600)->by('wallboard-media-read:session:'.hash('sha256', $sessionId)),
                Limit::perMinute(3600)->by('wallboard-media-read:wallboard:'.hash('sha256', $wallboardId)),
            ];
        });
        RateLimiter::for('wallboard-media-upload', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'wallboard-media-upload',
            perClient: 10,
            perUser: 20,
        ));
        RateLimiter::for('wallboard-admin-write', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'wallboard-admin-write',
            perClient: 30,
            perUser: 60,
        ));
        RateLimiter::for('wallboard-playlist-preview', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'wallboard-playlist-preview',
            perClient: 30,
            perUser: 60,
        ));
        RateLimiter::for('wallboard-preview-news-image', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'wallboard-preview-news-image',
            perClient: 120,
            perUser: 240,
        ));
        RateLimiter::for('wallboard-focus-preview', function (Request $request): array {
            $actor = hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'));
            $wallboard = $request->route('wallboard');
            $wallboardId = is_object($wallboard) && isset($wallboard->id)
                ? (string) $wallboard->id
                : (is_string($wallboard) ? $wallboard : 'missing');

            return [
                Limit::perMinute(12)->by('wallboard-focus-preview:actor:'.$actor),
                Limit::perMinute(6)->by('wallboard-focus-preview:actor-wallboard:'.$actor.':'.hash('sha256', $wallboardId)),
            ];
        });
        RateLimiter::for('two-factor', fn (Request $request): array => [
            Limit::perMinute(20)->by('two-factor:ip:'.$request->ip()),
            Limit::perMinute(6)->by('two-factor:subject:'.$this->authenticationSubjectKey($request)),
        ]);
        RateLimiter::for('password-reset', fn (Request $request): array => [
            Limit::perMinute(10)->by('password-reset:ip:'.$request->ip()),
            Limit::perMinute(5)->by('password-reset:account:'.$this->accountRateLimitKey($request)),
        ]);
        RateLimiter::for('alarm-read', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'alarm-read',
            perClient: 1200,
            perUser: 3600,
        ));
        RateLimiter::for('alarm-response', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'alarm-response',
            perClient: 120,
            perUser: 360,
        ));
        RateLimiter::for('alarm-dispatch', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'alarm-dispatch',
            perClient: 60,
            perUser: 120,
        ));
        RateLimiter::for('reachability-test', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'reachability-test',
            perClient: 10,
            perUser: 20,
        ));
        RateLimiter::for('operational-action', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'operational-action',
            perClient: 180,
            perUser: 540,
        ));
        RateLimiter::for('operational-forecast-read', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'operational-forecast-read',
            perClient: 30,
            perUser: 60,
        ));
        RateLimiter::for('operational-radar-read', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'operational-radar-read',
            perClient: 120,
            perUser: 240,
        ));
        RateLimiter::for('operational-telemetry', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'operational-telemetry',
            perClient: 600,
            perUser: 1800,
        ));
        RateLimiter::for('developer-upload', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
        RateLimiter::for('developer-update', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));
        RateLimiter::for('developer-logs', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('osrm-admin-read', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'osrm-admin-read',
            perClient: 120,
            perUser: 240,
        ));
        RateLimiter::for('system-metrics', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'system-metrics',
            perClient: 60,
            perUser: 120,
        ));
        RateLimiter::for('osrm-admin-write', fn (Request $request): array => [
            Limit::perMinute(2)->by('osrm-admin-write:client:'.$this->rateLimitClientKey($request)),
            Limit::perHour(4)->by('osrm-admin-write:user:'.hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'))),
        ]);
        RateLimiter::for('knmi-admin-read', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'knmi-admin-read',
            perClient: 120,
            perUser: 240,
        ));
        RateLimiter::for('knmi-admin-write', fn (Request $request): array => [
            Limit::perMinute(4)->by('knmi-admin-write:client:'.$this->rateLimitClientKey($request)),
            Limit::perHour(12)->by('knmi-admin-write:user:'.hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'))),
        ]);
        RateLimiter::for('speech-admin-read', fn (Request $request): array => $this->authenticatedClientLimits(
            request: $request,
            scope: 'speech-admin-read',
            perClient: 120,
            perUser: 240,
        ));
        RateLimiter::for('speech-admin-write', fn (Request $request): array => [
            Limit::perMinute(12)->by('speech-admin-write:client:'.$this->rateLimitClientKey($request)),
            Limit::perHour(60)->by('speech-admin-write:user:'.hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'))),
        ]);
        RateLimiter::for('speech-admin-install', fn (Request $request): array => [
            Limit::perHour(4)->by('speech-admin-install:client:'.$this->rateLimitClientKey($request)),
            Limit::perDay(8)->by('speech-admin-install:user:'.hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'))),
        ]);
        RateLimiter::for('speech-admin-upload', fn (Request $request): array => [
            Limit::perMinute(4)->by('speech-admin-upload:client:'.$this->rateLimitClientKey($request)),
            Limit::perHour(12)->by('speech-admin-upload:user:'.hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'))),
        ]);
        RateLimiter::for('speech-admin-preview', fn (Request $request): array => [
            Limit::perMinute(10)->by('speech-admin-preview:client:'.$this->rateLimitClientKey($request)),
            Limit::perHour(60)->by('speech-admin-preview:user:'.hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous'))),
        ]);
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

    private function wallboardPairingCodeKey(Request $request): string
    {
        $normalized = strtoupper(preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            (string) $request->input('code', 'missing'),
        ) ?? '');

        return hash('sha256', $normalized === '' ? 'missing' : $normalized);
    }

    /**
     * Protect authenticated traffic per client and account. A shared IP limit
     * is deliberately omitted because operators may all use the same mobile
     * carrier NAT during one broad alarm. Public auth routes retain their
     * stricter account-and-IP limiters above.
     *
     * @return array<int, Limit>
     */
    private function authenticatedClientLimits(
        Request $request,
        string $scope,
        int $perClient,
        int $perUser,
    ): array {
        $userId = (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous');
        $userKey = hash('sha256', 'user|'.$userId);
        $accessToken = $request->user()?->currentAccessToken();
        $tokenId = $accessToken instanceof PersonalAccessToken ? (string) $accessToken->getKey() : null;

        if (is_string($tokenId) && $tokenId !== '') {
            $clientIdentity = 'token|'.$tokenId;
        } elseif ($request->hasSession()) {
            $clientIdentity = 'session|'.$request->session()->getId();
        } else {
            $clientIdentity = 'ip|'.$request->ip();
        }

        $clientKey = hash('sha256', 'user|'.$userId.'|'.$clientIdentity);

        return [
            Limit::perMinute($perClient)->by($scope.':client:'.$clientKey),
            Limit::perMinute($perUser)->by($scope.':user:'.$userKey),
        ];
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
            $routingEnabled = SystemSetting::boolean('routing.enabled', (bool) config('dis.routing.enabled', false));
            Config::set('dis.routing.enabled', $routingEnabled);
            if ($routingEnabled) {
                Config::set('dis.routing.provider', 'osrm');
                Config::set('dis.routing.osrm.base_url', 'http://127.0.0.1:5000');
                Config::set('dis.routing.osrm.allowed_hosts', '127.0.0.1,localhost,::1');
            }
        } catch (Throwable) {
            return;
        }
    }

    private function managedRoutingEnabled(): bool
    {
        $fallback = (bool) config('dis.routing.enabled', false);
        try {
            if (! Schema::hasTable('system_settings')) {
                return $fallback;
            }

            return SystemSetting::boolean('routing.enabled', $fallback);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function rateLimitClientKey(Request $request): string
    {
        $userId = (string) ($request->user()?->getAuthIdentifier() ?: 'anonymous');
        $accessToken = $request->user()?->currentAccessToken();
        $tokenId = $accessToken instanceof PersonalAccessToken ? (string) $accessToken->getKey() : null;
        $identity = is_string($tokenId) && $tokenId !== ''
            ? 'token|'.$tokenId
            : ($request->hasSession() ? 'session|'.$request->session()->getId() : 'ip|'.$request->ip());

        return hash('sha256', 'user|'.$userId.'|'.$identity);
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
