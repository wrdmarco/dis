<?php

namespace App\Services\Firebase;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

final class FirebaseAccessTokenProvider
{
    public function token(): string
    {
        return Cache::remember('firebase.messaging.access_token', now()->addMinutes(45), function (): string {
            $credentialsPath = config('dis.push.credentials_path');

            if (! is_string($credentialsPath) || ! is_readable($credentialsPath)) {
                throw new RuntimeException('Firebase credentials file is not readable.');
            }

            $credentials = new ServiceAccountCredentials(
                ['https://www.googleapis.com/auth/firebase.messaging'],
                $credentialsPath,
            );

            $token = $credentials->fetchAuthToken();
            if (! isset($token['access_token']) || ! is_string($token['access_token'])) {
                throw new RuntimeException('Unable to fetch Firebase access token.');
            }

            return $token['access_token'];
        });
    }
}

