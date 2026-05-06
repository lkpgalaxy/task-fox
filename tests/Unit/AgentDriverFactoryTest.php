<?php

use App\Contracts\Agent;
use App\Contracts\CodingAgent;
use App\Services\Automation\AgentDriverFactory;
use App\Services\CodingAgents\CodexCodingAgent;
use App\Services\CodingAgents\OpenCodeCodingAgent;

test('agent driver factory resolves codex and opencode drivers', function () {
    app()->bind(Agent::class, CodexCodingAgent::class);
    app()->bind(CodingAgent::class, CodexCodingAgent::class);

    $factory = app(AgentDriverFactory::class);

    expect($factory->makeAgent('codex'))->toBeInstanceOf(CodexCodingAgent::class)
        ->and($factory->makeAgent('opencode'))->toBeInstanceOf(OpenCodeCodingAgent::class)
        ->and($factory->makeCodingAgent('codex'))->toBeInstanceOf(CodexCodingAgent::class)
        ->and($factory->makeCodingAgent('opencode'))->toBeInstanceOf(OpenCodeCodingAgent::class);
});
