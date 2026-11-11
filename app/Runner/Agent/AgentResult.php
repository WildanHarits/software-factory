<?php

namespace App\Runner\Agent;

readonly class AgentResult
{
    /**
     * @param  array<int, mixed>  $permissionDenials
     */
    public function __construct(
        public bool $successful,
        public ?string $sessionId = null,
        public float $costUsd = 0.0,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public ?int $numTurns = null,
        public ?string $resultText = null,
        public ?string $error = null,
        public bool $cancelled = false,
        public bool $timedOut = false,
        public array $permissionDenials = [],
    ) {
        //
    }
}
