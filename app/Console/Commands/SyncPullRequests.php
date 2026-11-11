<?php

namespace App\Console\Commands;

use App\Enums\PullRequestState;
use App\Models\Ticket;
use App\Tickets\PullRequestTracker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Checks open pull requests with the provider. Covers setups where webhooks can't reach the app
 * (e.g. running on a laptop without a tunnel).
 */
#[Signature('factory:sync-pull-requests')]
#[Description('Check open pull/merge requests and move merged features to Done')]
class SyncPullRequests extends Command
{
    public function handle(PullRequestTracker $tracker): int
    {
        $checked = 0;
        $merged = 0;

        Ticket::query()
            ->with('project.gitCredential')
            ->where('pull_request_state', PullRequestState::Open)
            ->whereNotNull('pull_request_number')
            ->whereHas('project')
            ->each(function (Ticket $ticket) use ($tracker, &$checked, &$merged) {
                if ($tracker->refresh($ticket)) {
                    $checked++;
                    $merged += $ticket->pull_request_state === PullRequestState::Merged ? 1 : 0;
                }
            });

        $this->components->info("Checked {$checked} open pull request(s); {$merged} merged.");

        return self::SUCCESS;
    }
}
