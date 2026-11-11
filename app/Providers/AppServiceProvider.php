<?php

namespace App\Providers;

use App\Models\User;
use App\Runner\Agent\AgentRunner;
use App\Runner\Agent\LocalAgentRunner;
use App\Runner\Agent\SimulatedAgentRunner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AgentRunner::class, fn ($app) => match (config('factory.runner.driver')) {
            'local' => $app->make(LocalAgentRunner::class),
            // Placeholder files instead of AI, for trying the board without an API key.
            'simulated' => $app->isProduction()
                ? throw new InvalidArgumentException('The simulated runner is not available in production.')
                : $app->make(SimulatedAgentRunner::class),
            default => throw new InvalidArgumentException('Unknown runner driver ['.config('factory.runner.driver').']. Use "local".'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureDevCommands();
    }

    /**
     * `composer run dev` runs Horizon instead of a plain queue worker, so agent runs on the "runs" queue are picked up too.
     */
    protected function configureDevCommands(): void
    {
        if (! $this->app->runningInConsole() || ! class_exists(DevCommands::class)) {
            return;
        }

        DevCommands::except('queue');
        DevCommands::artisan('horizon', 'horizon');
    }

    /**
     * Configure application-wide authorization gates.
     */
    protected function configureGates(): void
    {
        Gate::define('manage-users', fn (User $user): bool => $user->is_super_admin);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
