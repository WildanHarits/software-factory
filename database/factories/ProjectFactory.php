<?php

namespace Database\Factories;

use App\Enums\ApprovalMode;
use App\Enums\CredentialProvider;
use App\Enums\MergePolicy;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $repo = fake()->unique()->slug(2);

        return [
            'team_id' => Team::factory(),
            'name' => ucwords(str_replace('-', ' ', $repo)),
            'description' => fake()->sentence(),
            'provider' => CredentialProvider::GitHub,
            'repo_full_name' => "acme/{$repo}",
            'repo_external_id' => (string) fake()->numberBetween(1000, 999999),
            'repo_web_url' => "https://github.com/acme/{$repo}",
            'repo_clone_url' => "https://github.com/acme/{$repo}.git",
            'default_branch' => 'main',
            'repo_private' => true,
            'default_model' => config('factory.default_model'),
            'default_effort' => config('factory.default_effort'),
            'approval_mode' => ApprovalMode::Manual,
            'merge_policy' => MergePolicy::Manual,
        ];
    }
}
