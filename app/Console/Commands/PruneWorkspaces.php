<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\Project;
use App\Models\Run;
use App\Runner\GitClient;
use App\Runner\RunFailed;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Frees disk space in the workspace folder:
 * - folders of projects that no longer exist, or were removed more than a week ago;
 * - worktrees left behind by runs that crashed (normally each run removes its own).
 */
#[Signature('factory:prune-workspaces {--dry-run : Only list what would be removed}')]
#[Description('Remove leftover worktrees and folders of deleted projects')]
class PruneWorkspaces extends Command
{
    private const KEEP_DELETED_PROJECTS_DAYS = 7;

    private const WORKTREE_GRACE_SECONDS = 3600;

    public function handle(GitClient $git): int
    {
        $root = rtrim((string) config('factory.runner.workspace_path'), '/');
        $removed = 0;

        foreach (glob("{$root}/team-*/project-*", GLOB_ONLYDIR) ?: [] as $folder) {
            $project = Project::withTrashed()->find((int) substr(basename($folder), strlen('project-')));

            if (! $project || ($project->trashed() && $project->deleted_at?->lt(now()->subDays(self::KEEP_DELETED_PROJECTS_DAYS)))) {
                $removed += $this->remove($folder, 'project no longer exists');

                continue;
            }

            foreach (glob("{$folder}/worktrees/*", GLOB_ONLYDIR) ?: [] as $worktree) {
                if (! $this->inUse(basename($worktree)) && filemtime($worktree) < time() - self::WORKTREE_GRACE_SECONDS) {
                    $removed += $this->remove($worktree, 'no active run');
                }
            }

            if (! $this->option('dry-run') && is_dir("{$folder}/repo/.git")) {
                try {
                    $git->run(['worktree', 'prune'], "{$folder}/repo");
                } catch (RunFailed) {
                    //
                }
            }
        }

        $this->components->info(($this->option('dry-run') ? 'Would remove' : 'Removed')." {$removed} folder(s).");

        return self::SUCCESS;
    }

    /**
     * Worktrees are named "run-{id}" (project runs) or "ticket-{id}" (feature runs).
     */
    private function inUse(string $name): bool
    {
        $active = Run::query()->whereIn('status', RunStatus::active());

        return match (true) {
            str_starts_with($name, 'run-') => $active->whereKey((int) substr($name, 4))->exists(),
            str_starts_with($name, 'ticket-') => $active->where('ticket_id', (int) substr($name, 7))->exists(),
            default => true,
        };
    }

    private function remove(string $path, string $reason): int
    {
        $this->line(($this->option('dry-run') ? '  would remove ' : '  removing ')."{$path} ({$reason})");

        if (! $this->option('dry-run')) {
            File::deleteDirectory($path);
        }

        return 1;
    }
}
