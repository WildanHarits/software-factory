<?php

namespace App\Console\Commands;

use App\Enums\RunStatus;
use App\Models\Run;
use App\Runner\RunRecorder;
use App\Tickets\TicketWorkflow;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes runs that can't finish any more: the worker died mid-run (crash, server restart,
 * laptop asleep past the time limit), or nothing ever picked the run up.
 * Without this their cards would show "Running" forever.
 */
#[Signature('factory:recover-runs {--queued-hours=12 : Fail queued runs older than this}')]
#[Description('Mark runs that stopped responding as failed so their cards can be retried')]
class RecoverRuns extends Command
{
    public function handle(TicketWorkflow $workflow): int
    {
        // A run may legitimately take the full time limit; allow 15 minutes on top.
        $staleBefore = now()->subSeconds((int) config('factory.runner.timeout') + 900);
        $recovered = 0;

        Run::query()
            ->whereIn('status', [RunStatus::Running, RunStatus::Cancelling])
            ->where(fn ($query) => $query
                ->where('started_at', '<', $staleBefore)
                ->orWhere(fn ($query) => $query->whereNull('started_at')->where('updated_at', '<', $staleBefore)))
            ->each(function (Run $run) use ($workflow, &$recovered) {
                $cancelling = $run->status === RunStatus::Cancelling;
                $message = $cancelling ? null : 'The run stopped responding (the worker probably restarted or crashed). Try it again.';

                $this->close($run, $cancelling ? RunStatus::Cancelled : RunStatus::Failed, $message, $workflow);
                $recovered++;
            });

        Run::query()
            ->where('status', RunStatus::Queued)
            ->where('created_at', '<', now()->subHours(max(1, (int) $this->option('queued-hours'))))
            ->each(function (Run $run) use ($workflow, &$recovered) {
                $this->close($run, RunStatus::Failed, 'No worker picked this run up. Check that Horizon is running, then try again.', $workflow);
                $recovered++;
            });

        $this->components->info("Recovered {$recovered} run(s).");

        return self::SUCCESS;
    }

    private function close(Run $run, RunStatus $status, ?string $message, TicketWorkflow $workflow): void
    {
        $run->forceFill(['status' => $status, 'error' => $message, 'finished_at' => now()])->save();

        if ($message) {
            (new RunRecorder($run))->error($message);
        }

        $workflow->afterRun($run);
    }
}
