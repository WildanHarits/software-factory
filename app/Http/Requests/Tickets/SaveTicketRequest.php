<?php

namespace App\Http\Requests\Tickets;

use App\Enums\ApprovalMode;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a ticket, or changing its title, description and agent settings.
 */
class SaveTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && (bool) $this->user()?->can('work', $project);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'model' => ['required', Rule::in(array_keys(config('factory.models')))],
            'effort' => ['required', Rule::in(array_keys(config('factory.efforts')))],
            'approval_mode' => ['required', Rule::enum(ApprovalMode::class)],
            'skip_clarify' => ['boolean'],
            'skip_analyze' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'skip_clarify' => $this->boolean('skip_clarify'),
            'skip_analyze' => $this->boolean('skip_analyze'),
        ]);
    }
}
