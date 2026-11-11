<?php

namespace App\Runner;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Checks that everything a run needs is installed and running.
 */
class RunnerHealth
{
    /**
     * @return array<int, array{key: string, label: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        return Cache::remember('runner-health', now()->addSeconds(30), fn () => array_values(array_filter([
            $this->binary('git', 'git', 'Install git.'),
            match (config('factory.runner.driver')) {
                'docker' => $this->docker(),
                'simulated' => null,
                default => $this->binary('claude', 'Claude Code', 'Install Claude Code, or set CLAUDE_BIN in .env to its full path.'),
            },
            $this->speckit(),
            $this->workspace(),
            $this->queue(),
        ])));
    }

    /**
     * @return array<int, array{key: string, label: string, ok: bool, detail: string}>
     */
    public function problems(): array
    {
        return array_values(array_filter($this->checks(), fn (array $check) => ! $check['ok']));
    }

    public function forget(): void
    {
        Cache::forget('runner-health');
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function binary(string $key, string $label, string $fix): array
    {
        $version = $this->version($key);

        return $version === null
            ? $this->check($key, $label, false, "Not found. {$fix}")
            : $this->check($key, $label, true, $version);
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function docker(): array
    {
        $binary = (string) config('factory.runner.docker.binary', 'docker');
        $image = (string) config('factory.runner.docker.image');

        try {
            $server = Process::env(ProcessEnvironment::clean())->timeout(15)->run([$binary, 'version', '--format', '{{.Server.Version}}']);
        } catch (Throwable) {
            return $this->check('docker', 'Docker', false, 'Docker is not installed (or DOCKER_BIN is wrong).');
        }

        if ($server->failed()) {
            return $this->check('docker', 'Docker', false, 'Docker is installed but the app can’t reach the Docker daemon. Is it running, and is the app’s user in the "docker" group?');
        }

        $inspect = Process::env(ProcessEnvironment::clean())->timeout(15)->run([$binary, 'image', 'inspect', '--format', '{{.Id}}', $image]);

        if ($inspect->failed()) {
            return $this->check('docker', 'Docker runner image', false, "Image {$image} not found. Build it with: docker build -t {$image} docker/runner");
        }

        return $this->check('docker', 'Docker runner image', true, 'Docker '.trim($server->output()).", image {$image}");
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function speckit(): array
    {
        $expected = (string) config('factory.speckit.version');
        $version = $this->version('specify');

        if ($version === null) {
            return $this->check('specify', 'Spec Kit CLI', false, "Not found. Install it with: uv tool install specify-cli --from git+https://github.com/github/spec-kit.git@v{$expected}");
        }

        if (! str_contains($version, $expected)) {
            return $this->check('specify', 'Spec Kit CLI', false, "Found {$version}, but this app is set up for {$expected} (SPECKIT_VERSION). Command names differ between versions.");
        }

        return $this->check('specify', 'Spec Kit CLI', true, $version);
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function workspace(): array
    {
        $path = (string) config('factory.runner.workspace_path');

        try {
            File::ensureDirectoryExists($path, 0700);
        } catch (Throwable) {
            //
        }

        return is_dir($path) && is_writable($path)
            ? $this->check('workspace', 'Workspace folder', true, $path)
            : $this->check('workspace', 'Workspace folder', false, "Can't write to {$path}. Set FACTORY_WORKSPACE_PATH in .env.");
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function queue(): array
    {
        try {
            $running = count(app(MasterSupervisorRepository::class)->all()) > 0;
        } catch (Throwable) {
            return $this->check('queue', 'Queue (Horizon)', false, "Can't reach Redis. Start it with: brew services start redis");
        }

        return $running
            ? $this->check('queue', 'Queue (Horizon)', true, 'Running')
            : $this->check('queue', 'Queue (Horizon)', false, 'Not running, so runs will wait in the queue. Start it with: composer run dev (or php artisan horizon).');
    }

    private function version(string $key): ?string
    {
        try {
            $result = Process::env(ProcessEnvironment::clean())
                ->timeout(15)
                ->run([(string) config("factory.runner.binaries.{$key}"), '--version']);
        } catch (Throwable) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        $line = strtok(trim(RunRecorder::stripAnsi($result->output())), "\n");

        return $line === false ? null : trim($line);
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function check(string $key, string $label, bool $ok, string $detail): array
    {
        return compact('key', 'label', 'ok', 'detail');
    }
}
