<?php

namespace Database\Seeders;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed local development data. Never run this in production.
     *
     * Logins (password: "password"):
     *  - admin@example.com  super admin, owner of "Acme"
     *  - member@example.com member of "Acme"
     */
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'is_super_admin' => true,
        ]);

        $member = User::factory()->create([
            'name' => 'Member User',
            'email' => 'member@example.com',
        ]);

        $team = Team::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
        $team->members()->attach($admin, ['role' => TeamRole::Owner->value]);
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $admin->switchTeam($team);
        $member->switchTeam($team);
    }
}
