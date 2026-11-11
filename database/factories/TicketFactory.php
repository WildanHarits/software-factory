<?php

namespace Database\Factories;

use App\Enums\ApprovalMode;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'team_id' => fn (array $attributes) => Project::query()->whereKey($attributes['project_id'])->value('team_id'),
            'number' => fn (array $attributes) => (int) Ticket::withTrashed()->where('project_id', $attributes['project_id'])->max('number') + 1,
            'title' => rtrim(fake()->sentence(3), '.'),
            'description' => fake()->paragraph(),
            'stage' => TicketStage::Backlog,
            'status' => TicketStatus::Idle,
            'model' => 'claude-sonnet-5-5',
            'effort' => 'medium',
            'approval_mode' => ApprovalMode::Manual,
        ];
    }

    /**
     * A ticket whose phase finished and waits for review.
     */
    public function awaiting(TicketStage $stage): static
    {
        return $this->state(fn (array $attributes) => [
            'stage' => $stage,
            'status' => TicketStatus::AwaitingApproval,
        ])->afterMaking(function (Ticket $ticket) {
            $ticket->forceFill([
                'feature_number' => 1,
                'branch' => '001-'.str($ticket->title)->slug(),
                'feature_dir' => 'specs/001-'.str($ticket->title)->slug(),
                'session_id' => 'session-1',
            ]);
        });
    }
}
