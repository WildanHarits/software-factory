<?php

namespace App\Tickets;

use App\Data\Providers\PullRequest;
use App\Enums\PullRequestState;
use App\Enums\RunType;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Models\Project;
use App\Models\Ticket;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderRegistry;

/**
 * Keeps a ticket in step with its pull/merge request: a merged PR moves the card to Done.
 * Fed by webhooks, by the background sync, and by "merge immediately".
 */
class PullRequestTracker
{
    public function __construct(private ProviderRegistry $providers)
    {
        //
    }

    /**
     * Find the ticket a pull request belongs to, by number or by branch.
     */
    public function ticketFor(Project $project, int $number, ?string $branch = null): ?Ticket
    {
        return $project->tickets()
            ->where(fn ($query) => $query
                ->where('pull_request_number', $number)
                ->when($branch, fn ($query) => $query->orWhere('branch', $branch)))
            ->orderByDesc('id')
            ->first();
    }

    public function apply(Ticket $ticket, string $state): void
    {
        match ($state) {
            PullRequest::MERGED => $this->markMerged($ticket),
            PullRequest::CLOSED => $ticket->forceFill(['pull_request_state' => PullRequestState::Closed])->save(),
            default => $ticket->forceFill(['pull_request_state' => PullRequestState::Open])->save(),
        };
    }

    public function markMerged(Ticket $ticket): void
    {
        if ($ticket->pull_request_state === PullRequestState::Merged) {
            return;
        }

        $ticket->forceFill([
            'pull_request_state' => PullRequestState::Merged,
            'merged_at' => now(),
        ]);

        // The feature is in the base branch now. Move cards waiting in Review, or whose own publish run
        // just merged it ("merge immediately"); leave cards alone while the agent is still changing them.
        $publishing = $ticket->status === TicketStatus::Running && $ticket->lastRun?->type === RunType::Publish;

        if ($ticket->stage === TicketStage::Review && ($ticket->status !== TicketStatus::Running || $publishing)) {
            $ticket->forceFill(['stage' => TicketStage::Done, 'status' => TicketStatus::Idle]);
        }

        $ticket->save();
    }

    /**
     * Ask the provider for the current state. Returns false when it couldn't be read.
     */
    public function refresh(Ticket $ticket): bool
    {
        $credential = $ticket->project->gitCredential;

        if (! $credential || ! $ticket->pull_request_number) {
            return false;
        }

        try {
            $pullRequest = $this->providers->gitHost($credential)->pullRequest($ticket->project->repo_full_name, $ticket->pull_request_number);
        } catch (ProviderException $exception) {
            report($exception);

            return false;
        }

        $this->apply($ticket, $pullRequest->state);

        return true;
    }
}
