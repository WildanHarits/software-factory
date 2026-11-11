<?php

namespace App\Http\Requests\Credentials;

use App\Enums\CredentialProvider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'base_url' => ['nullable', 'string', 'max:255', 'url:https', 'prohibited_unless:provider,gitlab'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'base_url.url' => __('Use a full https:// address, like https://gitlab.example.com.'),
            'base_url.prohibited_unless' => __('A custom URL is only supported for GitLab.'),
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
