<?php

namespace App\Jobs;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Runner\RunCancelled;
use App\Runner\RunFailed;
use App\Runner\RunRecorder;
use App\Runs\Pipelines\Pipeline;
use App\Runs\Pipelines\SetupSpecKit;
use App\Runs\Pipelines\TicketPhase;
use App\Runs\Pipelines\UpdateConstitution;
use App\Tickets\TicketWorkflow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ExecuteRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public int $runId)
    {
        $this->timeout = (int) config('factory.runner.timeout') + 60;
        $this->onConnection('redis-runs')->onQueue('runs');
    }

    public static function pipelineFor(RunType $type): string
    {
        return match ($type) {
            RunType::SetupSpecKit => SetupSpecKit::class,
            RunType::Constitution => UpdateConstitution::class,
            RunType::Specify, RunType::Clarify, RunType::Plan, RunType::Tasks, RunType::Analyze, RunType::Implement => TicketPhase::class,
        };
    }

    public function handle(): void
    {
        $run = Run::query()->with(['project.gitCredential', 'project.aiCredential', 'ticket'])->find($this->runId);

        if (! $run) {
            return;
        }

        // Cancelled before a worker picked it up.
        if (in_array($run->status, [RunStatus::Cancelling, RunStatus::Cancelled], true)) {
            $this->finish($run, RunStatus::Cancelled);

            return;
        }

        if ($run->status !== RunStatus::Queued) {
            return;
        }

        $run->forceFill(['status' => RunStatus::Running, 'started_at' => now()])->save();
        $log = new RunRecorder($run);

        try {
            /** @var Pipeline $pipeline */
            $pipeline = app(self::pipelineFor($run->type));
            $pipeline->handle($run, $log);

            $this->finish($run, RunStatus::Succeeded);
            $log->stage('Done');
        } catch (RunCancelled) {
            $this->finish($run, RunStatus::Cancelled);
            $log->stage('Cancelled');
        } catch (RunFailed $exception) {
            $log->error($exception->getMessage());
            $this->finish($run, RunStatus::Failed, $exception->getMessage());
        } catch (Throwable $exception) {
            report($exception);
            $message = 'Unexpected error: '.$exception->getMessage();
            $log->error($message);
            $this->finish($run, RunStatus::Failed, $message);
        }
    }

    /**
     * Called by the queue when the job dies (e.g. it hit the worker's time limit).
     */
    public function failed(?Throwable $exception): void
    {
        $run = Run::find($this->runId);

        if ($run && $run->status->isActive()) {
            $this->finish($run, RunStatus::Failed, 'The run was stopped by the queue worker: '.($exception?->getMessage() ?? 'unknown reason'));
        }
    }

    private function finish(Run $run, RunStatus $status, ?string $error = null): void
    {
        $run->forceFill(['status' => $status, 'error' => $error, 'finished_at' => now()])->save();

        // Move the ticket on (or mark it failed). Auto mode may queue the next phase from here.
        app(TicketWorkflow::class)->afterRun($run);
    }
}
