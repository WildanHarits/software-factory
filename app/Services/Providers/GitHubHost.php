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

        return $this->toPullRequest($response->json());
    }

    public function pullRequest(string $fullName, int $number): PullRequest
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get("/repos/{$fullName}/pulls/{$number}"), 'read the pull request');

        return $this->toPullRequest($response->json());
    }

    public function mergePullRequest(string $fullName, PullRequest $pullRequest, string $method): void
    {
        $this->send(fn (PendingRequest $http) => $http->put("/repos/{$fullName}/pulls/{$pullRequest->number}/merge", [
            'merge_method' => $method,
        ]), 'merge the pull request');
    }

    public function enableAutoMerge(string $fullName, PullRequest $pullRequest, string $method): void
    {
        $nodeId = $pullRequest->nodeId ?? $this->pullRequest($fullName, $pullRequest->number)->nodeId;

        // Auto-merge only exists in GitHub's GraphQL API.
        $response = $this->send(fn (PendingRequest $http) => $http->post($this->graphqlUrl(), [
            'query' => 'mutation($id: ID!, $method: PullRequestMergeMethod!) { enablePullRequestAutoMerge(input: {pullRequestId: $id, mergeMethod: $method}) { pullRequest { number } } }',
            'variables' => ['id' => $nodeId, 'method' => strtoupper($method)],
        ]), 'turn on auto-merge');

        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)->pluck('message')->filter()->implode(' ');

            throw new ProviderException("GitHub couldn't turn on auto-merge. GitHub said: {$message} (Auto-merge must be allowed in the repository settings, and the branch needs required checks.)");
        }
    }

    public function registerWebhook(string $fullName, string $url, string $secret): string
    {
        $response = $this->send(fn (PendingRequest $http) => $http->post("/repos/{$fullName}/hooks", [
            'name' => 'web',
            'active' => true,
            'events' => ['pull_request'],
            'config' => ['url' => $url, 'content_type' => 'json', 'secret' => $secret, 'insecure_ssl' => '0'],
        ]), 'add a webhook');

        return (string) $response->json('id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toPullRequest(array $data): PullRequest
    {
        $state = match (true) {
            ! empty($data['merged']) || ! empty($data['merged_at']) => PullRequest::MERGED,
            ($data['state'] ?? 'open') === 'closed' => PullRequest::CLOSED,
            default => PullRequest::OPEN,
        };

        return new PullRequest(
            number: (int) $data['number'],
            url: (string) $data['html_url'],
            state: $state,
            nodeId: isset($data['node_id']) ? (string) $data['node_id'] : null,
        );
    }

    private function graphqlUrl(): string
    {
        $base = rtrim((string) config('factory.github_api_url'), '/');

        // GitHub Enterprise: https://host/api/v3 -> https://host/api/graphql
        return str_ends_with($base, '/api/v3') ? substr($base, 0, -3).'graphql' : "{$base}/graphql";
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
