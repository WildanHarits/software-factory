<?php

namespace App\Http\Controllers\Credentials;

use App\Actions\Credentials\VerifyCredential;
use App\Data\Providers\RepositoryNamespace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Credentials\StoreCredentialRequest;
use App\Http\Requests\Credentials\UpdateCredentialRequest;
use App\Models\Credential;
use App\Models\Team;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CredentialController extends Controller
{
    /**
     * Save a personal token for the signed-in user.
     */
    public function storePersonal(StoreCredentialRequest $request, VerifyCredential $verify): RedirectResponse
    {
        $credential = $request->user()->credentials()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $this->flashVerification($credential, $verify->handle($credential), __('Token saved.'));

        return back();
    }

    /**
     * Save a token shared by the whole team.
     */
    public function storeForTeam(StoreCredentialRequest $request, Team $team, VerifyCredential $verify): RedirectResponse
    {
        Gate::authorize('manageCredentials', $team);

        $credential = $team->credentials()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        $this->flashVerification($credential, $verify->handle($credential), __('Token saved.'));

        return back();
    }

    /**
     * Rename a credential, or replace its token.
     */
    public function update(UpdateCredentialRequest $request, Credential $credential, VerifyCredential $verify): RedirectResponse
    {
        Gate::authorize('manage', $credential);

        $data = $request->validated();

        $credential->name = $data['name'];

        if ($credential->provider->isGit()) {
            $credential->base_url = $data['base_url'];
        }

        $tokenChanged = ! empty($data['token']);

        if ($tokenChanged) {
            $credential->token = $data['token'];
        }

        $credential->save();

        if ($tokenChanged || $credential->wasChanged('base_url')) {
            $this->flashVerification($credential, $verify->handle($credential), __('Token updated.'));
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Token updated.')]);
        }

        return back();
    }

    /**
     * Check the token with its provider again.
     */
    public function verify(Credential $credential, VerifyCredential $verify): RedirectResponse
    {
        Gate::authorize('manage', $credential);

        $this->flashVerification($credential, $verify->handle($credential), __('Token works.'));

        return back();
    }

    public function destroy(Credential $credential): RedirectResponse
    {
        Gate::authorize('manage', $credential);

        $credential->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token deleted.')]);

        return back();
    }

    /**
     * Accounts, organizations and groups where this token can create a repository.
     */
    public function namespaces(Request $request, Credential $credential, ProviderRegistry $providers): JsonResponse
    {
        Gate::authorize('view', $credential);

        abort_unless($credential->provider->isGit(), 404);

        try {
            $namespaces = $providers->gitHost($credential)->namespaces();
        } catch (ProviderException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'namespaces' => array_map(fn (RepositoryNamespace $namespace) => $namespace->toArray(), $namespaces),
        ]);
    }

    private function flashVerification(Credential $credential, bool $verified, string $successMessage): void
    {
        Inertia::flash('toast', $verified
            ? ['type' => 'success', 'message' => $credential->account_name
                ? __(':message Connected as :account.', ['message' => $successMessage, 'account' => $credential->account_name])
                : $successMessage]
            : ['type' => 'warning', 'message' => __('Saved, but the check failed: :error', ['error' => $credential->last_error])]);
    }
}
