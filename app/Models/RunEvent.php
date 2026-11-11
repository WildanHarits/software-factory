<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line in a run's log.
 *
 * Kinds: stage (a step of the run started), output (command output), assistant (agent text),
 * tool (agent used a tool), tool_result, result (agent finished), error.
 *
 * @property int $id
 * @property int $run_id
 * @property int $seq
 * @property string $kind
 * @property string|null $title
 * @property string|null $body
 * @property Carbon|null $created_at
 */
#[Fillable(['run_id', 'seq', 'kind', 'title', 'body', 'created_at'])]
class RunEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return array{seq: int, kind: string, title: string|null, body: string|null, at: string|null}
     */
    public function toSummary(): array
    {
        return [
            'seq' => $this->seq,
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'at' => $this->created_at?->toISOString(),
        ];
    }
}
