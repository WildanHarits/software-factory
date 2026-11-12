<?php

namespace App\Runs\Pipelines;

use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use App\Runner\Agent\AgentInvocation;
use App\Runner\Agent\AgentRunner;
use App\Runner\GitClient;
use App\Runner\RunCancelled;
use App\Runner\RunFailed;
use App\Runner\RunRecorder;
use App\Runner\WorkspaceManager;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderRegistry;

/**
 * The steps of one kind of run. Shared helpers: check out a branch, run the agent,
 * commit, push and open a pull request.
 */
abstract class Pipeline
{
    /**
     * Added to Claude Code's system prompt for every run.
     */
    public const UNATTENDED_PROMPT = 'You are running unattended inside Software Factory. Nobody can answer you during this run. '
        .'If you need decisions or information from a person, ask all your questions in your final message as a numbered list, then stop; '
        .'their answers will arrive in a follow-up message. '
        .'Do not run git commit, git push, git checkout or git switch: Software Factory commits and pushes your changes after you finish. '
        .'End with a short summary of what you did.';

    public function __construct(
        protected WorkspaceManager $workspaces,
        protected GitClient $git,
        protected AgentRunner $agent,
        protected ProviderRegistry $providers,
    ) {
        //
    }

    abstract public function handle(Run $run, RunRecorder $log): void;

    protected function gitCredential(Project $project): Credential
    {
        return $project->gitCredential
            ?? throw new RunFailed('This project has no git token. Pick one in the project settings.');
    }

    /**
     * The AI token for this run: the project's key, or the Claude subscription of whoever started it.
     */
    protected function aiCredential(Run $run): Credential
    {
        $project = $run->project;

        return $project->aiCredentialFor($run->triggeredBy)
            ?? throw new RunFailed($project->missingAiCredentialMessage());
    }

    /**
     * Check out a branch for this run. Returns the worktree path.
     *
     * By default the branch starts fresh from the base branch; pass $startBranch to continue an existing branch.
     */
    protected function checkout(Run $run, RunRecorder $log, string $branch, ?string $startBranch = null, ?string $worktreeName = null): string
    {
        $project = $run->project;
        $base = $run->base_branch ?: $project->default_branch;

        $run->forceFill(['branch' => $branch, 'base_branch' => $base])->save();

        $log->stage('Preparing the workspace');

        return $this->workspaces->createWorktree(
            $project,
            $this->gitCredential($project),
            $worktreeName ?? "run-{$run->id}",
            $branch,
            $startBranch ?? $base,
            fn (string $message) => $log->output($message),
        );
    }

    /**
     * Run Claude Code with a prompt (usually a Spec Kit command) in the worktree.
     */
    /**
     * @param  array<string, string>  $environment
     */
    protected function runAgent(
        Run $run,
        RunRecorder $log,
        string $worktree,
        string $prompt,
        array $environment = [],
        ?string $resumeSessionId = null,
    ): void {
        $project = $run->project;
        $budget = config('factory.runner.max_budget_usd');
        $credential = $this->aiCredential($run);

        $result = $this->agent->run(
            new AgentInvocation(
                workingDirectory: $worktree,
                prompt: $prompt,
                model: $run->model ?: $project->default_model,
                effort: $run->effort ?: $project->default_effort,
                apiKey: $credential->token,
                configDirectory: $this->workspaces->claudeConfigPath($project),
                timeoutSeconds: (int) config('factory.runner.timeout'),
                maxBudgetUsd: is_numeric($budget) ? (float) $budget : null,
                resumeSessionId: $resumeSessionId,
                environment: $environment,
                appendSystemPrompt: self::UNATTENDED_PROMPT,
                provider: $credential->provider,
                baseUrl: $credential->base_url,
            ),
            onEvent: fn (string $kind, ?string $title, ?string $body) => $log->record($kind, $title, $body),
            shouldCancel: fn () => $run->cancelRequested(),
        );

        // Costs add up across agent calls within one run.
        $run->forceFill([
            'session_id' => $result->sessionId ?? $run->session_id,
            'num_turns' => ($run->num_turns ?? 0) + ($result->numTurns ?? 0),
            'input_tokens' => $run->input_tokens + $result->inputTokens,
            'output_tokens' => $run->output_tokens + $result->outputTokens,
            'cache_read_tokens' => $run->cache_read_tokens + $result->cacheReadTokens,
            'cache_write_tokens' => $run->cache_write_tokens + $result->cacheWriteTokens,
            'cost_usd' => (float) $run->cost_usd + $result->costUsd,
            'result_text' => $result->resultText ?? $run->result_text,
        ])->save();

        if ($result->cancelled) {
            throw new RunCancelled;
        }

        if ($result->permissionDenials !== []) {
            $log->record('error', 'Some actions were blocked', count($result->permissionDenials).' action(s) needed permission and were denied automatically.');
        }

        if (! $result->successful) {
            throw new RunFailed($result->error ?? 'The agent did not finish.');
        }
    }

    /**
     * Commit everything, push the branch and open a pull/merge request.
     */
    protected function commitAndOpenPullRequest(Run $run, RunRecorder $log, string $worktree, string $message, string $title, string $body): void
    {
        $project = $run->project;
        $credential = $this->gitCredential($project);

        $this->throwIfCancelled($run);
        $log->stage('Committing and opening a pull request');

        $sha = $this->git->commitAll($worktree, $message)
            ?? throw new RunFailed('Nothing changed, so there was nothing to commit.');

        $log->output('Committed '.substr($sha, 0, 8));

        $this->git->push($worktree, (string) $run->branch, $credential);
        $log->output("Pushed {$run->branch}");

        try {
            $pullRequest = $this->providers->gitHost($credential)
                ->createPullRequest($project->repo_full_name, (string) $run->branch, (string) $run->base_branch, $title, $body);
        } catch (ProviderException $exception) {
            $run->forceFill(['commit_sha' => $sha])->save();

            throw new RunFailed("The branch {$run->branch} was pushed, but opening the pull request failed: {$exception->getMessage()}");
        }

        $run->forceFill([
            'commit_sha' => $sha,
            'pull_request_number' => $pullRequest->number,
            'pull_request_url' => $pullRequest->url,
        ])->save();

        $log->record('stage', 'Pull request opened', $pullRequest->url);
    }

    /**
     * Commit everything and push the branch. Returns the commit SHA, or null when nothing changed.
     */
    protected function commitAndPush(Run $run, RunRecorder $log, string $worktree, string $message): ?string
    {
        $this->throwIfCancelled($run);
        $log->stage('Committing and pushing');

        $sha = $this->git->commitAll($worktree, $message);

        if ($sha === null) {
            $log->output('No file changes to commit.');

            return null;
        }

        $this->git->push($worktree, (string) $run->branch, $this->gitCredential($run->project));
        $run->forceFill(['commit_sha' => $sha])->save();
        $log->output('Committed '.substr($sha, 0, 8)." and pushed {$run->branch}");

        return $sha;
    }

    protected function throwIfCancelled(Run $run): void
    {
        if ($run->cancelRequested()) {
            throw new RunCancelled;
        }
    }

    protected function command(string $phase): string
    {
        return (string) config("factory.speckit.commands.{$phase}");
    }
}
