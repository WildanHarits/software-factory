<?php

namespace App\Services\Providers;

use App\Data\Providers\ProviderAccount;
use App\Enums\CredentialProvider;
use App\Models\Credential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class ProviderRegistry
{
    public function gitHost(Credential $credential): GitHost
    {
        return match ($credential->provider) {
            CredentialProvider::GitHub => new GitHubHost($credential),
            CredentialProvider::GitLab => new GitLabHost($credential),
            CredentialProvider::Anthropic => throw new InvalidArgumentException('Anthropic credentials are not git hosts.'),
        };
    }

    /**
     * Check a token against its provider and return who it belongs to.
     *
     * @throws ProviderException
     */
    public function account(Credential $credential): ProviderAccount
    {
        if ($credential->provider->isGit()) {
            return $this->gitHost($credential)->account();
        }

        return $this->anthropicAccount($credential);
    }

    private function anthropicAccount(Credential $credential): ProviderAccount
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('factory.anthropic_api_url'), '/'))
                ->withHeaders([
                    'x-api-key' => $credential->token,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout(15)
                ->get('/v1/models', ['limit' => 1]);
        } catch (ConnectionException $exception) {
            throw ProviderException::unreachable('Anthropic', $exception);
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse('Anthropic', $response, 'list models');
        }

        return new ProviderAccount(name: 'API key');
    }
}
