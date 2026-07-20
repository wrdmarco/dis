<?php

namespace App\Http\Requests\Admin;

use App\Services\KnmiOpenDataConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpdateKnmiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'open_data_api_key' => [
                'sometimes',
                'required',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || ! app(KnmiOpenDataConfiguration::class)->validApiKey($value)) {
                        $fail('De KNMI Open Data API-sleutel is ongeldig.');
                    }
                },
            ],
            'edr_api_key' => [
                'sometimes',
                'required',
                'string',
                'max:2000',
                'regex:/\A[\x21-\x7E]{16,2000}\z/D',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->exists('open_data_api_key') && ! $this->exists('edr_api_key')) {
                    $validator->errors()->add('settings', 'Geef minimaal een KNMI API-sleutel op.');
                }
            },
        ];
    }
}
