<?php

namespace App\Http\Requests\Projects;

use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartRunRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('update', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $constitution = $this->routeIs('projects.runs.constitution');

        return [
            'model' => ['required', Rule::in(array_keys(config('factory.models')))],
            'effort' => ['required', Rule::in(array_keys(config('factory.efforts')))],
            'principles' => [$constitution ? 'prohibited' : 'nullable', 'string', 'max:5000'],
            'instructions' => [$constitution ? 'required' : 'prohibited', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'instructions.required' => __('Describe what the constitution should say or change.'),
        ];
    }
}
