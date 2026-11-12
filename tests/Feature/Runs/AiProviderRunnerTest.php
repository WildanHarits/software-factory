<?php

namespace Tests\Feature\Runs;

use App\Enums\CredentialProvider;
use App\Runner\Agent\AgentInvocation;
use App\Runner\Agent\LocalAgentRunner;
use App\Runner\Agent\ProviderModels;
use Tests\TestCase;

/**
 * How Claude Code is started for each kind of AI token.
 */
class AiProviderRunnerTest extends TestCase
{
    private function invocation(CredentialProvider $provider, string $token, ?string $baseUrl = null, string $model = 'claude-sonnet-5-5'): AgentInvocation
    {
        return new AgentInvocation(
            workingDirectory: sys_get_temp_dir(),
            prompt: '/speckit-specify Add a cart',
            model: $model,
            effort: 'high',
            apiKey: $token,
            configDirectory: '/tmp/claude-config',
            timeoutSeconds: 60,
            maxBudgetUsd: 5,
            provider: $provider,
            baseUrl: $baseUrl,
        );
    }

    /**
     * The value after a flag on the command line.
     *
     * @param  array<int, string>  $command
     */
    private function flag(array $command, string $flag): ?string
    {
        $index = array_search($flag, $command, true);

        return $index === false ? null : $command[$index + 1];
    }

    public function test_an_anthropic_key_runs_in_bare_mode_with_the_api_key()
    {
        $runner = app(LocalAgentRunner::class);
        $invocation = $this->invocation(CredentialProvider::Anthropic, 'sk-ant-key');
        $command = $runner->command($invocation);
        $env = $runner->agentEnvironment($invocation);

        $this->assertContains('--bare', $command);
        $this->assertSame('claude-sonnet-5-5', $this->flag($command, '--model'));
        $this->assertSame('high', $this->flag($command, '--effort'));
        $this->assertSame('5', $this->flag($command, '--max-budget-usd'));
        $this->assertSame('sk-ant-key', $env['ANTHROPIC_API_KEY']);
        $this->assertArrayNotHasKey('ANTHROPIC_BASE_URL', $env);
        $this->assertArrayNotHasKey('CLAUDE_CODE_OAUTH_TOKEN', $env);
    }

    public function test_a_subscription_signs_in_with_the_oauth_token_and_keeps_the_repository_from_adding_hooks_or_servers()
    {
        $runner = app(LocalAgentRunner::class);
        $invocation = $this->invocation(CredentialProvider::ClaudeSubscription, 'sk-ant-oat01-token');
        $command = $runner->command($invocation);
        $env = $runner->agentEnvironment($invocation);
        $settings = json_decode((string) $this->flag($command, '--settings'), true);

        // Bare mode ignores subscription logins, so it's replaced by explicit switches.
        $this->assertNotContains('--bare', $command);
        $this->assertSame('user', $this->flag($command, '--setting-sources'));
        $this->assertContains('--strict-mcp-config', $command);
        $this->assertTrue($settings['disableAllHooks']);
        $this->assertTrue($settings['sandbox']['enabled']);
        $this->assertTrue($settings['sandbox']['failIfUnavailable']);

        $this->assertSame('sk-ant-oat01-token', $env['CLAUDE_CODE_OAUTH_TOKEN']);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertSame('high', $this->flag($command, '--effort'));

        // No per-token bill, so no dollar budget.
        $this->assertNull($this->flag($command, '--max-budget-usd'));
    }

    public function test_meta_uses_its_anthropic_endpoint_and_its_own_models()
    {
        $runner = app(LocalAgentRunner::class);
        $invocation = $this->invocation(CredentialProvider::Meta, 'meta-key', model: 'claude-opus-5-5');
        $command = $runner->command($invocation);
        $env = $runner->agentEnvironment($invocation);

        $this->assertContains('--bare', $command);
        $this->assertSame('muse-wonder-1.0', $this->flag($command, '--model'));
        $this->assertNull($this->flag($command, '--effort'));

        $this->assertSame('https://api.meta.ai', $env['ANTHROPIC_BASE_URL']);
        $this->assertSame('meta-key', $env['ANTHROPIC_AUTH_TOKEN']);
        $this->assertArrayNotHasKey('ANTHROPIC_API_KEY', $env);
        $this->assertSame('muse-wonder-1.0', $env['ANTHROPIC_DEFAULT_OPUS_MODEL']);
        $this->assertSame('muse-spark-1.3', $env['ANTHROPIC_DEFAULT_SONNET_MODEL']);
        $this->assertSame('muse-ember-1.0', $env['ANTHROPIC_DEFAULT_HAIKU_MODEL']);
        $this->assertSame('muse-ember-1.0', $env['CLAUDE_CODE_SUBAGENT_MODEL']);
    }

    public function test_qwen_uses_the_endpoint_and_models_of_the_keys_plan()
    {
        $runner = app(LocalAgentRunner::class);
        $coding = 'https://coding-intl.dashscope.aliyuncs.com/apps/anthropic';
        $invocation = $this->invocation(CredentialProvider::Qwen, 'sk-qwen', $coding, 'claude-haiku-5-5');

        $this->assertSame('qwen3.7-plus', $this->flag($runner->command($invocation), '--model'));
        $this->assertSame($coding, $runner->agentEnvironment($invocation)['ANTHROPIC_BASE_URL']);

        // An unknown endpoint falls back to the first (pay-as-you-go international).
        $fallback = $this->invocation(CredentialProvider::Qwen, 'sk-qwen', 'https://evil.example.com', 'claude-haiku-5-5');
        $this->assertSame('https://dashscope-intl.aliyuncs.com/apps/anthropic', $runner->agentEnvironment($fallback)['ANTHROPIC_BASE_URL']);
        $this->assertSame('qwen3.6-flash', $this->flag($runner->command($fallback), '--model'));
    }

    public function test_claude_models_map_to_tiers()
    {
        $this->assertSame('opus', ProviderModels::tier('claude-opus-5-5'));
        $this->assertSame('sonnet', ProviderModels::tier('claude-sonnet-5-5'));
        $this->assertSame('haiku', ProviderModels::tier('claude-haiku-5-5'));
        $this->assertSame('opus', ProviderModels::tier('claude-fable-5-2'));
    }
}
