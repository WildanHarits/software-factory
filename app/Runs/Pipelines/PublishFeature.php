<?php

namespace App\Runs\Pipelines;

use App\Data\Providers\PullRequest;
use App\Enums\MergePolicy;
use App\Enums\PullRequestState;
use App\Models\Run;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Runner\RunFailed;
use App\Runner\RunRecorder;
use App\Services\Providers\GitHost;
use App\Services\Providers\ProviderException;
use App\Tickets\PullRequestTracker;

/**
 * Gets a finished feature ready to merge:
 *
 * 1. Merge the latest base branch into the feature branch. If git reports conflicts, the agent
 *    resolves them; if any conflict markers remain, the merge is undone and the run fails.
 * 2. Push the branch.
 * 3. Open the pull/merge request, or keep the open one (pushing already updated it).
 * 4. Apply the project's merge policy: wait for a person, auto-merge when checks pass, or merge now.
 */
class PublishFeature extends Pipeline
{
    public function handle(Run $run, RunRecorder $log): void
    {
        $ticket = $run->ticket ?? throw new RunFailed('This run has no ticket.');
        $project = $run->project;

        if (! $ticket->branch || ! $this->workspaces->remoteBranchExists($project, $ticket->branch)) {
            throw new RunFailed('The feature branch is missing. Run Implement first.');
        }

        $worktree = $this->checkout($run, $log, $ticket->branch, startBranch: $ticket->branch, worktreeName: "ticket-{$ticket->id}");

        try {
            $this->mergeBase($run, $log, $ticket, $worktree);

            $this->throwIfCancelled($run);
            $log->stage('Pushing');
            $this->git->push($worktree, $ticket->branch, $this->gitCredential($project));
            $run->forceFill(['commit_sha' => $this->git->run(['rev-parse', 'HEAD'], $worktree)])->save();
            $log->output("Pushed {$ticket->branch}");
        } finally {
            $this->workspaces->removeWorktree($project, $worktree);
        }

        $host = $this->providers->gitHost($this->gitCredential($project));
        $pullRequest = $this->openOrReuse($run, $log, $ticket, $host);

        $this->applyMergePolicy($run, $log, $ticket, $host, $pullRequest);
    }

    private function mergeBase(Run $run, RunRecorder $log, Ticket $ticket, string $worktree): void
    {
        $base = (string) $run->base_branch;

        $log->stage("Merging the latest {$base}");

        if ($this->git->merge($worktree, "origin/{$base}")) {
            $log->output("Up to date with {$base}");

            return;
        }

        $conflicts = $this->git->conflictedFiles($worktree);
        $log->output('Conflicts in: '.implode(', ', $conflicts));

        $log->stage('Resolving conflicts with the agent');

        try {
            $this->runAgent($run, $log, $worktree, $this->conflictPrompt($ticket, $base, $conflicts), [
                'SPECIFY_FEATURE' => (string) $ticket->branch,
                'SPECIFY_FEATURE_DIRECTORY' => (string) $ticket->feature_dir,
            ]);

            $left = array_values(array_filter($conflicts, fn (string $file) => $this->hasConflictMarkers("{$worktree}/{$file}")));

            if ($left !== []) {
                throw new RunFailed('Conflicts are still in '.implode(', ', $left).". Resolve them in {$ticket->branch}, then press Update pull request.");
            }

            $sha = $this->git->concludeMerge($worktree);
            $log->output('Conflicts resolved in merge commit '.substr($sha, 0, 8));
        } catch (\Throwable $exception) {
            $this->git->abortMerge($worktree);

            throw $exception;
        }
    }

    /**
     * @param  array<int, string>  $files
     */
    private function conflictPrompt(Ticket $ticket, string $base, array $files): string
    {
        return "This feature branch ({$ticket->branch}) is being merged with the latest {$base}. Git stopped on conflicts in:\n"
            .implode("\n", array_map(fn (string $file) => "- {$file}", $files))
            ."\n\nResolve every conflict. Keep the intent of both sides so this feature ({$ticket->title}) and the latest {$base} both work, "
            .'and remove every conflict marker (<<<<<<<, =======, >>>>>>>). Run the tests if the project has them. '
            .'Do not run git commands. Finish with a short note on how you resolved each file.';
    }

    private function hasConflictMarkers(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        return (bool) preg_match('/^(<{7}|={7}|>{7})( |$)/m', (string) file_get_contents($path));
    }

