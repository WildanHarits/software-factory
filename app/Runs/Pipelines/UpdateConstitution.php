<?php

namespace App\Runs\Pipelines;

use App\Models\Run;
use App\Runner\RunFailed;
use App\Runner\RunRecorder;

/**
 * Asks the agent to update the project's constitution, then opens a pull request.
 */
class UpdateConstitution extends Pipeline
{
    public function handle(Run $run, RunRecorder $log): void
    {
        $instructions = trim((string) ($run->input['instructions'] ?? ''));

        if ($instructions === '') {
            throw new RunFailed('Describe what the constitution should say.');
        }

        $this->aiCredential($run);

        $worktree = $this->checkout($run, $log, "software-factory/constitution-{$run->id}");

        try {
            if (! is_file("{$worktree}/.specify/memory/constitution.md")) {
                throw new RunFailed('Spec Kit is not set up on '.$run->base_branch.' yet. Merge the setup pull request first.');
            }

            $log->stage('Updating the constitution');
            $this->runAgent($run, $log, $worktree, $this->command('constitution').' '.$instructions);

            $this->commitAndOpenPullRequest(
                $run,
                $log,
                $worktree,
                'Update constitution',
                'Update constitution',
                "Updates `.specify/memory/constitution.md` with:\n\n> ".str_replace("\n", "\n> ", $instructions)."\n\nOpened by Software Factory.",
            );
        } finally {
            $this->workspaces->removeWorktree($run->project, $worktree);
        }
    }
}
