<?php

namespace App\Http\Requests\Speech;

use App\Models\SpeechPreparedPhrase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreSpeechPreparedPhrasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') === true
            && $this->user()?->hasPermission('speech.cache.manage') === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('kind'))) {
            $this->merge(['kind' => trim((string) $this->input('kind'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(SpeechPreparedPhrase::KINDS)],
            'values' => ['required', 'array', 'min:1', 'max:50'],
            'values.*' => [
                'bail',
                'required',
                'string',
                'max:240',
                'not_regex:/[\x00-\x1F\x7F]/u',
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $kind = $this->input('kind');
                $values = $this->input('values');
                if (! is_string($kind) || ! is_array($values)) {
                    return;
                }
                foreach ($values as $index => $value) {
                    if (! is_string($value) || trim($value) === '') {
                        $validator->errors()->add("values.$index", 'De waarde mag niet leeg zijn.');

                        continue;
                    }
                    if (preg_match('/[<>]|&(?:#[0-9]+|#x[a-f0-9]+|[a-z][a-z0-9]+);/iu', $value) === 1) {
                        $validator->errors()->add("values.$index", 'Markup en tekentiteiten zijn niet toegestaan.');
                    }
                    if ($kind === 'fixed_phrase' && (str_contains($value, '{') || str_contains($value, '}'))) {
                        $validator->errors()->add(
                            "values.$index",
                            'Een vaste zin mag geen templatevariabelen bevatten.',
                        );
                    }
                    if ($kind === 'postcode'
                        && preg_match('/^[1-9][0-9]{3}\s?[A-Za-z]{2}$/D', trim($value)) !== 1) {
                        $validator->errors()->add("values.$index", 'Vul een geldige Nederlandse postcode in.');
                    }
                }
            },
        ];
    }
}
