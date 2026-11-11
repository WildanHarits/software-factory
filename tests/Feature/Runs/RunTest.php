<?php

namespace Tests\Feature\Runs;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\TeamRole;
use App\Jobs\ExecuteRun;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use App\Models\Team;
use App\Models\User;
use App\Runner\Agent\AgentResult;
use App\Runner\Agent\AgentRunner;
use App\Runner\RunnerHealth;
use App\Runner\RunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Fakes\FakeAgentRunner;
use Tests\TestCase;

class RunTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Project $project;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->workspace = storage_path('framework/testing/workspaces');
        config(['factory.runner.workspace_path' => $this->workspace]);

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create(['slug' => 'acme']);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);

        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'shop',
            'repo_full_name' => 'acme/shop',
            'repo_clone_url' => 'https://github.com/acme/shop.git',
            'git_credential_id' => Credential::factory()->create(['team_id' => $this->team->id, 'token' => 'ghp_gittoken'])->id,
            'ai_credential_id' => Credential::factory()->anthropic()->create(['team_id' => $this->team->id, 'token' => 'sk-ant-aikey'])->id,
        ]);

        $health = Mockery::mock(RunnerHealth::class);
        $health->shouldReceive('problems')->andReturn([]);
        $this->app->instance(RunnerHealth::class, $health);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    private function routeArgs(array $extra = []): array
    {
        return ['current_team' => 'acme', 'project' => 'shop', ...$extra];
    }

    /**
     * Fake git and specify. `git status --porcelain` reports changes so a commit happens.
     */
    private function fakeProcesses(int $specifyExitCode = 0): void
    {
        Process::fake(function (PendingProcess $process) use ($specifyExitCode) {
            $command = implode(' ', (array) $process->command);

            return match (true) {
                str_contains($command, ' init --here') => Process::result(
                    output: "│ Installing Spec Kit │\nDone\n",
                    errorOutput: $specifyExitCode ? 'template download failed' : '',
                    exitCode: $specifyExitCode,
                ),
                str_contains($command, 'status --porcelain') => Process::result(output: "?? .specify/\n"),
                str_contains($command, 'rev-parse HEAD') => Process::result(output: "abc123def4567890\n"),
                default => Process::result(output: ''),
            };
        });
    }

    private function fakePullRequest(): void
    {
        Http::fake(['api.github.com/repos/acme/shop/pulls' => Http::response(['number' => 12, 'html_url' => 'https://github.com/acme/shop/pull/12'], 201)]);
    }

    private function useFakeAgent(?AgentResult $result = null): FakeAgentRunner
    {
        $agent = new FakeAgentRunner($result);
        $this->app->instance(AgentRunner::class, $agent);

        return $agent;
    }

    private function queuedRun(RunType $type, array $input): Run
    {
        return Run::factory()->create([
            'project_id' => $this->project->id,
            'team_id' => $this->team->id,
            'type' => $type,
            'input' => $input,
            'model' => 'claude-opus-5-5',
            'effort' => 'high',
            'triggered_by' => $this->owner->id,
        ]);
    }

    public function test_starting_setup_queues_a_run_and_opens_its_page()
    {
        Queue::fake();

        $response = $this->actingAs($this->owner)->post(route('projects.runs.setup', $this->routeArgs()), [
            'principles' => 'Tests for everything.',
            'model' => 'claude-opus-5-5',
            'effort' => 'high',
        ]);

        $run = Run::firstOrFail();

        $response->assertRedirect(route('projects.runs.show', $this->routeArgs(['run' => $run->id])));
        $this->assertSame(RunStatus::Queued, $run->status);
        $this->assertSame(RunType::SetupSpecKit, $run->type);
        $this->assertSame(['principles' => 'Tests for everything.'], $run->input);
        $this->assertSame('main', $run->base_branch);

        Queue::assertPushedOn('runs', ExecuteRun::class, fn (ExecuteRun $job) => $job->runId === $run->id && $job->connection === 'redis-runs');
    }

    public function test_only_one_run_per_project_at_a_time()
    {
        Queue::fake();
        $this->queuedRun(RunType::SetupSpecKit, []);

        $this->actingAs($this->owner)
            ->post(route('projects.runs.setup', $this->routeArgs()), ['model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertSessionHasErrors('run');

        Queue::assertNothingPushed();
    }

    public function test_writing_the_constitution_during_setup_needs_an_ai_key()
    {
        Queue::fake();
        $this->project->update(['ai_credential_id' => null]);

        $this->actingAs($this->owner)
            ->post(route('projects.runs.setup', $this->routeArgs()), ['principles' => 'x', 'model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertSessionHasErrors('principles');

        // Without principles, setup only runs specify init, which needs no key.
        $this->actingAs($this->owner)
            ->post(route('projects.runs.setup', $this->routeArgs()), ['model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertSessionHasNoErrors();
    }

    public function test_setup_is_refused_when_spec_kit_is_already_there_and_constitution_needs_it()
    {
        Queue::fake();

        $this->actingAs($this->owner)
            ->post(route('projects.runs.constitution', $this->routeArgs()), ['instructions' => 'x', 'model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertSessionHasErrors('run');

        $this->project->update(['speckit_initialized' => true]);

        $this->actingAs($this->owner)
            ->post(route('projects.runs.setup', $this->routeArgs()), ['model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertSessionHasErrors('run');

        $this->actingAs($this->owner)
            ->post(route('projects.runs.constitution', $this->routeArgs()), ['model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertSessionHasErrors('instructions');

        Queue::assertNothingPushed();
    }

    public function test_members_can_watch_runs_but_not_start_or_cancel_them()
    {
        Queue::fake();
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $run = $this->queuedRun(RunType::SetupSpecKit, []);

        $this->actingAs($member)
            ->post(route('projects.runs.setup', $this->routeArgs()), ['model' => 'claude-opus-5-5', 'effort' => 'low'])
            ->assertForbidden();

        $this->actingAs($member)->post(route('projects.runs.cancel', $this->routeArgs(['run' => $run->id])))->assertForbidden();

        $this->actingAs($member)
            ->get(route('projects.runs.show', $this->routeArgs(['run' => $run->id])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('runs/show')->where('canCancel', false));
    }

    public function test_outsiders_cannot_see_runs_and_runs_are_scoped_to_their_project()
    {
        $run = $this->queuedRun(RunType::SetupSpecKit, []);
        $other = Project::factory()->create(['team_id' => $this->team->id, 'slug' => 'other']);

        $this->actingAs(User::factory()->create())
            ->getJson(route('projects.runs.events', $this->routeArgs(['run' => $run->id])))
            ->assertForbidden();

        $this->actingAs($this->owner)
            ->get(route('projects.runs.show', ['current_team' => 'acme', 'project' => $other->slug, 'run' => $run->id]))
            ->assertNotFound();
    }

    public function test_the_log_endpoint_returns_only_new_lines()
    {
        $run = $this->queuedRun(RunType::SetupSpecKit, []);
        foreach (['one', 'two', 'three'] as $seq => $body) {
            $run->events()->create(['seq' => $seq + 1, 'kind' => 'output', 'body' => $body, 'created_at' => now()]);
        }

        $this->actingAs($this->owner)
            ->getJson(route('projects.runs.events', $this->routeArgs(['run' => $run->id])).'?after=1')
            ->assertOk()
            ->assertJsonPath('run.status', 'queued')
            ->assertJsonCount(2, 'events')
            ->assertJsonPath('events.0.body', 'two');
    }

    public function test_the_log_endpoint_survives_broken_command_output()
    {
        $run = $this->queuedRun(RunType::SetupSpecKit, []);
        (new RunRecorder($run))->output("half a check mark: \xE2\x9C");

        $this->actingAs($this->owner)
            ->getJson(route('projects.runs.events', $this->routeArgs(['run' => $run->id])))
            ->assertOk()
            ->assertJsonCount(1, 'events');
    }

    public function test_cancelling_a_queued_run_stops_it_immediately_and_a_running_one_on_the_next_check()
    {
        $queued = $this->queuedRun(RunType::SetupSpecKit, []);
        $running = $this->queuedRun(RunType::Constitution, []);
        $running->forceFill(['status' => RunStatus::Running])->save();

        $this->actingAs($this->owner)->post(route('projects.runs.cancel', $this->routeArgs(['run' => $queued->id])));
        $this->actingAs($this->owner)->post(route('projects.runs.cancel', $this->routeArgs(['run' => $running->id])));

        $this->assertSame(RunStatus::Cancelled, $queued->fresh()->status);
        $this->assertSame(RunStatus::Cancelling, $running->fresh()->status);
        $this->assertTrue($running->fresh()->cancelRequested());

        // A worker picking up the cancelled run does nothing.
        Process::fake();
        (new ExecuteRun($queued->id))->handle();
        Process::assertNothingRan();
        $this->assertSame(RunStatus::Cancelled, $queued->fresh()->status);
    }

    public function test_setup_runs_specify_writes_the_constitution_and_opens_a_pull_request()
    {
        $this->fakeProcesses();
        $this->fakePullRequest();
        $agent = $this->useFakeAgent();
        $run = $this->queuedRun(RunType::SetupSpecKit, ['principles' => 'Ship small. Test everything.']);

        (new ExecuteRun($run->id))->handle();

        $run->refresh();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame("software-factory/setup-spec-kit-{$run->id}", $run->branch);
        $this->assertSame('abc123def4567890', $run->commit_sha);
        $this->assertSame(12, $run->pull_request_number);
        $this->assertSame('https://github.com/acme/shop/pull/12', $run->pull_request_url);
        $this->assertSame('session-123', $run->session_id);
        $this->assertSame(0.4321, (float) $run->cost_usd);
        $this->assertSame(1200, $run->input_tokens);
        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);

        // The agent got the constitution command, the run's model and effort, and the AI key.
        $this->assertCount(1, $agent->invocations);
        $this->assertSame('/speckit-constitution Ship small. Test everything.', $agent->invocations[0]->prompt);
        $this->assertSame('claude-opus-5-5', $agent->invocations[0]->model);
        $this->assertSame('high', $agent->invocations[0]->effort);
        $this->assertSame('sk-ant-aikey', $agent->invocations[0]->apiKey);
        $this->assertStringContainsString('/worktrees/run-'.$run->id, $agent->invocations[0]->workingDirectory);

        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'init --here --force --non-interactive --integration claude'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), "worktree add --force -B software-factory/setup-spec-kit-{$run->id}"));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'commit --no-verify -m Set up Spec Kit 1.4.0'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push --set-upstream origin'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'worktree remove --force'));

        // The git token never reaches a command line, and specify/the agent never get it.
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'ghp_gittoken'));
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), ' init --here')
            && ($p->environment['FACTORY_GIT_PASSWORD'] ?? null) !== null);

        Http::assertSent(fn (Request $request) => $request['head'] === "software-factory/setup-spec-kit-{$run->id}"
            && $request['base'] === 'main'
            && $request['title'] === 'Set up Spec Kit'
            && str_contains($request['body'], 'Ship small. Test everything.'));

        $kinds = $run->events()->pluck('kind')->all();
        $titles = $run->events()->whereNotNull('title')->pluck('title')->all();
        $this->assertContains('Installing Spec Kit 1.4.0', $titles);
        $this->assertContains('Writing the constitution', $titles);
        $this->assertContains('Pull request opened', $titles);
        $this->assertContains('assistant', $kinds);
        $this->assertNotContains('│ Installing Spec Kit │', $run->events()->pluck('body')->all());
    }

    public function test_setup_without_principles_skips_the_agent()
    {
        $this->fakeProcesses();
        $this->fakePullRequest();
        $agent = $this->useFakeAgent();
        $run = $this->queuedRun(RunType::SetupSpecKit, ['principles' => '']);

        (new ExecuteRun($run->id))->handle();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status);
        $this->assertCount(0, $agent->invocations);
        Http::assertSent(fn (Request $request) => str_contains($request['body'], 'still the template'));
    }

    public function test_a_failing_specify_init_fails_the_run_and_cleans_up()
    {
        $this->fakeProcesses(specifyExitCode: 1);
        $this->useFakeAgent();
        $run = $this->queuedRun(RunType::SetupSpecKit, ['principles' => 'x']);

        (new ExecuteRun($run->id))->handle();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('specify init failed', (string) $run->error);
        $this->assertStringContainsString('template download failed', (string) $run->error);
        $this->assertSame('error', $run->events()->reorder('seq', 'desc')->value('kind'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'worktree remove --force'));
        Http::assertNothingSent();
    }

    public function test_an_agent_error_fails_the_run_but_still_records_its_cost()
    {
        $this->fakeProcesses();
        $this->useFakeAgent(new AgentResult(successful: false, costUsd: 0.12, inputTokens: 50, error: 'The agent stopped (error max turns).'));
        $run = $this->queuedRun(RunType::SetupSpecKit, ['principles' => 'x']);

        (new ExecuteRun($run->id))->handle();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame('The agent stopped (error max turns).', $run->error);
        $this->assertSame(0.12, (float) $run->cost_usd);
    }

    public function test_cancelling_during_the_agent_marks_the_run_cancelled()
    {
        $this->fakeProcesses();
        $this->useFakeAgent(new AgentResult(successful: false, cancelled: true));
        $run = $this->queuedRun(RunType::SetupSpecKit, ['principles' => 'x']);

        (new ExecuteRun($run->id))->handle();

        $run->refresh();
        $this->assertSame(RunStatus::Cancelled, $run->status);
        $this->assertNull($run->error);
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push'));
    }

    public function test_nothing_to_commit_fails_with_a_clear_message()
    {
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'rev-parse HEAD') ? Process::result(output: 'abc') : Process::result(output: '');
        });
        $this->useFakeAgent();
        $run = $this->queuedRun(RunType::SetupSpecKit, []);

        (new ExecuteRun($run->id))->handle();

        $this->assertSame('Nothing changed, so there was nothing to commit.', $run->fresh()->error);
    }

    public function test_a_missing_base_branch_is_explained()
    {
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'rev-parse --verify') ? Process::result(exitCode: 1) : Process::result(output: '');
        });
        $this->useFakeAgent();
        $run = $this->queuedRun(RunType::SetupSpecKit, []);

        (new ExecuteRun($run->id))->handle();

        $this->assertStringContainsString("The branch \"main\" doesn't exist", (string) $run->fresh()->error);
    }

    public function test_the_pushed_branch_is_kept_when_opening_the_pull_request_fails()
    {
        $this->fakeProcesses();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'A pull request already exists']]], 422)]);
        $this->useFakeAgent();
        $run = $this->queuedRun(RunType::SetupSpecKit, []);

        (new ExecuteRun($run->id))->handle();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('was pushed, but opening the pull request failed', (string) $run->error);
        $this->assertStringContainsString('A pull request already exists', (string) $run->error);
        $this->assertSame('abc123def4567890', $run->commit_sha);
    }

    public function test_constitution_runs_need_spec_kit_in_the_checkout()
    {
        $this->fakeProcesses();
        $agent = $this->useFakeAgent();
        $run = $this->queuedRun(RunType::Constitution, ['instructions' => 'Add accessibility rules']);

        (new ExecuteRun($run->id))->handle();

        $this->assertStringContainsString('Spec Kit is not set up on main yet', (string) $run->fresh()->error);
        $this->assertCount(0, $agent->invocations);
    }

    public function test_constitution_runs_update_the_file_and_open_a_pull_request()
    {
        $this->fakeProcesses();
        $this->fakePullRequest();
        $agent = $this->useFakeAgent();
        $run = $this->queuedRun(RunType::Constitution, ['instructions' => 'Add accessibility rules']);

        // The fake `git worktree add` creates nothing, so put Spec Kit's constitution where the checkout will be.
        $worktree = "{$this->workspace}/team-{$this->team->id}/project-{$this->project->id}/worktrees/run-{$run->id}";
        File::ensureDirectoryExists("{$worktree}/.specify/memory");
        File::put("{$worktree}/.specify/memory/constitution.md", '# Constitution');
        File::ensureDirectoryExists("{$this->workspace}/team-{$this->team->id}/project-{$this->project->id}/repo/.git");

        (new ExecuteRun($run->id))->handle();

        $run->refresh();
        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame('/speckit-constitution Add accessibility rules', $agent->invocations[0]->prompt);
        $this->assertSame("software-factory/constitution-{$run->id}", $run->branch);
        Http::assertSent(fn (Request $request) => $request['title'] === 'Update constitution');
        // The existing clone is reused instead of cloning again.
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), ' clone '));
    }

    public function test_the_project_page_lists_runs_and_an_open_setup_pull_request()
    {
        $run = $this->queuedRun(RunType::SetupSpecKit, []);
        $run->forceFill(['status' => RunStatus::Succeeded, 'pull_request_number' => 3, 'pull_request_url' => 'https://github.com/acme/shop/pull/3'])->save();

        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->routeArgs()))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->has('runs', 1)
                ->where('setupPullRequest.pull_request_number', 3)
                ->where('speckitVersion', '1.4.0')
                ->has('runnerProblems', 0));
    }
}