    private function openOrReuse(Run $run, RunRecorder $log, Ticket $ticket, GitHost $host): PullRequest
    {
        $project = $run->project;

        if ($ticket->pull_request_number && $ticket->pull_request_state === PullRequestState::Open) {
            try {
                $existing = $host->pullRequest($project->repo_full_name, $ticket->pull_request_number);

                if ($existing->state === PullRequest::OPEN) {
                    $run->forceFill(['pull_request_number' => $existing->number, 'pull_request_url' => $existing->url])->save();
                    $log->record('stage', "Pull request #{$existing->number} updated", $existing->url);

                    return $existing;
                }

                app(PullRequestTracker::class)->apply($ticket, $existing->state);
            } catch (ProviderException) {
                // Fall through and open a new one.
            }
        }

        $log->stage('Opening the pull request');

        try {
            $pullRequest = $host->createPullRequest(
                $project->repo_full_name,
                (string) $ticket->branch,
                (string) $run->base_branch,
                "{$ticket->title} (#{$ticket->number})",
                $this->description($ticket),
            );
        } catch (ProviderException $exception) {
            throw new RunFailed("The branch {$ticket->branch} is pushed, but opening the pull request failed: {$exception->getMessage()}");
        }

        $ticket->forceFill([
            'pull_request_number' => $pullRequest->number,
            'pull_request_url' => $pullRequest->url,
            'pull_request_state' => PullRequestState::Open,
            'merged_at' => null,
        ])->save();

        $run->forceFill(['pull_request_number' => $pullRequest->number, 'pull_request_url' => $pullRequest->url])->save();
        $log->record('stage', "Pull request #{$pullRequest->number} opened", $pullRequest->url);

        return $pullRequest;
    }

    private function applyMergePolicy(Run $run, RunRecorder $log, Ticket $ticket, GitHost $host, PullRequest $pullRequest): void
    {
        $project = $run->project;
        $method = (string) config('factory.git.merge_method', 'squash');

        switch ($project->merge_policy) {
            case MergePolicy::Manual:
                $log->output('Waiting for a person to review and merge it.');

                return;

            case MergePolicy::WhenChecksPass:
                try {
                    $host->enableAutoMerge($project->repo_full_name, $pullRequest, $method);
                    $log->stage('Auto-merge is on: it merges once the required checks pass');
                } catch (ProviderException $exception) {
                    $log->record('error', 'Auto-merge could not be turned on', $exception->getMessage().' The pull request stays open for a person to merge.');
                }

                return;

            case MergePolicy::Immediate:
                try {
                    $host->mergePullRequest($project->repo_full_name, $pullRequest, $method);
                    app(PullRequestTracker::class)->markMerged($ticket->refresh());
                    $log->stage("Merged into {$run->base_branch}");
                } catch (ProviderException $exception) {
                    $log->record('error', 'Merging failed', $exception->getMessage().' The pull request stays open.');
                }

                return;
        }
    }

    /**
     * The pull request description: what the feature is, links to its Spec Kit files and task progress.
     */
    private function description(Ticket $ticket): string
    {
        $project = $ticket->project;
        $links = collect(['spec.md', 'plan.md', 'tasks.md'])
            ->filter(fn (string $file) => $ticket->documents()->where('path', "{$ticket->feature_dir}/{$file}")->exists())
            ->map(fn (string $file) => '['.$file.']('.$project->fileUrl((string) $ticket->branch, "{$ticket->feature_dir}/{$file}").')')
            ->implode(' · ');

        $tasks = TicketDocument::query()
            ->where('ticket_id', $ticket->id)
            ->where('path', "{$ticket->feature_dir}/tasks.md")
            ->value('content');

        $done = $tasks ? preg_match_all('/^\s*- \[[xX]\]/m', $tasks) : 0;
        $total = $tasks ? preg_match_all('/^\s*- \[[ xX]\]/m', $tasks) : 0;

        return implode("\n\n", array_filter([
            trim((string) $ticket->description),
            $links !== '' ? "**Spec Kit:** `{$ticket->feature_dir}` ({$links})" : null,
            $total > 0 ? "**Tasks:** {$done} of {$total} done" : null,
            '_Built phase by phase with [Spec Kit](https://github.com/github/spec-kit) in Software Factory._',
        ]));
    }
}
