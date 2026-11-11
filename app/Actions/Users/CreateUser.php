<?php

namespace App\Actions\Users;

use App\Actions\Teams\CreateTeam;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateUser
{
    public function __construct(private CreateTeam $createTeam)
    {
        //
    }

    /**
     * Create a user with their personal team, and optionally add them to another team.
     *
     * There is no public registration, so this is the only way accounts are made.
     */
    public function handle(
        string $name,
        string $email,
        string $password,
        bool $isSuperAdmin = false,
        ?Team $team = null,
        TeamRole $role = TeamRole::Member,
    ): User {
        return DB::transaction(function () use ($name, $email, $password, $isSuperAdmin, $team, $role) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $user->forceFill([
                'email_verified_at' => now(),
                'is_super_admin' => $isSuperAdmin,
            ])->save();

            $this->createTeam->handle($user, $user->name."'s Team", isPersonal: true);

            if ($team) {
                $team->memberships()->create([
                    'user_id' => $user->id,
                    'role' => $role,
                ]);

                $user->switchTeam($team);
            }

            return $user;
        });
    }
}
