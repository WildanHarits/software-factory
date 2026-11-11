<?php

namespace Tests\Feature\Tickets;

use App\Enums\RunStatus;
use App\Enums\TeamRole;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Jobs\ExecuteRun;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Runner\Agent\AgentRunner;
use App\Runs\Pipelines\Pipeline;
use App\Tickets\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeAgentRunner;
use Tests\TestCase;

/**
 * Runs ticket phases through the real pipeline with git and the agent faked.
 */
class TicketPhaseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private string $workspace;

    private FakeAgentRunner $agent;

    /** Remote branches the fake git knows about. */
    private array $remoteBranches = ['main'];

    private bool $hasChanges = true;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->workspace = storage_path('framework/testing/ticket-workspaces');
        config(['factory.runner.workspace_path' => $this->workspace]);

        $this->user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);

        $this->project = Project::factory()->create([
            'team_id' => $team->id,
            'speckit_initialized' => true,
            'git_credential_id' => Credential::factory()->create(['team_id' => $team->id])->id,
            'ai_credential_id' => Credential::factory()->anthropic()->create(['team_id' => $team->id, 'token' => 'sk-ant-key'])->id,
        ]);

        $this->agent = new FakeAgentRunner;
        $this->app->instance(AgentRunner::class, $this->agent);

        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            if (str_contains($command, 'rev-parse --verify --quiet refs/remotes/origin/')) {
                $branch = substr($command, strrpos($command, 'refs/remotes/origin/') + strlen('refs/remotes/origin/'));

                return in_array($branch, $this->remoteBranches, true) ? Process::result() : Process::result(exitCode: 1);
            }

            return match (true) {
                str_contains($command, 'ls-tree --name-only') => Process::result(output: "specs/003-old-thing\nspecs/010-search\nspecs/README.md\n"),
                str_contains($command, 'status --porcelain') => Process::result(output: $this->hasChanges ? "M specs/x/spec.md\n" : ''),
                str_contains($command, 'rev-parse HEAD') => Process::result(output: "feedbeef00\n"),
                default => Process::result(),
            };
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create([
            'project_id' => $this->project->id,
            'team_id' => $this->project->team_id,
            'title' => 'Photo albums for families',
            'description' => 'Let people group photos into albums.',
            ...$attributes,
        ]);
    }

    private function worktree(Ticket $ticket): string
    {
        return "{$this->workspace}/team-{$this->project->team_id}/project-{$this->project->id}/worktrees/ticket-{$ticket->id}";
    }

    private function runPhase(Ticket $ticket, TicketStage $stage, array $input = []): Run
    {
        $run = $this->project->runs()->create([
            'team_id' => $this->project->team_id,
            'ticket_id' => $ticket->id,
            'type' => $stage->runType(),
            'status' => RunStatus::Queued,
            'input' => $input,
            'model' => $ticket->model,
            'effort' => $ticket->effort,
            'base_branch' => 'main',
            'triggered_by' => $this->user->id,
        ]);

        $ticket->forceFill(['stage' => $stage, 'status' => TicketStatus::Running, 'last_run_id' => $run->id])->save();
        (new ExecuteRun($run->id))->handle();

        return $run->fresh();
    }

    public function test_specify_picks_a_feature_number_branch_and_folder()
    {
        // Another ticket already took feature 11; the repo has up to 010.
        $this->ticket(['feature_number' => 11, 'branch' => '011-other', 'feature_dir' => 'specs/011-other']);
        $ticket = $this->ticket();

        // What the agent would write: the spec in the folder we asked for.
        File::ensureDirectoryExists($this->worktree($ticket).'/specs/012-photo-albums-for-families/checklists');
        File::put($this->worktree($ticket).'/specs/012-photo-albums-for-families/spec.md', '# Photo albums');
        File::put($this->worktree($ticket).'/specs/012-photo-albums-for-families/checklists/requirements.md', '- [x] Clear');
        File::put($this->worktree($ticket).'/specs/012-photo-albums-for-families/notes.bin', 'binary');

        $run = $this->runPhase($ticket, TicketStage::Specify, ['notes' => 'Mobile first']);
        $ticket->refresh();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(12, $ticket->feature_number);
        $this->assertSame('012-photo-albums-for-families', $ticket->branch);
        $this->assertSame('specs/012-photo-albums-for-families', $ticket->feature_dir);
        $this->assertSame('012-photo-albums-for-families', $run->branch);
        $this->assertSame('feedbeef00', $run->commit_sha);

        // The agent ran the specify command with the title, description and notes, in the ticket's folder.
        $invocation = $this->agent->invocations[0];
        $this->assertSame("/speckit-specify Photo albums for families\n\nLet people group photos into albums.\n\nMobile first", $invocation->prompt);
        $this->assertSame('specs/012-photo-albums-for-families', $invocation->environment['SPECIFY_FEATURE_DIRECTORY']);
        $this->assertSame('012-photo-albums-for-families', $invocation->environment['SPECIFY_FEATURE']);
        $this->assertSame(Pipeline::UNATTENDED_PROMPT, $invocation->appendSystemPrompt);
        $this->assertNull($invocation->resumeSessionId);
        $this->assertStringEndsWith("/worktrees/ticket-{$ticket->id}", $invocation->workingDirectory);

        // A new branch from main, committed and pushed.
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'worktree add --force -B 012-photo-albums-for-families')
            && str_contains(implode(' ', (array) $p->command), 'origin/main'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), "commit --no-verify -m Specify: Photo albums for families (#{$ticket->number})"));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push --set-upstream origin 012-photo-albums-for-families:012-photo-albums-for-families'));

        // Markdown documents were saved; other files were not.
        $this->assertEqualsCanonicalizing(
            ['specs/012-photo-albums-for-families/spec.md', 'specs/012-photo-albums-for-families/checklists/requirements.md'],
            $ticket->documents()->pluck('path')->all(),
        );

        // The ticket waits for review, remembering the session for replies.
        $this->assertSame(TicketStatus::AwaitingApproval, $ticket->status);
        $this->assertSame('session-123', $ticket->session_id);
        $this->assertSame('Constitution written.', $run->result_text);
    }

    public function test_later_phases_continue_the_existing_branch()
    {
        $ticket = $this->ticket(['feature_number' => 12, 'branch' => '012-albums', 'feature_dir' => 'specs/012-albums', 'session_id' => 'old']);
        $this->remoteBranches[] = '012-albums';

        $run = $this->runPhase($ticket, TicketStage::Plan, ['notes' => 'Laravel + React']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame('/speckit-plan Laravel + React', $this->agent->invocations[0]->prompt);
        $this->assertNull($this->agent->invocations[0]->resumeSessionId);
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'worktree add --force -B 012-albums')
            && str_contains(implode(' ', (array) $p->command), 'origin/012-albums'));
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'ls-tree'));
    }

    public function test_a_reply_resumes_the_agent_session()
    {
        $ticket = $this->ticket(['feature_number' => 12, 'branch' => '012-albums', 'feature_dir' => 'specs/012-albums', 'session_id' => 'clarify-session']);
        $this->remoteBranches[] = '012-albums';

        $run = $this->runPhase($ticket, TicketStage::Clarify, ['reply' => '1. Yes 2. Fifty']);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame('1. Yes 2. Fifty', $this->agent->invocations[0]->prompt);
        $this->assertSame('clarify-session', $this->agent->invocations[0]->resumeSessionId);
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'commit --no-verify -m Clarify (revised): '));
    }

    public function test_analyze_without_changes_succeeds_without_a_commit()
    {
        $this->hasChanges = false;
        $ticket = $this->ticket(['feature_number' => 12, 'branch' => '012-albums', 'feature_dir' => 'specs/012-albums']);
        $this->remoteBranches[] = '012-albums';

        $run = $this->runPhase($ticket, TicketStage::Analyze);

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertNull($run->commit_sha);
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push'));
        $this->assertSame('/speckit-analyze', $this->agent->invocations[0]->prompt);
    }

    public function test_phases_after_specify_need_the_branch()
    {
        $ticket = $this->ticket();

        $run = $this->runPhase($ticket, TicketStage::Plan);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Run Specify first', (string) $run->error);
        $this->assertSame(TicketStatus::Failed, $ticket->fresh()->status);
        $this->assertCount(0, $this->agent->invocations);
    }

    public function test_a_deleted_remote_branch_is_reported()
    {
        $ticket = $this->ticket(['feature_number' => 12, 'branch' => '012-gone', 'feature_dir' => 'specs/012-gone']);

        $run = $this->runPhase($ticket, TicketStage::Tasks);

        $this->assertStringContainsString('The branch 012-gone is missing', (string) $run->error);
    }

    public function test_the_feature_folder_follows_the_agent_if_it_used_another_name()
    {
        $ticket = $this->ticket();
        File::ensureDirectoryExists($this->worktree($ticket).'/specs/011-albums');
        File::put($this->worktree($ticket).'/specs/011-albums/spec.md', '# Albums');

        // Feature number 11 is chosen (repo has up to 010), but the agent named the folder differently.
        $this->runPhase($ticket, TicketStage::Specify);

        $this->assertSame('specs/011-albums', $ticket->fresh()->feature_dir);
        $this->assertSame(['specs/011-albums/spec.md'], $ticket->fresh()->documents()->pluck('path')->all());
    }

    public function test_starting_from_the_board_runs_the_whole_chain_in_auto_mode()
    {
        $ticket = $this->ticket(['approval_mode' => 'auto', 'skip_clarify' => true]);
        $workflow = app(TicketWorkflow::class);

        // Specify, executed by hand here because the queue is faked.
        $workflow->moveTo($ticket, TicketStage::Specify, $this->user);
        (new ExecuteRun($ticket->fresh()->last_run_id))->handle();

        // Auto mode skipped Clarify and queued Plan.
        $ticket->refresh();
        $this->assertSame(TicketStage::Plan, $ticket->stage);
        $this->assertSame(TicketStatus::Running, $ticket->status);
        $this->assertSame('plan', $ticket->lastRun->type->value);
        Queue::assertPushed(ExecuteRun::class, 2);
    }
}
