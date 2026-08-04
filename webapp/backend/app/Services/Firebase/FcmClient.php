<?php

namespace App\Services\Firebase;

use App\Models\FcmToken;
use App\Models\SystemSetting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class FcmClient
{
    private const PREANNOUNCEMENT_TTL_SECONDS = 120;

    private const WEB_LOGIN_APPROVAL_TTL_SECONDS = 120;

    private const RESPONSE_SYNC_TTL_SECONDS = 30;

    /**
     * Data-only messages that immediately start, update or stop visible
     * operational feedback. Unknown and background-only control messages remain
     * normal priority so they cannot consume the time-sensitive delivery path.
     * Response synchronisation is high priority but short-lived: a sleeping
     * second device must wake promptly, then authenticate and reconcile before
     * it may stop its already-visible alarm feedback.
     *
     * @var list<string>
     */
    private const VISIBLE_HIGH_PRIORITY_TYPES = [
        'dispatch_request',
        'dispatch_update',
        'deployment_preannouncement',
        'deployment_preannouncement_cancelled',
        'incident_preannouncement',
        'manual_admin',
        'location_share_request',
        'web_login_approval',
        'deployment_cancelled',
        'incident_cancelled',
        'dispatch_response_sync',
    ];

    public function __construct(private readonly FirebaseAccessTokenProvider $tokens) {}

    /**
     * @param  array<string, string>  $data
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
        $android = ['priority' => $this->androidPriority($data)];
        if ($this->isPreannouncement($data)) {
            $android['ttl'] = self::PREANNOUNCEMENT_TTL_SECONDS.'s';
        } elseif ($this->notificationType($data) === 'dispatch_response_sync') {
            $android['ttl'] = self::RESPONSE_SYNC_TTL_SECONDS.'s';
        } elseif ($this->notificationType($data) === 'web_login_approval') {
            $android['ttl'] = self::WEB_LOGIN_APPROVAL_TTL_SECONDS.'s';
        }
        $message = [
            'token' => $token->token,
            'data' => $messageData,
            'android' => $android,
        ];

        return Http::withToken($this->tokens->token())
            ->connectTimeout(3)
            ->timeout(10)
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                'message' => $message,
            ]);
    }

    /**
     * @param  array<string, string>  $data
     */
    private function androidPriority(array $data): string
    {
        $type = $this->notificationType($data);

        return is_string($type) && in_array($type, self::VISIBLE_HIGH_PRIORITY_TYPES, true)
            ? 'HIGH'
            : 'NORMAL';
    }

    /**
     * @param  array<string, string>  $data
     */
    private function isPreannouncement(array $data): bool
    {
        $type = $this->notificationType($data);

        return $type === 'deployment_preannouncement'
            || $type === 'incident_preannouncement'
            || ($type === 'dispatch_update' && ($data['action_mode'] ?? null) === 'availability');
    }

    /**
     * @param  array<string, string>  $data
     */
    private function notificationType(array $data): ?string
    {
        $eventType = $data['deployment_event_type'] ?? null;
        if (is_string($eventType) && $eventType !== '') {
            return $eventType;
        }

        $type = $data['type'] ?? null;

        return is_string($type) && $type !== '' ? $type : null;
    }
}
