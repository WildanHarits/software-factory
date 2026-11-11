<?php

namespace App\Models;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Tickets\TicketWorkflow;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $project_id
 * @property int|null $ticket_id
 * @property string|null $result_text
 * @property RunType $type
 * @property RunStatus $status
 * @property array<string, mixed>|null $input
 * @property string|null $model
 * @property string|null $effort
 * @property string|null $base_branch
 * @property string|null $branch
 * @property string|null $commit_sha
 * @property int|null $pull_request_number
 * @property string|null $pull_request_url
 * @property string|null $session_id
 * @property int|null $num_turns
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cache_read_tokens
 * @property int $cache_write_tokens
 * @property string $cost_usd
 * @property string|null $error
 * @property int|null $triggered_by
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Ticket|null $ticket
 * @property-read Team $team
 * @property-read User|null $triggeredBy
 */
#[Fillable(['team_id', 'project_id', 'ticket_id', 'type', 'status', 'input', 'model', 'effort', 'base_branch', 'triggered_by'])]
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * @return HasMany<RunEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(RunEvent::class)->orderBy('seq');
    }

    /**
     * @param  Builder<Run>  $query
     * @return Builder<Run>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', RunStatus::active());
    }

    /**
     * Stop a queued run now, or ask the worker to stop a running one (it checks every couple of seconds).
     */
    public function requestCancel(): void
    {
        if ($this->status === RunStatus::Queued) {
            $this->forceFill(['status' => RunStatus::Cancelled, 'finished_at' => now()])->save();
            app(TicketWorkflow::class)->afterRun($this);
        } elseif ($this->status === RunStatus::Running) {
            $this->forceFill(['status' => RunStatus::Cancelling])->save();
        }
    }

    /**
     * Whether someone asked to stop this run. Reads the latest value from the database.
     */
    public function cancelRequested(): bool
    {
        // toBase() returns the raw string; through Eloquent it would come back as a RunStatus enum.
        $status = static::query()->whereKey($this->id)->toBase()->value('status');

        return in_array($status, [RunStatus::Cancelling->value, RunStatus::Cancelled->value], true);
    }

    /**
     * The shape sent to the browser.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_active' => $this->status->isActive(),
            'model' => $this->model,
            'model_label' => $this->model ? config("factory.models.{$this->model}", $this->model) : null,
            'effort' => $this->effort,
            'branch' => $this->branch,
            'commit_sha' => $this->commit_sha,
            'pull_request_number' => $this->pull_request_number,
            'pull_request_url' => $this->pull_request_url,
            'input_tokens' => $this->input_tokens,
            'output_tokens' => $this->output_tokens,
            'cache_read_tokens' => $this->cache_read_tokens,
            'cache_write_tokens' => $this->cache_write_tokens,
            'cost_usd' => (float) $this->cost_usd,
            'num_turns' => $this->num_turns,
            'error' => $this->error,
            'ticket_id' => $this->ticket_id,
            'is_reply' => ! empty($this->input['reply']),
            'triggered_by' => $this->triggeredBy?->name,
            'created_at' => $this->created_at?->toISOString(),
            'started_at' => $this->started_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RunType::class,
            'status' => RunStatus::class,
            'input' => 'array',
            'cost_usd' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
