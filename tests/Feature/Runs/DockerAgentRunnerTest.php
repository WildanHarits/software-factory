<?php

namespace Tests\Feature\Runs;

use App\Runner\Agent\AgentInvocation;
use App\Runner\Agent\AgentRunner;
use App\Runner\Agent\DockerAgentRunner;
use App\Runner\RunnerHealth;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DockerAgentRunnerTest extends TestCase
{
    private function invocation(): AgentInvocation
    {
        return new AgentInvocation(
            workingDirectory: '/srv/ws/team-1/project-2/worktrees/ticket-3',
            prompt: '/speckit-plan',
            model: 'claude-sonnet-5-5',
            effort: 'medium',
            apiKey: 'sk-ant-secret',
            configDirectory: '/srv/ws/team-1/project-2/claude-config',
            timeoutSeconds: 60,
            environment: ['SPECIFY_FEATURE_DIRECTORY' => 'specs/001-x'],
        );
    }

    public function test_the_driver_is_selected_by_config()
    {
        config(['factory.runner.driver' => 'docker']);

        $this->assertInstanceOf(DockerAgentRunner::class, app(AgentRunner::class));
    }

    public function test_each_run_gets_a_locked_down_container()
    {
        config(['factory.runner.docker.image' => 'my-runner:1', 'factory.runner.docker.memory' => '2g']);
        $runner = app(DockerAgentRunner::class);
        $invocation = $this->invocation();

        $docker = $runner->dockerArguments($invocation, 'sf-agent-test');
        $joined = implode(' ', $docker);

        $this->assertSame(['docker', 'run', '--rm', '--init'], array_slice($docker, 0, 4));
        $this->assertStringContainsString('--name sf-agent-test', $joined);
        $this->assertStringContainsString('--user '.posix_getuid().':'.posix_getgid(), $joined);
        $this->assertStringContainsString('--cap-drop ALL', $joined);
        $this->assertStringContainsString('--security-opt no-new-privileges', $joined);
        $this->assertStringContainsString('--memory 2g', $joined);
        $this->assertStringContainsString("--volume {$invocation->workingDirectory}:{$invocation->workingDirectory}", $joined);
        $this->assertStringContainsString("--volume {$invocation->configDirectory}:{$invocation->configDirectory}", $joined);
        $this->assertStringContainsString("--workdir {$invocation->workingDirectory}", $joined);
        $this->assertSame('my-runner:1', end($docker));

        // Secrets are passed by name only; the values never appear in the command line.
        $this->assertStringContainsString('--env ANTHROPIC_API_KEY', $joined);
        $this->assertStringContainsString('--env SPECIFY_FEATURE_DIRECTORY', $joined);
        $this->assertStringNotContainsString('sk-ant-secret', $joined);

        // The app folder is never mounted.
        $this->assertStringNotContainsString(base_path(), $joined);
    }

    public function test_claude_runs_inside_with_the_container_as_its_sandbox()
    {
        $runner = app(DockerAgentRunner::class);

        $this->assertSame('claude', $runner->command($this->invocation())[0]);
        $this->assertSame(['enabled' => false], $runner->settings($this->invocation())['sandbox']);
        $this->assertNotEmpty($runner->settings($this->invocation())['permissions']['deny']);
    }

    public function test_the_docker_cli_receives_the_secret_values_in_its_environment()
    {
        Process::fake(['*' => Process::result(output: json_encode(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'ok', 'session_id' => 's'])."\n")]);

        $result = app(DockerAgentRunner::class)->run($this->invocation(), fn () => null, fn () => false);

        $this->assertTrue($result->successful);
        Process::assertRan(fn (PendingProcess $process) => ($process->command[0] ?? null) === 'docker'
            && $process->environment['ANTHROPIC_API_KEY'] === 'sk-ant-secret'
            && ! str_contains(implode(' ', (array) $process->command), 'sk-ant-secret'));
        Process::assertNotRan(fn (PendingProcess $process) => in_array('rm', (array) $process->command, true));
    }

    public function test_a_cancelled_run_removes_its_container()
    {
        Process::fake(['*' => Process::describe()->output('')->runsFor(iterations: 100)]);

        $result = app(DockerAgentRunner::class)->run($this->invocation(), fn () => null, fn () => true);

        $this->assertTrue($result->cancelled);
        Process::assertRan(fn (PendingProcess $process) => array_slice((array) $process->command, 0, 3) === ['docker', 'rm', '--force']
            && str_starts_with((string) ($process->command[3] ?? ''), 'sf-agent-'));
    }

    public function test_the_health_check_looks_for_docker_and_the_image()
    {
        config(['factory.runner.driver' => 'docker', 'factory.runner.docker.image' => 'missing:1']);
        Process::fake(function (PendingProcess $process) {
            $command = implode(' ', (array) $process->command);

            return match (true) {
                str_contains($command, 'docker version') => Process::result(output: "27.1.0\n"),
                str_contains($command, 'image inspect') => Process::result(exitCode: 1),
                default => Process::result(output: 'specify 1.4.0'),
            };
        });

        $health = app(RunnerHealth::class);
        $health->forget();
        $docker = collect($health->checks())->firstWhere('key', 'docker');

        $this->assertFalse($docker['ok']);
        $this->assertStringContainsString('docker build -t missing:1 docker/runner', $docker['detail']);
        $this->assertNull(collect($health->checks())->firstWhere('key', 'claude'));
    }
}
