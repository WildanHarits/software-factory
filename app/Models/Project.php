<?php

namespace App\Models;

use App\Enums\ApprovalMode;
use App\Enums\CredentialProvider;
use App\Enums\MergePolicy;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $team_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property CredentialProvider $provider
 * @property string $repo_full_name
 * @property string $repo_external_id
 * @property string $repo_web_url
 * @property string $repo_clone_url
 * @property string $default_branch
 * @property bool $repo_private
 * @property int|null $git_credential_id
 * @property int|null $ai_credential_id
 * @property string $default_model
 * @property string $default_effort
 * @property ApprovalMode $approval_mode
 * @property MergePolicy $merge_policy
 * @property bool $speckit_initialized
 * @property Carbon|null $speckit_checked_at
 * @property string|null $webhook_secret
 * @property string|null $webhook_id
 * @property Carbon|null $webhook_last_received_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Team $team
 * @property-read Credential|null $gitCredential
 * @property-read Credential|null $aiCredential
 * @property-read User|null $creator
 */
#[Fillable([
    'team_id', 'name', 'slug', 'description',
    'provider', 'repo_full_name', 'repo_external_id', 'repo_web_url', 'repo_clone_url', 'default_branch', 'repo_private',
    'git_credential_id', 'ai_credential_id',
    'default_model', 'default_effort', 'approval_mode', 'merge_policy',
    'speckit_initialized', 'speckit_checked_at', 'created_by',
])]
#[Hidden(['webhook_secret'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = static::uniqueSlug($project->team_id, $project->name);
            }
        });
    }

    /**
     * A slug that is unique within the team, including soft-deleted projects.
     */
    public static function uniqueSlug(int $teamId, string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: 'project';
        $slug = $base;
        $suffix = 2;

        while (static::withTrashed()
            ->where('team_id', $teamId)
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function gitCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'git_credential_id');
    }

    /**
     * @return BelongsTo<Credential, $this>
     */
    public function aiCredential(): BelongsTo
    {
        return $this->belongsTo(Credential::class, 'ai_credential_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'repo_full_name' => $this->repo_full_name,
            'repo_web_url' => $this->repo_web_url,
            'default_branch' => $this->default_branch,
            'repo_private' => $this->repo_private,
            'git_credential_id' => $this->git_credential_id,
            'ai_credential_id' => $this->ai_credential_id,
            'git_credential' => $this->gitCredential?->toSummary(),
            'ai_credential' => $this->aiCredential?->toSummary(),
            'default_model' => $this->default_model,
            'default_model_label' => config("factory.models.{$this->default_model}", $this->default_model),
            'default_effort' => $this->default_effort,
            'default_effort_label' => config("factory.efforts.{$this->default_effort}", $this->default_effort),
            'approval_mode' => $this->approval_mode->value,
            'approval_mode_label' => $this->approval_mode->label(),
            'merge_policy' => $this->merge_policy->value,
            'merge_policy_label' => $this->merge_policy->label(),
            'speckit_initialized' => $this->speckit_initialized,
            'speckit_checked_at' => $this->speckit_checked_at?->toISOString(),
            'created_by' => $this->creator?->name,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @return HasMany<Run, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(Run::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * A link to a file on a branch, on GitHub or GitLab.
     */
    public function fileUrl(string $branch, string $path): string
    {
        $separator = $this->provider === CredentialProvider::GitLab ? '/-/blob/' : '/blob/';

        return rtrim($this->repo_web_url, '/').$separator.$branch.'/'.ltrim($path, '/');
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => CredentialProvider::class,
            'approval_mode' => ApprovalMode::class,
            'merge_policy' => MergePolicy::class,
            'repo_private' => 'boolean',
            'speckit_initialized' => 'boolean',
            'speckit_checked_at' => 'datetime',
            'webhook_secret' => 'encrypted',
            'webhook_last_received_at' => 'datetime',
        ];
    }
}
