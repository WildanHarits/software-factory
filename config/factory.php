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

];
