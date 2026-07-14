<?php

namespace App\Services\Apple;

use App\Models\FcmToken;
use App\Models\SystemSetting;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class ApnsClient
{
    /** @param array<string, string> $data */
    public function send(FcmToken $token, string $title, string $body, array $data = []): Response
    {
        $credentials = $this->credentials();
        $host = ($credentials['environment'] ?? 'production') === 'sandbox'
            ? 'https://api.sandbox.push.apple.com'
            : 'https://api.push.apple.com';

        return Http::withToken($this->providerToken($credentials))
            ->acceptJson()
            ->withHeaders(['apns-topic' => $credentials['bundle_id'], 'apns-push-type' => 'alert', 'apns-priority' => '10'])
            ->post($host.'/3/device/'.$token->token, [
                'aps' => ['alert' => ['title' => $title, 'body' => $body], 'sound' => 'default', 'content-available' => 1],
                ...$data,
                'title' => $title,
                'body' => $body,
                'display_title' => $title,
                'display_body' => $body,
            ]);
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
