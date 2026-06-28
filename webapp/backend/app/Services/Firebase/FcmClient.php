<?php

namespace App\Services\Firebase;

use App\Models\FcmToken;
use App\Models\SystemSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class FcmClient
{
    public function __construct(private readonly FirebaseAccessTokenProvider $tokens) {}

    /**
     * @param array<string, string> $data
     */
    public function send(FcmToken $token, string $title, string $body, array $data = []): Response
    {
        $projectId = SystemSetting::string('firebase.project_id', config('dis.push.fcm_project_id'));
        $messageData = array_merge($data, [
            'title' => $title,
            'body' => $body,
            'display_title' => $title,
            'display_body' => $body,
        ]);
        $message = [
            'token' => $token->token,
            'data' => $messageData,
            'android' => [
                'priority' => 'HIGH',
            ],
        ];

        return Http::withToken($this->tokens->token())
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
            ]);
    }
}
