<?php

namespace App\Http\Requests;

use App\Services\WallboardForecastLocationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ForecastLocationRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $locationLabel = $this->input('location_label');

        $this->merge([
            'location_mode' => $this->input(
                'location_mode',
                WallboardForecastLocationService::MODE_NETHERLANDS,
            ),
            ...is_string($locationLabel) ? ['location_label' => trim($locationLabel)] : [],
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'location_mode' => [
                'required',
                'string',
                Rule::in([
                    WallboardForecastLocationService::MODE_NETHERLANDS,
                    WallboardForecastLocationService::MODE_ADDRESS,
                ]),
            ],
            'location_label' => [
                'bail',
                Rule::requiredIf(fn (): bool => $this->input('location_mode') === WallboardForecastLocationService::MODE_ADDRESS),
                Rule::prohibitedIf(fn (): bool => $this->input('location_mode') !== WallboardForecastLocationService::MODE_ADDRESS),
                'string',
                'max:160',
            ],
            'latitude' => ['prohibited'],
            'longitude' => ['prohibited'],
        ];
    }

    /** @return array{location_mode: string, location_label?: string} */
    public function forecastOptions(): array
    {
        $validated = $this->validated();
        $options = ['location_mode' => (string) $validated['location_mode']];

        if ($options['location_mode'] === WallboardForecastLocationService::MODE_ADDRESS) {
            $options['location_label'] = (string) $validated['location_label'];
        }

        return $options;
    }
}
