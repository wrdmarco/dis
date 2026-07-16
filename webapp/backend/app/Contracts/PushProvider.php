<?php

namespace App\Contracts;

use App\Models\FcmToken;
use Illuminate\Http\Client\Response;

interface PushProvider
{
    /** @param array<string, string> $data */
    public function send(FcmToken $token, string $title, string $body, array $data = []): Response;
}
