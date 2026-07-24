<?php

namespace App\Services\Apple;

use App\Models\FcmToken;
use App\Models\SystemSetting;
use App\Support\PushNotificationIdentity;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ApnsClient
{
    private const PREANNOUNCEMENT_TTL_SECONDS = 120;

    private const SESSION_NEUTRAL_ALERT_TITLE = 'Nieuwe D.I.S.-melding';

    private const SESSION_NEUTRAL_ALERT_BODY = 'Open D.I.S. om de actuele melding veilig te bekijken.';

    /** @param array<string, string> $data */
    public function send(FcmToken $token, string $title, string $body, array $data = []): Response
    {
        $credentials = $this->credentials();
        $host = ($credentials['environment'] ?? 'production') === 'sandbox'
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';

        $headers = ['apns-topic' => $credentials['bundle_id'], 'apns-push-type' => 'alert', 'apns-priority' => '10'];
        $collapseId = PushNotificationIdentity::dispatchCollapseId($data);
        if ($collapseId !== null) {
            $headers['apns-collapse-id'] = $collapseId;
        }
        if ($this->isPreannouncement($data)) {
            $headers['apns-expiration'] = (string) now()->addSeconds(self::PREANNOUNCEMENT_TTL_SECONDS)->timestamp;
        }

        return Http::withToken($this->providerToken($credentials))
            ->connectTimeout(3)
            ->timeout(10)
            ->acceptJson()
            ->withHeaders($headers)
            ->post($host.'/3/device/'.$token->token, [
                // iOS can render an APNs alert before the application gets a
                // chance to compare session_token_id. Keep the system-owned
                // surface account-neutral; the app may use the caller copy
                // below only after its session-binding check succeeds.
                'aps' => [
                    'alert' => [
                        'title' => self::SESSION_NEUTRAL_ALERT_TITLE,
                        'body' => self::SESSION_NEUTRAL_ALERT_BODY,
                    ],
                    'sound' => 'default',
                    'content-available' => 1,
                ],
                ...$data,
                'title' => $title,
                'body' => $body,
                'display_title' => $title,
                'display_body' => $body,
            ]);
    }

    /** @param array<string, string> $data */
    private function isPreannouncement(array $data): bool
    {
        $type = $data['type'] ?? null;

        return $type === 'incident_preannouncement'
            || ($type === 'dispatch_update' && ($data['action_mode'] ?? null) === 'availability');
    }

    /** @return array{team_id:string,key_id:string,bundle_id:string,private_key:string,environment:string} */
    private function credentials(): array
    {
        $value = SystemSetting::value('push.apns.credentials', []);
        if (! is_array($value) || ! filled($value['team_id'] ?? null) || ! filled($value['key_id'] ?? null)
            || ! filled($value['bundle_id'] ?? null) || ! filled($value['private_key'] ?? null)) {
            throw new RuntimeException('APNs credentials are not configured.');
        }

        return $value;
    }

    /** @param array{team_id:string,key_id:string,bundle_id:string,private_key:string,environment:string} $credentials */
    private function providerToken(array $credentials): string
    {
        $cacheKey = 'apns.provider_token.'.hash('sha256', $credentials['key_id'].$credentials['team_id']);

        return Cache::remember($cacheKey, now()->addMinutes(50), fn (): string => JWT::encode(
            ['iss' => $credentials['team_id'], 'iat' => now()->timestamp],
            $credentials['private_key'], 'ES256', $credentials['key_id'],
        ));
    }
}
