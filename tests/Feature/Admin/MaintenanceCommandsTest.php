<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\BackupDatabase;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use App\Models\Project;
use App\Models\Run;
use App\Models\Ticket;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * factory:recover-runs, factory:prune-workspaces, factory:backup and factory:doctor.
 */
class MaintenanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $sandbox;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->sandbox = storage_path('framework/testing/maintenance');
        File::ensureDirectoryExists($this->sandbox);
        config([
            'factory.runner.workspace_path' => "{$this->sandbox}/workspaces",
            'factory.backups.path' => "{$this->sandbox}/backups",
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);

        parent::tearDown();
    }

    public function test_stuck_runs_are_failed_and_their_tickets_freed()
    {
        config(['factory.runner.timeout' => 600]);
        $ticket = Ticket::factory()->awaiting(TicketStage::Plan)->create(['status' => TicketStatus::Running]);

        $stuck = Run::factory()->create(['project_id' => $ticket->project_id, 'ticket_id' => $ticket->id, 'type' => RunType::Plan, 'status' => RunStatus::Running, 'started_at' => now()->subMinutes(30)]);
        $ticket->forceFill(['last_run_id' => $stuck->id])->save();
        $fresh = Run::factory()->create(['project_id' => $ticket->project_id, 'status' => RunStatus::Running, 'started_at' => now()->subMinutes(5)]);
        $cancelling = Run::factory()->create(['project_id' => $ticket->project_id, 'status' => RunStatus::Cancelling, 'started_at' => now()->subHour()]);
        $neverPicked = Run::factory()->create(['project_id' => $ticket->project_id, 'status' => RunStatus::Queued, 'created_at' => now()->subDay()]);

        $this->artisan('factory:recover-runs')->assertSuccessful()->expectsOutputToContain('Recovered 3 run(s).');

        $this->assertSame(RunStatus::Failed, $stuck->fresh()->status);
        $this->assertStringContainsString('stopped responding', (string) $stuck->fresh()->error);
        $this->assertSame('error', $stuck->events()->value('kind'));
        $this->assertSame(TicketStatus::Failed, $ticket->fresh()->status);
        $this->assertSame(RunStatus::Running, $fresh->fresh()->status);
        $this->assertSame(RunStatus::Cancelled, $cancelling->fresh()->status);
        $this->assertStringContainsString('Horizon', (string) $neverPicked->fresh()->error);
    }

    public function test_leftover_worktrees_and_deleted_projects_are_pruned()
    {
        Process::fake();
        $root = "{$this->sandbox}/workspaces";

        $live = Project::factory()->create();
        $removedLongAgo = Project::factory()->create();
        $removedLongAgo->forceFill(['deleted_at' => now()->subDays(10)])->save();
        $removedRecently = Project::factory()->create();
        $removedRecently->delete();

        $activeTicket = Ticket::factory()->create(['project_id' => $live->id, 'team_id' => $live->team_id]);
        Run::factory()->create(['project_id' => $live->id, 'ticket_id' => $activeTicket->id, 'status' => RunStatus::Running]);

        $liveFolder = "{$root}/team-{$live->team_id}/project-{$live->id}";
        foreach (["{$liveFolder}/repo/.git", "{$liveFolder}/worktrees/ticket-{$activeTicket->id}", "{$liveFolder}/worktrees/ticket-999", "{$liveFolder}/worktrees/run-555",
            "{$root}/team-{$removedLongAgo->team_id}/project-{$removedLongAgo->id}/repo",
            "{$root}/team-{$removedRecently->team_id}/project-{$removedRecently->id}/repo",
            "{$root}/team-1/project-987654/repo"] as $dir) {
            File::ensureDirectoryExists($dir);
        }
        foreach (glob("{$liveFolder}/worktrees/*") as $worktree) {
            touch($worktree, time() - 7200);
        }

        $this->artisan('factory:prune-workspaces', ['--dry-run' => true])->assertSuccessful()->expectsOutputToContain('Would remove 4 folder(s).');
        $this->assertDirectoryExists("{$liveFolder}/worktrees/ticket-999");

        $this->artisan('factory:prune-workspaces')->assertSuccessful()->expectsOutputToContain('Removed 4 folder(s).');

        $this->assertDirectoryExists("{$liveFolder}/repo");
        $this->assertDirectoryExists("{$liveFolder}/worktrees/ticket-{$activeTicket->id}");
        $this->assertDirectoryDoesNotExist("{$liveFolder}/worktrees/ticket-999");
        $this->assertDirectoryDoesNotExist("{$liveFolder}/worktrees/run-555");
        $this->assertDirectoryDoesNotExist("{$root}/team-{$removedLongAgo->team_id}/project-{$removedLongAgo->id}");
        $this->assertDirectoryExists("{$root}/team-{$removedRecently->team_id}/project-{$removedRecently->id}");
        $this->assertDirectoryDoesNotExist("{$root}/team-1/project-987654");
    }

    public function test_sqlite_databases_are_backed_up_and_old_backups_removed()
    {
        $database = "{$this->sandbox}/app.sqlite";
        copy(__FILE__, $database);
        config(['database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $database]]);

        File::ensureDirectoryExists("{$this->sandbox}/backups");
        touch($old = "{$this->sandbox}/backups/software-factory-2020-01-01-000000.sql.gz", now()->subDays(30)->getTimestamp());

        $this->artisan('factory:backup', ['--connection' => 'backup_test'])->assertSuccessful();

        $files = glob("{$this->sandbox}/backups/software-factory-*.sqlite.gz");
        $this->assertCount(1, $files);
        $this->assertSame(file_get_contents(__FILE__), gzdecode((string) file_get_contents($files[0])));
        $this->assertSame('600', substr(sprintf('%o', fileperms($files[0])), -3));
        $this->assertFileDoesNotExist($old);
    }

    public function test_mysqldump_gets_the_password_from_the_environment_and_only_known_flags()
    {
        $command = app(BackupDatabase::class);
        $connection = ['host' => 'db', 'port' => 3307, 'username' => 'app', 'password' => 'p@ss', 'database' => 'factory'];

        $old = $command->mysqldumpCommand($connection, '/tmp/x.sql', help: 'mysqldump Ver 10.19 Distrib 10.11.6-MariaDB');
        $new = $command->mysqldumpCommand($connection, '/tmp/x.sql', help: "--set-gtid-purged[=name]\n--masking-policies");

        $this->assertNotContains('--set-gtid-purged=OFF', $old);
        $this->assertContains('--set-gtid-purged=OFF', $new);
        $this->assertContains('--skip-masking-policies', $new);
        $this->assertContains('--host=db', $new);
        $this->assertContains('--port=3307', $new);
        $this->assertSame('factory', end($new));
        $this->assertStringNotContainsString('p@ss', implode(' ', $new));
    }

    public function test_a_failed_dump_reports_an_error()
    {
        config(['database.connections.backup_test' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'u', 'password' => 'secret']]);
        Process::fake(fn () => Process::result(errorOutput: 'Access denied', exitCode: 2));

        $this->artisan('factory:backup', ['--connection' => 'backup_test'])->assertFailed()->expectsOutputToContain('Access denied');

        Process::assertRan(fn ($process) => ($process->environment['MYSQL_PWD'] ?? null) === 'secret');
        $this->assertSame([], glob("{$this->sandbox}/backups/*.gz"));
    }

    public function test_the_doctor_fails_on_unsafe_production_settings()
    {
        Process::fake(['*' => Process::result(output: 'specify 1.4.0')]);
        Cache::forever('factory:scheduler-heartbeat', now()->toIso8601String());
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://factory.example.com', 'factory.runner.driver' => 'local']);

        $this->artisan('factory:doctor')
            ->assertFailed()
            ->expectsOutputToContain('APP_DEBUG is on in production');
    }

    public function test_the_doctor_reports_a_stopped_scheduler()
    {
        Process::fake(['*' => Process::result(output: 'ok')]);
        Cache::forget('factory:scheduler-heartbeat');

        $this->artisan('factory:doctor')->expectsOutputToContain('No heartbeat in the last 3 minutes');
    }

    public function test_maintenance_is_scheduled()
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command ?? $event->description)->implode("\n");

        foreach (['factory:recover-runs', 'factory:prune-workspaces', 'factory:backup', 'factory:sync-pull-requests'] as $command) {
            $this->assertStringContainsString($command, $events);
        }
    }
}
