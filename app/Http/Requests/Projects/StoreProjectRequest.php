<?php

namespace App\Http\Requests\Projects;

use App\Models\Team;
use App\Support\RepositoryReference;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    use ProjectSettingsRules;

    public function authorize(): bool
    {
        $team = $this->route('current_team');

        return $team instanceof Team && (bool) $this->user()?->can('manageProjects', $team);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Team $team */
        $team = $this->route('current_team');

        return [
            ...$this->settingsRules($this->user(), $team),
            'mode' => ['required', 'in:new,existing'],

            // Create a new repository.
            'repo_namespace' => ['required_if:mode,new', 'nullable', 'string', 'max:255'],
            'repo_name' => ['required_if:mode,new', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
            'repo_private' => ['boolean'],

            // Connect an existing repository.
            'repository' => [
                'required_if:mode,existing', 'nullable', 'string', 'max:255',
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($this->input('mode') === 'existing' && RepositoryReference::parse((string) $value) === null) {
                        $fail(__('Use owner/repo or paste the repository URL.'));
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'repo_name.regex' => __('Use only letters, numbers, dots, dashes and underscores.'),
            'repo_namespace.required_if' => __('Pick where to create the repository.'),
            'repository.required_if' => __('Enter the repository to connect.'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->prepareSettings();
        $this->merge(['repo_private' => $this->boolean('repo_private')]);
    }
}
