<?php

namespace App\Runner\Agent;

use Illuminate\Support\Facades\File;

/**
 * Pretends to be Claude Code: writes placeholder Spec Kit files for each phase, costs nothing.
 *
 * For trying the board and developing the app without an Anthropic key
 * (FACTORY_RUNNER_DRIVER=simulated). Never used in production.
 */
class SimulatedAgentRunner implements AgentRunner
{
    public function run(AgentInvocation $invocation, callable $onEvent, callable $shouldCancel): AgentResult
    {
        $onEvent('system', 'Simulated agent started (no AI is used)', null);

        $folder = $invocation->environment['SPECIFY_FEATURE_DIRECTORY'] ?? null;
        $dir = $folder ? rtrim($invocation->workingDirectory, '/').'/'.$folder : null;
        $prompt = trim($invocation->prompt);
        $command = str_starts_with($prompt, '/') ? strtok($prompt, " \n") : null;

        usleep(700_000);

        if ($shouldCancel()) {
            return new AgentResult(successful: false, cancelled: true);
        }

        $message = match (true) {
            $command === null => $this->reply($dir, $prompt),
            str_ends_with($command, 'specify') => $this->write($dir, 'spec.md', "# Feature specification\n\n".trim(substr($prompt, strlen($command)))."\n\n## User scenarios\n\n1. A person can use the feature.\n\n## Requirements\n\n- **FR-001**: The system must do what was described.\n", 'Wrote spec.md.'),
            str_ends_with($command, 'clarify') => "I have two questions before planning:\n\n1. Who can use this feature: everyone or only signed-in people?\n2. Is there a limit on how many items a person can create?",
            str_ends_with($command, 'plan') => $this->write($dir, 'plan.md', "# Implementation plan\n\n## Technical context\n\n".(trim(substr($prompt, strlen($command))) ?: 'Use the existing stack.')."\n\n## Structure\n\n- Backend endpoint\n- Frontend page\n", 'Wrote plan.md.', ['research.md' => "# Research\n\nNo unknowns left.\n"]),
            str_ends_with($command, 'tasks') => $this->write($dir, 'tasks.md', "# Tasks\n\n- [ ] T001 Create the model\n- [ ] T002 Add the endpoint\n- [ ] T003 Build the page\n", 'Wrote tasks.md with 3 tasks.'),
            str_ends_with($command, 'analyze') => "## Analysis report\n\n| Check | Result |\n| --- | --- |\n| Requirements covered by tasks | 1/1 |\n| Conflicts | none |\n\nNo critical issues.",
            str_ends_with($command, 'implement') => $this->implement($invocation->workingDirectory, $dir),
            default => 'Done.',
        };

        $onEvent('assistant', null, $message);
        $onEvent('result', 'Agent finished · $0.0000', $message);

        return new AgentResult(
            successful: true,
            sessionId: $invocation->resumeSessionId ?? 'simulated-'.bin2hex(random_bytes(6)),
            numTurns: 1,
            resultText: $message,
        );
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function write(?string $dir, string $file, string $content, string $message, array $extra = []): string
    {
        if ($dir === null) {
            return 'No feature folder was given.';
        }

        File::ensureDirectoryExists($dir);
        File::put("{$dir}/{$file}", $content);

        foreach ($extra as $name => $body) {
            File::put("{$dir}/{$name}", $body);
        }

        return $message;
    }

    private function reply(?string $dir, string $text): string
    {
        if ($dir && is_file("{$dir}/spec.md")) {
            File::append("{$dir}/spec.md", "\n## Clarifications\n\n- {$text}\n");

            return 'Thanks, I added your answers to spec.md.';
        }

        return 'Noted.';
    }

    private function implement(string $root, ?string $dir): string
    {
        if ($dir && is_file("{$dir}/tasks.md")) {
            File::put("{$dir}/tasks.md", str_replace('- [ ]', '- [x]', (string) File::get("{$dir}/tasks.md")));
        }

        File::put(rtrim($root, '/').'/SIMULATED_FEATURE.md', "This file was written by the simulated agent.\n");

        return 'Implemented all tasks (simulated).';
    }
}
