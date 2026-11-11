<?php

namespace App\Runner\Agent;

readonly class AgentInvocation
{
    public function __construct(
        public string $workingDirectory,
        public string $prompt,
        public string $model,
        public string $effort,
        public string $apiKey,
        public string $configDirectory,
        public int $timeoutSeconds,
        public ?float $maxBudgetUsd = null,
        public ?string $resumeSessionId = null,
    ) {
        //
    }
}
