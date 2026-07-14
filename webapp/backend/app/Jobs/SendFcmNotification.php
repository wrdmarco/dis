<?php

namespace App\Jobs;

use App\Models\FcmToken;
use App\Models\PushDeliveryLog;
use App\Services\PushProviderClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SendFcmNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @param  array<string, string>  $data
     */
    public function __construct(
        public readonly string $fcmTokenId,
        public readonly string $messageType,
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
        public readonly ?string $dispatchRequestId = null,
    ) {}

    public function handle(PushProviderClient $client): void
    {
        $token = FcmToken::query()->find($this->fcmTokenId);
        if ($token === null || ! $token->is_active) {
            return;
        }

        try {
            $response = $client->send($token, $this->title, $this->body, $this->data);
            $payload = $response->json();
            $status = $response->successful() ? 'sent' : 'failed';
            $errorCode = $response->successful() ? null : $this->providerErrorCode($token, $payload, $response->status());

            $providerMessageId = $response->successful()
                ? ((string) ($payload['name'] ?? $response->header('apns-id') ?: '')) ?: null
                : null;

            $this->recordDelivery($token, $status, $providerMessageId, $errorCode);

            if (in_array($errorCode, ['NOT_FOUND', 'INVALID_ARGUMENT', 'UNREGISTERED', 'BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
                $token->update(['is_active' => false, 'revoked_at' => now()]);
                if (! $token->user?->fcmTokens()->where('is_active', true)->exists()) {
                    $token->user?->update(['push_enabled' => false]);
                }
            }
        } catch (Throwable $exception) {
            $this->recordDelivery($token, 'failed', null, 'delivery_exception');
            report($exception);

            throw $exception;
        }
    }

    private function recordDelivery(FcmToken $token, string $status, ?string $providerMessageId, ?string $errorCode): void
    {
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
    }

    private function providerErrorCode(FcmToken $token, mixed $payload, int $httpStatus): string
    {
        if (strtolower((string) $token->platform) === 'ios') {
            return is_array($payload) && is_string($payload['reason'] ?? null)
                ? $payload['reason']
                : 'apns_http_'.max(100, min(599, $httpStatus));
        }

        $candidates = [];
        if (is_array($payload) && is_array($payload['error'] ?? null)) {
            $details = $payload['error']['details'] ?? [];
            if (is_array($details)) {
                foreach ($details as $detail) {
                    if (is_array($detail)) {
                        $candidates[] = $detail['errorCode'] ?? null;
                    }
                }
            }

            $candidates[] = $payload['error']['status'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return 'fcm_http_'.max(100, min(599, $httpStatus));
    }
}
