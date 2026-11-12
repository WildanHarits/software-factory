<?php

namespace App\Enums;

enum CredentialProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Anthropic = 'anthropic';
    case ClaudeSubscription = 'claude_subscription';
    case Meta = 'meta';
    case Qwen = 'qwen';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Anthropic => 'Anthropic API',
            self::ClaudeSubscription => 'Claude subscription',
            self::Meta => 'Meta',
            self::Qwen => 'Qwen',
        };
    }

    /**
     * Whether this provider hosts git repositories.
     */
    public function isGit(): bool
    {
        return in_array($this, [self::GitHub, self::GitLab], true);
    }

    /**
     * Whether this token runs the agent (Claude Code).
     */
    public function isAi(): bool
    {
        return ! $this->isGit();
    }

    /**
     * A Claude Pro/Max/Team login token. Anthropic only allows it for its owner's own use,
     * so it is always a personal token and only runs work its owner starts.
     */
    public function isSubscription(): bool
    {
        return $this === self::ClaudeSubscription;
    }

    /**
     * An Anthropic-compatible endpoint from another company, reached through ANTHROPIC_BASE_URL.
     */
    public function isThirdPartyModel(): bool
    {
        return in_array($this, [self::Meta, self::Qwen], true);
    }

    /**
     * @return array<int, self>
     */
    public static function gitProviders(): array
    {
        return array_values(array_filter(self::cases(), fn (self $provider) => $provider->isGit()));
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(bool $includeSubscription = true): array
    {
        $cases = array_filter(self::cases(), fn (self $provider) => $includeSubscription || ! $provider->isSubscription());

        return array_values(array_map(fn (self $provider) => ['value' => $provider->value, 'label' => $provider->label()], $cases));
    }
}
