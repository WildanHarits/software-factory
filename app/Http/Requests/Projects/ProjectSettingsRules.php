<?php

namespace App\Http\Requests\Projects;

use App\Enums\ApprovalMode;
use App\Enums\CredentialProvider;
use App\Enums\MergePolicy;
use App\Models\Credential;
use App\Models\Team;
use App\Models\User;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation shared by creating a project and changing its settings.
 */
trait ProjectSettingsRules
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function settingsRules(User $user, Team $team): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'git_credential_id' => ['required', 'integer', $this->usableCredential($user, $team, git: true)],
            'ai_credential_id' => ['nullable', 'integer', $this->usableCredential($user, $team, git: false)],
            'default_model' => ['required', Rule::in(array_keys(config('factory.models')))],
            'default_effort' => ['required', Rule::in(array_keys(config('factory.efforts')))],
            'approval_mode' => ['required', Rule::enum(ApprovalMode::class)],
            'merge_policy' => ['required', Rule::enum(MergePolicy::class)],
        ];
    }

    /**
     * The credential must belong to this team or to the person saving the project.
     */
    private function usableCredential(User $user, Team $team, bool $git): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($user, $team, $git) {
            $credential = Credential::query()->usableBy($user, $team)->whereKey($value)->first();

            if (! $credential) {
                $fail(__('Pick a token from this team or one of your personal tokens.'));

                return;
            }

            if ($git !== $credential->provider->isGit()) {
                $fail($git
                    ? __('Pick a GitHub or GitLab token.')
                    : __('Pick an :provider key.', ['provider' => CredentialProvider::Anthropic->label()]));
            }
        };
    }

    protected function prepareSettings(): void
    {
        $this->merge([
            'ai_credential_id' => $this->filled('ai_credential_id') && $this->input('ai_credential_id') !== 'none'
                ? $this->input('ai_credential_id')
                : null,
        ]);
    }
}
