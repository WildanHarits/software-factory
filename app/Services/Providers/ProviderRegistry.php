<?php

namespace App\Services\Providers;

use App\Data\Providers\ProviderAccount;
use App\Enums\CredentialProvider;
use App\Models\Credential;
use App\Runner\Agent\ProviderModels;
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
            default => throw new InvalidArgumentException("{$credential->provider->label()} credentials are not git hosts."),
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

        return match ($credential->provider) {
            CredentialProvider::ClaudeSubscription => $this->subscriptionAccount($credential),
            CredentialProvider::Meta => $this->metaAccount($credential),
            CredentialProvider::Qwen => $this->qwenAccount($credential),
            default => $this->anthropicAccount($credential),
        };
    }

    /**
     * Subscription tokens are only ever used by Claude Code itself, so the app doesn't call
     * Anthropic with them; it only checks the shape. A wrong token shows up in the first run.
     */
    private function subscriptionAccount(Credential $credential): ProviderAccount
    {
        if (! str_starts_with($credential->token, 'sk-ant-oat')) {
            throw new ProviderException('This is not a Claude subscription token. Run `claude setup-token` and paste what it prints.');
        }

        return new ProviderAccount(name: 'Claude subscription');
    }

    private function metaAccount(Credential $credential): ProviderAccount
    {
        try {
            $response = Http::baseUrl(rtrim((string) config('factory.ai_providers.meta.base_url'), '/'))
                ->withToken($credential->token)
                ->timeout(15)
                ->get('/v1/models');
        } catch (ConnectionException $exception) {
            throw ProviderException::unreachable('Meta', $exception);
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse('Meta', $response, 'list models');
        }

        return new ProviderAccount(name: 'API key');
    }

    /**
     * Qwen's Anthropic endpoint has no model list, so send the smallest possible message.
     */
    private function qwenAccount(Credential $credential): ProviderAccount
    {
        $baseUrl = (string) ProviderModels::baseUrl(CredentialProvider::Qwen, $credential->base_url);
        $tiers = ProviderModels::tiers(CredentialProvider::Qwen, $credential->base_url) ?? ['haiku' => 'qwen3.6-flash'];

        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($credential->token)
                ->withHeaders(['anthropic-version' => '2023-06-01'])
                ->timeout(30)
                ->post('/v1/messages', [
                    'model' => $tiers['haiku'],
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'Hi']],
                ]);
        } catch (ConnectionException $exception) {
            throw ProviderException::unreachable('Qwen', $exception);
        }

        if ($response->failed()) {
            throw ProviderException::fromResponse('Qwen', $response, 'send a test message');
        }

        return new ProviderAccount(name: ProviderModels::qwenEndpoints()[$baseUrl] ?? 'API key');
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
