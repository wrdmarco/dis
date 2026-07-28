<?php

namespace App\Http\Resources;

use App\Models\CalendarEventRegistration;
use App\Support\ApiDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CalendarEventRegistrationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CalendarEventRegistration $registration */
        $registration = $this->resource;

        return [
            'id' => (string) $registration->id,
            'user' => [
                'id' => $registration->user_id === null
                    ? null
                    : (string) $registration->user_id,
                'name' => (string) $registration->user_name,
                'email' => $registration->user?->email,
            ],
            'registered_at' => ApiDateTime::dateTime($registration->registered_at),
            'registered_by_name' => (string) $registration->registered_by_name,
        ];
    }
}
