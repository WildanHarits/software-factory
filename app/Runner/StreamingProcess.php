<?php

namespace App\Runner;

use Illuminate\Support\Facades\Process;

/**
 * Runs a command, hands its output over line by line, and stops it when the run is
 * cancelled or takes too long.
 */
class StreamingProcess
{
    private const CANCEL_CHECK_SECONDS = 2.0;

    private const POLL_MICROSECONDS = 200_000;

    /**
     * @param  array<int, string>  $command  Passed as an argument list, never through a shell.
     * @param  array<string, string|false>  $env
     * @param  callable(string $line, string $stream): void  $onLine
     * @param  (callable(): bool)|null  $shouldCancel
     */
    public function run(
        array $command,
        string $cwd,
        array $env,
        int $timeoutSeconds,
        callable $onLine,
        ?callable $shouldCancel = null,
    ): ProcessOutcome {
        $buffers = ['out' => '', 'err' => ''];
        $errorOutput = '';

        $handler = function (string $type, string $chunk) use (&$buffers, &$errorOutput, $onLine) {
            $stream = $type === 'err' ? 'err' : 'out';

            if ($stream === 'err') {
                $errorOutput = substr($errorOutput.$chunk, -8000);
            }

            $buffers[$stream] .= $chunk;

            while (($position = strpos($buffers[$stream], "\n")) !== false) {
                $line = rtrim(substr($buffers[$stream], 0, $position), "\r");
                $buffers[$stream] = substr($buffers[$stream], $position + 1);
                $onLine($line, $stream);
            }
        };

        $process = Process::path($cwd)
            ->env($env)
            ->forever()
            ->start($command, $handler);

        $startedAt = microtime(true);
        $lastCancelCheck = $startedAt;
        $cancelled = false;
        $timedOut = false;

        while ($process->running()) {
            $now = microtime(true);

            if ($now - $startedAt > $timeoutSeconds) {
                $timedOut = true;
                $process->stop(10, SIGTERM);
                break;
            }

            if ($shouldCancel && $now - $lastCancelCheck >= self::CANCEL_CHECK_SECONDS) {
                $lastCancelCheck = $now;

                if ($shouldCancel()) {
                    $cancelled = true;
                    $process->stop(10, SIGTERM);
                    break;
                }
            }

            usleep(self::POLL_MICROSECONDS);
        }

        $result = $process->wait();

        foreach ($buffers as $stream => $rest) {
            if (trim($rest) !== '') {
                $onLine(rtrim($rest, "\r\n"), $stream);
            }
        }

        return new ProcessOutcome(
            exitCode: (int) ($result->exitCode() ?? 1),
            cancelled: $cancelled,
            timedOut: $timedOut,
            errorOutput: trim($errorOutput),
        );
    }
}
