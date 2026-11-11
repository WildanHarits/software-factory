<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use App\Rules\UniqueTeamInvitation;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamInvitationRequest extends FormRequest
{
    /**
     * Check permission before validating, so non-admins cannot probe which emails have accounts.
     */
    public function authorize(): bool
    {
        $team = $this->route('team');

        return $team instanceof Team && (bool) $this->user()?->can('inviteMember', $team);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $team = $this->route('team');

        abort_if(! $team instanceof Team, 404);

        return [
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                // There is no public registration, so only existing accounts can accept an invitation.
                function (string $attribute, mixed $value, Closure $fail) {
                    if (! User::whereRaw('LOWER(email) = ?', [strtolower((string) $value)])->exists()) {
                        $fail(__('No account uses this email. Ask a super admin to create the account first.'));
                    }
                },
                new UniqueTeamInvitation($team),
            ],
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ];
    }
}
