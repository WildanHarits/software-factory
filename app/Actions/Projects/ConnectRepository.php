<?php

namespace App\Actions\Projects;

use App\Data\Providers\RemoteRepository;
use App\Models\Credential;
use App\Services\Providers\GitHost;
use App\Services\Providers\ProviderException;
use App\Services\Providers\ProviderRegistry;
use Illuminate\Validation\ValidationException;

/**
 * Creates or opens a repository with a token, and makes sure the token can push to it.
 */
class ConnectRepository
{
    public function __construct(private ProviderRegistry $providers)
    {
        //
    }

    /**
     * @throws ValidationException
     */
    public function create(Credential $credential, string $namespaceId, string $name, bool $private, ?string $description): RemoteRepository
    {
        return $this->attempt('repo_name', fn (GitHost $host) => $host->createRepository($namespaceId, $name, $private, $description), $credential);
    }

    /**
     * @throws ValidationException
     */
    public function existing(Credential $credential, string $fullName, string $field = 'repository'): RemoteRepository
    {
        $repository = $this->attempt($field, fn (GitHost $host) => $host->repository($fullName), $credential);

        if (! $repository->canPush) {
            throw ValidationException::withMessages([
                $field => __('This token can see :repo but cannot push to it. Agents need write access.', ['repo' => $repository->fullName]),
            ]);
        }

        return $repository;
    }

    /**
     * Whether Spec Kit has been set up in the repository (it creates a .specify folder).
     */
    public function hasSpecKit(Credential $credential, RemoteRepository $repository): bool
    {
        try {
            return $this->providers->gitHost($credential)->pathExists($repository->fullName, '.specify', $repository->defaultBranch);
        } catch (ProviderException) {
            return false;
        }
    }

    /**
     * @param  callable(GitHost): RemoteRepository  $call
     */
    private function attempt(string $field, callable $call, Credential $credential): RemoteRepository
    {
        try {
            return $call($this->providers->gitHost($credential));
        } catch (ProviderException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
