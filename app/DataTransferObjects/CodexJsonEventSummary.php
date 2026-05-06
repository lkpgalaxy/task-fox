<?php

namespace App\DataTransferObjects;

readonly class CodexJsonEventSummary
{
    /**
     * @param  list<string>  $messages
     */
    public function __construct(
        public ?string $sessionId = null,
        public ?string $model = null,
        public array $messages = [],
        public int $inputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $outputTokens = 0,
        public int $totalTokens = 0,
    ) {}

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
