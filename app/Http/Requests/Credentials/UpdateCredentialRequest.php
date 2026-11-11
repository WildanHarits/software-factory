<?php

namespace App\Http\Requests\Credentials;

use App\Enums\CredentialProvider;
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
            'base_url' => [
                'nullable', 'string', 'max:255', 'url:https',
                ...($credential->provider === CredentialProvider::GitLab ? [] : ['prohibited']),
            ],
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
