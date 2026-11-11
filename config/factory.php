<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI models
    |--------------------------------------------------------------------------
    |
    | Models a project or ticket can pick. Keys are passed to Claude Code's
    | --model flag, so aliases ("opus") and full model IDs both work.
    |
    */

    'models' => [
        'claude-opus-5-5' => 'Claude Opus 5.5',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5',
        'claude-haiku-5-5' => 'Claude Haiku 5.5',
        'claude-fable-5-2' => 'Claude Fable 5.2',
    ],

    'default_model' => env('FACTORY_DEFAULT_MODEL', 'claude-sonnet-5-5'),

    /*
    |--------------------------------------------------------------------------
    | Effort levels
    |--------------------------------------------------------------------------
    |
    | How much reasoning effort the agent spends. Higher is slower and costs
    | more. The exact CLI flag is wired up with the runner (Phase 3).
    |
    */

    'efforts' => [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'xhigh' => 'Extra high',
        'max' => 'Max',
    ],

    'default_effort' => env('FACTORY_DEFAULT_EFFORT', 'medium'),

    /*
    |--------------------------------------------------------------------------
    | Provider API endpoints
    |--------------------------------------------------------------------------
    */

    'github_api_url' => env('GITHUB_API_URL', 'https://api.github.com'),

    'gitlab_url' => env('GITLAB_URL', 'https://gitlab.com'),

    'anthropic_api_url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com'),

    /*
    |--------------------------------------------------------------------------
    | Agent runner
    |--------------------------------------------------------------------------
    |
    | "local" runs Claude Code directly on this machine, inside a dedicated
    | workspace folder with Claude Code's sandbox turned on. "simulated" writes
    | placeholder Spec Kit files instead (no AI, no cost; not in production).
    | A "docker" driver (one container per run) is planned for servers.
    |
    */

    'runner' => [
        'driver' => env('FACTORY_RUNNER_DRIVER', 'local'),

        // Where repositories are cloned. Keep this outside the app's own folder.
        'workspace_path' => env('FACTORY_WORKSPACE_PATH') ?: rtrim((string) getenv('HOME'), '/').'/software-factory-workspaces',

        // Hard limit for one run, in seconds.
        'timeout' => (int) env('FACTORY_RUN_TIMEOUT', 3600),

        // Claude Code stops a run that would cost more than this (USD). Empty = no limit.
        'max_budget_usd' => env('FACTORY_MAX_BUDGET_USD', 10),

        'binaries' => [
            'claude' => env('CLAUDE_BIN', 'claude'),
            'specify' => env('SPECIFY_BIN', 'specify'),
            'git' => env('GIT_BIN', 'git'),
        ],

        // Tools the agent may not use. Web tools run outside the sandbox, so they're off.
        'disallowed_tools' => ['WebFetch', 'WebSearch'],

        // Domains shell commands inside the sandbox may reach (package registries etc.).
        // Claude's own API calls are not affected by this list.
        'allowed_domains' => array_values(array_filter(explode(',', (string) env(
            'FACTORY_SANDBOX_DOMAINS',
            'registry.npmjs.org,registry.yarnpkg.com,pypi.org,files.pythonhosted.org,repo.packagist.org,api.github.com,github.com,codeload.github.com,objects.githubusercontent.com',
        )))),

        // Commits made by the app use this identity.
        'git_author_name' => env('FACTORY_GIT_AUTHOR_NAME', 'Software Factory'),
        'git_author_email' => env('FACTORY_GIT_AUTHOR_EMAIL', 'software-factory@users.noreply.local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pull requests
    |--------------------------------------------------------------------------
    */

    'git' => [
        // How finished features are merged: "squash", "merge" or "rebase" (GitLab: squash or merge).
        'merge_method' => env('FACTORY_MERGE_METHOD', 'squash'),

        // How often open pull requests are checked when webhooks can't reach this app (minutes).
        'sync_every_minutes' => (int) env('FACTORY_PR_SYNC_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spec Kit
    |--------------------------------------------------------------------------
    |
    | Command names change between Spec Kit versions (v1.4 uses skills such as
    | /speckit-specify; older versions used /speckit.specify or /specify).
    | Update these together with the installed CLI version.
    |
    */

    'speckit' => [
        'version' => env('SPECKIT_VERSION', '1.4.0'),
        'integration' => 'claude',
        'commands' => [
            'constitution' => '/speckit-constitution',
            'specify' => '/speckit-specify',
            'clarify' => '/speckit-clarify',
            'plan' => '/speckit-plan',
            'tasks' => '/speckit-tasks',
            'analyze' => '/speckit-analyze',
            'implement' => '/speckit-implement',
            'converge' => '/speckit-converge',
        ],
    ],

];
