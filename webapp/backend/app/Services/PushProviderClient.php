<?php

namespace App\Services;

use App\Models\FcmToken;
use App\Services\Apple\ApnsClient;
use App\Services\Firebase\FcmClient;
use Illuminate\Http\Client\Response;

final class PushProviderClient
{
    public function __construct(private readonly FcmClient $fcm, private readonly ApnsClient $apns) {}

    /** @param array<string, string> $data */
    public function send(FcmToken $token, string $title, string $body, array $data = []): Response
    {
        return strtolower((string) $token->platform) === 'ios'
            ? $this->apns->send($token, $title, $body, $data)
            : $this->fcm->send($token, $title, $body, $data);
    }
}
