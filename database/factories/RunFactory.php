<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Run>
 */
class RunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => fn (array $attributes) => Project::query()->whereKey($attributes['project_id'])->value('team_id'),
            'type' => RunType::SetupSpecKit,
            'status' => RunStatus::Queued,
            'model' => 'claude-sonnet-5-5',
            'effort' => 'medium',
            'base_branch' => 'main',
        ];
    }
}
