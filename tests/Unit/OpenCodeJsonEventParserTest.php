<?php

use App\Services\CodingAgents\OpenCodeJsonEventParser;

test('it parses an opencode error event and session id', function () {
    $output = file_get_contents(__DIR__.'/../Fixtures/opencode/failure-error-event.jsonl');

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->sessionId)->toBe('session-error-123')
        ->and($summary->hasError())->toBeTrue()
        ->and($summary->errorMessage)->toBe('Token refresh failed: 401');
});

test('it preserves raw non json stdout lines', function () {
    $output = file_get_contents(__DIR__.'/../Fixtures/opencode/failure-default-agent.txt');

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->hasError())->toBeFalse()
        ->and($summary->messages)->toBe([])
        ->and($summary->rawText())->toContain('default agent "Sisyphus - Ultraworker" not found');
});

test('it extracts assistant text model and usage from a success stream', function () {
    $output = file_get_contents(__DIR__.'/../Fixtures/opencode/success-events.jsonl');

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->sessionId)->toBe('session-success-123')
        ->and($summary->model)->toBe('openai/gpt-5.5')
        ->and($summary->messages)->toBe([
            "<proposed_plan>\n## Plan\n\n- Inspect files.\n</proposed_plan>",
        ])
        ->and($summary->inputTokens)->toBe(120)
        ->and($summary->cachedInputTokens)->toBe(20)
        ->and($summary->outputTokens)->toBe(30)
        ->and($summary->totalTokens)->toBe(150);
});

test('it falls back to error message variants when nested data message is absent', function () {
    $output = <<<'JSON'
{"type":"error","sessionID":"session-error-456","error":{"message":"Bad request"}}
JSON;

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->hasError())->toBeTrue()
        ->and($summary->errorMessage)->toBe('Bad request');
});

test('it collects recoverable tool errors without marking the stream as a top level failure', function () {
    $output = <<<'JSON'
{"type":"message.delta","sessionID":"session-tool-123","message":{"content":[{"type":"tool_use","name":"grep","state":{"status":"error","error":{"message":"ripgrep exited with status 2"}}},{"type":"text","text":"Recovered and finished the task."}]}}
JSON;

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->hasError())->toBeFalse()
        ->and($summary->hasAssistantOutput())->toBeTrue()
        ->and($summary->messages)->toBe(['Recovered and finished the task.'])
        ->and($summary->toolErrors)->toHaveCount(1)
        ->and($summary->toolErrors[0]['tool_name'])->toBe('grep')
        ->and($summary->toolErrors[0]['message'])->toBe('ripgrep exited with status 2');
});

test('it preserves proposed plan output when a recoverable tool error is present', function () {
    $output = <<<'JSON'
{"type":"message.completed","sessionID":"session-plan-123","message":{"content":[{"type":"tool_use","name":"glob","state":{"status":"error","error":{"message":"glob pattern failed"}}},{"type":"text","text":"<proposed_plan>\n## Plan\n\n- Retry with a narrower pattern.\n</proposed_plan>"}]}}
JSON;

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->hasError())->toBeFalse()
        ->and($summary->messages)->toBe([
            "<proposed_plan>\n## Plan\n\n- Retry with a narrower pattern.\n</proposed_plan>",
        ])
        ->and($summary->toolErrors)->toHaveCount(1)
        ->and($summary->toolErrors[0]['message'])->toBe('glob pattern failed');
});

test('it extracts assistant text from opencode part text events', function () {
    $output = <<<'JSON'
{"type":"text","timestamp":1778062129932,"sessionID":"ses_2033da4eeffeJWYa1EeernCavN","part":{"id":"prt_dfcc2cd73001tqfPtus9o0bv8k","messageID":"msg_dfcc2b04400119ghCPdpFyrf3P","sessionID":"ses_2033da4eeffeJWYa1EeernCavN","type":"text","text":"Now I have a complete understanding of the project. Let me create the implementation plan.\n\n<proposed_plan>\n## Summary\nPatch the parser.\n</proposed_plan>"}}
JSON;

    $summary = (new OpenCodeJsonEventParser)->parse($output);

    expect($summary->hasError())->toBeFalse()
        ->and($summary->hasAssistantOutput())->toBeTrue()
        ->and($summary->messages)->toBe([
            "Now I have a complete understanding of the project. Let me create the implementation plan.\n\n<proposed_plan>\n## Summary\nPatch the parser.\n</proposed_plan>",
        ]);
});
