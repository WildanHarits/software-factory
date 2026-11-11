<?php

namespace App\Policies;

use App\Enums\TeamPermission;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    /**
     * Create tickets and move them across the board. Every team member can do this.
     */
    public function work(User $user, Project $project): bool
    {
        return $user->belongsToTeam($project->team);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::ManageProjects);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasTeamPermission($project->team, TeamPermission::ManageProjects);
    }
}
