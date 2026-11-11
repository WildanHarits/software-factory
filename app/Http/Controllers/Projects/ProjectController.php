<?php

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\ConnectRepository;
use App\Enums\ApprovalMode;
use App\Enums\MergePolicy;
use App\Enums\RunType;
use App\Enums\TicketStage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Run;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Runner\RunnerHealth;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderRegistry;
use App\Support\RepositoryReference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function create(Request $request, Team $current_team): Response
    {
        Gate::authorize('manageProjects', $current_team);

        return Inertia::render('projects/create', [
            ...$this->formOptions($request->user(), $current_team),
            'defaults' => [
                'default_model' => config('factory.default_model'),
                'default_effort' => config('factory.default_effort'),
                'approval_mode' => ApprovalMode::Manual->value,
                'merge_policy' => MergePolicy::Manual->value,
            ],
        ]);
    }

    public function store(StoreProjectRequest $request, Team $current_team, ConnectRepository $connect): RedirectResponse
    {
        $data = $request->validated();
        $credential = Credential::query()->whereKey($data['git_credential_id'])->firstOrFail();

        $repository = $data['mode'] === 'new'
            ? $connect->create($credential, $data['repo_namespace'], $data['repo_name'], $data['repo_private'], $data['description'] ?? null)
            : $connect->existing($credential, (string) RepositoryReference::parse($data['repository']));

        $alreadyConnected = $current_team->projects()
            ->where('provider', $credential->provider)
            ->where('repo_external_id', $repository->externalId)
            ->first();

        if ($alreadyConnected) {
            throw ValidationException::withMessages([
                'repository' => __(':repo is already connected as ":project".', ['repo' => $repository->fullName, 'project' => $alreadyConnected->name]),
            ]);
        }

        $project = $current_team->projects()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'provider' => $credential->provider,
            'repo_full_name' => $repository->fullName,
            'repo_external_id' => $repository->externalId,
            'repo_web_url' => $repository->webUrl,
            'repo_clone_url' => $repository->cloneUrl,
            'default_branch' => $repository->defaultBranch,
            'repo_private' => $repository->private,
            'git_credential_id' => $credential->id,
            'ai_credential_id' => $data['ai_credential_id'] ?? null,
            'default_model' => $data['default_model'],
            'default_effort' => $data['default_effort'],
            'approval_mode' => $data['approval_mode'],
            'merge_policy' => $data['merge_policy'],
            'speckit_initialized' => $connect->hasSpecKit($credential, $repository),
            'speckit_checked_at' => now(),
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $data['mode'] === 'new'
            ? __('Repository :repo created.', ['repo' => $repository->fullName])
            : __('Repository :repo connected.', ['repo' => $repository->fullName])]);

        return to_route('projects.show', ['current_team' => $current_team->slug, 'project' => $project->slug]);
    }

    public function show(Request $request, Team $current_team, Project $project, RunnerHealth $health): Response
    {
        Gate::authorize('view', $project);

        $project->load(['gitCredential.team', 'gitCredential.user', 'aiCredential.team', 'aiCredential.user', 'creator']);
        $canManage = $request->user()->can('update', $project);

        $runs = $project->runs()->whereNull('ticket_id')->with('triggeredBy')->latest('id')->limit(10)->get();
        $selected = $request->integer('ticket');

        // A setup PR that hasn't been merged yet (Spec Kit isn't on the default branch).
        $setupPullRequest = $project->speckit_initialized ? null : $project->runs()
            ->where('type', RunType::SetupSpecKit)
            ->whereNotNull('pull_request_url')
            ->latest('id')
            ->first();

        return Inertia::render('projects/show', [
            'team' => ['name' => $current_team->name, 'slug' => $current_team->slug],
            'project' => $project->toSummary(),
            'canManage' => $canManage,
            'runs' => $runs->map(fn (Run $run) => $run->toSummary()),
            'setupPullRequest' => $setupPullRequest?->toSummary(),
            'runnerProblems' => $canManage ? $health->problems() : [],
            'models' => $this->options(config('factory.models')),
            'efforts' => $this->options(config('factory.efforts')),
            'speckitVersion' => config('factory.speckit.version'),
            'columns' => TicketStage::columns(),
            'tickets' => fn () => $project->tickets()
                ->withSum('runs', 'cost_usd')
                ->orderByDesc('updated_at')
                ->get()
                ->map(fn (Ticket $ticket) => $ticket->toCard()),
            'selectedTicket' => fn () => $selected
                ? $project->tickets()->with(['lastRun.triggeredBy', 'documents', 'creator'])->withSum('runs', 'cost_usd')->where('number', $selected)->first()?->toDetail()
                : null,
            'approvalModes' => ApprovalMode::options(),
        ]);
    }

    public function edit(Request $request, Team $current_team, Project $project): Response
    {
        Gate::authorize('update', $project);

        // Every project gets a webhook secret, so the webhook can be set up by hand too.
        if (! $project->webhook_secret) {
            $project->forceFill(['webhook_secret' => Str::random(40)])->save();
        }

        return Inertia::render('projects/edit', [
            'project' => $project->toSummary(),
            ...$this->formOptions($request->user(), $current_team, $project),
            'webhook' => [
                'url' => $this->webhookUrl($project),
                'secret' => $project->webhook_secret,
                'registered' => $project->webhook_id !== null,
                'last_received_at' => $project->webhook_last_received_at?->toISOString(),
                'reachable' => ! $this->isLocalUrl($this->webhookUrl($project)),
                'sync_every_minutes' => (int) config('factory.git.sync_every_minutes'),
            ],
        ]);
    }

    /**
     * Add the webhook on GitHub/GitLab so merged pull requests move cards to Done right away.
     */
    public function registerWebhook(Team $current_team, Project $project, ProviderRegistry $providers): RedirectResponse
    {
        Gate::authorize('update', $project);

        $url = $this->webhookUrl($project);

        if ($this->isLocalUrl($url)) {
            throw ValidationException::withMessages(['webhook' => __(':provider can’t reach :url. Expose the app with a tunnel (for example cloudflared or ngrok), set APP_URL to its public address, then try again. Until then, open pull requests are checked every few minutes.', ['provider' => $project->provider->label(), 'url' => $url])]);
        }

        if (! $project->gitCredential) {
            throw ValidationException::withMessages(['webhook' => __('Pick a git token first.')]);
        }

        if (! $project->webhook_secret) {
            $project->forceFill(['webhook_secret' => Str::random(40)])->save();
        }

        try {
            $id = $providers->gitHost($project->gitCredential)->registerWebhook($project->repo_full_name, $url, (string) $project->webhook_secret);
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages(['webhook' => $exception->getMessage()]);
        }

        $project->forceFill(['webhook_id' => $id])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Webhook added on :provider.', ['provider' => $project->provider->label()])]);

        return back();
    }

    private function webhookUrl(Project $project): string
    {
        return route($project->provider->value === 'gitlab' ? 'webhooks.gitlab' : 'webhooks.github', ['projectId' => $project->id]);
    }

    private function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]', '0.0.0.0'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || preg_match('/^(10|192\.168|172\.(1[6-9]|2\d|3[01]))\./', $host) === 1;
    }

    public function update(UpdateProjectRequest $request, Team $current_team, Project $project, ConnectRepository $connect): RedirectResponse
    {
        $data = $request->validated();

        if ((int) $data['git_credential_id'] !== $project->git_credential_id) {
            $credential = Credential::query()->whereKey($data['git_credential_id'])->firstOrFail();

            if ($credential->provider !== $project->provider) {
                throw ValidationException::withMessages([
                    'git_credential_id' => __('This project is on :provider. Pick a :provider token.', ['provider' => $project->provider->label()]),
                ]);
            }

            // Make sure the new token can actually push to this repository before switching.
            $connect->existing($credential, $project->repo_full_name, 'git_credential_id');
        }

        $project->fill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'git_credential_id' => $data['git_credential_id'],
            'ai_credential_id' => $data['ai_credential_id'] ?? null,
            'default_model' => $data['default_model'],
            'default_effort' => $data['default_effort'],
            'approval_mode' => $data['approval_mode'],
            'merge_policy' => $data['merge_policy'],
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project settings saved.')]);

        return to_route('projects.edit', ['current_team' => $current_team->slug, 'project' => $project->slug]);
    }

    /**
     * Re-read the repository details and check for Spec Kit again.
     */
    public function refresh(Team $current_team, Project $project, ConnectRepository $connect): RedirectResponse
    {
        Gate::authorize('update', $project);

        if (! $project->gitCredential) {
            throw ValidationException::withMessages(['refresh' => __('This project has no git token. Pick one in the project settings.')]);
        }

        $repository = $connect->existing($project->gitCredential, $project->repo_full_name, 'refresh');

        $project->forceFill([
            'repo_full_name' => $repository->fullName,
            'repo_web_url' => $repository->webUrl,
            'repo_clone_url' => $repository->cloneUrl,
            'default_branch' => $repository->defaultBranch,
            'repo_private' => $repository->private,
            'speckit_initialized' => $connect->hasSpecKit($project->gitCredential, $repository),
            'speckit_checked_at' => now(),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Repository details refreshed.')]);

        return back();
    }

    public function destroy(Team $current_team, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        $project->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project ":name" removed. The repository itself was not touched.', ['name' => $project->name])]);

        return to_route('dashboard', ['current_team' => $current_team->slug]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(User $user, Team $team, ?Project $project = null): array
    {
        $credentials = Credential::query()
            ->usableBy($user, $team)
            ->with(['team', 'user'])
            ->orderBy('name')
            ->get();

        // Keep showing the project's current tokens even if they belong to another member.
        if ($project) {
            $current = Credential::query()
                ->with(['team', 'user'])
                ->whereKey(array_filter([$project->git_credential_id, $project->ai_credential_id]))
                ->get();
            $credentials = $credentials->merge($current)->unique('id');
        }

        return [
            'gitCredentials' => $credentials->filter(fn (Credential $credential) => $credential->provider->isGit())
                ->map->toSummary()->values(),
            'aiCredentials' => $credentials->reject(fn (Credential $credential) => $credential->provider->isGit())
                ->map->toSummary()->values(),
            'models' => $this->options(config('factory.models')),
            'efforts' => $this->options(config('factory.efforts')),
            'approvalModes' => ApprovalMode::options(),
            'mergePolicies' => MergePolicy::options(),
            'team' => ['name' => $team->name, 'slug' => $team->slug],
        ];
    }

    /**
     * @param  array<string, string>  $values
     * @return array<int, array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return collect($values)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values()->all();
    }
}
