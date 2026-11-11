<?php

namespace App\Runner;

use App\Enums\CredentialProvider;
use App\Models\Credential;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Runs git with a provider token.
 *
 * The token is handed to git through GIT_ASKPASS and environment variables only: it never
 * appears in a URL, a command argument, git config or the macOS keychain.
 */
class GitClient
{
    /**
     * @param  array<int, string>  $args
     * @param  (callable(string $line, string $stream): void)|null  $onLine
     *
     * @throws RunFailed
     */
    public function run(array $args, string $cwd, ?Credential $credential = null, ?callable $onLine = null, int $timeout = 600): string
    {
        $command = [
            (string) config('factory.runner.binaries.git'),
            // Ignore credential helpers (e.g. osxkeychain), so your own git login is never used or overwritten.
            '-c', 'credential.helper=',
            '-c', 'core.hooksPath=/dev/null',
            ...$args,
        ];

        $result = Process::path($cwd)
            ->env(ProcessEnvironment::clean($this->credentialEnv($credential)))
            ->timeout($timeout)
            ->run($command, $onLine ? function (string $type, string $output) use ($onLine) {
                foreach (preg_split('/\r?\n/', rtrim($output)) ?: [] as $line) {
                    if ($line !== '') {
                        $onLine($line, $type);
                    }
                }
            } : null);

        if ($result->failed()) {
            $detail = $this->redact(trim($result->errorOutput() ?: $result->output()), $credential);

            throw new RunFailed(trim('git '.($args[0] ?? '').' failed. '.$detail));
        }

        return trim($result->output());
    }

    /**
     * Commit every change in the working tree. Returns the new commit SHA, or null when nothing changed.
     */
    public function commitAll(string $cwd, string $message): ?string
    {
        $this->run(['add', '--all'], $cwd);

        if ($this->run(['status', '--porcelain'], $cwd) === '') {
            return null;
        }

        $this->run([
            '-c', 'user.name='.config('factory.runner.git_author_name'),
            '-c', 'user.email='.config('factory.runner.git_author_email'),
            '-c', 'commit.gpgsign=false',
            'commit', '--no-verify', '-m', $message,
        ], $cwd);

        return $this->run(['rev-parse', 'HEAD'], $cwd);
    }

    public function push(string $cwd, string $branch, Credential $credential): void
    {
        $this->run(['push', '--set-upstream', 'origin', "{$branch}:{$branch}"], $cwd, $credential);
    }

    /**
     * @return array<string, string>
     */
    private function credentialEnv(?Credential $credential): array
    {
        $env = [
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_CONFIG_NOSYSTEM' => '1',
        ];

        if (! $credential) {
            return $env;
        }

        return [
            ...$env,
            'GIT_ASKPASS' => $this->askPassScript(),
            'FACTORY_GIT_USERNAME' => $credential->provider === CredentialProvider::GitLab ? 'oauth2' : 'x-access-token',
            'FACTORY_GIT_PASSWORD' => $credential->token,
        ];
    }

    /**
     * A tiny script git calls to ask for a username and password. It reads them from the environment.
     */
    private function askPassScript(): string
    {
        $path = storage_path('app/private/runner/git-askpass.sh');

        if (! File::exists($path)) {
            File::ensureDirectoryExists(dirname($path), 0700);
            File::put($path, <<<'SH'
                #!/bin/sh
                case "$1" in
                  Username*) printf '%s\n' "$FACTORY_GIT_USERNAME" ;;
                  *) printf '%s\n' "$FACTORY_GIT_PASSWORD" ;;
                esac
                SH);
            File::chmod($path, 0700);
        }

        return $path;
    }

    private function redact(string $text, ?Credential $credential): string
    {
        return $credential ? str_replace($credential->token, '***', $text) : $text;
    }
}
