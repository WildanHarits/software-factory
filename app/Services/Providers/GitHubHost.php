<?php

namespace App\Services\Providers;

use App\Data\Providers\ProviderAccount;
use App\Data\Providers\PullRequest;
use App\Data\Providers\RemoteRepository;
use App\Data\Providers\RepositoryNamespace;
use App\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GitHubHost implements GitHost
{
    private const NAME = 'GitHub';

    private ?string $login = null;

    public function __construct(private Credential $credential)
    {
        //
    }

    public function account(): ProviderAccount
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/user'), 'read the account');

        $this->login = (string) $response->json('login');

        // Classic tokens list their scopes in a header; fine-grained tokens don't.
        return new ProviderAccount(
            name: $this->login,
            scopes: $response->hasHeader('X-OAuth-Scopes')
                ? array_values(array_filter(array_map('trim', explode(',', $response->header('X-OAuth-Scopes')))))
                : null,
        );
    }

    public function namespaces(): array
    {
        $login = $this->login ?? $this->account()->name;

        $orgs = $this->send(fn (PendingRequest $http) => $http->get('/user/orgs', ['per_page' => 100]), 'list organizations');

        return [
            new RepositoryNamespace(id: $login, path: $login, kind: 'user'),
            ...$orgs->collect()
                ->map(fn (array $org) => new RepositoryNamespace(id: $org['login'], path: $org['login'], kind: 'org'))
                ->all(),
        ];
    }

    public function repository(string $fullName): RemoteRepository
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("/repos/{$fullName}"), 'open this repository');

        return $this->toRepository($response->json());
    }

    public function createRepository(string $namespaceId, string $name, bool $private, ?string $description = null): RemoteRepository
    {
        $login = $this->login ?? $this->account()->name;

        $url = strcasecmp($namespaceId, $login) === 0 ? '/user/repos' : "/orgs/{$namespaceId}/repos";

        $response = $this->send(fn (PendingRequest $http) => $http->post($url, [
            'name' => $name,
            'description' => $description,
            'private' => $private,
            'auto_init' => true,
        ]), 'create the repository');

        return $this->toRepository($response->json());
    }

    public function pathExists(string $fullName, string $path, string $ref): bool
    {
        try {
            $response = $this->http()->get("/repos/{$fullName}/contents/{$path}", ['ref' => $ref]);
        } catch (ConnectionException $exception) {
            throw ProviderException::unreachable(self::NAME, $exception);
        }

        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse(self::NAME, $response, 'read the repository files');
        }

        return true;
    }

    public function createPullRequest(string $fullName, string $head, string $base, string $title, string $body): PullRequest
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post("/repos/{$fullName}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]), 'open a pull request');

        return new PullRequest(number: (int) $response->json('number'), url: (string) $response->json('html_url'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toRepository(array $data): RemoteRepository
    {
        return new RemoteRepository(
            externalId: (string) $data['id'],
            fullName: $data['full_name'],
            webUrl: $data['html_url'],
            cloneUrl: $data['clone_url'],
            defaultBranch: $data['default_branch'] ?? 'main',
            private: (bool) $data['private'],
            canPush: (bool) ($data['permissions']['push'] ?? false),
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
        return Http::baseUrl(rtrim((string) config('factory.github_api_url'), '/'))
            ->withToken($this->credential->token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15);
    }
}
