<?php

namespace App\Runner;

use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use Illuminate\Support\Facades\File;

/**
 * Keeps one clone per project and gives each run its own git worktree, so several runs on
 * the same project never touch each other's files.
 *
 * Layout: {workspace_path}/team-{id}/project-{id}/{repo, worktrees/run-{id}, claude-config}
 */
class WorkspaceManager
{
    public function __construct(private GitClient $git)
    {
        //
    }

    public function projectPath(Project $project): string
    {
        return rtrim((string) config('factory.runner.workspace_path'), '/')."/team-{$project->team_id}/project-{$project->id}";
    }

    /**
     * Where Claude Code keeps its sessions for this project (kept so later runs can resume them).
     */
    public function claudeConfigPath(Project $project): string
    {
        $path = $this->projectPath($project).'/claude-config';
        File::ensureDirectoryExists($path, 0700);

        return $path;
    }

    /**
     * Check out a new branch for the run, starting from the latest commit on $baseBranch.
     *
     * @param  callable(string): void  $log
     */
    public function createWorktree(Project $project, Credential $credential, Run $run, string $branch, string $baseBranch, callable $log): string
    {
        $repo = $this->projectPath($project).'/repo';
        $worktree = $this->projectPath($project)."/worktrees/run-{$run->id}";

        File::ensureDirectoryExists(dirname($worktree), 0700);

        if (! File::isDirectory("{$repo}/.git")) {
            $log("Cloning {$project->repo_full_name}");
            File::ensureDirectoryExists(dirname($repo), 0700);
            $this->git->run(['clone', '--no-checkout', $project->repo_clone_url, $repo], dirname($repo), $credential, timeout: 1800);
        } else {
            // The clone URL may have changed (e.g. the repo was renamed).
            $this->git->run(['remote', 'set-url', 'origin', $project->repo_clone_url], $repo);
        }

        $log("Fetching the latest changes on {$baseBranch}");
        $this->git->run(['fetch', '--prune', 'origin'], $repo, $credential, timeout: 1800);

        try {
            $this->git->run(['rev-parse', '--verify', '--quiet', "refs/remotes/origin/{$baseBranch}"], $repo);
        } catch (RunFailed) {
            throw new RunFailed("The branch \"{$baseBranch}\" doesn't exist in {$project->repo_full_name}. If the repository is empty, push a first commit, then try again.");
        }

        if (File::isDirectory($worktree)) {
            $this->removeWorktree($project, $worktree);
        }

        $log("Creating branch {$branch}");
        $this->git->run(['worktree', 'add', '--force', '-B', $branch, $worktree, "origin/{$baseBranch}"], $repo);

        return $worktree;
    }

    public function removeWorktree(Project $project, string $worktree): void
    {
        $repo = $this->projectPath($project).'/repo';

        try {
            $this->git->run(['worktree', 'remove', '--force', $worktree], $repo);
        } catch (RunFailed) {
            File::deleteDirectory($worktree);

            try {
                $this->git->run(['worktree', 'prune'], $repo);
            } catch (RunFailed) {
                //
            }
        }
    }
}
