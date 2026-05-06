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
