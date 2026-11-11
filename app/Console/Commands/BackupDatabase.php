<?php

namespace App\Console\Commands;

use App\Runner\ProcessEnvironment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Daily database backup: a compressed dump in storage/app/private/backups, optionally copied
 * to another filesystem disk (e.g. S3), with old local copies removed.
 *
 * The database holds encrypted tokens. They can only be decrypted with the same APP_KEY,
 * so keep a copy of APP_KEY somewhere safe, apart from these backups.
 */
#[Signature('factory:backup
    {--keep-days= : Delete local backups older than this (default from config)}
    {--connection= : Database connection to back up (default: the app\'s)}')]
#[Description('Back up the database')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $folder = self::folder();
        File::ensureDirectoryExists($folder, 0700);

        $name = $this->option('connection') ?: config('database.default');
        $connection = (array) config("database.connections.{$name}");
        $target = $folder.'/software-factory-'.now()->format('Y-m-d-His').'.'.($connection['driver'] === 'sqlite' ? 'sqlite' : 'sql').'.gz';

        try {
            match ($connection['driver'] ?? null) {
                'mysql', 'mariadb' => $this->dumpMysql($connection, $target),
                'sqlite' => $this->copySqlite($connection, $target),
                default => throw new RuntimeException("Backups for the {$connection['driver']} driver aren't supported."),
            };
        } catch (Throwable $exception) {
            File::delete($target);
            $this->components->error('Backup failed: '.$exception->getMessage());
            report($exception);

            return self::FAILURE;
        }

        chmod($target, 0600);
        $this->components->info('Backup written: '.$target.' ('.number_format(filesize($target) / 1024, 1).' KB)');

        if ($disk = config('factory.backups.disk')) {
            Storage::disk($disk)->putFileAs('software-factory-backups', $target, basename($target));
            $this->components->info("Copied to the \"{$disk}\" disk.");
        }

        $this->prune($folder);

        return self::SUCCESS;
    }

    public static function folder(): string
    {
        return rtrim((string) config('factory.backups.path'), '/');
    }

    /**
     * The mysqldump command. The password goes in MYSQL_PWD, never on the command line.
     *
     * $help is `mysqldump --help` output: newer MySQL options are only added when the installed
     * version knows them (MariaDB and older MySQL versions would reject them).
     *
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    public function mysqldumpCommand(array $connection, string $resultFile, string $help = ''): array
    {
        return array_values(array_filter([
            (string) config('factory.backups.mysqldump_binary', 'mysqldump'),
            '--single-transaction',
            '--quick',
            '--routines',
            '--no-tablespaces',
            // Without this, MySQL asks for FLUSH TABLES (RELOAD privilege) that the app's user shouldn't need.
            str_contains($help, '--set-gtid-purged') ? '--set-gtid-purged=OFF' : null,
            // MySQL 9.x: masking policies need extra privileges and aren't used by this app.
            str_contains($help, '--masking-policies') ? '--skip-masking-policies' : null,
            ! empty($connection['unix_socket']) ? '--socket='.$connection['unix_socket'] : '--host='.($connection['host'] ?? '127.0.0.1'),
            empty($connection['unix_socket']) ? '--port='.($connection['port'] ?? 3306) : null,
            '--user='.($connection['username'] ?? 'root'),
            '--result-file='.$resultFile,
            (string) $connection['database'],
        ]));
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function dumpMysql(array $connection, string $target): void
    {
        $raw = $target.'.part';

        $help = Process::env(ProcessEnvironment::clean())->timeout(30)
            ->run([(string) config('factory.backups.mysqldump_binary', 'mysqldump'), '--help'])
            ->output();

        $result = Process::env(ProcessEnvironment::clean(['MYSQL_PWD' => (string) ($connection['password'] ?? '')]))
            ->timeout(3600)
            ->run($this->mysqldumpCommand($connection, $raw, $help));

        if ($result->failed() || ! is_file($raw)) {
            File::delete($raw);

            throw new RuntimeException(trim($result->errorOutput()) ?: 'mysqldump did not produce a file.');
        }

        $this->compress($raw, $target);
        File::delete($raw);
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function copySqlite(array $connection, string $target): void
    {
        $path = (string) ($connection['database'] ?? '');

        if ($path === '' || $path === ':memory:' || ! is_file($path)) {
            throw new RuntimeException('The SQLite database is not a file.');
        }

        $this->compress($path, $target);
    }

    private function compress(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');

        if (! $in || ! $out) {
            throw new RuntimeException('Could not open the backup files.');
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);
    }

    private function prune(string $folder): void
    {
        $days = (int) ($this->option('keep-days') ?? config('factory.backups.keep_days', 14));
        $deleted = 0;

        foreach (glob("{$folder}/software-factory-*.gz") ?: [] as $file) {
            if (filemtime($file) < now()->subDays(max(1, $days))->getTimestamp()) {
                File::delete($file);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->components->info("Removed {$deleted} backup(s) older than {$days} days.");
        }
    }
}
