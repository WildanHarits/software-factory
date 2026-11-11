<?php

namespace Tests\Feature\Runs;

use App\Models\Credential;
use App\Runner\Agent\AgentInvocation;
use App\Runner\Agent\LocalAgentRunner;
use App\Runner\GitClient;
use App\Runner\ProcessEnvironment;
use App\Runner\RunFailed;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The guarantees that keep secrets away from the agent and out of logs.
 */
class RunnerSecurityTest extends TestCase
{
    private function invocation(): AgentInvocation
    {
        return new AgentInvocation(
            workingDirectory: sys_get_temp_dir(),
            prompt: '/speckit-constitution Keep it simple',
            model: 'claude-sonnet-5-5',
            effort: 'high',
            apiKey: 'sk-ant-secret-key',
            configDirectory: '/tmp/claude-config',
            timeoutSeconds: 60,
            maxBudgetUsd: 2.5,
        );
    }

    public function test_the_app_environment_is_not_passed_to_child_processes()
    {
        putenv('DB_PASSWORD=super-secret');
        putenv('APP_KEY=base64:abc');
        $_ENV['MAIL_PASSWORD'] = 'mail-secret';

        try {
            $env = ProcessEnvironment::clean(['ANTHROPIC_API_KEY' => 'sk-ant-x']);
        } finally {
            putenv('DB_PASSWORD');
            putenv('APP_KEY');
            unset($_ENV['MAIL_PASSWORD']);
        }

        $this->assertFalse($env['DB_PASSWORD']);
        $this->assertFalse($env['APP_KEY']);
        $this->assertFalse($env['MAIL_PASSWORD']);
        $this->assertSame('sk-ant-x', $env['ANTHROPIC_API_KEY']);
        $this->assertIsString($env['PATH']);
        $this->assertStringContainsString('/.local/bin', $env['PATH']);
    }

    public function test_the_agent_runs_isolated_and_sandboxed()
    {
        $runner = app(LocalAgentRunner::class);
        $command = $runner->command($this->invocation());
        $settings = $runner->settings($this->invocation());

        $this->assertContains('--bare', $command);
        $this->assertSame('high', $command[array_search('--effort', $command) + 1]);
        $this->assertSame('claude-sonnet-5-5', $command[array_search('--model', $command) + 1]);
        $this->assertSame('none', $command[array_search('--permission-prompts', $command) + 1]);
        $this->assertSame('2.5', $command[array_search('--max-budget-usd', $command) + 1]);
        $this->assertSame('WebFetch,WebSearch', $command[array_search('--disallowedTools', $command) + 1]);
        $this->assertNotContains('--dangerously-skip-permissions', $command);

        $this->assertTrue($settings['sandbox']['enabled']);
        $this->assertTrue($settings['sandbox']['failIfUnavailable']);
        $this->assertFalse($settings['sandbox']['allowUnsandboxedCommands']);
        $this->assertContains(base_path(), $settings['sandbox']['filesystem']['denyRead']);
        $this->assertContains('Read(/'.base_path().'/**)', $settings['permissions']['deny']);

        // The API key travels in the environment only, never on the command line.
        $this->assertStringNotContainsString('sk-ant-secret-key', implode(' ', $command));
    }

    public function test_the_agent_gets_its_key_and_own_config_folder_through_the_environment()
    {
        Process::fake(['*' => Process::result(output: json_encode([
            'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 's1',
            'total_cost_usd' => 0.05, 'num_turns' => 2, 'result' => 'ok',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50, 'cache_read_input_tokens' => 10, 'cache_creation_input_tokens' => 5],
        ])."\n")]);

        $result = app(LocalAgentRunner::class)->run($this->invocation(), fn () => null, fn () => false);

        $this->assertTrue($result->successful);
        $this->assertSame('s1', $result->sessionId);
        $this->assertSame(0.05, $result->costUsd);
        $this->assertSame(100, $result->inputTokens);
        $this->assertSame(10, $result->cacheReadTokens);

        Process::assertRan(fn (PendingProcess $process) => $process->environment['ANTHROPIC_API_KEY'] === 'sk-ant-secret-key'
            && $process->environment['CLAUDE_CONFIG_DIR'] === '/tmp/claude-config'
            && $process->path === sys_get_temp_dir());
    }

    public function test_a_failed_agent_reports_why()
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'claude: command not found', exitCode: 127)]);

        $result = app(LocalAgentRunner::class)->run($this->invocation(), fn () => null, fn () => false);

        $this->assertFalse($result->successful);
        $this->assertStringContainsString('without a result', (string) $result->error);
    }

    public function test_git_gets_the_token_through_askpass_never_in_arguments()
    {
        Process::fake(['*' => Process::result(output: 'ok')]);
        $credential = Credential::factory()->make(['team_id' => null, 'token' => 'ghp_verysecret']);

        app(GitClient::class)->run(['fetch', 'origin'], sys_get_temp_dir(), $credential);

        Process::assertRan(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return ! str_contains($command, 'ghp_verysecret')
                && str_contains($command, 'credential.helper=')
                && $process->environment['FACTORY_GIT_PASSWORD'] === 'ghp_verysecret'
                && $process->environment['FACTORY_GIT_USERNAME'] === 'x-access-token'
                && $process->environment['GIT_TERMINAL_PROMPT'] === '0'
                && is_file($process->environment['GIT_ASKPASS']);
        });

        $script = (string) file_get_contents(storage_path('app/private/runner/git-askpass.sh'));
        $this->assertStringNotContainsString('ghp_verysecret', $script);
    }

    public function test_gitlab_uses_the_oauth2_username_and_errors_hide_the_token()
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'fatal: could not read from https://oauth2:glpat-secret123@gitlab.com', exitCode: 128)]);
        $credential = Credential::factory()->gitlab()->make(['team_id' => null, 'token' => 'glpat-secret123']);

        try {
            app(GitClient::class)->run(['fetch'], sys_get_temp_dir(), $credential);
            $this->fail('Expected git to fail.');
        } catch (RunFailed $exception) {
            $this->assertStringNotContainsString('glpat-secret123', $exception->getMessage());
            $this->assertStringContainsString('***', $exception->getMessage());
        }

        Process::assertRan(fn (PendingProcess $process) => $process->environment['FACTORY_GIT_USERNAME'] === 'oauth2');
    }
}
