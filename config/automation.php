<?php

use App\Services\PullRequests\GithubPullRequestProvider;

return [
    'repository' => [
        'path' => env('REPOSITORY_PATH', base_path()),
        'base_branch' => env('REPOSITORY_BASE_BRANCH', 'main'),
    ],

    'coding_agent' => [
        'driver' => env('CODING_AGENT', 'codex'),
    ],

    'agent' => [
        'retry_limit' => (int) env('AGENT_RETRY_LIMIT', 2),
    ],

    'tests' => [
        'command' => env('TEST_COMMAND', 'php artisan test --compact'),
    ],

    'external_task_provider' => env('EXTERNAL_TASK_PROVIDER'),

    'pull_request_provider' => GithubPullRequestProvider::class,
];
