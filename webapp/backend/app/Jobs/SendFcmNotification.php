<?php

namespace App\Jobs;

use App\Models\DispatchRequest;
use App\Models\FcmToken;
use App\Models\PushDeliveryLog;
use App\Services\Firebase\FcmClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class SendFcmNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param array<string, string> $data
     */
    public function __construct(
        public readonly string $fcmTokenId,
        public readonly string $messageType,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $dispatchRequestId = null,
    ) {}

    public function handle(FcmClient $client): void
    {
        $token = FcmToken::query()->find($this->fcmTokenId);
        if ($token === null || ! $token->is_active) {
            return;
        }

        $response = $client->send($token, $this->title, $this->body, $this->data);
        $payload = $response->json();
        $status = $response->successful() ? 'sent' : 'failed';
        $errorCode = $response->successful() ? null : (string) ($payload['error']['status'] ?? $payload['error']['message'] ?? 'fcm_error');

        $providerMessageId = $response->successful() && isset($payload['name']) ? (string) $payload['name'] : null;

        PushDeliveryLog::query()->create([
            'user_id' => $token->user_id,
            'fcm_token_id' => $token->id,
            'dispatch_request_id' => $this->dispatchRequestId,
            'message_type' => $this->messageType,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'error_code' => $errorCode,
            'sent_at' => now(),
        ]);

        if (in_array($errorCode, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED'], true)) {
            $token->update(['is_active' => false, 'revoked_at' => now()]);
            if (! $token->user?->fcmTokens()->where('is_active', true)->exists()) {
                $token->user?->update(['push_enabled' => false]);
            }
        }
    }
}
