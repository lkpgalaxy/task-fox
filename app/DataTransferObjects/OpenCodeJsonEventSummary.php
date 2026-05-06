<?php

namespace App\DataTransferObjects;

readonly class OpenCodeJsonEventSummary
{
    /**
     * @param  list<string>  $messages
     * @param  list<string>  $rawTextLines
     */
    public function __construct(
        public ?string $sessionId = null,
        public ?string $model = null,
        public array $messages = [],
        public array $rawTextLines = [],
        public ?string $errorMessage = null,
        public ?string $errorEventJson = null,
        public int $inputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $outputTokens = 0,
        public int $totalTokens = 0,
    ) {}

    public function hasError(): bool
    {
        return $this->errorMessage !== null || $this->errorEventJson !== null;
    }

    public function rawText(): string
    {
        return trim(implode("\n", $this->rawTextLines));
    }

    /**
     * @return array{input_tokens: int, cached_input_tokens: int, output_tokens: int, total_tokens: int}
     */
    public function usage(): array
    {
        return [
            'input_tokens' => $this->inputTokens,
            'cached_input_tokens' => $this->cachedInputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens,
        ];
    }
}
