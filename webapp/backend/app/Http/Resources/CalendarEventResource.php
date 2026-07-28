<?php

namespace App\Http\Resources;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\CalendarEventAccessService;
use App\Services\WebSessionService;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CalendarEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CalendarEvent $event */
        $event = $this->resource;
        $actor = $request->user();
        if (! $actor instanceof User) {
            $actor = null;
        }

        $groups = $event->relationLoaded('audienceGroups')
            ? $event->audienceGroups
            : collect();
        $participantCount = (int) ($event->getAttribute('participant_count') ?? 0);
        $maximum = $event->max_participants === null
            ? null
            : (int) $event->max_participants;
        $full = $maximum !== null && $participantCount >= $maximum;
        $registered = $actor !== null
            && app(CalendarEventAccessService::class)->isRegistered($event, $actor);
        $audienceMember = $actor !== null
            && app(CalendarEventAccessService::class)->isAudienceMember($event, $actor);
        $maySelfRegister = $actor !== null
            && $this->hasPermission($request, $actor, 'calendar.view')
            && $this->hasPermission($request, $actor, 'calendar.register');
        $statefulWeb = app(WebSessionService::class)->isStatefulWebRequest($request);
        $canViewParticipants = $statefulWeb
            && $actor !== null
            && $this->hasPermission($request, $actor, 'calendar.view')
            && $this->hasPermission($request, $actor, 'calendar.registrations.view');
        $canManageParticipants = $statefulWeb
            && $actor !== null
            && $this->hasPermission($request, $actor, 'calendar.view')
            && $this->hasPermission($request, $actor, 'calendar.registrations.manage');
        $canRegister = $maySelfRegister
            && $audienceMember
            && ! $registered
            && (bool) $event->registration_enabled
            && ! $full;
        $canUnregister = $maySelfRegister && $registered;
        $audienceScope = $groups->contains(
            static fn ($group): bool => (bool) $group->is_everyone,
        )
            ? CalendarEvent::AUDIENCE_SCOPE_EVERYONE
            : CalendarEvent::AUDIENCE_SCOPE_GROUPS;

        return [
            'id' => (string) $event->id,
            'title' => (string) $event->title,
            'type' => (string) $event->type,
            'starts_at' => ApiDateTime::dateTime($event->starts_at),
            'ends_at' => ApiDateTime::dateTime($event->ends_at),
            'location_label' => $event->location_label,
            'description' => $event->description,
            'audience_scope' => $audienceScope,
            'group_ids' => $groups
                ->pluck('id')
                ->map(static fn ($id): string => (string) $id)
                ->values()
                ->all(),
            'audience_groups' => $groups
                ->map(static fn ($group): array => [
                    'id' => (string) $group->id,
                    'name' => (string) $group->name,
                    'is_everyone' => (bool) $group->is_everyone,
                ])
                ->values()
                ->all(),
            // team_id/team are bounded legacy response fields only.
            'team_id' => $event->team_id === null ? null : (string) $event->team_id,
            'team' => $event->team === null ? null : [
                'id' => (string) $event->team->id,
                'code' => (string) $event->team->code,
                'name' => (string) $event->team->name,
                'type' => (string) $event->team->type,
            ],
            'registration' => [
                'enabled' => (bool) $event->registration_enabled,
                'status' => ! $event->registration_enabled
                    ? 'closed'
                    : ($full ? 'full' : 'open'),
                'max_participants' => $maximum,
                'participant_count' => $participantCount,
                'current_user_registered' => $registered,
                'can_register' => $canRegister,
                'can_unregister' => $canUnregister,
                'can_view_participants' => $canViewParticipants,
                'can_manage_participants' => $canManageParticipants,
                'unavailable_reason' => $this->unavailableReason(
                    $event,
                    $registered,
                    $maySelfRegister,
                    $audienceMember,
                    $full,
                    $canRegister,
                ),
            ],
            'created_by_name' => $event->creator?->name,
            'created_at' => ApiDateTime::dateTime($event->created_at),
        ];
    }

    private function unavailableReason(
        CalendarEvent $event,
        bool $registered,
        bool $maySelfRegister,
        bool $audienceMember,
        bool $full,
        bool $canRegister,
    ): ?string {
        if ($canRegister) {
            return null;
        }
        if ($registered) {
            return 'already_registered';
        }
        if (! $event->registration_enabled) {
            return 'registration_closed';
        }
        if (! $maySelfRegister) {
            return 'permission_missing';
        }
        if (! $audienceMember) {
            return 'outside_audience';
        }
        if ($full) {
            return 'calendar_event_full';
        }

        return null;
    }

    private function hasPermission(
        Request $request,
        User $actor,
        string $permission,
    ): bool {
        $cacheKey = 'calendar_event_permission:'.$permission;
        if (! $request->attributes->has($cacheKey)) {
            $request->attributes->set($cacheKey, $actor->hasPermission($permission));
        }

        return $request->attributes->getBoolean($cacheKey);
    }
}
