<?php

namespace App\Runs\Pipelines;

use App\Enums\TicketStage;
use App\Models\Run;
use App\Models\Ticket;
use App\Models\TicketDocument;
use App\Runner\RunFailed;
use App\Runner\RunRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Runs one Spec Kit phase (specify, clarify, plan, tasks, analyze, implement) for a ticket,
 * or continues the last agent session with a person's reply.
 *
 * Every ticket has one branch ("012-photo-albums") and one feature folder ("specs/012-photo-albums").
 * Each run checks out that branch, runs the agent, commits, pushes, and saves the Spec Kit files.
 */
class TicketPhase extends Pipeline
{
    private const MAX_DOCUMENT_BYTES = 200_000;

    private const MAX_DOCUMENTS = 40;

    public function handle(Run $run, RunRecorder $log): void
    {
        $ticket = $run->ticket ?? throw new RunFailed('This run has no ticket.');
        $stage = $run->type->stage() ?? throw new RunFailed('This run type is not a ticket phase.');
        $reply = trim((string) ($run->input['reply'] ?? ''));

        // Fail before cloning if the agent can't run.
        $this->aiCredential($run->project);

        if ($reply !== '' && ! $ticket->session_id) {
            throw new RunFailed('There is no agent session to reply to yet.');
        }

        if ($ticket->branch === null) {
            if ($stage !== TicketStage::Specify) {
                throw new RunFailed('Run Specify first: it creates the feature branch and spec.');
            }

            $this->assignFeature($run, $ticket, $log);
        }

        $isNewBranch = ! $this->workspaces->remoteBranchExists($run->project, (string) $ticket->branch);

        if ($isNewBranch && $stage !== TicketStage::Specify) {
            throw new RunFailed("The branch {$ticket->branch} is missing from the repository. Was it deleted?");
        }

        $worktree = $this->checkout(
            $run,
            $log,
            (string) $ticket->branch,
            startBranch: $isNewBranch ? null : $ticket->branch,
            worktreeName: "ticket-{$ticket->id}",
        );

        try {
            $log->stage($reply !== ''
                ? "Sending your reply to the agent ({$stage->label()})"
                : 'Running '.$this->command($stage->value));

            $this->runAgent(
                $run,
                $log,
                $worktree,
                $reply !== '' ? $reply : $this->prompt($stage, $ticket, trim((string) ($run->input['notes'] ?? ''))),
                [
                    'SPECIFY_FEATURE' => (string) $ticket->branch,
                    'SPECIFY_FEATURE_DIRECTORY' => (string) $ticket->feature_dir,
                ],
                $reply !== '' ? $ticket->session_id : null,
            );

            $this->commitAndPush(
                $run,
                $log,
                $worktree,
                $stage->label().($reply !== '' ? ' (revised)' : '').": {$ticket->title} (#{$ticket->number})",
            );

            $this->findFeatureFolder($ticket, $worktree);
            $this->saveDocuments($ticket, $worktree, $log);
        } finally {
            $this->workspaces->removeWorktree($run->project, $worktree);
        }
    }

    /**
     * What the agent is asked to do. Notes typed when approving are passed as the command's arguments.
     */
    public function prompt(TicketStage $stage, Ticket $ticket, string $notes = ''): string
    {
        $command = $this->command($stage->value);

        if ($stage === TicketStage::Specify) {
            return trim($command.' '.$ticket->title."\n\n".trim((string) $ticket->description)."\n\n".$notes);
        }

        return trim($command.' '.$notes);
    }

    /**
     * Pick the feature number, branch and folder on the first run.
     *
     * The number is above anything already in specs/ on the base branch and above every other
     * ticket of the project, so parallel features never collide when they're merged.
     */
    private function assignFeature(Run $run, Ticket $ticket, RunRecorder $log): void
    {
        $project = $run->project;
        $base = $run->base_branch ?: $project->default_branch;

        $log->stage('Preparing the workspace');
        $this->workspaces->sync($project, $this->gitCredential($project), fn (string $message) => $log->output($message), $base);

        $inRepository = $this->workspaces->highestFeatureNumber($project, $base);
        $slug = Str::of($ticket->title)->slug()->explode('-')->filter()->take(5)->implode('-') ?: 'feature';

        DB::transaction(function () use ($ticket, $project, $inRepository, $slug) {
            // Lock the project's tickets so two tickets starting at once can't take the same number.
            $inTickets = (int) Ticket::withTrashed()->where('project_id', $project->id)->lockForUpdate()->max('feature_number');
            $number = max($inRepository, $inTickets) + 1;
            $branch = sprintf('%03d-%s', $number, $slug);

            $ticket->forceFill([
                'feature_number' => $number,
                'branch' => $branch,
                'feature_dir' => "specs/{$branch}",
            ])->save();
        });

        $log->output("Feature {$ticket->branch}");
    }

    /**
     * If the agent wrote the spec somewhere other than the folder we asked for, follow it.
     */
    private function findFeatureFolder(Ticket $ticket, string $worktree): void
    {
        if (is_file("{$worktree}/{$ticket->feature_dir}/spec.md")) {
            return;
        }

        $candidates = glob("{$worktree}/specs/*-*/spec.md") ?: [];

        foreach ($candidates as $spec) {
            $folder = 'specs/'.basename(dirname($spec));

            if (str_starts_with(basename(dirname($spec)), sprintf('%03d-', (int) $ticket->feature_number))) {
                $ticket->forceFill(['feature_dir' => $folder])->save();

                return;
            }
        }
    }

    /**
     * Copy the feature folder's files into the database, so the board can show them without a checkout.
     */
    private function saveDocuments(Ticket $ticket, string $worktree, RunRecorder $log): void
    {
        $folder = "{$worktree}/{$ticket->feature_dir}";

        if (! is_dir($folder)) {
            return;
        }

        $saved = [];

        foreach (File::allFiles($folder) as $file) {
            if (count($saved) >= self::MAX_DOCUMENTS) {
                break;
            }

            if (! in_array(strtolower($file->getExtension()), ['md', 'yaml', 'yml', 'json', 'txt'], true) || $file->getSize() > self::MAX_DOCUMENT_BYTES) {
                continue;
            }

            $path = $ticket->feature_dir.'/'.str_replace('\\', '/', $file->getRelativePathname());

            TicketDocument::updateOrCreate(
                ['ticket_id' => $ticket->id, 'path' => $path],
                ['content' => RunRecorder::validUtf8((string) file_get_contents($file->getPathname()))],
            );

            $saved[] = $path;
        }

        TicketDocument::query()->where('ticket_id', $ticket->id)->whereNotIn('path', $saved)->delete();

        if ($saved !== []) {
            $log->output('Saved '.count($saved).' document(s): '.implode(', ', array_map('basename', $saved)));
        }
    }
}
