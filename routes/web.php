<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// There is no public landing page: guests go to login, users to their team's dashboard.
Route::get('/', function (Request $request) {
    $user = $request->user();

    if (! $user) {
        return redirect()->route('login');
    }

    $team = $user->currentTeam ?? $user->personalTeam();

    abort_if(! $team, 403, __('You are not a member of any team.'));

    return redirect()->route('dashboard', ['current_team' => $team->slug]);
})->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::get('projects/create', [ProjectController::class, 'create'])->name('projects.create');
        Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');

        // Projects are looked up within the team in the URL, since slugs are only unique per team.
        Route::scopeBindings()->group(function () {
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::get('projects/{project}/settings', [ProjectController::class, 'edit'])->name('projects.edit');
            Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
            Route::post('projects/{project}/refresh', [ProjectController::class, 'refresh'])->middleware('throttle:20,1')->name('projects.refresh');
        });
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
require __DIR__.'/admin.php';
