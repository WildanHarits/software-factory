<?php

namespace Tests\Feature\Projects;

use App\Enums\TeamRole;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private Credential $github;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->owner = User::factory()->create();
        $this->team = Team::factory()->create(['slug' => 'acme-team']);
        $this->team->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $this->github = Credential::factory()->create(['team_id' => $this->team->id, 'last_verified_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'mode' => 'existing',
            'repository' => 'acme/app',
            'name' => 'Acme App',
            'git_credential_id' => $this->github->id,
            'ai_credential_id' => 'none',
            'default_model' => 'claude-sonnet-5-5',
            'default_effort' => 'medium',
            'approval_mode' => 'manual',
            'merge_policy' => 'manual',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function githubRepo(array $overrides = []): array
    {
        return [
            'id' => 4242,
            'full_name' => 'acme/app',
            'html_url' => 'https://github.com/acme/app',
            'clone_url' => 'https://github.com/acme/app.git',
            'default_branch' => 'main',
            'private' => true,
            'permissions' => ['push' => true],
            ...$overrides,
        ];
    }

    private function store(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(route('projects.store', ['current_team' => $this->team->slug]), $payload);
    }

    public function test_an_existing_repository_can_be_connected()
    {
        Http::fake([
            'api.github.com/repos/acme/app' => Http::response($this->githubRepo()),
            'api.github.com/repos/acme/app/contents/.specify*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $this->store($this->payload(['repository' => 'https://github.com/acme/app.git']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('projects.show', ['current_team' => 'acme-team', 'project' => 'acme-app']));

        $project = $this->team->projects()->firstOrFail();

        $this->assertSame('acme/app', $project->repo_full_name);
        $this->assertSame('4242', $project->repo_external_id);
        $this->assertSame('main', $project->default_branch);
        $this->assertFalse($project->speckit_initialized);
        $this->assertSame($this->github->id, $project->git_credential_id);
        $this->assertNull($project->ai_credential_id);
        $this->assertSame($this->owner->id, $project->created_by);
    }

    public function test_spec_kit_is_detected_when_the_specify_folder_exists()
    {
        Http::fake([
            'api.github.com/repos/acme/app' => Http::response($this->githubRepo(['default_branch' => 'develop'])),
            'api.github.com/repos/acme/app/contents/.specify*' => Http::response([['name' => 'memory']]),
        ]);

        $this->store($this->payload())->assertSessionHasNoErrors();

        $project = $this->team->projects()->firstOrFail();
        $this->assertTrue($project->speckit_initialized);
        $this->assertSame('develop', $project->default_branch);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'contents/.specify') && str_contains($request->url(), 'ref=develop'));
    }

    public function test_tokens_without_push_access_are_rejected()
    {
        Http::fake(['api.github.com/repos/acme/app' => Http::response($this->githubRepo(['permissions' => ['push' => false]]))]);

        $this->store($this->payload())->assertSessionHasErrors('repository');

        $this->assertSame(0, $this->team->projects()->count());
    }

    public function test_missing_repositories_show_a_clear_error()
    {
        Http::fake(['api.github.com/repos/acme/app' => Http::response(['message' => 'Not Found'], 404)]);

        $this->store($this->payload())->assertSessionHasErrors([
            'repository' => "GitHub couldn't find it, or the token can't see it. GitHub said: Not Found.",
        ]);
    }

    public function test_a_new_github_repository_can_be_created_in_an_organization()
    {
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'octocat']),
            'api.github.com/orgs/acme/repos' => Http::response($this->githubRepo(['full_name' => 'acme/new-app', 'id' => 99]), 201),
            'api.github.com/repos/acme/new-app/contents/.specify*' => Http::response([], 404),
        ]);

        $this->store($this->payload([
            'mode' => 'new',
            'repository' => null,
            'repo_namespace' => 'acme',
            'repo_name' => 'new-app',
            'repo_private' => '1',
            'name' => 'New App',
        ]))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.github.com/orgs/acme/repos'
            && $request['name'] === 'new-app'
            && $request['private'] === true
            && $request['auto_init'] === true);

        $this->assertSame('acme/new-app', $this->team->projects()->firstOrFail()->repo_full_name);
    }

    public function test_a_new_github_repository_can_be_created_on_the_token_owners_account()
    {
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'octocat']),
            'api.github.com/user/repos' => Http::response($this->githubRepo(['full_name' => 'octocat/side', 'id' => 7]), 201),
            'api.github.com/repos/octocat/side/contents/.specify*' => Http::response([], 404),
        ]);

        $this->store($this->payload([
            'mode' => 'new',
            'repo_namespace' => 'octocat',
            'repo_name' => 'side',
        ]))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/user/repos' && $request['private'] === false);
    }

    public function test_a_new_gitlab_project_can_be_created_in_a_group()
    {
        $gitlab = Credential::factory()->gitlab()->create(['team_id' => $this->team->id]);

        Http::fake([
            'gitlab.com/api/v4/projects' => Http::response([
                'id' => 555,
                'path_with_namespace' => 'platform/billing',
                'web_url' => 'https://gitlab.com/platform/billing',
                'http_url_to_repo' => 'https://gitlab.com/platform/billing.git',
                'default_branch' => 'main',
                'visibility' => 'private',
                'permissions' => ['project_access' => ['access_level' => 50]],
            ], 201),
            'gitlab.com/api/v4/projects/*/repository/tree*' => Http::response([]),
        ]);

        $this->store($this->payload([
            'mode' => 'new',
            'git_credential_id' => $gitlab->id,
            'repo_namespace' => '12',
            'repo_name' => 'billing',
            'repo_private' => '1',
            'name' => 'Billing',
        ]))->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://gitlab.com/api/v4/projects'
            && $request['namespace_id'] === 12
            && $request['visibility'] === 'private'
            && $request['initialize_with_readme'] === true);

        $project = $this->team->projects()->firstOrFail();
        $this->assertSame('gitlab', $project->provider->value);
        $this->assertFalse($project->speckit_initialized);
    }

    public function test_gitlab_developers_can_connect_but_reporters_cannot()
    {
        $gitlab = Credential::factory()->gitlab()->create(['team_id' => $this->team->id]);
        $repo = fn (int $level) => [
            'id' => 1,
            'path_with_namespace' => 'group/sub/app',
            'web_url' => 'https://gitlab.com/group/sub/app',
            'http_url_to_repo' => 'https://gitlab.com/group/sub/app.git',
            'default_branch' => 'main',
            'visibility' => 'internal',
            'permissions' => ['project_access' => null, 'group_access' => ['access_level' => $level]],
        ];

        Http::fakeSequence('gitlab.com/api/v4/projects/group%2Fsub%2Fapp')
            ->push($repo(20))
            ->push($repo(30));
        Http::fake(['gitlab.com/api/v4/projects/*/repository/tree*' => Http::response([['name' => 'memory']])]);

        $payload = $this->payload(['git_credential_id' => $gitlab->id, 'repository' => 'https://gitlab.com/group/sub/app']);

        $this->store($payload)->assertSessionHasErrors('repository');
        $this->store($payload)->assertSessionHasNoErrors();

        $this->assertTrue($this->team->projects()->firstOrFail()->speckit_initialized);
    }

    public function test_the_same_repository_cannot_be_connected_twice()
    {
        Http::fake([
            'api.github.com/repos/acme/app' => Http::response($this->githubRepo()),
            'api.github.com/repos/acme/app/contents/.specify*' => Http::response([], 404),
        ]);

        $this->store($this->payload())->assertSessionHasNoErrors();
        $this->store($this->payload(['name' => 'Again']))->assertSessionHasErrors('repository');

        $this->assertSame(1, $this->team->projects()->count());
    }

    public function test_members_cannot_create_projects_but_can_view_them()
    {
        $member = User::factory()->create();
        $this->team->members()->attach($member, ['role' => TeamRole::Member->value]);
        $project = Project::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($member)->get(route('projects.create', ['current_team' => $this->team->slug]))->assertForbidden();
        $this->store($this->payload(), $member)->assertForbidden();

        $this->actingAs($member)
            ->get(route('projects.show', ['current_team' => $this->team->slug, 'project' => $project->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('projects/show')->where('canManage', false));

        $this->actingAs($member)
            ->get(route('projects.edit', ['current_team' => $this->team->slug, 'project' => $project->slug]))
            ->assertForbidden();
    }

    public function test_another_members_personal_token_cannot_be_used()
    {
        $someoneElse = User::factory()->create();
        $foreign = Credential::factory()->personal($someoneElse)->create();

        $this->store($this->payload(['git_credential_id' => $foreign->id]))->assertSessionHasErrors('git_credential_id');
    }

    public function test_your_own_personal_token_can_be_used()
    {
        $mine = Credential::factory()->personal($this->owner)->create();
        Http::fake([
            'api.github.com/repos/acme/app' => Http::response($this->githubRepo()),
            'api.github.com/repos/acme/app/contents/.specify*' => Http::response([], 404),
        ]);

        $this->store($this->payload(['git_credential_id' => $mine->id]))->assertSessionHasNoErrors();

        $this->assertSame($mine->id, $this->team->projects()->firstOrFail()->git_credential_id);
    }

    public function test_an_anthropic_key_cannot_be_the_git_token_and_vice_versa()
    {
        $anthropic = Credential::factory()->anthropic()->create(['team_id' => $this->team->id]);

        $this->store($this->payload([
            'git_credential_id' => $anthropic->id,
            'ai_credential_id' => $this->github->id,
        ]))->assertSessionHasErrors(['git_credential_id', 'ai_credential_id']);
    }

    public function test_unknown_models_and_efforts_are_rejected()
    {
        $this->store($this->payload(['default_model' => 'gpt-9', 'default_effort' => 'ludicrous']))
            ->assertSessionHasErrors(['default_model', 'default_effort']);
    }

    public function test_projects_are_only_found_within_their_own_team()
    {
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach($this->owner, ['role' => TeamRole::Owner->value]);
        $project = Project::factory()->create(['team_id' => $otherTeam->id, 'slug' => 'secret']);

        $this->actingAs($this->owner)
            ->get(route('projects.show', ['current_team' => $this->team->slug, 'project' => 'secret']))
            ->assertNotFound();

        $this->actingAs(User::factory()->create())
            ->get(route('projects.show', ['current_team' => $otherTeam->slug, 'project' => $project->slug]))
            ->assertForbidden();
    }

    public function test_slugs_are_unique_per_team()
    {
        $first = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Web']);
        $second = Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Web']);
        $elsewhere = Project::factory()->create(['name' => 'Web']);

        $this->assertSame('web', $first->slug);
        $this->assertSame('web-2', $second->slug);
        $this->assertSame('web', $elsewhere->slug);
    }

    public function test_settings_can_be_updated()
    {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'git_credential_id' => $this->github->id]);
        $anthropic = Credential::factory()->anthropic()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)
            ->patch(route('projects.update', ['current_team' => $this->team->slug, 'project' => $project->slug]), [
                'name' => 'Renamed',
                'description' => 'New words',
                'git_credential_id' => $this->github->id,
                'ai_credential_id' => $anthropic->id,
                'default_model' => 'claude-opus-5-5',
                'default_effort' => 'high',
                'approval_mode' => 'auto',
                'merge_policy' => 'when_checks_pass',
            ])
            ->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertSame('Renamed', $project->name);
        $this->assertSame($anthropic->id, $project->ai_credential_id);
        $this->assertSame('claude-opus-5-5', $project->default_model);
        $this->assertSame('auto', $project->approval_mode->value);
        $this->assertSame('when_checks_pass', $project->merge_policy->value);
        Http::assertNothingSent();
    }

    public function test_switching_the_git_token_checks_it_can_push()
    {
        $project = Project::factory()->create(['team_id' => $this->team->id, 'git_credential_id' => $this->github->id, 'repo_full_name' => 'acme/app']);
        $readOnly = Credential::factory()->create(['team_id' => $this->team->id]);
        Http::fake(['api.github.com/repos/acme/app' => Http::response($this->githubRepo(['permissions' => ['push' => false]]))]);

        $this->actingAs($this->owner)
            ->patch(route('projects.update', ['current_team' => $this->team->slug, 'project' => $project->slug]), [
                'name' => $project->name,
                'git_credential_id' => $readOnly->id,
                'default_model' => 'claude-sonnet-5-5',
                'default_effort' => 'medium',
                'approval_mode' => 'manual',
                'merge_policy' => 'manual',
            ])
            ->assertSessionHasErrors('git_credential_id');

        $this->assertSame($this->github->id, $project->fresh()->git_credential_id);
    }

    public function test_refresh_updates_repository_details_and_spec_kit_status()
    {
        $project = Project::factory()->create([
            'team_id' => $this->team->id,
            'git_credential_id' => $this->github->id,
            'repo_full_name' => 'acme/app',
            'speckit_initialized' => false,
        ]);
        Http::fake([
            'api.github.com/repos/acme/app' => Http::response($this->githubRepo(['default_branch' => 'trunk'])),
            'api.github.com/repos/acme/app/contents/.specify*' => Http::response([['name' => 'memory']]),
        ]);

        $this->actingAs($this->owner)
            ->post(route('projects.refresh', ['current_team' => $this->team->slug, 'project' => $project->slug]))
            ->assertSessionHasNoErrors();

        $project->refresh();
        $this->assertTrue($project->speckit_initialized);
        $this->assertSame('trunk', $project->default_branch);
    }

    public function test_removing_a_project_soft_deletes_it_without_touching_the_repository()
    {
        $project = Project::factory()->create(['team_id' => $this->team->id]);

        $this->actingAs($this->owner)
            ->delete(route('projects.destroy', ['current_team' => $this->team->slug, 'project' => $project->slug]))
            ->assertRedirect(route('dashboard', ['current_team' => $this->team->slug]));

        $this->assertSoftDeleted($project);
        Http::assertNothingSent();
    }

    public function test_the_dashboard_lists_the_teams_projects()
    {
        Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Zeta']);
        Project::factory()->create(['team_id' => $this->team->id, 'name' => 'Alpha']);
        Project::factory()->create(['name' => 'Other team']);

        $this->actingAs($this->owner)
            ->get(route('dashboard', ['current_team' => $this->team->slug]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('dashboard')
                ->has('projects', 2)
                ->where('projects.0.name', 'Alpha')
                ->where('canManageProjects', true));
    }
}
