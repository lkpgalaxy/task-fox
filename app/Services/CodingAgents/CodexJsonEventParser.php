<?php

namespace App\Services\CodingAgents;

use App\DataTransferObjects\CodexJsonEventSummary;
use Illuminate\Support\Arr;
use JsonException;

class CodexJsonEventParser
{
    public function parse(string $output): CodexJsonEventSummary
    {
        $sessionId = null;
        $model = null;
        $messages = [];
        $inputTokens = 0;
        $cachedInputTokens = 0;
        $outputTokens = 0;
        $totalTokens = 0;

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_starts_with($line, '{')) {
                continue;
            }

            try {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (! is_array($event)) {
                continue;
            }

            $eventType = (string) Arr::get($event, 'type', '');

            if ($eventType === 'thread.started') {
                $threadId = Arr::get($event, 'thread_id');

                if (is_string($threadId) && $threadId !== '') {
                    $sessionId = $threadId;
                }
            }

            $eventModel = Arr::get($event, 'model');
            if (is_string($eventModel) && $eventModel !== '') {
                $model = $eventModel;
            }

            if ($eventType === 'item.completed') {
                $text = Arr::get($event, 'item.text');

                if (is_string($text) && trim($text) !== '') {
                    $messages[] = trim($text);
                }
            }

            if ($eventType === 'turn.completed') {
                $usage = Arr::get($event, 'usage', []);

                if (is_array($usage)) {
                    $inputTokens += (int) Arr::get($usage, 'input_tokens', 0);
                    $cachedInputTokens += (int) Arr::get($usage, 'cached_input_tokens', 0);
                    $outputTokens += (int) Arr::get($usage, 'output_tokens', 0);
                    $totalTokens += max(
                        0,
                        (int) Arr::get($usage, 'total_tokens', 0),
                    );
                }
            }
        }

        if ($totalTokens === 0) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        return new CodexJsonEventSummary(
            sessionId: $sessionId,
            model: $model,
            messages: $messages,
            inputTokens: $inputTokens,
            cachedInputTokens: $cachedInputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
        );
    }
}
