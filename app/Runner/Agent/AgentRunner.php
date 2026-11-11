<?php

namespace App\Runner\Agent;

/**
 * Runs Claude Code once. Drivers decide where: on this machine ("local") or in a container (later).
 */
interface AgentRunner
{
    /**
     * @param  callable(string $kind, ?string $title, ?string $body): void  $onEvent
     * @param  callable(): bool  $shouldCancel
     */
    public function run(AgentInvocation $invocation, callable $onEvent, callable $shouldCancel): AgentResult;
}
