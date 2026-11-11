<?php

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProvider;
use App\Enums\TeamRole;
use App\Models\Credential;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fakeGitHubUser(string $login = 'octocat', ?string $scopes = 'repo, read:org'): void
    {
        Http::fake([
            'api.github.com/user' => Http::response(['login' => $login], 200, $scopes === null ? [] : ['X-OAuth-Scopes' => $scopes]),
        ]);
    }

    /**
     * @return array{0: User, 1: Team}
     */
    private function teamWith(TeamRole $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => $role->value]);

        return [$user, $team];
    }

    public function test_personal_tokens_are_encrypted_and_checked()
    {
        $this->fakeGitHubUser();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('tokens.store'), [
                'provider' => 'github',
                'name' => 'My GitHub',
                'token' => 'ghp_secretvalue1234',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $credential = $user->credentials()->firstOrFail();

        $this->assertSame('ghp_secretvalue1234', $credential->token);
        $this->assertSame('1234', $credential->token_last_four);
        $this->assertSame('octocat', $credential->account_name);
        $this->assertSame(['repo', 'read:org'], $credential->scopes);
        $this->assertNotNull($credential->last_verified_at);

        $raw = DB::table('credentials')->where('id', $credential->id)->value('token');
        $this->assertStringNotContainsString('ghp_secretvalue1234', $raw);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer ghp_secretvalue1234'));
    }

    public function test_fine_grained_github_tokens_have_no_scope_list()
    {
        $this->fakeGitHubUser(scopes: null);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'github',
            'name' => 'Fine grained',
            'token' => 'github_pat_abcdefgh',
        ]);

        $this->assertNull($user->credentials()->firstOrFail()->scopes);
    }

    public function test_a_rejected_token_is_saved_with_the_error()
    {
        Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('tokens.store'), [
                'provider' => 'github',
                'name' => 'Broken',
                'token' => 'ghp_wrongtoken',
            ])
            ->assertSessionHasNoErrors();

        $credential = $user->credentials()->firstOrFail();

        $this->assertNull($credential->last_verified_at);
        $this->assertStringContainsString('rejected the token', $credential->last_error);
        $this->assertStringContainsString('Bad credentials', $credential->last_error);
    }

    public function test_anthropic_keys_are_checked_against_the_models_endpoint()
    {
        Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => []])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'anthropic',
            'name' => 'Claude key',
            'token' => 'sk-ant-testkey123',
        ]);

        $this->assertNotNull($user->credentials()->firstOrFail()->last_verified_at);

        Http::assertSent(fn (Request $request) => $request->hasHeader('x-api-key', 'sk-ant-testkey123')
            && $request->hasHeader('anthropic-version'));
    }

    public function test_self_hosted_gitlab_uses_the_custom_url()
    {
        Http::fake([
            'gitlab.example.com/api/v4/user' => Http::response(['username' => 'dev']),
            'gitlab.example.com/api/v4/personal_access_tokens/self' => Http::response(['scopes' => ['api']]),
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'gitlab',
            'name' => 'Company GitLab',
            'token' => 'glpat-abcdefghij',
            'base_url' => 'https://gitlab.example.com/',
        ])->assertSessionHasNoErrors();

        $credential = $user->credentials()->firstOrFail();

        $this->assertSame('https://gitlab.example.com', $credential->base_url);
        $this->assertSame('dev', $credential->account_name);
        $this->assertSame(['api'], $credential->scopes);
        Http::assertSent(fn (Request $request) => $request->hasHeader('PRIVATE-TOKEN', 'glpat-abcdefghij'));
    }

    public function test_custom_urls_must_be_https_and_only_for_gitlab()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'gitlab',
            'name' => 'Plain http',
            'token' => 'glpat-abcdefghij',
            'base_url' => 'http://gitlab.internal',
        ])->assertSessionHasErrors('base_url');

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'github',
            'name' => 'GitHub with URL',
            'token' => 'ghp_abcdefghij',
            'base_url' => 'https://example.com',
        ])->assertSessionHasErrors('base_url');

        $this->assertDatabaseCount('credentials', 0);
    }

    public function test_tokens_are_never_sent_to_the_browser()
    {
        $user = User::factory()->create();
        Credential::factory()->personal($user)->create(['token' => 'ghp_topsecretvalue']);

        $response = $this->actingAs($user)->get(route('tokens.index'));

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/tokens')
                ->has('credentials', 1)
                ->where('credentials.0.token_hint', '••••alue')
                ->missing('credentials.0.token'));

        $this->assertStringNotContainsString('ghp_topsecretvalue', $response->getContent());
    }

    public function test_admins_can_add_team_tokens_but_members_cannot()
    {
        $this->fakeGitHubUser();
        [$admin, $team] = $this->teamWith(TeamRole::Admin);
        $member = User::factory()->create();
        $team->members()->attach($member, ['role' => TeamRole::Member->value]);

        $payload = ['provider' => 'github', 'name' => 'Team bot', 'token' => 'ghp_teamtoken1'];

        $this->actingAs($member)->post(route('teams.tokens.store', $team), $payload)->assertForbidden();
        $this->actingAs($admin)->post(route('teams.tokens.store', $team), $payload)->assertSessionHasNoErrors();

        $this->assertSame(1, $team->credentials()->count());
    }

    public function test_members_can_view_team_tokens_without_managing_them()
    {
        [$member, $team] = $this->teamWith(TeamRole::Member);
        Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs($member)
            ->get(route('teams.tokens.index', $team))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('teams/tokens')
                ->has('credentials', 1)
                ->where('canManage', false));
    }

    public function test_outsiders_cannot_see_a_teams_tokens()
    {
        $outsider = User::factory()->create();
        $team = Team::factory()->create();

        $this->actingAs($outsider)->get(route('teams.tokens.index', $team))->assertForbidden();
    }

    public function test_only_the_owner_can_change_a_personal_token()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $credential = Credential::factory()->personal($owner)->create();

        $this->actingAs($other)->patch(route('credentials.update', $credential), ['name' => 'Hijack'])->assertForbidden();
        $this->actingAs($other)->delete(route('credentials.destroy', $credential))->assertForbidden();
        $this->actingAs($other)->post(route('credentials.verify', $credential))->assertForbidden();

        $this->assertModelExists($credential);
    }

    public function test_members_cannot_delete_team_tokens()
    {
        [$member, $team] = $this->teamWith(TeamRole::Member);
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs($member)->delete(route('credentials.destroy', $credential))->assertForbidden();

        $this->assertModelExists($credential);
    }

    public function test_renaming_does_not_call_the_provider_but_replacing_the_token_does()
    {
        Http::fake(['api.github.com/user' => Http::response(['login' => 'new-bot'])]);
        $user = User::factory()->create();
        $credential = Credential::factory()->personal($user)->create(['token' => 'ghp_oldtoken0000']);

        $this->actingAs($user)->patch(route('credentials.update', $credential), ['name' => 'Renamed'])->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->assertSame('Renamed', $credential->fresh()->name);

        $this->actingAs($user)->patch(route('credentials.update', $credential), [
            'name' => 'Renamed',
            'token' => 'ghp_newtoken9999',
        ])->assertSessionHasNoErrors();

        $credential->refresh();
        $this->assertSame('ghp_newtoken9999', $credential->token);
        $this->assertSame('9999', $credential->token_last_four);
        $this->assertSame('new-bot', $credential->account_name);
    }

    public function test_deleting_a_token_clears_it_from_projects()
    {
        [$owner, $team] = $this->teamWith(TeamRole::Owner);
        $credential = Credential::factory()->create(['team_id' => $team->id]);
        $project = Project::factory()->create(['team_id' => $team->id, 'git_credential_id' => $credential->id]);

        $this->actingAs($owner)->delete(route('credentials.destroy', $credential))->assertSessionHasNoErrors();

        $this->assertModelMissing($credential);
        $this->assertNull($project->fresh()->git_credential_id);
    }

    public function test_namespaces_lists_the_account_and_organizations()
    {
        Http::fake([
            'api.github.com/user' => Http::response(['login' => 'octocat']),
            'api.github.com/user/orgs*' => Http::response([['login' => 'acme'], ['login' => 'globex']]),
        ]);
        [$member, $team] = $this->teamWith(TeamRole::Member);
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs($member)
            ->getJson(route('credentials.namespaces', $credential))
            ->assertOk()
            ->assertExactJson(['namespaces' => [
                ['id' => 'octocat', 'path' => 'octocat', 'kind' => 'user'],
                ['id' => 'acme', 'path' => 'acme', 'kind' => 'org'],
                ['id' => 'globex', 'path' => 'globex', 'kind' => 'org'],
            ]]);
    }

    public function test_namespaces_are_hidden_from_outsiders_and_report_provider_errors()
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);
        [$member, $team] = $this->teamWith(TeamRole::Member);
        $credential = Credential::factory()->create(['team_id' => $team->id]);

        $this->actingAs(User::factory()->create())
            ->getJson(route('credentials.namespaces', $credential))
            ->assertForbidden();

        $this->actingAs($member)
            ->getJson(route('credentials.namespaces', $credential))
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'rejected the token'));
    }

    public function test_anthropic_keys_have_no_namespaces()
    {
        $user = User::factory()->create();
        $credential = Credential::factory()->personal($user)->anthropic()->create();

        $this->actingAs($user)->getJson(route('credentials.namespaces', $credential))->assertNotFound();
        $this->assertSame(CredentialProvider::Anthropic, $credential->provider);
    }
}
