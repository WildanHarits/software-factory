<?php

namespace App\Enums;

enum CredentialProvider: string
{
    case GitHub = 'github';
    case GitLab = 'gitlab';
    case Anthropic = 'anthropic';

    public function label(): string
    {
        return match ($this) {
            self::GitHub => 'GitHub',
            self::GitLab => 'GitLab',
            self::Anthropic => 'Anthropic',
        };
    }

    /**
     * Whether this provider hosts git repositories.
     */
    public function isGit(): bool
    {
        return $this !== self::Anthropic;
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
    public static function options(): array
    {
        return array_map(fn (self $provider) => ['value' => $provider->value, 'label' => $provider->label()], self::cases());
    }
}
