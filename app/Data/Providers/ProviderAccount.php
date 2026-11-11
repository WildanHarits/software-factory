<?php

namespace App\Data\Providers;

readonly class ProviderAccount
{
    /**
     * @param  array<int, string>|null  $scopes  Null when the provider doesn't report scopes (e.g. GitHub fine-grained tokens).
     */
    public function __construct(
        public string $name,
        public ?array $scopes = null,
    ) {
        //
    }
}
