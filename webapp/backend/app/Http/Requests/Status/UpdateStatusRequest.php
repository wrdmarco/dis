<?php

namespace App\Http\Requests\Status;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:available,unavailable,assigned,en_route,on_scene,resting,suspended'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

