<?php

namespace Tests\Feature\Projects;

use App\Enums\CredentialProvider;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\TeamRole;
use App\Enums\TicketStage;
use App\Jobs\ExecuteRun;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Runner\Agent\AgentRunner;
use App\Runner\RunnerHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Fakes\FakeAgentRunner;
use Tests\TestCase;

/**
 * Projects that run on each member's own Claude subscription.
 */
class MemberSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private Team $team;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['factory.runner.workspace_path' => storage_path('framework/testing/workspaces')]);

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->team = Team::factory()->create(['slug' => 'acme']);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'shop',
            'repo_full_name' => 'acme/shop',
            'repo_clone_url' => 'https://github.com/acme/shop.git',
            'speckit_initialized' => true,
            'git_credential_id' => Credential::factory()->create(['team_id' => $this->team->id])->id,
            'ai_credential_id' => null,
            'ai_uses_member_subscription' => true,
        ]);

        $health = Mockery::mock(RunnerHealth::class);
        $health->shouldReceive('problems')->andReturn([]);
        $this->app->instance(RunnerHealth::class, $health);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/workspaces'));

        parent::tearDown();
    }

    private function settings(array $overrides = []): array
    {
        return [
            'name' => 'Shop',
            'git_credential_id' => $this->project->git_credential_id,
            'ai_credential_id' => 'subscription',
            'default_model' => 'claude-sonnet-5-5',
            'default_effort' => 'medium',
            'approval_mode' => 'manual',
            'merge_policy' => 'manual',
            ...$overrides,
        ];
    }

    public function test_a_project_can_switch_to_members_own_subscriptions_and_back()
    {
        $this->project->update(['ai_uses_member_subscription' => false]);
        $route = route('projects.update', ['current_team' => 'acme', 'project' => 'shop']);

        $this->actingAs($this->owner)->patch($route, $this->settings())->assertSessionHasNoErrors();
        $this->project->refresh();
        $this->assertTrue($this->project->ai_uses_member_subscription);
        $this->assertNull($this->project->ai_credential_id);

        $key = Credential::factory()->meta()->create(['team_id' => $this->team->id]);
        $this->actingAs($this->owner)->patch($route, $this->settings(['ai_credential_id' => $key->id]))->assertSessionHasNoErrors();
        $this->project->refresh();
        $this->assertFalse($this->project->ai_uses_member_subscription);
        $this->assertSame($key->id, $this->project->ai_credential_id);
    }

    public function test_one_persons_subscription_cannot_become_the_projects_shared_key()
    {
        $subscription = Credential::factory()->personal($this->owner)->subscription()->create();

        $this->actingAs($this->owner)
            ->patch(route('projects.update', ['current_team' => 'acme', 'project' => 'shop']), $this->settings(['ai_credential_id' => $subscription->id]))
            ->assertSessionHasErrors('ai_credential_id');
    }

    public function test_the_settings_page_hides_subscriptions_from_the_key_list()
    {
        Credential::factory()->personal($this->owner)->subscription()->create();
        $meta = Credential::factory()->meta()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)
            ->get(route('projects.edit', ['current_team' => 'acme', 'project' => 'shop']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('aiCredentials', fn ($credentials) => collect($credentials)->pluck('id')->all() === [$meta->id])
                ->where('hasSubscription', true)
                ->where('project.ai_uses_member_subscription', true));
    }

    public function test_a_member_without_a_subscription_cannot_start_a_phase()
    {
        Queue::fake();
        $ticket = Ticket::factory()->create(['project_id' => $this->project->id, 'team_id' => $this->team->id]);

        // The owner's subscription doesn't help the member.
        Credential::factory()->personal($this->owner)->subscription()->create();

        $this->actingAs($this->member)
            ->post(route('projects.tickets.move', ['current_team' => 'acme', 'project' => 'shop', 'ticket' => $ticket->number]), ['stage' => 'specify'])
            ->assertSessionHasErrors('ticket');

        $this->assertSame(TicketStage::Backlog, $ticket->fresh()->stage);
        Queue::assertNothingPushed();

        Credential::factory()->personal($this->member)->subscription()->create();

        $this->actingAs($this->member)
            ->post(route('projects.tickets.move', ['current_team' => 'acme', 'project' => 'shop', 'ticket' => $ticket->number]), ['stage' => 'specify'])
            ->assertSessionHasNoErrors();

        Queue::assertPushed(ExecuteRun::class);
    }

    public function test_each_run_uses_the_subscription_of_whoever_started_it()
    {
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return match (true) {
                str_contains($command, 'status --porcelain') => Process::result(output: "?? .specify/\n"),
                str_contains($command, 'rev-parse HEAD') => Process::result(output: "abc123\n"),
                default => Process::result(output: ''),
            };
        });
        Http::fake(['api.github.com/repos/acme/shop/pulls' => Http::response(['number' => 3, 'html_url' => 'https://github.com/acme/shop/pull/3'], 201)]);
        $agent = new FakeAgentRunner;
        $this->app->instance(AgentRunner::class, $agent);

        Credential::factory()->personal($this->owner)->subscription()->create(['token' => 'sk-ant-oat01-owner']);
        Credential::factory()->personal($this->member)->subscription()->create(['token' => 'sk-ant-oat01-member']);
        $this->project->update(['speckit_initialized' => false]);

        $run = Run::factory()->create([
            'project_id' => $this->project->id,
            'team_id' => $this->team->id,
            'type' => RunType::SetupSpecKit,
            'input' => ['principles' => 'Keep it small.'],
            'model' => 'claude-opus-5-5',
            'effort' => 'high',
            'triggered_by' => $this->member->id,
        ]);

        (new ExecuteRun($run->id))->handle();

        $this->assertSame(RunStatus::Succeeded, $run->fresh()->status, (string) $run->fresh()->error);
        $this->assertSame('sk-ant-oat01-member', $agent->invocations[0]->apiKey);
        $this->assertSame(CredentialProvider::ClaudeSubscription, $agent->invocations[0]->provider);
    }

    public function test_a_run_fails_clearly_when_its_starter_has_no_subscription()
    {
        $agent = new FakeAgentRunner;
        $this->app->instance(AgentRunner::class, $agent);
        $this->project->update(['speckit_initialized' => false]);

        $run = Run::factory()->create([
            'project_id' => $this->project->id,
            'team_id' => $this->team->id,
            'type' => RunType::SetupSpecKit,
            'input' => ['principles' => 'Keep it small.'],
            'triggered_by' => $this->member->id,
        ]);

        (new ExecuteRun($run->id))->handle();

        $run->refresh();
        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertStringContainsString('own Claude subscription', (string) $run->error);
        $this->assertCount(0, $agent->invocations);
    }
}
