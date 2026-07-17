<?php

namespace App\Services\Firebase;

use App\Models\SystemSetting;
use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Auth\HttpHandler\HttpHandlerFactory;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class FirebaseAccessTokenProvider
{
    public function token(): string
    {
        return Cache::remember('firebase.messaging.access_token', now()->addMinutes(45), function (): string {
            $credentials = SystemSetting::value('firebase.service_account', []);

            if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
                throw new RuntimeException('Firebase service account is not configured.');
            }

            $serviceAccount = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                [
                    'type' => 'service_account',
                    'project_id' => SystemSetting::string('firebase.project_id', config('dis.push.fcm_project_id')),
                    'private_key_id' => $credentials['private_key_id'] ?? '',
                    'private_key' => str_replace('\\n', "\n", (string) $credentials['private_key']),
                    'client_email' => $credentials['client_email'],
                    'client_id' => $credentials['client_id'] ?? '',
                    'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
                    'token_uri' => 'https://oauth2.googleapis.com/token',
                    'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
                    'client_x509_cert_url' => $credentials['client_x509_cert_url'] ?? '',
                    'universe_domain' => 'googleapis.com',
                ],
            );

            $token = $serviceAccount->fetchAuthToken(HttpHandlerFactory::build(
                new Client([
                    'connect_timeout' => 3.0,
                    'timeout' => 8.0,
                ]),
                false,
            ));
            if (! isset($token['access_token']) || ! is_string($token['access_token'])) {
                throw new RuntimeException('Unable to fetch Firebase access token.');
            }

            return $token['access_token'];
        });
    }
}
