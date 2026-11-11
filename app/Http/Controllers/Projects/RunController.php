<?php

namespace App\Http\Controllers\Projects;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StartRunRequest;
use App\Jobs\ExecuteRun;
use App\Models\Project;
use App\Models\Run;
use App\Models\RunEvent;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
    /**
     * Start setting up Spec Kit in the repository.
     */
    public function setup(StartRunRequest $request, Team $current_team, Project $project): RedirectResponse
    {
        $data = $request->validated();
        $principles = trim((string) ($data['principles'] ?? ''));

        if ($project->speckit_initialized) {
            throw ValidationException::withMessages(['run' => __('Spec Kit is already set up in this repository.')]);
        }

        if ($principles !== '' && ! $project->ai_credential_id) {
            throw ValidationException::withMessages(['principles' => __('Writing the constitution needs an Anthropic API key. Pick one in the project settings, or leave this empty.')]);
        }

        return $this->start($request, $current_team, $project, RunType::SetupSpecKit, ['principles' => $principles]);
    }

    /**
     * Start updating the constitution.
     */
    public function constitution(StartRunRequest $request, Team $current_team, Project $project): RedirectResponse
    {
        if (! $project->speckit_initialized) {
            throw ValidationException::withMessages(['run' => __('Set up Spec Kit first.')]);
        }

        if (! $project->ai_credential_id) {
            throw ValidationException::withMessages(['instructions' => __('This needs an Anthropic API key. Pick one in the project settings.')]);
        }

        return $this->start($request, $current_team, $project, RunType::Constitution, ['instructions' => trim((string) $request->validated('instructions'))]);
    }

    public function show(Request $request, Team $current_team, Project $project, Run $run): Response
    {
        Gate::authorize('view', $project);

        $run->load('triggeredBy');

        return Inertia::render('runs/show', [
            'team' => ['name' => $current_team->name, 'slug' => $current_team->slug],
            'project' => ['name' => $project->name, 'slug' => $project->slug, 'repo_full_name' => $project->repo_full_name, 'repo_web_url' => $project->repo_web_url],
            'run' => $run->toSummary(),
            'events' => $run->events()->get()->map(fn (RunEvent $event) => $event->toSummary()),
            'canCancel' => $request->user()->can('update', $project),
        ]);
    }

    /**
     * New log lines since `after`, plus the run's current state. The run page polls this.
     */
    public function events(Request $request, Team $current_team, Project $project, Run $run): JsonResponse
    {
        Gate::authorize('view', $project);

        $after = max(0, (int) $request->query('after', '0'));

        return response()->json([
            'run' => $run->load('triggeredBy')->toSummary(),
            'events' => $run->events()->where('seq', '>', $after)->limit(500)->get()->map(fn (RunEvent $event) => $event->toSummary()),
        ]);
    }

    public function cancel(Team $current_team, Project $project, Run $run): RedirectResponse
    {
        Gate::authorize('update', $project);

        $run->requestCancel();

        return back();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function start(StartRunRequest $request, Team $team, Project $project, RunType $type, array $input): RedirectResponse
    {
        if (! $project->git_credential_id) {
            throw ValidationException::withMessages(['run' => __('This project has no git token. Pick one in the project settings.')]);
        }

        if ($active = $project->runs()->whereNull('ticket_id')->active()->first()) {
            throw ValidationException::withMessages(['run' => __('":type" is still running for this project. Wait for it to finish or cancel it.', ['type' => $active->type->label()])]);
        }

        $run = $project->runs()->create([
            'team_id' => $team->id,
            'type' => $type,
            'status' => RunStatus::Queued,
            'input' => $input,
            'model' => $request->validated('model'),
            'effort' => $request->validated('effort'),
            'base_branch' => $project->default_branch,
            'triggered_by' => $request->user()->id,
        ]);

        ExecuteRun::dispatch($run->id);

        return to_route('projects.runs.show', ['current_team' => $team->slug, 'project' => $project->slug, 'run' => $run->id]);
    }
}
