<?php

namespace App\Http\Controllers\Credentials;

use App\Enums\CredentialProvider;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Team;
use App\Runner\Agent\ProviderModels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CredentialPageController extends Controller
{
    /**
     * The signed-in user's personal tokens.
     */
    public function personal(Request $request): Response
    {
        return Inertia::render('settings/tokens', [
            'credentials' => $this->summaries($request->user()->credentials()->with('user')->get()),
            'providers' => CredentialProvider::options(),
            'qwenEndpoints' => $this->qwenEndpoints(),
        ]);
    }

    /**
     * Tokens shared by a team.
     */
    public function team(Request $request, Team $team): Response
    {
        Gate::authorize('view', $team);

        return Inertia::render('teams/tokens', [
            'team' => ['name' => $team->name, 'slug' => $team->slug],
            'credentials' => $this->summaries($team->credentials()->with('team')->get()),
            'providers' => CredentialProvider::options(includeSubscription: false),
            'qwenEndpoints' => $this->qwenEndpoints(),
            'canManage' => $request->user()->can('manageCredentials', $team),
        ]);
    }

    /**
     * @param  iterable<Credential>  $credentials
     * @return array<int, array<string, mixed>>
     */
    private function summaries(iterable $credentials): array
    {
        $ids = collect($credentials)->pluck('id');

        // How many projects use each token, so people know what deleting it affects.
        $usage = Project::query()
            ->where(fn ($query) => $query->whereIn('git_credential_id', $ids)->orWhereIn('ai_credential_id', $ids))
            ->get(['git_credential_id', 'ai_credential_id']);

        return collect($credentials)
            ->sortBy(fn (Credential $credential) => [$credential->provider->value, strtolower($credential->name)])
            ->map(fn (Credential $credential) => [
                ...$credential->toSummary(),
                'projects_count' => $usage->filter(fn (Project $project) => $project->git_credential_id === $credential->id
                    || $project->ai_credential_id === $credential->id)->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function qwenEndpoints(): array
    {
        return collect(ProviderModels::qwenEndpoints())
            ->map(fn (string $label, string $url) => ['value' => $url, 'label' => $label])
            ->values()
            ->all();
    }
}
