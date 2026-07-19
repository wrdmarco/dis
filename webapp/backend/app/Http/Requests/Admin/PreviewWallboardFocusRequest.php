<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PreviewWallboardFocusRequest extends FormRequest
{
    /** @var list<string> */
    public const KINDS = ['preannouncement', 'test_alarm', 'real_alarm'];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(self::KINDS)],
            'expected_control_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
