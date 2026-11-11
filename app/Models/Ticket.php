<?php

namespace App\Models;

use App\Enums\ApprovalMode;
use App\Enums\PullRequestState;
use App\Enums\RunStatus;
use App\Enums\TicketStage;
use App\Enums\TicketStatus;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $team_id
 * @property int $project_id
 * @property int $number
 * @property string $title
 * @property string|null $description
 * @property TicketStage $stage
 * @property TicketStatus $status
 * @property int|null $feature_number
 * @property string|null $branch
 * @property string|null $feature_dir
 * @property int|null $pull_request_number
 * @property string|null $pull_request_url
 * @property PullRequestState|null $pull_request_state
 * @property Carbon|null $merged_at
 * @property string $model
 * @property string $effort
 * @property ApprovalMode $approval_mode
 * @property bool $skip_clarify
 * @property bool $skip_analyze
 * @property string|null $session_id
 * @property int|null $last_run_id
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Project $project
 * @property-read Run|null $lastRun
 * @property-read User|null $creator
 */
#[Fillable([
    'team_id', 'project_id', 'number', 'title', 'description', 'stage', 'status',
    'model', 'effort', 'approval_mode', 'skip_clarify', 'skip_analyze', 'created_by',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function lastRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'last_run_id');
    }

    /**
     * @return HasMany<TicketDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TicketDocument::class)->orderBy('path');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getRouteKeyName(): string
    {
        return 'number';
    }

    public function hasActiveRun(): bool
    {
        return $this->runs()->whereIn('status', RunStatus::active())->exists();
    }

    /**
     * The next column after the current one, skipping optional phases the ticket opted out of.
     */
    public function nextStage(): ?TicketStage
    {
        $stages = TicketStage::cases();

        for ($i = $this->stage->position() + 1; $i < count($stages); $i++) {
            if (! $this->skips($stages[$i])) {
                return $stages[$i];
            }
        }

        return null;
    }

    public function skips(TicketStage $stage): bool
    {
        return ($stage === TicketStage::Clarify && $this->skip_clarify)
            || ($stage === TicketStage::Analyze && $this->skip_analyze);
    }

    /**
     * The shape sent to the board.
     *
     * @return array<string, mixed>
     */
    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'title' => $this->title,
            'stage' => $this->stage->value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'branch' => $this->branch,
            'model' => $this->model,
            'model_label' => config("factory.models.{$this->model}", $this->model),
            'effort' => $this->effort,
            'approval_mode' => $this->approval_mode->value,
            'cost_usd' => round((float) ($this->runs_sum_cost_usd ?? 0), 4),
            'pull_request_number' => $this->pull_request_number,
            'pull_request_url' => $this->pull_request_url,
            'pull_request_state' => $this->pull_request_state?->value,
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * Everything the ticket panel shows.
     *
     * @return array<string, mixed>
     */
    public function toDetail(): array
    {
        $next = $this->nextStage();

        return [
            ...$this->toCard(),
            'description' => $this->description,
            'stage_label' => $this->stage->label(),
            'feature_dir' => $this->feature_dir,
            'skip_clarify' => $this->skip_clarify,
            'skip_analyze' => $this->skip_analyze,
            'next_stage' => $next?->value,
            'next_stage_label' => $next?->label(),
            // In Review, a reply goes to the Implement session and updates the pull request.
            'can_reply' => $this->session_id !== null && ($this->stage->isPhase() || $this->stage === TicketStage::Review),
            'pull_request_state_label' => $this->pull_request_state?->label(),
            'merged_at' => $this->merged_at?->toISOString(),
            'merge_policy_label' => $this->project->merge_policy->label(),
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at?->toISOString(),
            'last_run' => $this->lastRun?->toSummary(),
            'last_message' => $this->lastRun?->result_text,
            'documents' => $this->documents->map(fn (TicketDocument $document) => [
                'path' => $document->path,
                'name' => basename($document->path),
                'content' => $document->content,
                'updated_at' => $document->updated_at?->toISOString(),
            ])->values(),
            'runs' => $this->runs()->with('triggeredBy')->latest('id')->limit(20)->get()
                ->map(fn (Run $run) => $run->toSummary())->values(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => TicketStage::class,
            'status' => TicketStatus::class,
            'approval_mode' => ApprovalMode::class,
            'skip_clarify' => 'boolean',
            'skip_analyze' => 'boolean',
            'pull_request_state' => PullRequestState::class,
            'merged_at' => 'datetime',
        ];
    }
}
