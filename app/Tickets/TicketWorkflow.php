<?php

namespace App\Tickets;

use App\Enums\ApprovalMode;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Jobs\ExecuteRun;
use App\Models\Project;
use App\Models\Run;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The rules for moving a ticket across the board.
 *
 * - Cards move forward one phase at a time. Clarify and Analyze can be skipped by moving past them.
 * - Moving into a phase column starts that phase's run.
 * - A phase must be finished ("Ready for review") before the card can move on.
 * - Moving from Implement to Review merges the latest base branch, pushes and opens the pull request.
 * - A merged pull request moves the card to Done (webhook or background sync); people can also move it.
 * - In auto mode the next phase starts by itself, except after Clarify, which needs a person's answers.
 */
class TicketWorkflow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Project $project, User $user, array $data): Ticket
    {
        return DB::transaction(function () use ($project, $user, $data) {
            $number = (int) Ticket::withTrashed()->where('project_id', $project->id)->lockForUpdate()->max('number') + 1;

            return $project->tickets()->create([
                'team_id' => $project->team_id,
                'number' => $number,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'stage' => TicketStage::Backlog,
                'status' => TicketStatus::Idle,
                'model' => $data['model'] ?? $project->default_model,
                'effort' => $data['effort'] ?? $project->default_effort,
                'approval_mode' => $data['approval_mode'] ?? $project->approval_mode,
                'skip_clarify' => (bool) ($data['skip_clarify'] ?? false),
                'skip_analyze' => (bool) ($data['skip_analyze'] ?? false),
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * Move a card to a column. Returns the run that was started, if any.
     *
     * @throws ValidationException
     */
    public function moveTo(Ticket $ticket, TicketStage $target, ?User $user, string $notes = ''): ?Run
    {
        $this->ensureIdle($ticket);

        $from = $ticket->stage;

        if ($target === $from) {
            $this->fail("#{$ticket->number} is already in {$target->label()}.");
        }

        // People can mark a feature Done themselves (e.g. merged outside the app, or no webhook), and undo it.
        if ($from === TicketStage::Review && $target === TicketStage::Done
            || $from === TicketStage::Done && $target === TicketStage::Review) {
            $ticket->forceFill(['stage' => $target, 'status' => TicketStatus::Idle])->save();

            return null;
        }

        if ($target->position() < $from->position()) {
            $this->fail('Cards only move forward. To redo a phase, use Retry or reply to the agent.');
        }

        if ($from->isPhase() && $ticket->status !== TicketStatus::AwaitingApproval) {
            $this->fail("Finish {$from->label()} first.");
        }

        if ($target === TicketStage::Done) {
            $this->fail('Move the card to Review first.');
        }

        // Every column passed over must be an optional phase; those get skipped.
        $skipped = [];

        foreach (TicketStage::cases() as $stage) {
            if ($stage->position() <= $from->position() || $stage->position() >= $target->position()) {
                continue;
            }

            if (! $stage->isOptional() && ! $ticket->skips($stage)) {
                $this->fail("{$stage->label()} can't be skipped. Move the card to {$stage->label()} first.");
            }

            $skipped[] = $stage;
        }

        if ($target->isPhase() || $target === TicketStage::Review) {
            $this->ensureProjectCanRun($ticket->project);
        }

        $ticket->forceFill([
            'stage' => $target,
            'status' => TicketStatus::Idle,
            'skip_clarify' => $ticket->skip_clarify || in_array(TicketStage::Clarify, $skipped, true),
            'skip_analyze' => $ticket->skip_analyze || in_array(TicketStage::Analyze, $skipped, true),
        ])->save();

        if ($target === TicketStage::Review) {
            return $this->queue($ticket, RunType::Publish, $user, []);
        }

        return $target->isPhase()
            ? $this->queue($ticket, $target->runType(), $user, $notes !== '' ? ['notes' => $notes] : [])
            : null;
    }

    /**
     * Approve the finished phase and start the next one.
     */
    public function approve(Ticket $ticket, ?User $user, string $notes = ''): ?Run
    {
        $next = $ticket->nextStage() ?? $this->fail('This card is already at the end of the board.');

        return $this->moveTo($ticket, $next, $user, $notes);
    }

    /**
     * Continue the agent's session with a person's message: answers to clarify questions, or requested changes.
     */
    public function reply(Ticket $ticket, User $user, string $message): Run
    {
        $this->ensureIdle($ticket);

        $inReview = $ticket->stage === TicketStage::Review;

        if ((! $ticket->stage->isPhase() && ! $inReview) || ! $ticket->session_id) {
            $this->fail('There is no agent session to reply to.');
        }

        $this->ensureProjectCanRun($ticket->project);

        // In Review, changes go to the Implement session; pushing them updates the pull request.
        return $this->queue($ticket, $inReview ? RunType::Implement : $ticket->stage->runType(), $user, ['reply' => $message]);
    }

    /**
     * Run the current phase again from scratch.
     */
    public function retry(Ticket $ticket, User $user): Run
    {
        $this->ensureIdle($ticket);

        if ($ticket->stage === TicketStage::Review) {
            return $this->publish($ticket, $user);
        }

        if (! $ticket->stage->isPhase()) {
            $this->fail('Only phases can be run again.');
        }

        $this->ensureProjectCanRun($ticket->project);

        return $this->queue($ticket, $ticket->stage->runType(), $user, []);
    }

    /**
     * Merge the latest base branch into the feature again, push, and open or refresh the pull request.
     */
    public function publish(Ticket $ticket, User $user): Run
    {
        $this->ensureIdle($ticket);

        if ($ticket->stage !== TicketStage::Review) {
            $this->fail('Pull requests are opened from Review.');
        }

        $this->ensureProjectCanRun($ticket->project);

        return $this->queue($ticket, RunType::Publish, $user, []);
    }

    public function cancel(Ticket $ticket): void
    {
        $ticket->runs()->whereIn('status', RunStatus::active())->get()->each(fn (Run $run) => $run->requestCancel());
    }

    /**
     * Update the ticket once its run has finished. Called by the run job.
     */
    public function afterRun(Run $run): void
    {
        $ticket = $run->ticket;

        if (! $ticket || $ticket->trashed() || $ticket->last_run_id !== $run->id) {
            return;
        }

        if ($run->status !== RunStatus::Succeeded) {
            $ticket->forceFill(['status' => TicketStatus::Failed])->save();

            return;
        }

        // In Review the card simply waits for the merge; there is no next phase to approve.
        $ticket->forceFill([
            'status' => $ticket->stage->isPhase() ? TicketStatus::AwaitingApproval : TicketStatus::Idle,
            'session_id' => $run->type === RunType::Publish ? $ticket->session_id : ($run->session_id ?? $ticket->session_id),
        ])->save();

        if (! $ticket->stage->isPhase()) {
            return;
        }

        $replied = ! empty($run->input['reply']);

        if ($ticket->approval_mode === ApprovalMode::Auto && ! $replied && $ticket->stage !== TicketStage::Clarify) {
            try {
                $this->approve($ticket->fresh() ?? $ticket, $run->triggeredBy);
            } catch (ValidationException) {
                // E.g. the project lost its API key; the card simply waits for a person.
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function queue(Ticket $ticket, RunType $type, ?User $user, array $input): Run
    {
        $project = $ticket->project;

        $run = DB::transaction(function () use ($ticket, $type, $user, $input, $project) {
            $run = $project->runs()->create([
                'team_id' => $project->team_id,
                'ticket_id' => $ticket->id,
                'type' => $type,
                'status' => RunStatus::Queued,
                'input' => $input,
                'model' => $ticket->model,
                'effort' => $ticket->effort,
                'base_branch' => $project->default_branch,
                'triggered_by' => $user?->id,
            ]);

            $ticket->forceFill(['status' => TicketStatus::Running, 'last_run_id' => $run->id])->save();

            return $run;
        });

        ExecuteRun::dispatch($run->id);

        return $run;
    }

    private function ensureIdle(Ticket $ticket): void
    {
        if ($ticket->status === TicketStatus::Running || $ticket->hasActiveRun()) {
            $this->fail("#{$ticket->number} is still running. Wait for it to finish or cancel it.");
        }
    }

    private function ensureProjectCanRun(Project $project): void
    {
        if (! $project->speckit_initialized) {
            $this->fail('Set up Spec Kit in this repository first.');
        }

        if (! $project->git_credential_id) {
            $this->fail('This project has no git token. Pick one in the project settings.');
        }

        if (! $project->ai_credential_id) {
            $this->fail('This project has no Anthropic API key. Pick one in the project settings.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['ticket' => $message]);
    }
}
