<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Users\CreateUser;
use App\Enums\TeamRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    /**
     * List every user in the application.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('admin/users/index', [
            'users' => User::query()
                ->withCount(['teams' => fn ($query) => $query->where('is_personal', false)])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->is_super_admin,
                    'teams_count' => $user->teams_count,
                    'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                    'created_at' => $user->created_at?->toDateString(),
                ]),
            'teams' => Team::query()
                ->where('is_personal', false)
                ->orderBy('name')
                ->get(['id', 'name']),
            'roles' => TeamRole::assignable(),
        ]);
    }

    /**
     * Create a new user account.
     */
    public function store(StoreUserRequest $request, CreateUser $createUser): RedirectResponse
    {
        $data = $request->validated();

        $createUser->handle(
            name: $data['name'],
            email: $data['email'],
            password: $data['password'],
            isSuperAdmin: $data['is_super_admin'],
            team: isset($data['team_id']) ? Team::query()->whereKey($data['team_id'])->first() : null,
            role: TeamRole::tryFrom($data['role'] ?? '') ?? TeamRole::Member,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User created.')]);

        return to_route('admin.users.index');
    }

    /**
     * Update a user's details, password or super admin access.
     */
    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        if ($request->user()->is($user) && ! $data['is_super_admin']) {
            throw ValidationException::withMessages([
                'is_super_admin' => __('You cannot remove your own super admin access.'),
            ]);
        }

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
        ]);

        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->is_super_admin = $data['is_super_admin'];
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User updated.')]);

        return to_route('admin.users.index');
    }

    /**
     * Delete a user and their personal team.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages([
                'user' => __('You cannot delete your own account here.'),
            ]);
        }

        $ownedTeams = $user->ownedTeams()->where('teams.is_personal', false)->pluck('teams.name');

        if ($ownedTeams->isNotEmpty()) {
            throw ValidationException::withMessages([
                'user' => __('This user owns :teams. Delete those teams or transfer ownership first.', [
                    'teams' => $ownedTeams->join(', ', ' and '),
                ]),
            ]);
        }

        DB::transaction(function () use ($user) {
            $user->ownedTeams()->where('teams.is_personal', true)->get()->each->delete();
            $user->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('User deleted.')]);

        return to_route('admin.users.index');
    }
}
