<?php

namespace App\Runs\Pipelines;

use App\Models\Run;
use App\Runner\ProcessEnvironment;
use App\Runner\RunCancelled;
use App\Runner\RunFailed;
use App\Runner\RunRecorder;
use App\Runner\StreamingProcess;

/**
 * Runs `specify init` on a new branch, optionally writes the constitution with the agent,
 * and opens a pull request with the result.
 */
class SetupSpecKit extends Pipeline
{
    public function handle(Run $run, RunRecorder $log): void
    {
        $principles = trim((string) ($run->input['principles'] ?? ''));

        if ($principles !== '') {
            // Fail early, before cloning, if the agent step can't run.
            $this->aiCredential($run->project);
        }

        $worktree = $this->checkout($run, $log, "software-factory/setup-spec-kit-{$run->id}");

        try {
            $this->initialize($run, $log, $worktree);

            if ($principles !== '') {
                $log->stage('Writing the constitution');
                $this->runAgent($run, $log, $worktree, $this->command('constitution').' '.$principles);
            }

            $version = config('factory.speckit.version');

            $this->commitAndOpenPullRequest(
                $run,
                $log,
                $worktree,
                "Set up Spec Kit {$version}",
                'Set up Spec Kit',
                implode("\n\n", [
                    "Adds [Spec Kit](https://github.com/github/spec-kit) {$version} with the Claude integration (`.specify/` and `.claude/skills/`).",
                    $principles !== '' ? "The constitution (`.specify/memory/constitution.md`) was written from these principles:\n\n> ".str_replace("\n", "\n> ", $principles) : 'The constitution is still the template; update it from Software Factory once this is merged.',
                    'Opened by Software Factory.',
                ]),
            );
        } finally {
            $this->workspaces->removeWorktree($run->project, $worktree);
        }
    }

    /**
     * Drop the CLI's ASCII-art banner and the box borders around its panels.
     * Uses a Unicode-aware pattern: trim() works on bytes and would cut characters like ✓ in half.
     */
    public static function tidy(string $line): ?string
    {
        $text = RunRecorder::stripAnsi($line);

        // Banner art is drawn with block and double-line box characters only.
        if (str_contains($text, '█') || preg_match('/^[\s╔╗╚╝═║╠╣╦╩╬]+$/u', $text)) {
            return null;
        }

        $text = (string) preg_replace('/^[\s│┃╭╮╰╯─━┏┓┗┛]+|[\s│┃╭╮╰╯─━┏┓┗┛]+$/u', '', $text);

        return $text === '' ? null : $text;
    }

    private function initialize(Run $run, RunRecorder $log, string $worktree): void
    {
        $this->throwIfCancelled($run);
        $log->stage('Installing Spec Kit '.config('factory.speckit.version'));

        $outcome = app(StreamingProcess::class)->run(
            command: [
                (string) config('factory.runner.binaries.specify'),
                'init', '--here', '--force', '--non-interactive',
                '--integration', (string) config('factory.speckit.integration'),
                '--script', 'sh',
            ],
            cwd: $worktree,
            // A wide terminal keeps the CLI from wrapping lines at 80 columns.
            env: ProcessEnvironment::clean(['NO_COLOR' => '1', 'COLUMNS' => '160']),
            timeoutSeconds: 600,
            onLine: function (string $line) use ($log) {
                $clean = self::tidy($line);

                if ($clean !== null) {
                    $log->output($clean);
                }
            },
            shouldCancel: fn () => $run->cancelRequested(),
        );

        if ($outcome->cancelled) {
            throw new RunCancelled;
        }

        $this->throwIfCancelled($run);

        if (! $outcome->successful()) {
            throw new RunFailed(trim("specify init failed (exit code {$outcome->exitCode}). ".$outcome->errorOutput));
        }
    }
}
