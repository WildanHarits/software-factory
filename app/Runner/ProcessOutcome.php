<?php

namespace App\Runner;

readonly class ProcessOutcome
{
    public function __construct(
        public int $exitCode,
        public bool $cancelled = false,
        public bool $timedOut = false,
        public string $errorOutput = '',
    ) {
        //
    }

    public function successful(): bool
    {
        return $this->exitCode === 0 && ! $this->cancelled && ! $this->timedOut;
    }
}
