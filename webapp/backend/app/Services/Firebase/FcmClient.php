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
        $isDispatchRequest = ($data['type'] ?? null) === 'dispatch_request';
        $messageData = $isDispatchRequest
            ? array_merge($data, ['title' => $title, 'body' => $body])
            : $data;
        $message = [
            'token' => $token->token,
            'data' => $messageData,
            'android' => [
                'priority' => 'HIGH',
                'notification' => [
                    'channel_id' => 'dispatch_alerts',
                    'default_sound' => true,
                ],
            ],
        ];

        if (! $isDispatchRequest) {
            $message['notification'] = [
                'title' => $title,
                'body' => $body,
            ];
        }

        return Http::withToken($this->tokens->token())
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
            ]);
    }
}
