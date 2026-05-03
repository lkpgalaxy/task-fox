<?php

use App\Services\PullRequests\GithubPullRequestProvider;

return [
    'coding_agent' => [
        'driver' => env('CODING_AGENT', 'codex'),
    ],

    'agent' => [
        'driver' => env('AGENT', env('CODING_AGENT', 'codex')),
        'retry_limit' => (int) env('AGENT_RETRY_LIMIT', 2),
    ],

    'tests' => [
        'command' => env('TEST_COMMAND', 'php artisan test --compact'),
    ],

    'external_task_provider' => env('EXTERNAL_TASK_PROVIDER'),

    'pull_request_provider' => GithubPullRequestProvider::class,
];
