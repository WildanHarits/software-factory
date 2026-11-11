<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $email = strtolower($request->user()->email);

        $pendingInvitations = TeamInvitation::query()
            ->with(['inviter', 'team'])
            ->whereRaw('LOWER(email) = ?', [$email])
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()))
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'code' => $invitation->code,
                'inviterName' => $invitation->inviter->name,
                'team' => [
                    'name' => $invitation->team->name,
                    'slug' => $invitation->team->slug,
                ],
            ]);

        $team = $request->user()->currentTeam;

        return Inertia::render('dashboard', [
            'pendingInvitations' => $pendingInvitations,
            'projects' => $team->projects()
                ->with(['gitCredential.team', 'gitCredential.user', 'aiCredential.team', 'aiCredential.user'])
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project) => $project->toSummary()),
            'canManageProjects' => $request->user()->can('manageProjects', $team),
        ]);
    }
}
