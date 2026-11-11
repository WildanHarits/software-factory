<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// Catches merged pull requests when webhooks can't reach the app.
Schedule::command('factory:sync-pull-requests')
    ->cron('*/'.max(1, (int) config('factory.git.sync_every_minutes', 5)).' * * * *')
    ->withoutOverlapping()
    ->description('Sync open pull requests');

// Close runs whose worker died, so their cards can be retried.
Schedule::command('factory:recover-runs')->everyFiveMinutes()->withoutOverlapping()->description('Recover stuck runs');

// Remove leftover worktrees and folders of deleted projects.
Schedule::command('factory:prune-workspaces')->dailyAt('03:30')->withoutOverlapping()->description('Prune workspaces');

// Daily database backup.
Schedule::command('factory:backup')->dailyAt('02:30')->withoutOverlapping()->description('Back up the database');

// Lets `factory:doctor` see that the scheduler is running.
Schedule::call(fn () => Cache::forever('factory:scheduler-heartbeat', now()->toIso8601String()))
    ->everyMinute()
    ->name('scheduler-heartbeat')
    ->description('Scheduler heartbeat');
