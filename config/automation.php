<?php

use App\Services\PullRequests\GithubPullRequestProvider;

return [
    'supported_agent_drivers' => [
        'codex' => 'Codex',
        'opencode' => 'OpenCode',
    ],

    'supported_coding_agent_drivers' => [
        'codex' => 'Codex',
        'opencode' => 'OpenCode',
    ],

    'coding_agent' => [
        'driver' => env('CODING_AGENT', 'codex'),
    ],

    'agent' => [
        'driver' => env('AGENT', env('CODING_AGENT', 'codex')),
        'retry_limit' => (int) env('AGENT_RETRY_LIMIT', 3),
    ],

    'external_task_provider' => env('EXTERNAL_TASK_PROVIDER'),

    'external_task_providers' => [
        // 'linear' => [
        //     'label' => 'Linear',
        //     'class' => \App\Services\ExternalTaskProviders\LinearExternalTaskProvider::class,
        // ],
    ],

    'pull_request_provider' => GithubPullRequestProvider::class,
];
