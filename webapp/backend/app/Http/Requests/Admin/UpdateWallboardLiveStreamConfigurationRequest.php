<?php

namespace App\Http\Requests\Admin;

use App\Services\WallboardLiveStreamProcessService;
use App\Services\WebSessionService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateWallboardLiveStreamConfigurationRequest extends FormRequest
{
    public function authorize(WebSessionService $webSessionService): bool
    {
        $webSessionService->assertStatefulWebRequest($this);

        return $this->user()?->hasPermission('wallboards.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(WallboardLiveStreamProcessService $process): array
    {
        $requiredWhenEnabled = Rule::requiredIf(fn (): bool => $this->boolean('enabled'));

        return [
            'enabled' => ['required', 'boolean'],
            'public_host' => [
                'bail',
                'present',
                $requiredWhenEnabled,
                'nullable',
                'string',
                'max:253',
                $this->validWhenPresent(
                    static fn (string $value): bool => $process->isValidPublicHost($value),
                    'Vul een geldige hostnaam of een geldig IPv4-adres in, zonder schema of poort.',
                ),
            ],
            'rtmps_bind_address' => [
                'bail',
                'required',
                'string',
                'max:45',
                static function (string $attribute, mixed $value, Closure $fail) use ($process): void {
                    if (! is_string($value) || ! $process->isValidBindAddress($value)) {
                        $fail('Vul 0.0.0.0 of een geldig lokaal IPv4-adres in.');
                    }
                },
            ],
            'rtmps_port' => [
                'bail',
                'required',
                'integer',
                static function (string $attribute, mixed $value, Closure $fail) use ($process): void {
                    if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                        return;
                    }
                    if (! $process->isValidRtmpsPort((int) $value)) {
                        $fail('Kies een poort van 1024 tot en met 65535 die niet voor de interne stream is gereserveerd.');
                    }
                },
            ],
            'tls_certificate_path' => [
                'bail',
                'present',
                $requiredWhenEnabled,
                'nullable',
                'string',
                'max:4096',
                $this->validWhenPresent(
                    static fn (string $value): bool => $process->isValidPortalTlsPath($value),
                    'Gebruik een certificaatpad onder /etc/letsencrypt/live/ of /etc/ssl/.',
                ),
            ],
            'tls_private_key_path' => [
                'bail',
                'present',
                $requiredWhenEnabled,
                'nullable',
                'string',
                'max:4096',
                $this->validWhenPresent(
                    static fn (string $value): bool => $process->isValidPortalTlsPath($value),
                    'Gebruik een pad naar de private sleutel onder /etc/letsencrypt/live/ of /etc/ssl/.',
                ),
            ],
            'configuration_revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/D'],
            'operation' => ['prohibited'],
            'stream_key' => ['prohibited'],
            'expected_config_sha256' => ['prohibited'],
            'config_sha256' => ['prohibited'],
            'key_created' => ['prohibited'],
            'bind_address' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'created_at' => ['prohibited'],
            'expires_at' => ['prohibited'],
            'state' => ['prohibited'],
            'exit_code' => ['prohibited'],
            'output' => ['prohibited'],
            'finished_at' => ['prohibited'],
            'request_id' => ['prohibited'],
            'outcome' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach ([
            'public_host',
            'rtmps_bind_address',
            'tls_certificate_path',
            'tls_private_key_path',
        ] as $field) {
            $value = $this->input($field);
            if (is_string($value)) {
                $normalized[$field] = trim($value);
            }
        }
        if (isset($normalized['public_host'])) {
            $normalized['public_host'] = strtolower($normalized['public_host']);
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    private function validWhenPresent(callable $validator, string $message): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($validator, $message): void {
            if (is_string($value) && $value !== '' && ! $validator($value)) {
                $fail($message);
            }
        };
    }
}
