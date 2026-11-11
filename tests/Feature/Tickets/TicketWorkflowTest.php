<?php

namespace Tests\Feature\Tickets;

use App\Enums\ApprovalMode;
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
use App\Runner\RunnerHealth;
use App\Tickets\TicketWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Team $team;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->member = User::factory()->create();
        $this->team = Team::factory()->create(['slug' => 'acme']);
        $this->team->members()->attach($this->member, ['role' => TeamRole::Member->value]);

        $this->project = Project::factory()->create([
            'team_id' => $this->team->id,
            'slug' => 'shop',
            'speckit_initialized' => true,
            'default_model' => 'claude-opus-5-5',
            'default_effort' => 'high',
            'git_credential_id' => Credential::factory()->create(['team_id' => $this->team->id])->id,
            'ai_credential_id' => Credential::factory()->anthropic()->create(['team_id' => $this->team->id])->id,
        ]);

        $health = Mockery::mock(RunnerHealth::class);
        $health->shouldReceive('problems')->andReturn([]);
        $this->app->instance(RunnerHealth::class, $health);
    }

    private function args(array $extra = []): array
    {
        return ['current_team' => 'acme', 'project' => 'shop', ...$extra];
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create(['project_id' => $this->project->id, 'team_id' => $this->team->id, ...$attributes]);
    }

    private function awaiting(TicketStage $stage, array $attributes = []): Ticket
    {
        return Ticket::factory()->awaiting($stage)->create(['project_id' => $this->project->id, 'team_id' => $this->team->id, ...$attributes]);
    }

    private function moveTo(Ticket $ticket, string $stage, array $extra = [])
    {
        return $this->actingAs($this->member)->post(route('projects.tickets.move', $this->args(['ticket' => $ticket->number])), ['stage' => $stage, ...$extra]);
    }

    public function test_members_can_create_tickets_with_project_defaults()
    {
        $this->actingAs($this->member)
            ->post(route('projects.tickets.store', $this->args()), [
                'title' => 'Photo albums',
                'description' => 'Group photos into albums.',
                'model' => 'claude-opus-5-5',
                'effort' => 'high',
                'approval_mode' => 'manual',
                'skip_analyze' => '1',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', $this->args(['ticket' => 1])));

        $this->actingAs($this->member)->post(route('projects.tickets.store', $this->args()), [
            'title' => 'Second', 'model' => 'claude-opus-5-5', 'effort' => 'high', 'approval_mode' => 'auto',
        ]);

        $first = $this->project->tickets()->where('number', 1)->firstOrFail();
        $this->assertSame('Photo albums', $first->title);
        $this->assertSame(TicketStage::Backlog, $first->stage);
        $this->assertSame(TicketStatus::Idle, $first->status);
        $this->assertTrue($first->skip_analyze);
        $this->assertFalse($first->skip_clarify);
        $this->assertSame(2, $this->project->tickets()->max('number'));
        Queue::assertNothingPushed();
    }

    public function test_outsiders_cannot_touch_the_board()
    {
        $ticket = $this->ticket();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->post(route('projects.tickets.store', $this->args()), ['title' => 'x'])->assertForbidden();
        $this->actingAs($outsider)->post(route('projects.tickets.move', $this->args(['ticket' => $ticket->number])), ['stage' => 'specify'])->assertForbidden();
    }

    public function test_moving_from_backlog_to_specify_starts_the_specify_run()
    {
        $ticket = $this->ticket(['model' => 'claude-haiku-5-5', 'effort' => 'low']);

        $this->moveTo($ticket, 'specify', ['notes' => 'Mobile first'])->assertSessionHasNoErrors();

        $ticket->refresh();
        $run = Run::firstOrFail();

        $this->assertSame(TicketStage::Specify, $ticket->stage);
        $this->assertSame(TicketStatus::Running, $ticket->status);
        $this->assertSame($run->id, $ticket->last_run_id);
        $this->assertSame(RunType::Specify, $run->type);
        $this->assertSame($ticket->id, $run->ticket_id);
        $this->assertSame('claude-haiku-5-5', $run->model);
        $this->assertSame('low', $run->effort);
        $this->assertSame(['notes' => 'Mobile first'], $run->input);
        Queue::assertPushed(ExecuteRun::class, fn (ExecuteRun $job) => $job->runId === $run->id);
    }

    public function test_specify_cannot_be_skipped()
    {
        $ticket = $this->ticket();

        $this->moveTo($ticket, 'plan')->assertSessionHasErrors(['ticket' => "Specify can't be skipped. Move the card to Specify first."]);

        $this->assertSame(TicketStage::Backlog, $ticket->fresh()->stage);
        Queue::assertNothingPushed();
    }

    public function test_moving_past_clarify_skips_it()
    {
        $ticket = $this->awaiting(TicketStage::Specify);

        $this->moveTo($ticket, 'plan')->assertSessionHasNoErrors();

        $ticket->refresh();
        $this->assertSame(TicketStage::Plan, $ticket->stage);
        $this->assertTrue($ticket->skip_clarify);
        $this->assertSame(RunType::Plan, Run::firstOrFail()->type);
    }

    public function test_required_phases_cannot_be_jumped_over()
    {
        $ticket = $this->awaiting(TicketStage::Specify);

        $this->moveTo($ticket, 'tasks')->assertSessionHasErrors(['ticket' => "Plan can't be skipped. Move the card to Plan first."]);
    }

    public function test_cards_only_move_forward()
    {
        $ticket = $this->awaiting(TicketStage::Plan);

        $this->moveTo($ticket, 'specify')->assertSessionHasErrors('ticket');
        $this->moveTo($ticket, 'backlog')->assertSessionHasErrors('ticket');
    }

    public function test_a_phase_must_finish_before_moving_on()
    {
        $running = $this->awaiting(TicketStage::Specify, ['status' => TicketStatus::Running]);
        $failed = $this->awaiting(TicketStage::Specify, ['status' => TicketStatus::Failed]);

        $this->moveTo($running, 'clarify')->assertSessionHasErrors(['ticket' => "#{$running->number} is still running. Wait for it to finish or cancel it."]);
        $this->moveTo($failed, 'clarify')->assertSessionHasErrors(['ticket' => 'Finish Specify first.']);
    }

    public function test_after_implement_cards_go_to_review_then_done()
    {
        $ticket = $this->awaiting(TicketStage::Implement);

        $this->moveTo($ticket, 'done')->assertSessionHasErrors('ticket');
        $this->moveTo($ticket, 'review')->assertSessionHasNoErrors();
        $this->assertSame(TicketStage::Review, $ticket->fresh()->stage);
        $this->assertSame(TicketStatus::Idle, $ticket->fresh()->status);

        $this->moveTo($ticket, 'done')->assertSessionHasNoErrors();
        $this->assertSame(TicketStage::Done, $ticket->fresh()->stage);

        $this->moveTo($ticket, 'review')->assertSessionHasNoErrors();
        $this->assertSame(TicketStage::Review, $ticket->fresh()->stage);

        Queue::assertNothingPushed();
    }

    public function test_analyze_is_skipped_when_moving_from_tasks_to_implement()
    {
        $ticket = $this->awaiting(TicketStage::Tasks);

        $this->moveTo($ticket, 'implement')->assertSessionHasNoErrors();

        $this->assertTrue($ticket->fresh()->skip_analyze);
        $this->assertSame(RunType::Implement, Run::firstOrFail()->type);
    }

    public function test_phases_need_spec_kit_and_both_tokens()
    {
        $ticket = $this->ticket();

        $this->project->update(['speckit_initialized' => false]);
        $this->moveTo($ticket, 'specify')->assertSessionHasErrors(['ticket' => 'Set up Spec Kit in this repository first.']);

        $this->project->update(['speckit_initialized' => true, 'ai_credential_id' => null]);
        $this->moveTo($ticket, 'specify')->assertSessionHasErrors(['ticket' => 'This project has no Anthropic API key. Pick one in the project settings.']);

        $this->assertSame(TicketStage::Backlog, $ticket->fresh()->stage);
    }

    public function test_replying_continues_the_same_phase_and_session()
    {
        $ticket = $this->awaiting(TicketStage::Clarify);

        $this->actingAs($this->member)
            ->post(route('projects.tickets.reply', $this->args(['ticket' => $ticket->number])), ['message' => '1. Yes  2. Max 50 photos'])
            ->assertSessionHasNoErrors();

        $run = Run::firstOrFail();
        $this->assertSame(RunType::Clarify, $run->type);
        $this->assertSame(['reply' => '1. Yes  2. Max 50 photos'], $run->input);
        $this->assertSame(TicketStage::Clarify, $ticket->fresh()->stage);
        $this->assertSame(TicketStatus::Running, $ticket->fresh()->status);
    }

    public function test_replying_needs_a_session()
    {
        $ticket = $this->ticket(['stage' => TicketStage::Specify, 'status' => TicketStatus::Failed]);

        $this->actingAs($this->member)
            ->post(route('projects.tickets.reply', $this->args(['ticket' => $ticket->number])), ['message' => 'hello'])
            ->assertSessionHasErrors('ticket');
    }

    public function test_a_failed_phase_can_be_retried()
    {
        $ticket = $this->awaiting(TicketStage::Plan, ['status' => TicketStatus::Failed]);

        $this->actingAs($this->member)->post(route('projects.tickets.retry', $this->args(['ticket' => $ticket->number])))->assertSessionHasNoErrors();

        $this->assertSame(RunType::Plan, Run::firstOrFail()->type);
        $this->assertSame([], Run::firstOrFail()->input);
    }

    public function test_cancelling_a_queued_run_marks_the_ticket_for_attention()
    {
        $ticket = $this->ticket();
        $this->moveTo($ticket, 'specify');

        $this->actingAs($this->member)->post(route('projects.tickets.cancel', $this->args(['ticket' => $ticket->number])));

        $this->assertSame(RunStatus::Cancelled, Run::firstOrFail()->status);
        $this->assertSame(TicketStatus::Failed, $ticket->fresh()->status);
    }

    public function test_a_finished_run_puts_the_ticket_up_for_review()
    {
        $ticket = $this->ticket();
        $run = app(TicketWorkflow::class)->moveTo($ticket, TicketStage::Specify, $this->member);
        $run->forceFill(['status' => RunStatus::Succeeded, 'session_id' => 'sess-9'])->save();

        app(TicketWorkflow::class)->afterRun($run);

        $ticket->refresh();
        $this->assertSame(TicketStatus::AwaitingApproval, $ticket->status);
        $this->assertSame('sess-9', $ticket->session_id);
        $this->assertCount(1, Run::all());
    }

    public function test_auto_mode_starts_the_next_phase_but_waits_after_clarify()
    {
        $ticket = $this->ticket(['approval_mode' => ApprovalMode::Auto]);
        $workflow = app(TicketWorkflow::class);

        $specify = $workflow->moveTo($ticket, TicketStage::Specify, $this->member);
        $specify->forceFill(['status' => RunStatus::Succeeded, 'session_id' => 's1'])->save();
        $workflow->afterRun($specify);

        $ticket->refresh();
        $this->assertSame(TicketStage::Clarify, $ticket->stage);
        $this->assertSame(TicketStatus::Running, $ticket->status);

        $clarify = $ticket->lastRun;
        $clarify->forceFill(['status' => RunStatus::Succeeded, 'session_id' => 's2'])->save();
        $workflow->afterRun($clarify);

        $ticket->refresh();
        $this->assertSame(TicketStage::Clarify, $ticket->stage);
        $this->assertSame(TicketStatus::AwaitingApproval, $ticket->status);
    }

    public function test_auto_mode_does_not_advance_after_a_reply()
    {
        $ticket = $this->awaiting(TicketStage::Plan, ['approval_mode' => ApprovalMode::Auto]);
        $workflow = app(TicketWorkflow::class);

        $reply = $workflow->reply($ticket, $this->member, 'Use Postgres instead');
        $reply->forceFill(['status' => RunStatus::Succeeded])->save();
        $workflow->afterRun($reply);

        $this->assertSame(TicketStage::Plan, $ticket->fresh()->stage);
        $this->assertSame(TicketStatus::AwaitingApproval, $ticket->fresh()->status);
    }

    public function test_a_failed_run_marks_the_ticket_failed()
    {
        $ticket = $this->ticket();
        $run = app(TicketWorkflow::class)->moveTo($ticket, TicketStage::Specify, $this->member);
        $run->forceFill(['status' => RunStatus::Failed])->save();

        app(TicketWorkflow::class)->afterRun($run);

        $this->assertSame(TicketStatus::Failed, $ticket->fresh()->status);
    }

    public function test_the_title_is_fixed_once_the_branch_exists()
    {
        $ticket = $this->awaiting(TicketStage::Specify, ['title' => 'Albums']);
        $payload = ['model' => 'claude-opus-5-5', 'effort' => 'max', 'approval_mode' => 'auto'];

        $this->actingAs($this->member)
            ->patch(route('projects.tickets.update', $this->args(['ticket' => $ticket->number])), ['title' => 'Renamed', ...$payload])
            ->assertSessionHasErrors('title');

        $this->actingAs($this->member)
            ->patch(route('projects.tickets.update', $this->args(['ticket' => $ticket->number])), ['title' => 'Albums', ...$payload])
            ->assertSessionHasNoErrors();

        $this->assertSame('max', $ticket->fresh()->effort);
        $this->assertSame(ApprovalMode::Auto, $ticket->fresh()->approval_mode);
    }

    public function test_cards_can_be_deleted_unless_running()
    {
        $running = $this->ticket(['status' => TicketStatus::Running]);
        $idle = $this->ticket();

        $this->actingAs($this->member)->delete(route('projects.tickets.destroy', $this->args(['ticket' => $running->number])))->assertSessionHasErrors('ticket');
        $this->actingAs($this->member)->delete(route('projects.tickets.destroy', $this->args(['ticket' => $idle->number])))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($idle);
        $this->assertNotSoftDeleted($running);
    }

    public function test_tickets_are_found_by_number_within_their_project()
    {
        $other = Project::factory()->create(['team_id' => $this->team->id, 'slug' => 'other']);
        $elsewhere = Ticket::factory()->create(['project_id' => $other->id, 'team_id' => $this->team->id, 'number' => 7]);

        $this->actingAs($this->member)
            ->post(route('projects.tickets.move', $this->args(['ticket' => 7])), ['stage' => 'specify'])
            ->assertNotFound();

        $this->assertSame(TicketStage::Backlog, $elsewhere->fresh()->stage);
    }

    public function test_the_board_shows_cards_and_the_opened_ticket()
    {
        $ticket = $this->awaiting(TicketStage::Clarify, ['title' => 'Albums']);
        $ticket->documents()->create(['path' => 'specs/001-albums/spec.md', 'content' => '# Spec']);
        $run = Run::factory()->create(['project_id' => $this->project->id, 'team_id' => $this->team->id, 'ticket_id' => $ticket->id, 'type' => RunType::Clarify, 'status' => RunStatus::Succeeded, 'cost_usd' => 0.5, 'result_text' => 'Q1: How many photos?']);
        $ticket->forceFill(['last_run_id' => $run->id])->save();
        $this->ticket(['title' => 'Other']);

        $this->actingAs($this->member)
            ->get(route('projects.show', $this->args(['ticket' => $ticket->number])))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('projects/show')
                ->has('columns', 9)
                ->has('tickets', 2)
                ->where('selectedTicket.title', 'Albums')
                ->where('selectedTicket.next_stage', 'plan')
                ->where('selectedTicket.can_reply', true)
                ->where('selectedTicket.last_message', 'Q1: How many photos?')
                ->where('selectedTicket.cost_usd', 0.5)
                ->has('selectedTicket.documents', 1));
    }
}
