<?php

namespace App\Runner;

use App\Models\Run;
use App\Models\RunEvent;

/**
 * Appends lines to a run's log, which the run page shows live.
 */
class RunRecorder
{
    private const MAX_BODY = 20_000;

    private int $seq;

    public function __construct(private Run $run)
    {
        $this->seq = (int) RunEvent::query()->where('run_id', $run->id)->max('seq');
    }

    public function record(string $kind, ?string $title = null, ?string $body = null): void
    {
        $this->seq++;

        RunEvent::create([
            'run_id' => $this->run->id,
            'seq' => $this->seq,
            'kind' => $kind,
            'title' => $title === null ? null : mb_substr(self::validUtf8($title), 0, 250),
            'body' => $body === null ? null : $this->truncate(self::validUtf8($body)),
            'created_at' => now(),
        ]);
    }

    public function stage(string $title): void
    {
        $this->record('stage', $title);
    }

    public function output(string $line): void
    {
        $clean = trim(self::stripAnsi($line));

        if ($clean !== '') {
            $this->record('output', null, $clean);
        }
    }

    public function error(string $message): void
    {
        $this->record('error', null, $message);
    }

    /**
     * Command output can contain broken byte sequences; those would break the JSON the log page reads.
     */
    public static function validUtf8(string $text): string
    {
        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    public static function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]|\e\][^\a]*\a/', '', $text);
    }

    private function truncate(string $body): string
    {
        return mb_strlen($body) > self::MAX_BODY
            ? mb_substr($body, 0, self::MAX_BODY)."\n… (cut, ".(mb_strlen($body) - self::MAX_BODY).' more characters)'
            : $body;
    }
}
