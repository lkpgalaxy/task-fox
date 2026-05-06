<?php

namespace App\Services\CodingAgents;

use App\DataTransferObjects\OpenCodeJsonEventSummary;
use Illuminate\Support\Arr;
use JsonException;

class OpenCodeJsonEventParser
{
    public function parse(string $output): OpenCodeJsonEventSummary
    {
        $sessionId = null;
        $model = null;
        $messages = [];
        $rawTextLines = [];
        $errorMessage = null;
        $errorEventJson = null;
        $inputTokens = 0;
        $cachedInputTokens = 0;
        $outputTokens = 0;
        $totalTokens = 0;

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $rawTextLines[] = $line;

                continue;
            }

            if (! is_array($event)) {
                $rawTextLines[] = $line;

                continue;
            }

            $sessionId ??= $this->extractSessionId($event);
            $model ??= $this->extractModel($event);

            foreach ($this->extractMessages($event) as $message) {
                $messages[] = $message;
            }

            foreach ($this->extractUsageBlocks($event) as $usage) {
                $inputTokens += (int) Arr::get($usage, 'input_tokens', 0);
                $cachedInputTokens += (int) Arr::get($usage, 'cached_input_tokens', 0);
                $outputTokens += (int) Arr::get($usage, 'output_tokens', 0);
                $totalTokens += max(0, (int) Arr::get($usage, 'total_tokens', 0));
            }

            if ((string) Arr::get($event, 'type', '') === 'error') {
                $errorMessage ??= $this->extractErrorMessage($event);
                $errorEventJson ??= json_encode($event);
            }
        }

        if ($totalTokens === 0) {
            $totalTokens = $inputTokens + $outputTokens;
        }

        return new OpenCodeJsonEventSummary(
            sessionId: $sessionId,
            model: $model,
            messages: array_values(array_unique(array_filter($messages, static fn (string $message): bool => $message !== ''))),
            rawTextLines: $rawTextLines,
            errorMessage: $errorMessage,
            errorEventJson: $errorEventJson,
            inputTokens: $inputTokens,
            cachedInputTokens: $cachedInputTokens,
            outputTokens: $outputTokens,
            totalTokens: $totalTokens,
        );
    }

    private function extractSessionId(array $event): ?string
    {
        foreach (['sessionID', 'sessionId', 'session.id'] as $path) {
            $value = Arr::get($event, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function extractModel(array $event): ?string
    {
        foreach ([
            'model',
            'modelID',
            'message.model',
            'assistant.model',
            'result.model',
            'provider.model',
        ] as $path) {
            $value = Arr::get($event, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        $providerId = Arr::get($event, 'providerID');
        $modelId = Arr::get($event, 'modelID');

        if (is_string($providerId) && is_string($modelId) && $providerId !== '' && $modelId !== '') {
            return "{$providerId}/{$modelId}";
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractMessages(array $event): array
    {
        if ((string) Arr::get($event, 'type', '') === 'error') {
            return [];
        }

        $fragments = [];

        foreach ([
            Arr::get($event, 'message'),
            Arr::get($event, 'assistant'),
            Arr::get($event, 'result'),
            Arr::get($event, 'response'),
            Arr::get($event, 'output'),
            Arr::get($event, 'item'),
            Arr::get($event, 'content'),
            Arr::get($event, 'output_text'),
            Arr::get($event, 'text'),
        ] as $candidate) {
            foreach ($this->collectTextFragments($candidate) as $fragment) {
                $fragments[] = $fragment;
            }
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (string $fragment): string => trim($fragment), $fragments),
            static fn (string $fragment): bool => $fragment !== '',
        )));
    }

    /**
     * @return list<string>
     */
    private function collectTextFragments(mixed $value): array
    {
        if (is_string($value)) {
            return [trim($value)];
        }

        if (! is_array($value)) {
            return [];
        }

        $fragments = [];

        if (isset($value['text']) && is_string($value['text'])) {
            $fragments[] = trim($value['text']);
        }

        foreach (['content', 'parts', 'items', 'messages', 'output'] as $key) {
            $nested = $value[$key] ?? null;

            if (is_array($nested)) {
                foreach ($this->collectTextFragments($nested) as $fragment) {
                    $fragments[] = $fragment;
                }
            }
        }

        if (array_is_list($value)) {
            foreach ($value as $item) {
                foreach ($this->collectTextFragments($item) as $fragment) {
                    $fragments[] = $fragment;
                }
            }
        }

        return $fragments;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractUsageBlocks(array $event): array
    {
        $blocks = [];

        foreach (['usage', 'result.usage', 'message.usage', 'data.usage'] as $path) {
            $usage = Arr::get($event, $path);

            if (is_array($usage)) {
                $blocks[] = $usage;
            }
        }

        return $blocks;
    }

    private function extractErrorMessage(array $event): ?string
    {
        foreach (['error.data.message', 'error.message', 'error.name'] as $path) {
            $value = Arr::get($event, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
