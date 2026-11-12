<?php

namespace App\Runner\Agent;

use App\Enums\CredentialProvider;

/**
 * Maps the Claude model a ticket picked to the model of the same tier at another provider.
 */
class ProviderModels
{
    /**
     * The provider's models by tier (opus, sonnet, haiku), or null for Anthropic itself.
     *
     * @return array{opus: string, sonnet: string, haiku: string}|null
     */
    public static function tiers(CredentialProvider $provider, ?string $baseUrl): ?array
    {
        $models = match ($provider) {
            CredentialProvider::Meta => config('factory.ai_providers.meta.models'),
            // Endpoint URLs contain dots, so they can't be part of a config() key.
            CredentialProvider::Qwen => ((array) config('factory.ai_providers.qwen.endpoints', []))[self::qwenEndpointKey($baseUrl)]['models'] ?? null,
            default => null,
        };

        return is_array($models) ? $models : null;
    }

    /**
     * The model to pass to --model.
     */
    public static function model(CredentialProvider $provider, ?string $baseUrl, string $claudeModel): string
    {
        $tiers = self::tiers($provider, $baseUrl);

        return $tiers === null ? $claudeModel : $tiers[self::tier($claudeModel)];
    }

    /**
     * Opus, Sonnet or Haiku. Other Claude models (e.g. Fable) count as the strongest tier.
     */
    public static function tier(string $claudeModel): string
    {
        foreach (['haiku', 'sonnet', 'opus'] as $tier) {
            if (str_contains(strtolower($claudeModel), $tier)) {
                return $tier;
            }
        }

        return 'opus';
    }

    /**
     * Where Claude Code sends requests for this provider (ANTHROPIC_BASE_URL), or null for Anthropic.
     */
    public static function baseUrl(CredentialProvider $provider, ?string $baseUrl): ?string
    {
        return match ($provider) {
            CredentialProvider::Meta => (string) config('factory.ai_providers.meta.base_url'),
            CredentialProvider::Qwen => self::qwenEndpointKey($baseUrl),
            default => null,
        };
    }

    /**
     * @return array<string, string> Endpoint URL => label.
     */
    public static function qwenEndpoints(): array
    {
        return array_map(fn (array $endpoint) => (string) $endpoint['label'], (array) config('factory.ai_providers.qwen.endpoints', []));
    }

    private static function qwenEndpointKey(?string $baseUrl): string
    {
        $endpoints = array_keys((array) config('factory.ai_providers.qwen.endpoints', []));

        return in_array($baseUrl, $endpoints, true) ? $baseUrl : (string) ($endpoints[0] ?? '');
    }
}
