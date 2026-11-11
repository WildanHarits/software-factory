<?php

namespace App\Console\Commands;

use App\Runner\RunnerHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Checks that this installation is set up correctly. Run it after deploying; it exits
 * with an error when something would break runs, logins or data safety.
 */
#[Signature('factory:doctor')]
#[Description('Check the installation: runner, queue, scheduler, security settings and backups')]
class Doctor extends Command
{
    private const OK = 'ok';

    private const WARN = 'warn';

    private const FAIL = 'fail';

    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $results = [];

    public function handle(RunnerHealth $health): int
    {
        $production = app()->isProduction();

        $health->forget();

        foreach ($health->checks() as $check) {
            $this->add($check['label'], $check['ok'] ? self::OK : self::FAIL, $check['detail']);
        }

        $this->add('App key', config('app.key') ? self::OK : self::FAIL, config('app.key') ? 'Set. Keep a copy apart from backups: tokens can only be decrypted with it.' : 'Missing. Run php artisan key:generate.');

        if ($production && config('app.debug')) {
            $this->add('Debug mode', self::FAIL, 'APP_DEBUG is on in production; error pages would show secrets.');
        } else {
            $this->add('Debug mode', self::OK, config('app.debug') ? 'On (fine outside production).' : 'Off.');
        }

        $https = str_starts_with((string) config('app.url'), 'https://');
        $this->add('HTTPS', $https ? self::OK : ($production ? self::FAIL : self::WARN), $https ? (string) config('app.url') : 'APP_URL is not https://. Logins and tokens must go over HTTPS in production.');

        if ($https) {
            $this->add('Secure cookies', config('session.secure') ? self::OK : self::WARN, config('session.secure') ? 'On.' : 'Set SESSION_SECURE_COOKIE=true.');
        }

        $driver = (string) config('factory.runner.driver');
        $this->add('Runner driver', match (true) {
            $driver === 'simulated' && $production => self::FAIL,
            $driver === 'local' && $production => self::WARN,
            default => self::OK,
        }, match ($driver) {
            'docker' => 'docker: one container per run.',
            'local' => 'local: Claude Code runs on this machine inside its sandbox.'.($production ? ' On a shared server, prefer FACTORY_RUNNER_DRIVER=docker.' : ''),
            'simulated' => 'simulated: no AI, placeholder files only.',
            default => $driver,
        });

        $queue = (string) config('queue.default');
        $this->add('Queue', $queue === 'redis' ? self::OK : self::WARN, $queue === 'redis' ? 'Redis' : "QUEUE_CONNECTION={$queue}. Use redis so Horizon runs the agent queue.");

        $heartbeat = Cache::get('factory:scheduler-heartbeat');
        $beating = is_string($heartbeat) && Carbon::parse($heartbeat)->gt(now()->subMinutes(3));
        $this->add('Scheduler', $beating ? self::OK : ($production ? self::FAIL : self::WARN), $beating
            ? 'Running.'
            : 'No heartbeat in the last 3 minutes. Add the cron entry (* * * * * php artisan schedule:run), or run composer run dev locally. Needed for PR sync, stuck-run recovery and backups.');

        $mailer = (string) config('mail.default');
        $this->add('Mail', in_array($mailer, ['log', 'array'], true) ? ($production ? self::WARN : self::OK) : self::OK, in_array($mailer, ['log', 'array'], true)
            ? "MAIL_MAILER={$mailer}: password reset and invitation emails only go to the log."
            : "MAIL_MAILER={$mailer}");

        $this->add(...$this->backupCheck($production));

        $this->table(['Check', 'Status', 'Details'], array_map(
            fn (array $row) => [$row[0], match ($row[1]) {
                self::OK => '<fg=green>OK</>',
                self::WARN => '<fg=yellow>Warning</>',
                default => '<fg=red>Problem</>',
            }, $row[2]],
            $this->results,
        ));

        $problems = count(array_filter($this->results, fn (array $row) => $row[1] === self::FAIL));

        if ($problems > 0) {
            $this->components->error("{$problems} problem(s) to fix.");

            return self::FAILURE;
        }

        $this->components->info('No problems found.');

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function backupCheck(bool $production): array
    {
        $times = array_filter(array_map('filemtime', glob(BackupDatabase::folder().'/software-factory-*.gz') ?: []));
        $newest = $times === [] ? null : max($times);

        if ($newest === null) {
            return ['Backups', $production ? self::WARN : self::OK, 'No backup yet. One runs every night (php artisan factory:backup).'];
        }

        $age = Carbon::createFromTimestamp($newest);
        $fresh = $age->gt(now()->subDays(2));

        return ['Backups', $fresh ? self::OK : self::WARN, 'Last backup '.$age->diffForHumans().(config('factory.backups.disk') ? ', copied to "'.config('factory.backups.disk').'".' : ', kept on this server only (set FACTORY_BACKUP_DISK for an off-site copy).')];
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->results[] = [$check, $status, $detail];
    }
}
