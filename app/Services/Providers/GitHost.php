<?php

namespace App\Services\Providers;

use App\Data\Providers\ProviderAccount;
use App\Data\Providers\PullRequest;
use App\Data\Providers\RemoteRepository;
use App\Data\Providers\RepositoryNamespace;

/**
 * The calls Software Factory makes to a git hosting provider. All methods throw ProviderException on failure.
 */
interface GitHost
{
    public function account(): ProviderAccount;

    /**
     * @return array<int, RepositoryNamespace>
     */
    public function namespaces(): array;

    public function repository(string $fullName): RemoteRepository;

    public function createRepository(string $namespaceId, string $name, bool $private, ?string $description = null): RemoteRepository;

    public function pathExists(string $fullName, string $path, string $ref): bool;

    public function createPullRequest(string $fullName, string $head, string $base, string $title, string $body): PullRequest;

    public function pullRequest(string $fullName, int $number): PullRequest;

    /**
     * Merge now. $method is "squash", "merge" or "rebase".
     */
    public function mergePullRequest(string $fullName, PullRequest $pullRequest, string $method): void;

    /**
     * Merge automatically once required checks pass (GitHub auto-merge, GitLab "merge when pipeline succeeds").
     */
    public function enableAutoMerge(string $fullName, PullRequest $pullRequest, string $method): void;

    /**
     * Ask the provider to send pull/merge request events to $url. Returns the webhook's id.
     */
    public function registerWebhook(string $fullName, string $url, string $secret): string;
}
