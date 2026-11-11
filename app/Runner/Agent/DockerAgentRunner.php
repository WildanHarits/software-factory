<?php

namespace App\Runner\Agent;

use App\Runner\ProcessOutcome;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Runs Claude Code inside a fresh container for every run (for servers).
 *
 * The container only sees the run's worktree and the project's Claude config folder, mounted at
 * the same paths as on the host (so session ids and feature folders line up). It runs as the app's
 * user, with no Linux capabilities, no privilege escalation, limited memory/CPU/processes, and a
 * private /tmp. Secrets are passed by name (`-e NAME`), so their values never show in `ps`.
 *
 * Claude Code's own sandbox is turned off here: the container is the sandbox.
 */
class DockerAgentRunner extends LocalAgentRunner
{
    private ?string $containerName = null;

    protected function processCommand(AgentInvocation $invocation): array
    {
        $this->containerName = 'sf-agent-'.Str::lower(Str::random(12));

        return [...$this->dockerArguments($invocation, $this->containerName), ...$this->command($invocation)];
    }

    /**
     * @return array<int, string>
     */
    public function dockerArguments(AgentInvocation $invocation, string $name): array
    {
        $config = (array) config('factory.runner.docker');

        $arguments = [
            (string) ($config['binary'] ?? 'docker'), 'run', '--rm', '--init',
            '--name', $name,
            '--user', posix_getuid().':'.posix_getgid(),
            '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges',
            '--pids-limit', (string) ($config['pids_limit'] ?? 512),
            '--memory', (string) ($config['memory'] ?? '4g'),
            '--cpus', (string) ($config['cpus'] ?? '2'),
            '--network', (string) ($config['network'] ?? 'bridge'),
            '--tmpfs', '/tmp:rw,exec,size=1g',
            '--workdir', $invocation->workingDirectory,
            '--volume', "{$invocation->workingDirectory}:{$invocation->workingDirectory}",
            '--volume', "{$invocation->configDirectory}:{$invocation->configDirectory}",
            '--env', 'HOME=/tmp',
        ];

        // Values come from the docker CLI's own environment (see agentEnvironment()).
        foreach (array_keys($this->agentEnvironment($invocation)) as $variable) {
            array_push($arguments, '--env', $variable);
        }

        $arguments[] = (string) ($config['image'] ?? 'software-factory-runner:latest');

        return $arguments;
    }

    protected function claudeBinary(): string
    {
        return 'claude';
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(AgentInvocation $invocation): array
    {
        $settings = parent::settings($invocation);

        $settings['sandbox'] = ['enabled' => false];

        return $settings;
    }

    /**
     * Make sure a cancelled or timed-out container is really gone.
     */
    protected function afterProcess(ProcessOutcome $outcome): void
    {
        if ($this->containerName && ($outcome->cancelled || $outcome->timedOut)) {
            Process::timeout(30)->run([(string) config('factory.runner.docker.binary', 'docker'), 'rm', '--force', $this->containerName]);
        }

        $this->containerName = null;
    }
}
