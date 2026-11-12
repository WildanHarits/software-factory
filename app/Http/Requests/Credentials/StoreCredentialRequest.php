<?php

namespace App\Http\Requests\Credentials;

use App\Enums\CredentialProvider;
use App\Runner\Agent\ProviderModels;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCredentialRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::enum(CredentialProvider::class)],
            'name' => ['required', 'string', 'max:100'],
            'token' => ['required', 'string', 'min:8', 'max:2000'],
            'base_url' => self::baseUrlRules($this->enum('provider', CredentialProvider::class)),
        ];
    }

    /**
     * GitLab takes an optional self-hosted URL; Qwen takes the endpoint of the key's plan.
     *
     * @return array<int, mixed>
     */
    public static function baseUrlRules(?CredentialProvider $provider): array
    {
        return match ($provider) {
            CredentialProvider::GitLab => ['nullable', 'string', 'max:255', 'url:https'],
            CredentialProvider::Qwen => ['required', Rule::in(array_keys(ProviderModels::qwenEndpoints()))],
            default => ['prohibited'],
        };
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $provider = $this->enum('provider', CredentialProvider::class);

                if (! $provider?->isSubscription()) {
                    return;
                }

                // Saving to a team (the route has a team) would share one person's subscription.
                if ($this->route('team')) {
                    $validator->errors()->add('provider', __('A Claude subscription is personal. Add it under your own Settings → Tokens.'));
                }

                if (! str_starts_with((string) $this->input('token'), 'sk-ant-oat')) {
                    $validator->errors()->add('token', __('Paste the token printed by `claude setup-token` (it starts with sk-ant-oat).'));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_url.url' => __('Use a full https:// address, like https://gitlab.example.com.'),
            'base_url.prohibited' => __('A custom URL is only supported for GitLab.'),
            'base_url.required' => __('Pick the plan this key belongs to.'),
            'base_url.in' => __('Pick the plan this key belongs to.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'token' => trim((string) $this->input('token')),
            'base_url' => $this->filled('base_url') ? rtrim(trim((string) $this->input('base_url')), '/') : null,
        ]);
    }
}
