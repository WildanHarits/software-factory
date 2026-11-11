<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use App\Enums\TeamRole;
use App\Models\Team;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'password' => ['required', 'string', Password::default()],
            'is_super_admin' => ['boolean'],
            'team_id' => ['nullable', 'integer', Rule::exists(Team::class, 'id')->where('is_personal', false)->whereNull('deleted_at')],
            'role' => ['required_with:team_id', 'nullable', Rule::enum(TeamRole::class)->except([TeamRole::Owner])],
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_super_admin' => $this->boolean('is_super_admin'),
            'team_id' => $this->filled('team_id') && $this->input('team_id') !== 'none' ? $this->input('team_id') : null,
        ]);
    }
}
