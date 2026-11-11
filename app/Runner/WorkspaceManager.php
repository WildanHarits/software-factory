<?php

namespace App\Runner;

use App\Models\Credential;
use App\Models\Project;
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
     * Clone the project's repository if needed, then fetch the latest changes.
     *
     * @param  callable(string): void  $log
     */
    public function sync(Project $project, Credential $credential, callable $log, ?string $forBranch = null): void
    {
        $repo = $this->repoPath($project);

        if (! File::isDirectory("{$repo}/.git")) {
            $log("Cloning {$project->repo_full_name}");
            File::ensureDirectoryExists(dirname($repo), 0700);
            $this->git->run(['clone', '--no-checkout', $project->repo_clone_url, $repo], dirname($repo), $credential, timeout: 1800);
        } else {
            // The clone URL may have changed (e.g. the repo was renamed).
            $this->git->run(['remote', 'set-url', 'origin', $project->repo_clone_url], $repo);
        }

        $log('Fetching the latest changes'.($forBranch ? " on {$forBranch}" : ''));
        $this->git->run(['fetch', '--prune', 'origin'], $repo, $credential, timeout: 1800);
    }

    public function repoPath(Project $project): string
    {
        return $this->projectPath($project).'/repo';
    }

    /**
     * The highest Spec Kit feature number ("012" in specs/012-photo-albums) on a remote branch.
     * Call after createWorktree() has fetched.
     */
    public function highestFeatureNumber(Project $project, string $branch): int
    {
        try {
            $listing = $this->git->run(['ls-tree', '--name-only', "refs/remotes/origin/{$branch}", 'specs/'], $this->repoPath($project));
        } catch (RunFailed) {
            return 0;
        }

        $highest = 0;

        foreach (explode("\n", $listing) as $path) {
            if (preg_match('#^specs/(\d{3,})-#', trim($path), $matches)) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return $highest;
    }

    public function remoteBranchExists(Project $project, string $branch): bool
    {
        try {
            $this->git->run(['rev-parse', '--verify', '--quiet', "refs/remotes/origin/{$branch}"], $this->repoPath($project));

            return true;
        } catch (RunFailed) {
            return false;
        }
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
     * Check out $branch in its own worktree, starting from the latest commit of $startBranch on the remote.
     *
     * $name keeps the folder stable between runs of the same ticket ("ticket-12"), which Claude Code
     * needs to resume a session (it files sessions by folder).
     *
     * @param  callable(string): void  $log
     */
    public function createWorktree(Project $project, Credential $credential, string $name, string $branch, string $startBranch, callable $log): string
    {
        $repo = $this->repoPath($project);
        $worktree = $this->projectPath($project)."/worktrees/{$name}";
        $baseBranch = $startBranch;

        File::ensureDirectoryExists(dirname($worktree), 0700);

        $this->sync($project, $credential, $log, $baseBranch);

        try {
            $this->git->run(['rev-parse', '--verify', '--quiet', "refs/remotes/origin/{$baseBranch}"], $repo);
        } catch (RunFailed) {
            throw new RunFailed("The branch \"{$baseBranch}\" doesn't exist in {$project->repo_full_name}. If the repository is empty, push a first commit, then try again.");
        }

        if (File::isDirectory($worktree)) {
            $this->removeWorktree($project, $worktree);
        }

        $log($branch === $baseBranch ? "Checking out {$branch}" : "Creating branch {$branch} from {$baseBranch}");
        $this->git->run(['worktree', 'add', '--force', '-B', $branch, $worktree, "origin/{$baseBranch}"], $repo);

        return $worktree;
    }

    public function removeWorktree(Project $project, string $worktree): void
    {
        $repo = $this->repoPath($project);

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
