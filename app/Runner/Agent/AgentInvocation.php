<?php

namespace App\Runner\Agent;

use App\Enums\CredentialProvider;

readonly class AgentInvocation
{
    public function __construct(
        public string $workingDirectory,
        public string $prompt,
        public string $model,
        public string $effort,
        /** The AI token: an Anthropic, Meta or Qwen API key, or a Claude subscription token. */
        public string $apiKey,
        public string $configDirectory,
        public int $timeoutSeconds,
        public ?float $maxBudgetUsd = null,
        public ?string $resumeSessionId = null,
        /** @var array<string, string> Extra environment variables, e.g. SPECIFY_FEATURE_DIRECTORY. */
        public array $environment = [],
        public ?string $appendSystemPrompt = null,
        public CredentialProvider $provider = CredentialProvider::Anthropic,
        /** The credential's endpoint, e.g. which Qwen plan. */
        public ?string $baseUrl = null,
    ) {
        //
    }
}
