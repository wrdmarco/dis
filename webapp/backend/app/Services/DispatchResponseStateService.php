<?php

namespace App\Services;

use App\Models\DispatchRecipient;
use App\Models\DispatchRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class DispatchResponseStateService
{
    private const RESPONSE_OPEN_DISPATCH_STATUSES = ['draft', 'sent', 'escalated'];

    private const TERMINAL_DEPLOYMENT_STATUSES = ['resolved', 'cancelled', 'canceled'];

    /**
     * Return only the authenticated operator's response state. This remains
     * readable after accepting, declining or acknowledging a test alert so a
     * second registered device can safely reconcile its local alarm.
     *
     * @return array{
     *   dispatch_id: string,
     *   deployment_id: string,
     *   dispatch_status: string,
     *   response_status: string,
     *   requires_response: bool
     * }
     */
    public function forOperator(DispatchRequest $dispatch, User $actor): array
    {
        $hasOperatorPermission = $actor->hasClientPermission('deployments.assigned.view', 'operator')
            || $actor->hasClientPermission('deployments.dispatch.view', 'operator');
        if (! $actor->isOperatorClient() || ! $hasOperatorPermission) {
            throw new AuthorizationException('The dispatch is not assigned to this operator.');
        }

        $recipient = DispatchRecipient::query()
            ->where('dispatch_request_id', $dispatch->id)
            ->where('user_id', $actor->id)
            ->first();
        if ($recipient === null) {
            throw new AuthorizationException('The dispatch is not assigned to this operator.');
        }

        $deploymentStatus = (string) $dispatch->deployment()->value('status');
        $responseStatus = (string) $recipient->response_status;
        $requiresResponse = $responseStatus === 'pending'
            && in_array((string) $dispatch->status, self::RESPONSE_OPEN_DISPATCH_STATUSES, true)
            && ! in_array($deploymentStatus, self::TERMINAL_DEPLOYMENT_STATUSES, true);

        return [
            'dispatch_id' => (string) $dispatch->id,
            'deployment_id' => (string) $dispatch->deployment_id,
            'dispatch_status' => (string) $dispatch->status,
            'response_status' => $responseStatus,
            'requires_response' => $requiresResponse,
        ];
    }
}
