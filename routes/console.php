<?php

use App\Models\TeamInvitation;
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
