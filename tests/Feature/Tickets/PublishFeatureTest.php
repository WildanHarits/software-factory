<?php

namespace Tests\Feature\Tickets;

use App\Enums\MergePolicy;
use App\Enums\PullRequestState;
use App\Enums\RunStatus;
use App\Enums\RunType;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeAgentRunner;
use Tests\TestCase;

/**
 * The publish run: merge the base branch, push, open the pull request, apply the merge policy.
 */
class PublishFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private Ticket $ticket;

    private string $workspace;

    private FakeAgentRunner $agent;

    /** What the fake `git merge` does: 'clean' or 'conflict'. */
    private string $merge = 'clean';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();

        $this->workspace = storage_path('framework/testing/publish-workspaces');
        config(['factory.runner.workspace_path' => $this->workspace]);

        $this->user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($this->user, ['role' => TeamRole::Owner->value]);

        $this->project = Project::factory()->create([
            'team_id' => $team->id,
            'repo_full_name' => 'acme/shop',
            'repo_web_url' => 'https://github.com/acme/shop',
            'speckit_initialized' => true,
            'merge_policy' => MergePolicy::Manual,
            'git_credential_id' => Credential::factory()->create(['team_id' => $team->id])->id,
            'ai_credential_id' => Credential::factory()->anthropic()->create(['team_id' => $team->id])->id,
        ]);

        $this->ticket = Ticket::factory()->awaiting(TicketStage::Review)->create([
            'project_id' => $this->project->id,
            'team_id' => $team->id,
            'title' => 'Albums',
            'description' => 'Group photos into albums.',
            'status' => TicketStatus::Idle,
        ]);
        $this->ticket->documents()->create(['path' => 'specs/001-albums/spec.md', 'content' => '# Spec']);
        $this->ticket->documents()->create(['path' => 'specs/001-albums/tasks.md', 'content' => "- [x] T001 one\n- [x] T002 two\n- [ ] T003 three\n"]);

        $this->agent = new FakeAgentRunner;
        $this->app->instance(AgentRunner::class, $this->agent);

        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return match (true) {
                str_contains($command, ' merge --no-edit --no-verify origin/main') => $this->merge === 'clean'
                    ? Process::result(output: 'Already up to date.')
                    : Process::result(output: 'CONFLICT (content): Merge conflict in app/Http/routes.php', exitCode: 1),
                str_contains($command, 'diff --name-only --diff-filter=U') => Process::result(output: $this->merge === 'clean' ? '' : "routes/web.php\n"),
                str_contains($command, 'rev-parse HEAD') => Process::result(output: "c0ffee1234\n"),
                default => Process::result(),
            };
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    private function worktree(): string
    {
        return "{$this->workspace}/team-{$this->project->team_id}/project-{$this->project->id}/worktrees/ticket-{$this->ticket->id}";
    }

    private function publish(): Run
    {
        $run = $this->project->runs()->create([
            'team_id' => $this->project->team_id,
            'ticket_id' => $this->ticket->id,
            'type' => RunType::Publish,
            'status' => RunStatus::Queued,
            'base_branch' => 'main',
            'triggered_by' => $this->user->id,
        ]);

        $this->ticket->forceFill(['status' => TicketStatus::Running, 'last_run_id' => $run->id])->save();
        (new ExecuteRun($run->id))->handle();

        return $run->fresh();
    }

    private function fakeCreatedPullRequest(array $extra = []): void
    {
        Http::fake(array_merge([
            'api.github.com/repos/acme/shop/pulls' => Http::response(['number' => 42, 'html_url' => 'https://github.com/acme/shop/pull/42', 'state' => 'open', 'node_id' => 'PR_node42'], 201),
        ], $extra));
    }

    public function test_it_merges_main_pushes_and_opens_the_pull_request()
    {
        $this->fakeCreatedPullRequest();

        $run = $this->publish();
        $this->ticket->refresh();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(42, $this->ticket->pull_request_number);
        $this->assertSame('https://github.com/acme/shop/pull/42', $this->ticket->pull_request_url);
        $this->assertSame(PullRequestState::Open, $this->ticket->pull_request_state);
        $this->assertSame(TicketStage::Review, $this->ticket->stage);
        $this->assertSame(TicketStatus::Idle, $this->ticket->status);
        $this->assertSame(42, $run->pull_request_number);

        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'merge --no-edit --no-verify origin/main'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push --set-upstream origin 001-albums:001-albums'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/shop/pulls'
            && $request['head'] === '001-albums'
            && $request['base'] === 'main'
            && $request['title'] === "Albums (#{$this->ticket->number})"
            && str_contains($request['body'], 'Group photos into albums.')
            && str_contains($request['body'], '**Tasks:** 2 of 3 done')
            && str_contains($request['body'], '[spec.md](https://github.com/acme/shop/blob/001-albums/specs/001-albums/spec.md)'));

        // Manual merge policy: nothing else is called.
        Http::assertSentCount(1);
        $this->assertCount(0, $this->agent->invocations);
    }

    public function test_an_open_pull_request_is_reused()
    {
        $this->ticket->forceFill(['pull_request_number' => 7, 'pull_request_url' => 'https://github.com/acme/shop/pull/7', 'pull_request_state' => PullRequestState::Open])->save();
        Http::fake(['api.github.com/repos/acme/shop/pulls/7' => Http::response(['number' => 7, 'html_url' => 'https://github.com/acme/shop/pull/7', 'state' => 'open'])]);

        $run = $this->publish();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(7, $run->pull_request_number);
        Http::assertNotSent(fn (Request $request) => $request->method() === 'POST');
        $this->assertContains('Pull request #7 updated', $run->events()->pluck('title')->all());
    }

    public function test_a_closed_pull_request_gets_a_new_one()
    {
        $this->ticket->forceFill(['pull_request_number' => 7, 'pull_request_url' => 'x', 'pull_request_state' => PullRequestState::Open])->save();
        $this->fakeCreatedPullRequest(['api.github.com/repos/acme/shop/pulls/7' => Http::response(['number' => 7, 'html_url' => 'x', 'state' => 'closed', 'merged' => false])]);

        $this->publish();

        $this->assertSame(42, $this->ticket->fresh()->pull_request_number);
    }

    public function test_the_agent_resolves_merge_conflicts()
    {
        $this->merge = 'conflict';
        $this->fakeCreatedPullRequest();
        // What the agent leaves behind: the file without conflict markers.
        File::ensureDirectoryExists($this->worktree().'/routes');
        File::put($this->worktree().'/routes/web.php', "<?php\n// both sides kept\n");

        $run = $this->publish();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertCount(1, $this->agent->invocations);
        $this->assertStringContainsString('Git stopped on conflicts in:', $this->agent->invocations[0]->prompt);
        $this->assertStringContainsString('- routes/web.php', $this->agent->invocations[0]->prompt);
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'commit --no-edit --no-verify'));
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push --set-upstream'));
        $this->assertGreaterThan(0, (float) $run->cost_usd);
    }

    public function test_leftover_conflict_markers_undo_the_merge_and_fail()
    {
        $this->merge = 'conflict';
        Http::fake();
        File::ensureDirectoryExists($this->worktree().'/routes');
        File::put($this->worktree().'/routes/web.php', "<<<<<<< HEAD\nours\n=======\ntheirs\n>>>>>>> origin/main\n");

        $run = $this->publish();

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('Conflicts are still in routes/web.php', (string) $run->error);
        Process::assertRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'merge --abort'));
        Process::assertNotRan(fn (PendingProcess $p) => str_contains(implode(' ', (array) $p->command), 'push'));
        Http::assertNothingSent();
        $this->assertSame(TicketStatus::Failed, $this->ticket->fresh()->status);
    }

    public function test_auto_merge_is_turned_on_when_checks_must_pass()
    {
        $this->project->update(['merge_policy' => MergePolicy::WhenChecksPass]);
        $this->fakeCreatedPullRequest(['api.github.com/graphql' => Http::response(['data' => ['enablePullRequestAutoMerge' => ['pullRequest' => ['number' => 42]]]])]);

        $run = $this->publish();

        $this->assertSame(RunStatus::Succeeded, $run->status);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/graphql'
            && str_contains($request['query'], 'enablePullRequestAutoMerge')
            && $request['variables'] === ['id' => 'PR_node42', 'method' => 'SQUASH']);
        $this->assertContains('Auto-merge is on: it merges once the required checks pass', $run->events()->pluck('title')->all());
    }

    public function test_an_auto_merge_refusal_is_reported_but_the_pull_request_stays_open()
    {
        $this->project->update(['merge_policy' => MergePolicy::WhenChecksPass]);
        $this->fakeCreatedPullRequest(['api.github.com/graphql' => Http::response(['errors' => [['message' => 'Auto merge is not allowed for this repository']]])]);

        $run = $this->publish();

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame('Auto-merge could not be turned on', $run->events()->where('kind', 'error')->value('title'));
        $this->assertSame(PullRequestState::Open, $this->ticket->fresh()->pull_request_state);
    }

    public function test_immediate_merge_moves_the_card_to_done()
    {
        $this->project->update(['merge_policy' => MergePolicy::Immediate]);
        $this->fakeCreatedPullRequest(['api.github.com/repos/acme/shop/pulls/42/merge' => Http::response(['merged' => true])]);

        $run = $this->publish();
        $this->ticket->refresh();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(PullRequestState::Merged, $this->ticket->pull_request_state);
        $this->assertSame(TicketStage::Done, $this->ticket->stage);
        $this->assertNotNull($this->ticket->merged_at);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['merge_method'] === 'squash');
    }

    public function test_gitlab_merge_requests_use_merge_when_pipeline_succeeds()
    {
        $gitlab = Credential::factory()->gitlab()->create(['team_id' => $this->project->team_id]);
        $this->project->update(['provider' => 'gitlab', 'git_credential_id' => $gitlab->id, 'merge_policy' => MergePolicy::WhenChecksPass, 'repo_web_url' => 'https://gitlab.com/acme/shop']);
        Http::fake([
            'gitlab.com/api/v4/projects/acme%2Fshop/merge_requests' => Http::response(['iid' => 5, 'web_url' => 'https://gitlab.com/acme/shop/-/merge_requests/5', 'state' => 'opened'], 201),
            'gitlab.com/api/v4/projects/acme%2Fshop/merge_requests/5/merge' => Http::response(['iid' => 5, 'state' => 'opened']),
        ]);

        $run = $this->publish();

        $this->assertSame(RunStatus::Succeeded, $run->status, (string) $run->error);
        $this->assertSame(5, $this->ticket->fresh()->pull_request_number);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/merge_requests')
            && $request['source_branch'] === '001-albums'
            && $request['remove_source_branch'] === true
            && str_contains($request['description'], 'https://gitlab.com/acme/shop/-/blob/001-albums/specs/001-albums/spec.md'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/merge') && $request['merge_when_pipeline_succeeds'] === true);
    }

    public function test_a_missing_branch_fails_clearly()
    {
        Process::fake(fn (PendingProcess $process) => str_contains(implode(' ', (array) $process->command), 'rev-parse --verify')
            ? Process::result(exitCode: 1)
            : Process::result());

        $run = $this->publish();

        $this->assertSame('The feature branch is missing. Run Implement first.', $run->error);
    }
}
