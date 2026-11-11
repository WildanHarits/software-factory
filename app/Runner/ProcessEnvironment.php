<?php

namespace App\Runner;

/**
 * Builds the environment for commands the runner starts.
 *
 * Child processes normally inherit every environment variable, and Laravel puts .env values
 * (APP_KEY, DB_PASSWORD, ...) there. The agent must never see those, so everything is removed
 * except a short allow-list, then only the variables a command needs are added back.
 */
class ProcessEnvironment
{
    private const KEEP = ['PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'SHELL', 'TERM'];

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string|false> False removes an inherited variable.
     */
    public static function clean(array $extra = []): array
    {
        $inherited = [];

        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && (is_string($value) || is_numeric($value))) {
                    $inherited[$key] = (string) $value;
                }
            }
        }

        $env = array_fill_keys(array_keys($inherited), false);

        foreach (self::KEEP as $key) {
            if (isset($inherited[$key]) && $inherited[$key] !== '') {
                $env[$key] = $inherited[$key];
            }
        }

        $env['PATH'] = self::path($inherited['PATH'] ?? '/usr/local/bin:/usr/bin:/bin');

        return array_merge($env, $extra);
    }

    /**
     * Make sure the folders of the configured binaries (e.g. ~/.local/bin) are on PATH,
     * since queue workers often start with a shorter PATH than your terminal.
     */
    private static function path(string $path): string
    {
        $dirs = explode(':', $path);

        foreach ((array) config('factory.runner.binaries', []) as $binary) {
            if (is_string($binary) && str_contains($binary, '/')) {
                array_unshift($dirs, dirname($binary));
            }
        }

        $home = getenv('HOME');

        foreach (array_filter([$home ? "{$home}/.local/bin" : null, '/opt/homebrew/bin', '/usr/local/bin']) as $dir) {
            $dirs[] = $dir;
        }

        return implode(':', array_values(array_unique(array_filter($dirs))));
    }
}
