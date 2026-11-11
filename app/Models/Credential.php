<?php

namespace App\Models;

use App\Enums\CredentialProvider;
use Database\Factories\CredentialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An API token for GitHub, GitLab or Anthropic, owned by a team or by one user.
 *
 * @property int $id
 * @property int|null $team_id
 * @property int|null $user_id
 * @property int|null $created_by
 * @property CredentialProvider $provider
 * @property string $name
 * @property string $token
 * @property string $token_last_four
 * @property string|null $base_url
 * @property string|null $account_name
 * @property array<int, string>|null $scopes
 * @property Carbon|null $last_verified_at
 * @property string|null $last_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Team|null $team
 * @property-read User|null $user
 */
#[Fillable(['team_id', 'user_id', 'created_by', 'provider', 'name', 'token', 'base_url'])]
#[Hidden(['token'])]
class Credential extends Model
{
    /** @use HasFactory<CredentialFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Credential $credential) {
            if ($credential->isDirty('token')) {
                $credential->token_last_four = substr($credential->token, -4);
            }
        });
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Credentials a user may pick for a project in the given team:
     * the team's own tokens plus the user's personal tokens.
     *
     * @param  Builder<Credential>  $query
     * @return Builder<Credential>
     */
    public function scopeUsableBy(Builder $query, User $user, Team $team): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('team_id', $team->id)
            ->orWhere('user_id', $user->id));
    }

    /**
     * The shape sent to the browser. Never includes the token itself.
     *
     * @return array<string, mixed>
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'provider' => $this->provider->value,
            'provider_label' => $this->provider->label(),
            'owner' => $this->isPersonal() ? 'personal' : 'team',
            'owner_label' => $this->isPersonal() ? ($this->user->name ?? 'Personal') : ($this->team->name ?? 'Team'),
            'token_hint' => '••••'.$this->token_last_four,
            'base_url' => $this->base_url,
            'account_name' => $this->account_name,
            'scopes' => $this->scopes,
            'last_verified_at' => $this->last_verified_at?->toISOString(),
            'last_error' => $this->last_error,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => CredentialProvider::class,
            'token' => 'encrypted',
            'scopes' => 'array',
            'last_verified_at' => 'datetime',
        ];
    }
}
