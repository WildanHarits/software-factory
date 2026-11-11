<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Credential;
use App\Models\User;

class CredentialPolicy
{
    /**
     * See the credential's name and status, and use it on a project.
     */
    public function view(User $user, Credential $credential): bool
    {
        if ($credential->isPersonal()) {
            return $credential->user_id === $user->id;
        }

        return $credential->team !== null && $user->belongsToTeam($credential->team);
    }

    /**
     * Rename, replace, verify or delete the credential.
     */
    public function manage(User $user, Credential $credential): bool
    {
        if ($credential->isPersonal()) {
            return $credential->user_id === $user->id;
        }

        return $credential->team !== null
            && $user->hasTeamPermission($credential->team, TeamPermission::ManageCredentials);
    }
}
