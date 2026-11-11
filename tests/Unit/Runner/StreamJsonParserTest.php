<?php

namespace Tests\Unit\Runner;

use App\Runner\Agent\StreamJsonParser;
use PHPUnit\Framework\TestCase;

class StreamJsonParserTest extends TestCase
{
    private function line(array $data): string
    {
        return json_encode($data);
    }

    public function test_it_reads_a_full_agent_session()
    {
        $parser = new StreamJsonParser;

        $events = [
            ...$parser->parse($this->line(['type' => 'system', 'subtype' => 'init', 'session_id' => 'abc', 'model' => 'claude-sonnet-5-5'])),
            ...$parser->parse($this->line(['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'text', 'text' => 'Let me look at the template.'],
                ['type' => 'tool_use', 'name' => 'Bash', 'input' => ['command' => "ls -la\n.specify"]],
                ['type' => 'tool_use', 'name' => 'Write', 'input' => ['file_path' => '/w/constitution.md', 'content' => '# Principles']],
            ]]])),
            ...$parser->parse($this->line(['type' => 'user', 'message' => ['content' => [
                ['type' => 'tool_result', 'content' => [['type' => 'text', 'text' => 'memory templates']]],
                ['type' => 'tool_result', 'content' => 'permission denied', 'is_error' => true],
            ]]])),
            ...$parser->parse($this->line([
                'type' => 'result', 'subtype' => 'success', 'is_error' => false, 'session_id' => 'abc',
                'total_cost_usd' => 0.1234, 'num_turns' => 3, 'result' => 'Done.',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ])),
        ];

        $this->assertSame([
            ['kind' => 'system', 'title' => 'Agent started (claude-sonnet-5-5)', 'body' => null],
            ['kind' => 'assistant', 'title' => null, 'body' => 'Let me look at the template.'],
            ['kind' => 'tool', 'title' => 'Bash: ls -la .specify', 'body' => null],
            ['kind' => 'tool', 'title' => 'Write: /w/constitution.md', 'body' => '# Principles'],
            ['kind' => 'tool_result', 'title' => null, 'body' => 'memory templates'],
            ['kind' => 'tool_result', 'title' => 'Tool error', 'body' => 'permission denied'],
            ['kind' => 'result', 'title' => 'Agent finished · $0.1234', 'body' => 'Done.'],
        ], $events);

        $this->assertSame('abc', $parser->sessionId);
        $this->assertSame(0.1234, $parser->result['total_cost_usd']);
    }

    public function test_errors_reported_as_success_get_a_plain_title()
    {
        $parser = new StreamJsonParser;

        $events = $parser->parse($this->line(['type' => 'result', 'subtype' => 'success', 'is_error' => true, 'result' => 'Invalid API key']));

        $this->assertSame('Agent stopped', $events[0]['title']);

        $events = $parser->parse($this->line(['type' => 'result', 'subtype' => 'error_max_turns', 'is_error' => true]));

        $this->assertSame('Agent stopped: error max turns', $events[0]['title']);
    }

    public function test_non_json_lines_are_kept_as_output_and_unknown_types_are_ignored()
    {
        $parser = new StreamJsonParser;

        $this->assertSame([['kind' => 'output', 'title' => null, 'body' => 'warning: something']], $parser->parse('warning: something'));
        $this->assertSame([], $parser->parse($this->line(['type' => 'stream_event'])));
        $this->assertSame([], $parser->parse('   '));
        $this->assertNull($parser->result);
    }

    public function test_long_tool_output_is_shortened()
    {
        $parser = new StreamJsonParser;

        $events = $parser->parse($this->line(['type' => 'user', 'message' => ['content' => [
            ['type' => 'tool_result', 'content' => str_repeat('x', 10_000)],
        ]]]));

        $this->assertLessThan(4100, mb_strlen((string) $events[0]['body']));
    }
}
