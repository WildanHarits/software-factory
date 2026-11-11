<?php

namespace App\Runner\Agent;

/**
 * Turns Claude Code's `--output-format stream-json` lines into readable log events,
 * and keeps the final result (cost, tokens, session).
 */
class StreamJsonParser
{
    private const MAX_TOOL_OUTPUT = 4000;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?string $sessionId = null;

    /**
     * @return array<int, array{kind: string, title: string|null, body: string|null}>
     */
    public function parse(string $line): array
    {
        $line = trim($line);

        if ($line === '' || $line[0] !== '{') {
            return $line === '' ? [] : [$this->event('output', null, $line)];
        }

        $data = json_decode($line, true);

        if (! is_array($data)) {
            return [$this->event('output', null, $line)];
        }

        $this->sessionId = is_string($data['session_id'] ?? null) ? $data['session_id'] : $this->sessionId;

        return match ($data['type'] ?? null) {
            'system' => $this->system($data),
            'assistant' => $this->assistant($data),
            'user' => $this->toolResults($data),
            'result' => $this->finish($data),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{kind: string, title: string|null, body: string|null}>
     */
    private function system(array $data): array
    {
        if (($data['subtype'] ?? null) !== 'init') {
            return [];
        }

        $model = is_string($data['model'] ?? null) ? $data['model'] : 'unknown model';

        return [$this->event('system', "Agent started ({$model})", null)];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{kind: string, title: string|null, body: string|null}>
     */
    private function assistant(array $data): array
    {
        $events = [];

        foreach ($data['message']['content'] ?? [] as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (($block['type'] ?? null) === 'text' && trim((string) ($block['text'] ?? '')) !== '') {
                $events[] = $this->event('assistant', null, trim((string) $block['text']));
            }

            if (($block['type'] ?? null) === 'tool_use') {
                $events[] = $this->toolUse((string) ($block['name'] ?? 'Tool'), is_array($block['input'] ?? null) ? $block['input'] : []);
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{kind: string, title: string|null, body: string|null}
     */
    private function toolUse(string $name, array $input): array
    {
        $summary = match ($name) {
            'Bash' => $input['command'] ?? null,
            'Read', 'Write', 'Edit', 'MultiEdit', 'NotebookEdit' => $input['file_path'] ?? null,
            'Glob', 'Grep' => $input['pattern'] ?? null,
            'Skill' => $input['skill'] ?? $input['command'] ?? null,
            default => null,
        };

        $title = $summary ? "{$name}: ".str_replace("\n", ' ', (string) $summary) : $name;

        $body = match ($name) {
            'Write' => $input['content'] ?? null,
            'Edit' => isset($input['new_string']) ? '- '.($input['old_string'] ?? '')."\n+ ".$input['new_string'] : null,
            'Bash' => null,
            default => $summary ? null : json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        };

        return $this->event('tool', $title, is_string($body) ? mb_substr($body, 0, self::MAX_TOOL_OUTPUT) : null);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{kind: string, title: string|null, body: string|null}>
     */
    private function toolResults(array $data): array
    {
        $events = [];

        foreach ($data['message']['content'] ?? [] as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'tool_result') {
                continue;
            }

            $content = $block['content'] ?? '';

            if (is_array($content)) {
                $content = collect($content)
                    ->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part)
                    ->implode("\n");
            }

            $text = trim((string) $content);

            if ($text === '' && empty($block['is_error'])) {
                continue;
            }

            $events[] = $this->event(
                'tool_result',
                ! empty($block['is_error']) ? 'Tool error' : null,
                mb_strlen($text) > self::MAX_TOOL_OUTPUT ? mb_substr($text, 0, self::MAX_TOOL_OUTPUT).'…' : $text,
            );
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{kind: string, title: string|null, body: string|null}>
     */
    private function finish(array $data): array
    {
        $this->result = $data;

        $cost = number_format((float) ($data['total_cost_usd'] ?? 0), 4);
        $subtype = (string) ($data['subtype'] ?? '');
        $title = ! empty($data['is_error'])
            ? 'Agent stopped'.(in_array($subtype, ['', 'success'], true) ? '' : ': '.str_replace('_', ' ', $subtype))
            : "Agent finished · \${$cost}";

        return [$this->event('result', $title, is_string($data['result'] ?? null) ? $data['result'] : null)];
    }

    /**
     * @return array{kind: string, title: string|null, body: string|null}
     */
    private function event(string $kind, ?string $title, ?string $body): array
    {
        return ['kind' => $kind, 'title' => $title, 'body' => $body];
    }
}
