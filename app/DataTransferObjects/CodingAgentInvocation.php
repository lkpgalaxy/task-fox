<?php

namespace App\DataTransferObjects;

readonly class CodingAgentInvocation
{
    /**
     * @param  list<string>  $command
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public array $command = [],
        public ?string $sessionId = null,
        public ?string $resumeCommand = null,
        public ?string $model = null,
        public ?string $reasoningEffort = null,
        public array $usage = [],
        public array $context = [],
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function toContext(array $context = []): array
    {
        return array_merge($context, $this->context, array_filter([
            'command' => $this->command !== [] ? $this->command : null,
            'session_id' => $this->sessionId,
            'resume_command' => $this->resumeCommand,
            'model' => $this->model,
            'reasoning_effort' => $this->reasoningEffort,
            'usage' => $this->usage !== [] ? $this->usage : null,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
