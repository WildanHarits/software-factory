<?php

namespace App\Runner\Agent;

use App\Runner\ProcessEnvironment;
use App\Runner\ProcessOutcome;
use App\Runner\StreamingProcess;

/**
 * Runs Claude Code on this machine.
 *
 * Isolation:
 * - --bare: no personal settings, hooks, plugins, memory or keychain login; only the project's API key.
 * - Its own config folder per project (CLAUDE_CONFIG_DIR), never ~/.claude.
 * - Claude Code's sandbox (macOS Seatbelt / Linux bubblewrap) for shell commands: writes only in the
 *   workspace, network only to allowed domains, and the app folder (.env) and common secret folders unreadable.
 * - Anything that would ask for permission is denied, and web tools are off.
 * - The git token is never given to the agent; the app commits and pushes afterwards.
 */
class LocalAgentRunner implements AgentRunner
{
    public function __construct(private StreamingProcess $processes)
    {
        //
    }

    public function run(AgentInvocation $invocation, callable $onEvent, callable $shouldCancel): AgentResult
    {
        $parser = new StreamJsonParser;
        $stderr = [];

        $outcome = $this->processes->run(
            command: $this->processCommand($invocation),
            cwd: $invocation->workingDirectory,
            env: ProcessEnvironment::clean($this->agentEnvironment($invocation)),
            timeoutSeconds: $invocation->timeoutSeconds,
            onLine: function (string $line, string $stream) use ($parser, $onEvent, &$stderr) {
                if ($stream === 'err') {
                    $stderr[] = $line;
                    $stderr = array_slice($stderr, -20);

                    return;
                }

                foreach ($parser->parse($line) as $event) {
                    $onEvent($event['kind'], $event['title'], $event['body']);
                }
            },
            shouldCancel: $shouldCancel,
        );

        $this->afterProcess($outcome);

        $result = $parser->result ?? [];
        $usage = is_array($result['usage'] ?? null) ? $result['usage'] : [];
        $isError = (bool) ($result['is_error'] ?? true);

        return new AgentResult(
            successful: $outcome->successful() && $parser->result !== null && ! $isError,
            sessionId: $parser->sessionId,
            costUsd: (float) ($result['total_cost_usd'] ?? 0),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWriteTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
            numTurns: isset($result['num_turns']) ? (int) $result['num_turns'] : null,
            resultText: is_string($result['result'] ?? null) ? $result['result'] : null,
            error: $this->error($outcome->timedOut, $parser->result, $outcome->exitCode, $stderr),
            cancelled: $outcome->cancelled,
            timedOut: $outcome->timedOut,
            permissionDenials: is_array($result['permission_denials'] ?? null) ? $result['permission_denials'] : [],
        );
    }

    /**
     * What is started: Claude Code itself here; drivers can wrap it (e.g. in `docker run`).
     *
     * @return array<int, string>
     */
    protected function processCommand(AgentInvocation $invocation): array
    {
        return $this->command($invocation);
    }

    /**
     * Variables Claude Code needs. Everything else from the app's environment is removed.
     *
     * @return array<string, string>
     */
    public function agentEnvironment(AgentInvocation $invocation): array
    {
        return [
            ...$invocation->environment,
            'ANTHROPIC_API_KEY' => $invocation->apiKey,
            'CLAUDE_CONFIG_DIR' => $invocation->configDirectory,
            'DISABLE_AUTOUPDATER' => '1',
            'CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC' => '1',
        ];
    }

    protected function claudeBinary(): string
    {
        return (string) config('factory.runner.binaries.claude');
    }

    protected function afterProcess(ProcessOutcome $outcome): void
    {
        //
    }

    /**
     * The Claude Code command line.
     *
     * @return array<int, string>
     */
    public function command(AgentInvocation $invocation): array
    {
        $command = [
            $this->claudeBinary(),
            '-p', $invocation->prompt,
            '--bare',
            '--output-format', 'stream-json',
            '--verbose',
            '--model', $invocation->model,
            '--effort', $invocation->effort,
            '--permission-mode', 'acceptEdits',
            '--permission-prompts', 'none',
            '--settings', json_encode($this->settings($invocation), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ];

        $disallowed = (array) config('factory.runner.disallowed_tools', []);

        if ($disallowed !== []) {
            array_push($command, '--disallowedTools', implode(',', $disallowed));
        }

        if ($invocation->maxBudgetUsd !== null && $invocation->maxBudgetUsd > 0) {
            array_push($command, '--max-budget-usd', (string) $invocation->maxBudgetUsd);
        }

        if ($invocation->resumeSessionId) {
            array_push($command, '--resume', $invocation->resumeSessionId);
        }

        if ($invocation->appendSystemPrompt) {
            array_push($command, '--append-system-prompt', $invocation->appendSystemPrompt);
        }

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(AgentInvocation $invocation): array
    {
        $appPath = base_path();
        $secretFolders = ['~/.ssh', '~/.aws', '~/.config/gh', '~/.gnupg', '~/.kube', '~/.docker', '~/.claude', '~/Library/Keychains'];

        return [
            'sandbox' => [
                'enabled' => true,
                // Refuse to run at all rather than run unsandboxed.
                'failIfUnavailable' => true,
                'allowUnsandboxedCommands' => false,
                'autoAllowBashIfSandboxed' => true,
                'network' => [
                    'allowedDomains' => array_values((array) config('factory.runner.allowed_domains', [])),
                ],
                'filesystem' => [
                    'denyRead' => [$appPath, ...$secretFolders],
                ],
            ],
            'permissions' => [
                'deny' => [
                    "Read(/{$appPath}/**)",
                    "Edit(/{$appPath}/**)",
                    ...array_map(fn (string $folder) => "Read({$folder}/**)", $secretFolders),
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $result
     * @param  array<int, string>  $stderr
     */
    private function error(bool $timedOut, ?array $result, int $exitCode, array $stderr): ?string
    {
        if ($timedOut) {
            return 'The agent took longer than the time limit and was stopped.';
        }

        if ($result !== null && ! empty($result['is_error'])) {
            $detail = is_string($result['result'] ?? null) ? trim($result['result']) : '';
            $subtype = (string) ($result['subtype'] ?? '');

            // Errors such as an invalid API key arrive with subtype "success" and the message in `result`.
            if ($subtype === '' || $subtype === 'success') {
                return $detail !== '' ? "The agent stopped: {$detail}" : 'The agent stopped with an error.';
            }

            return trim('The agent stopped ('.str_replace('_', ' ', $subtype).'). '.$detail);
        }

        if ($result === null) {
            $tail = trim(implode("\n", array_slice($stderr, -5)));

            return trim("Claude Code exited (code {$exitCode}) without a result. {$tail}");
        }

        return null;
    }
}
