<?php

namespace Tests\Feature\Tickets;

use App\Enums\PullRequestState;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\TeamRole;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Tickets\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Webhooks, the background sync, webhook registration, and working on a card in Review.
 */
class PullRequestEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Project $project;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create(['slug' => 'acme']);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);

        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'shop',
            'repo_full_name' => 'acme/shop',
            'speckit_initialized' => true,
            'git_credential_id' => Credential::factory()->create(['team_id' => $this->team->id])->id,
            'ai_credential_id' => Credential::factory()->anthropic()->create(['team_id' => $this->team->id])->id,
        ]);
        $this->project->forceFill(['webhook_secret' => 'shh-secret'])->save();

        $this->ticket = Ticket::factory()->awaiting(TicketStage::Review)->create([
            'project_id' => $this->project->id,
            'team_id' => $this->team->id,
            'title' => 'Albums',
            'status' => TicketStatus::Idle,
        ]);
        $this->ticket->forceFill(['pull_request_number' => 42, 'pull_request_url' => 'https://github.com/acme/shop/pull/42', 'pull_request_state' => PullRequestState::Open])->save();
    }

    private function github(array $payload, string $event = 'pull_request', ?string $secret = 'shh-secret')
    {
        $body = json_encode($payload);

        return $this->call('POST', "/webhooks/github/{$this->project->id}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, (string) $secret),
        ], $body);
    }

    private function args(array $extra = []): array
    {
        return ['current_team' => 'acme', 'project' => 'shop', ...$extra];
    }

    public function test_a_merged_pull_request_moves_the_card_to_done()
    {
        $this->github(['action' => 'closed', 'pull_request' => ['number' => 42, 'merged' => true, 'state' => 'closed', 'head' => ['ref' => '001-albums']]])
            ->assertOk()
            ->assertJson(['ok' => true, 'state' => 'merged']);

        $this->ticket->refresh();
        $this->assertSame(TicketStage::Done, $this->ticket->stage);
        $this->assertSame(PullRequestState::Merged, $this->ticket->pull_request_state);
        $this->assertNotNull($this->ticket->merged_at);
        $this->assertNotNull($this->project->fresh()->webhook_last_received_at);
    }

    public function test_a_pull_request_closed_without_merging_keeps_the_card_in_review()
    {
        $this->github(['action' => 'closed', 'pull_request' => ['number' => 42, 'merged' => false, 'state' => 'closed', 'head' => ['ref' => 'x']]])->assertOk();

        $this->assertSame(TicketStage::Review, $this->ticket->fresh()->stage);
        $this->assertSame(PullRequestState::Closed, $this->ticket->fresh()->pull_request_state);
    }

    public function test_tickets_are_matched_by_branch_too()
    {
        $this->ticket->forceFill(['pull_request_number' => null])->save();

        $this->github(['action' => 'closed', 'pull_request' => ['number' => 99, 'merged' => true, 'state' => 'closed', 'head' => ['ref' => '001-albums']]])->assertOk();

        $this->assertSame(TicketStage::Done, $this->ticket->fresh()->stage);
    }

    public function test_bad_signatures_are_rejected()
    {
        $this->github(['action' => 'closed', 'pull_request' => ['number' => 42, 'merged' => true]], secret: 'wrong')->assertForbidden();

        $this->assertSame(TicketStage::Review, $this->ticket->fresh()->stage);
    }

    public function test_ping_and_other_events_are_acknowledged()
    {
        $this->github(['zen' => 'hi'], 'ping')->assertOk()->assertJson(['message' => 'pong']);
        $this->github(['ref' => 'main'], 'push')->assertStatus(202);
        $this->github(['action' => 'opened', 'pull_request' => ['number' => 1000, 'state' => 'open', 'head' => ['ref' => 'other']]])->assertStatus(202);
    }

    public function test_unknown_projects_and_wrong_providers_are_not_found()
    {
        $this->call('POST', '/webhooks/github/99999', [], [], [], ['HTTP_X_GITHUB_EVENT' => 'ping'], '{}')->assertNotFound();
        $this->call('POST', "/webhooks/gitlab/{$this->project->id}", [], [], [], ['HTTP_X_GITLAB_TOKEN' => 'shh-secret'], '{}')->assertNotFound();
    }

    public function test_gitlab_merge_request_hooks_are_checked_by_token()
    {
        $this->project->update(['provider' => 'gitlab']);
        $payload = ['object_kind' => 'merge_request', 'object_attributes' => ['iid' => 42, 'state' => 'merged', 'action' => 'merge', 'source_branch' => '001-albums']];

        $this->postJson("/webhooks/gitlab/{$this->project->id}", $payload, ['X-Gitlab-Event' => 'Merge Request Hook', 'X-Gitlab-Token' => 'nope'])->assertForbidden();
        $this->assertSame(TicketStage::Review, $this->ticket->fresh()->stage);

        $this->postJson("/webhooks/gitlab/{$this->project->id}", $payload, ['X-Gitlab-Event' => 'Merge Request Hook', 'X-Gitlab-Token' => 'shh-secret'])->assertOk();
        $this->assertSame(TicketStage::Done, $this->ticket->fresh()->stage);
    }

    public function test_the_background_sync_catches_merges()
    {
        Http::fake(['api.github.com/repos/acme/shop/pulls/42' => Http::response(['number' => 42, 'html_url' => 'x', 'state' => 'closed', 'merged' => true])]);

        $this->artisan('factory:sync-pull-requests')->assertSuccessful()->expectsOutputToContain('Checked 1 open pull request(s); 1 merged.');

        $this->assertSame(TicketStage::Done, $this->ticket->fresh()->stage);
    }

    public function test_the_sync_survives_provider_errors()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);

        $this->artisan('factory:sync-pull-requests')->assertSuccessful();

        $this->assertSame(TicketStage::Review, $this->ticket->fresh()->stage);
    }

    public function test_webhooks_are_not_registered_for_local_addresses()
    {
        config(['app.url' => 'http://localhost:8000']);
        url()->forceRootUrl('http://localhost:8000');

        $this->actingAs($this->owner)
            ->post(route('projects.webhook', $this->args()))
            ->assertSessionHasErrors('webhook');

        Http::assertNothingSent();
    }

    public function test_webhooks_are_registered_with_the_project_secret()
    {
        url()->forceRootUrl('https://factory.example.com');
        url()->forceScheme('https');
        Http::fake(['api.github.com/repos/acme/shop/hooks' => Http::response(['id' => 777], 201)]);

        $this->actingAs($this->owner)
            ->post(route('projects.webhook', $this->args()))
            ->assertSessionHasNoErrors();

        $this->assertSame('777', $this->project->fresh()->webhook_id);
        Http::assertSent(fn (Request $request) => $request['events'] === ['pull_request']
            && $request['config']['url'] === "https://factory.example.com/webhooks/github/{$this->project->id}"
            && $request['config']['secret'] === 'shh-secret');
    }

    public function test_the_settings_page_shows_the_webhook_details()
    {
        $this->project->forceFill(['webhook_secret' => null])->save();

        $this->actingAs($this->owner)
            ->get(route('projects.edit', $this->args()))
            ->assertInertia(fn (Assert $page) => $page
                ->where('webhook.url', route('webhooks.github', ['projectId' => $this->project->id]))
                ->where('webhook.registered', false)
                ->where('webhook.secret', fn (string $secret) => strlen($secret) === 40));

        $this->assertNotNull($this->project->fresh()->webhook_secret);
    }

    public function test_the_secret_is_never_sent_with_the_project_summary()
    {
        $this->actingAs($this->owner)
            ->get(route('projects.show', $this->args()))
            ->assertDontSee('shh-secret');
    }

    public function test_publishing_again_from_review()
    {
        $this->actingAs($this->owner)->post(route('projects.tickets.publish', $this->args(['ticket' => $this->ticket->number])))->assertSessionHasNoErrors();

        $this->assertSame(RunType::Publish, Run::firstOrFail()->type);

        $backlog = Ticket::factory()->create(['project_id' => $this->project->id, 'team_id' => $this->team->id]);
        $this->actingAs($this->owner)->post(route('projects.tickets.publish', $this->args(['ticket' => $backlog->number])))->assertSessionHasErrors('ticket');
    }

    public function test_a_reply_in_review_continues_the_implement_session()
    {
        $this->actingAs($this->owner)
            ->post(route('projects.tickets.reply', $this->args(['ticket' => $this->ticket->number])), ['message' => 'Rename the button to "New album"'])
            ->assertSessionHasNoErrors();

        $run = Run::firstOrFail();
        $this->assertSame(RunType::Implement, $run->type);
        $this->assertSame(['reply' => 'Rename the button to "New album"'], $run->input);

        $run->forceFill(['status' => RunStatus::Succeeded, 'session_id' => 'new-session'])->save();
        app(TicketWorkflow::class)->afterRun($run);

        $this->ticket->refresh();
        $this->assertSame(TicketStage::Review, $this->ticket->stage);
        $this->assertSame(TicketStatus::Idle, $this->ticket->status);
        $this->assertSame('new-session', $this->ticket->session_id);
    }

    public function test_retry_in_review_publishes_again()
    {
        $this->ticket->forceFill(['status' => TicketStatus::Failed])->save();

        $this->actingAs($this->owner)->post(route('projects.tickets.retry', $this->args(['ticket' => $this->ticket->number])))->assertSessionHasNoErrors();

        $this->assertSame(RunType::Publish, Run::firstOrFail()->type);
    }
}
