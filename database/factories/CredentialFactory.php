<?php

namespace Database\Factories;

use App\Enums\CredentialProvider;
use App\Models\Credential;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => null,
            'provider' => CredentialProvider::GitHub,
            'name' => fake()->words(2, true),
            'token' => 'ghp_'.Str::random(36),
        ];
    }

    public function personal(User $user): static
    {
        return $this->state(fn () => ['team_id' => null, 'user_id' => $user->id]);
    }

    public function gitlab(): static
    {
        return $this->state(fn () => ['provider' => CredentialProvider::GitLab, 'token' => 'glpat-'.Str::random(20)]);
    }

    public function anthropic(): static
    {
        return $this->state(fn () => ['provider' => CredentialProvider::Anthropic, 'token' => 'sk-ant-'.Str::random(40)]);
    }

    public function subscription(): static
    {
        return $this->state(fn () => ['provider' => CredentialProvider::ClaudeSubscription, 'token' => 'sk-ant-oat01-'.Str::random(40)]);
    }

    public function meta(): static
    {
        return $this->state(fn () => ['provider' => CredentialProvider::Meta, 'token' => 'meta-'.Str::random(40)]);
    }

    public function qwen(string $baseUrl = 'https://dashscope-intl.aliyuncs.com/apps/anthropic'): static
    {
        return $this->state(fn () => ['provider' => CredentialProvider::Qwen, 'token' => 'sk-'.Str::random(32), 'base_url' => $baseUrl]);
    }
}
