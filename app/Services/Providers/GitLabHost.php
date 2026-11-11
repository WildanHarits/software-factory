<?php

namespace App\Services\Providers;

use App\Data\Providers\ProviderAccount;
use App\Data\Providers\RemoteRepository;
use App\Data\Providers\RepositoryNamespace;
use App\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GitLabHost implements GitHost
{
    private const NAME = 'GitLab';

    /** GitLab "Developer" access level: enough to push branches. */
    private const DEVELOPER = 30;

    public function __construct(private Credential $credential)
    {
        //
    }

    public function account(): ProviderAccount
    {
        $user = $this->send(fn (PendingRequest $http) => $http->get('user'), 'read the account');

        // Only personal/project/group access tokens can describe themselves; ignore failures here.
        $scopes = null;

        try {
            $self = $this->http()->get('personal_access_tokens/self');
            $scopes = $self->successful() ? $self->json('scopes') : null;
        } catch (ConnectionException) {
            //
        }

        return new ProviderAccount(name: (string) $user->json('username'), scopes: $scopes);
    }

    public function namespaces(): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('namespaces', ['per_page' => 100]), 'list namespaces');

        return $response->collect()
            ->map(fn (array $namespace) => new RepositoryNamespace(
                id: (string) $namespace['id'],
                path: $namespace['full_path'],
                kind: $namespace['kind'] === 'user' ? 'user' : 'group',
            ))
            ->all();
    }

    public function repository(string $fullName): RemoteRepository
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get('projects/'.rawurlencode($fullName)),
            'open this repository',
        );

        return $this->toRepository($response->json());
    }

    public function createRepository(string $namespaceId, string $name, bool $private, ?string $description = null): RemoteRepository
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post('projects', [
            'name' => $name,
            'path' => Str::slug($name),
            'namespace_id' => (int) $namespaceId,
            'description' => $description,
            'visibility' => $private ? 'private' : 'public',
            'initialize_with_readme' => true,
        ]), 'create the repository');

        return $this->toRepository($response->json());
    }

    public function pathExists(string $fullName, string $path, string $ref): bool
    {
        try {
            $response = $this->http()->get('projects/'.rawurlencode($fullName).'/repository/tree', [
                'path' => $path,
                'ref' => $ref,
                'per_page' => 1,
            ]);
        } catch (ConnectionException $exception) {
            throw ProviderException::unreachable(self::NAME, $exception);
        }

        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse(self::NAME, $response, 'read the repository files');
        }

        return ! empty($response->json());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toRepository(array $data): RemoteRepository
    {
        $access = max(
            (int) ($data['permissions']['project_access']['access_level'] ?? 0),
            (int) ($data['permissions']['group_access']['access_level'] ?? 0),
        );

        return new RemoteRepository(
            externalId: (string) $data['id'],
            fullName: $data['path_with_namespace'],
            webUrl: $data['web_url'],
            cloneUrl: $data['http_url_to_repo'],
            defaultBranch: $data['default_branch'] ?? 'main',
            private: ($data['visibility'] ?? 'private') !== 'public',
            canPush: $access >= self::DEVELOPER,
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $action): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException $exception) {
            throw ProviderException::unreachable(self::NAME, $exception);
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse(self::NAME, $response, $action);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        $base = rtrim($this->credential->base_url ?: (string) config('factory.gitlab_url'), '/');

        return Http::baseUrl("{$base}/api/v4/")
            ->withHeaders(['PRIVATE-TOKEN' => $this->credential->token])
            ->acceptJson()
            ->timeout(15);
    }
}
