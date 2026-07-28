<?php

namespace App\Http\Controllers;

use App\Exceptions\CalendarEventConflictException;
use App\Http\Requests\Calendar\ListCalendarRegistrationOptionsRequest;
use App\Http\Requests\Calendar\ManageCalendarRegistrationRequest;
use App\Http\Requests\Calendar\ManageOwnCalendarRegistrationRequest;
use App\Http\Requests\Calendar\ViewCalendarRegistrationsRequest;
use App\Http\Resources\CalendarEventRegistrationResource;
use App\Http\Resources\CalendarEventResource;
use App\Http\Responses\ApiResponse;
use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\CalendarEventRegistrationService;
use Illuminate\Http\JsonResponse;

final class CalendarEventRegistrationController extends Controller
{
    public function __construct(
        private readonly CalendarEventRegistrationService $service,
    ) {}

    public function storeMine(
        ManageOwnCalendarRegistrationRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        try {
            $event = $this->service->registerSelf(
                $calendarEvent,
                $request->user(),
                $request,
            );
        } catch (CalendarEventConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(
            (new CalendarEventResource($event))->resolve($request),
        );
    }

    public function destroyMine(
        ManageOwnCalendarRegistrationRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        $event = $this->service->unregisterSelf(
            $calendarEvent,
            $request->user(),
            $request,
        );

        return ApiResponse::success(
            (new CalendarEventResource($event))->resolve($request),
        );
    }

    public function index(
        ViewCalendarRegistrationsRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        return ApiResponse::success(
            CalendarEventRegistrationResource::collection(
                $this->service->roster($calendarEvent, $request->user()),
            )->resolve($request),
        );
    }

    public function options(
        ListCalendarRegistrationOptionsRequest $request,
        CalendarEvent $calendarEvent,
    ): JsonResponse {
        $data = $request->validated();

        return ApiResponse::success(
            $this->service->options(
                $calendarEvent,
                $request->user(),
                isset($data['search']) ? (string) $data['search'] : null,
            )
                ->map(static fn (User $user): array => [
                    'id' => (string) $user->id,
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ])
                ->values(),
        );
    }

    public function store(
        ManageCalendarRegistrationRequest $request,
        CalendarEvent $calendarEvent,
        User $user,
    ): JsonResponse {
        try {
            $event = $this->service->registerForUser(
                $calendarEvent,
                $user,
                $request->user(),
                $request,
            );
        } catch (CalendarEventConflictException $exception) {
            return $this->conflict($exception);
        }

        return ApiResponse::success(
            (new CalendarEventResource($event))->resolve($request),
        );
    }

    public function destroy(
        ManageCalendarRegistrationRequest $request,
        CalendarEvent $calendarEvent,
        User $user,
    ): JsonResponse {
        $event = $this->service->unregisterForUser(
            $calendarEvent,
            $user,
            $request->user(),
            $request,
        );

        return ApiResponse::success(
            (new CalendarEventResource($event))->resolve($request),
        );
    }

    private function conflict(CalendarEventConflictException $exception): JsonResponse
    {
        return ApiResponse::error(
            $exception->errorCode,
            $exception->getMessage(),
            409,
            $exception->details,
        );
    }
}
