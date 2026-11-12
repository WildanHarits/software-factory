<?php

namespace Tests\Feature\Credentials;

use App\Enums\CredentialProvider;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_a_claude_subscription_is_saved_as_a_personal_token_without_calling_anthropic()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'claude_subscription',
            'name' => 'My Max plan',
            'token' => 'sk-ant-oat01-abcdefghijklmnop',
        ])->assertSessionHasNoErrors();

        $credential = $user->credentials()->firstOrFail();
        $this->assertSame(CredentialProvider::ClaudeSubscription, $credential->provider);
        $this->assertNotNull($credential->last_verified_at);
        $this->assertSame('Claude subscription', $credential->account_name);
        Http::assertNothingSent();
    }

    public function test_line_breaks_copied_from_the_terminal_are_removed_from_tokens()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'claude_subscription',
            'name' => 'Wrapped',
            'token' => "  sk-ant-oat01-abcdefgh\nijklmnop\r\nqrstuv  ",
        ])->assertSessionHasNoErrors();

        $credential = $user->credentials()->firstOrFail();
        $this->assertSame('sk-ant-oat01-abcdefghijklmnopqrstuv', $credential->token);

        $this->actingAs($user)->patch(route('credentials.update', $credential), [
            'name' => 'Wrapped',
            'token' => "sk-ant-oat01-new\n token",
        ])->assertSessionHasNoErrors();

        $this->assertSame('sk-ant-oat01-newtoken', $credential->fresh()->token);
    }

    public function test_a_subscription_token_must_look_like_one()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'claude_subscription',
            'name' => 'Wrong',
            'token' => 'sk-ant-api03-thisisanapikey',
        ])->assertSessionHasErrors('token');

        $this->assertSame(0, $user->credentials()->count());
    }

    public function test_a_subscription_cannot_be_a_team_token_and_is_not_offered_there()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create();
        $team->members()->attach($owner, ['role' => TeamRole::Owner->value]);

        $this->actingAs($owner)->post(route('teams.tokens.store', $team), [
            'provider' => 'claude_subscription',
            'name' => 'Shared plan',
            'token' => 'sk-ant-oat01-abcdefghijklmnop',
        ])->assertSessionHasErrors('provider');

        $this->assertSame(0, $team->credentials()->count());

        $this->actingAs($owner)->get(route('teams.tokens.index', $team))
            ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($providers) => ! collect($providers)->contains('value', 'claude_subscription')));

        $this->actingAs($owner)->get(route('tokens.index'))
            ->assertInertia(fn (Assert $page) => $page->where('providers', fn ($providers) => collect($providers)->contains('value', 'claude_subscription')));
    }

    public function test_meta_keys_are_checked_against_its_models_endpoint()
    {
        Http::fake(['api.meta.ai/v1/models' => Http::response(['data' => []])]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'meta',
            'name' => 'Muse',
            'token' => 'meta-testkey-123',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($user->credentials()->firstOrFail()->last_verified_at);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer meta-testkey-123'));
    }

    public function test_qwen_keys_need_their_plan_and_are_checked_with_a_tiny_message()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'qwen', 'name' => 'Qwen', 'token' => 'sk-qwen-123456',
        ])->assertSessionHasErrors('base_url');

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'qwen', 'name' => 'Qwen', 'token' => 'sk-qwen-123456', 'base_url' => 'https://evil.example.com',
        ])->assertSessionHasErrors('base_url');

        Http::fake(['coding-intl.dashscope.aliyuncs.com/*' => Http::response(['id' => 'msg_1'])]);

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'qwen',
            'name' => 'Qwen',
            'token' => 'sk-qwen-123456',
            'base_url' => 'https://coding-intl.dashscope.aliyuncs.com/apps/anthropic',
        ])->assertSessionHasNoErrors();

        $credential = $user->credentials()->firstOrFail();
        $this->assertNotNull($credential->last_verified_at);
        $this->assertSame('Coding Plan', $credential->account_name);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://coding-intl.dashscope.aliyuncs.com/apps/anthropic/v1/messages'
            && $request->hasHeader('Authorization', 'Bearer sk-qwen-123456')
            && $request['model'] === 'qwen3.7-plus'
            && $request['max_tokens'] === 1);
    }

    public function test_other_providers_cannot_have_a_custom_url()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('tokens.store'), [
            'provider' => 'meta', 'name' => 'Muse', 'token' => 'meta-testkey-123', 'base_url' => 'https://proxy.example.com',
        ])->assertSessionHasErrors('base_url');
    }
}
