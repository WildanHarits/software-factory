<?php

namespace Tests\Fakes;

use App\Runner\Agent\AgentInvocation;
use App\Runner\Agent\AgentResult;
use App\Runner\Agent\AgentRunner;

/**
 * Stands in for Claude Code in tests: records what it was asked and returns a set result.
 */
class FakeAgentRunner implements AgentRunner
{
    /** @var array<int, AgentInvocation> */
    public array $invocations = [];

    public function __construct(public ?AgentResult $result = null)
    {
        $this->result ??= new AgentResult(
            successful: true,
            sessionId: 'session-123',
            costUsd: 0.4321,
            inputTokens: 1200,
            outputTokens: 340,
            cacheReadTokens: 5000,
            cacheWriteTokens: 800,
            numTurns: 7,
            resultText: 'Constitution written.',
        );
    }

    public function run(AgentInvocation $invocation, callable $onEvent, callable $shouldCancel): AgentResult
    {
        $this->invocations[] = $invocation;

        $onEvent('system', 'Agent started (fake)', null);
        $onEvent('assistant', null, 'Updating the constitution.');
        $onEvent('result', 'Agent finished', $this->result->resultText);

        return $this->result;
    }
}
