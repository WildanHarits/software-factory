<?php

namespace App\Http\Requests\Credentials;

use App\Models\Credential;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCredentialRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Credential $credential */
        $credential = $this->route('credential');

        return [
            'name' => ['required', 'string', 'max:100'],
            'token' => ['nullable', 'string', 'min:8', 'max:2000'],
            'base_url' => StoreCredentialRequest::baseUrlRules($credential->provider),
        ];
    }

    public function after(): array
    {
        return [
            function ($validator) {
                /** @var Credential $credential */
                $credential = $this->route('credential');

                if ($credential->provider->isSubscription() && $this->filled('token') && ! str_starts_with((string) $this->input('token'), 'sk-ant-oat')) {
                    $validator->errors()->add('token', __('Paste the token printed by `claude setup-token` (it starts with sk-ant-oat).'));
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'token' => $this->filled('token') ? trim((string) $this->input('token')) : null,
            'base_url' => $this->filled('base_url') ? rtrim(trim((string) $this->input('base_url')), '/') : null,
        ]);
    }
}
