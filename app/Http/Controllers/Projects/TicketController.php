<?php

namespace App\Http\Controllers\Projects;

use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\SaveTicketRequest;
use App\Models\Project;
use App\Models\Team;
use App\Models\Ticket;
use App\Tickets\TicketWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TicketController extends Controller
{
    public function __construct(private TicketWorkflow $workflow)
    {
        //
    }

    public function store(SaveTicketRequest $request, Team $current_team, Project $project): RedirectResponse
    {
        $ticket = $this->workflow->create($project, $request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('#:number created in Backlog.', ['number' => $ticket->number])]);

        return $this->toBoard($current_team, $project, $ticket);
    }

    public function update(SaveTicketRequest $request, Team $current_team, Project $project, Ticket $ticket): RedirectResponse
    {
        if ($ticket->status === TicketStatus::Running) {
            throw ValidationException::withMessages(['ticket' => __('Wait for the run to finish before editing.')]);
        }

        $data = $request->validated();

        // The title names the branch and feature folder, so it's fixed once the work has started.
        if ($ticket->branch !== null && $data['title'] !== $ticket->title) {
            throw ValidationException::withMessages(['title' => __('The title can’t change after Specify has run, because it names the branch.')]);
        }

        $ticket->fill($data)->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('#:number saved.', ['number' => $ticket->number])]);

        return $this->toBoard($current_team, $project, $ticket);
    }

    /**
     * Drag a card to a column (or approve the next phase).
     */
    public function move(Request $request, Team $current_team, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('work', $project);

        $data = $request->validate([
            'stage' => ['required', Rule::enum(TicketStage::class)],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $run = $this->workflow->moveTo($ticket, TicketStage::from($data['stage']), $request->user(), trim((string) ($data['notes'] ?? '')));

        Inertia::flash('toast', ['type' => 'success', 'message' => $run
            ? __('#:number: :phase started.', ['number' => $ticket->number, 'phase' => $ticket->stage->label()])
            : __('#:number moved to :stage.', ['number' => $ticket->number, 'stage' => $ticket->stage->label()])]);

        return back();
    }

    public function reply(Request $request, Team $current_team, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('work', $project);

        $data = $request->validate(['message' => ['required', 'string', 'max:10000']]);

        $this->workflow->reply($ticket, $request->user(), trim($data['message']));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reply sent to the agent.')]);

        return back();
    }

    public function retry(Request $request, Team $current_team, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('work', $project);

        $this->workflow->retry($ticket, $request->user());

        return back();
    }

    public function cancel(Team $current_team, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('work', $project);

        $this->workflow->cancel($ticket);

        return back();
    }

    public function destroy(Team $current_team, Project $project, Ticket $ticket): RedirectResponse
    {
        Gate::authorize('work', $project);

        if ($ticket->status === TicketStatus::Running || $ticket->hasActiveRun()) {
            throw ValidationException::withMessages(['ticket' => __('Cancel the run before deleting the card.')]);
        }

        $ticket->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('#:number deleted. Its branch stays in the repository.', ['number' => $ticket->number])]);

        return to_route('projects.show', ['current_team' => $current_team->slug, 'project' => $project->slug]);
    }

    private function toBoard(Team $team, Project $project, Ticket $ticket): RedirectResponse
    {
        return to_route('projects.show', ['current_team' => $team->slug, 'project' => $project->slug, 'ticket' => $ticket->number]);
    }
}
