<?php

namespace App\Http\Requests\Devices;

use Illuminate\Foundation\Http\FormRequest;

final class RegisterFcmTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'string', 'max:180'],
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'in:android'],
            'app_version' => ['nullable', 'string', 'max:80'],
            'device_manufacturer' => ['nullable', 'string', 'max:120'],
            'device_model' => ['nullable', 'string', 'max:160'],
            'android_version' => ['nullable', 'string', 'max:80'],
            'sdk_version' => ['nullable', 'string', 'max:40'],
        ];
    }
}
