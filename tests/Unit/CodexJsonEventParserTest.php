<?php

use App\Services\CodingAgents\CodexJsonEventParser;

test('it parses a complete codex json event stream', function () {
    $output = <<<'JSON'
{"type":"thread.started","thread_id":"thread-123"}
{"type":"item.completed","item":{"id":"item_1","type":"agent_message","text":"ok"}}
{"type":"turn.completed","usage":{"input_tokens":120,"cached_input_tokens":20,"output_tokens":30,"total_tokens":150}}
JSON;

    $summary = (new CodexJsonEventParser)->parse($output);

    expect($summary->sessionId)->toBe('thread-123')
        ->and($summary->messages)->toBe(['ok'])
        ->and($summary->inputTokens)->toBe(120)
        ->and($summary->cachedInputTokens)->toBe(20)
        ->and($summary->outputTokens)->toBe(30)
        ->and($summary->totalTokens)->toBe(150);
});

test('it tolerates missing session ids', function () {
    $summary = (new CodexJsonEventParser)->parse('{"type":"turn.completed","usage":{"input_tokens":10,"output_tokens":5}}');

    expect($summary->sessionId)->toBeNull()
        ->and($summary->totalTokens)->toBe(15);
});

test('it tolerates missing usage blocks', function () {
    $summary = (new CodexJsonEventParser)->parse('{"type":"thread.started","thread_id":"thread-456"}');

    expect($summary->sessionId)->toBe('thread-456')
        ->and($summary->totalTokens)->toBe(0);
});

test('it accumulates usage across multiple completed turns', function () {
    $output = <<<'JSON'
{"type":"thread.started","thread_id":"thread-789"}
{"type":"turn.completed","usage":{"input_tokens":10,"cached_input_tokens":2,"output_tokens":3,"total_tokens":13}}
{"type":"turn.completed","usage":{"input_tokens":20,"cached_input_tokens":5,"output_tokens":7,"total_tokens":27}}
JSON;

    $summary = (new CodexJsonEventParser)->parse($output);

    expect($summary->sessionId)->toBe('thread-789')
        ->and($summary->inputTokens)->toBe(30)
        ->and($summary->cachedInputTokens)->toBe(7)
        ->and($summary->outputTokens)->toBe(10)
        ->and($summary->totalTokens)->toBe(40);
});
