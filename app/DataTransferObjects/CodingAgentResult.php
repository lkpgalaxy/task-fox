<?php

namespace App\DataTransferObjects;

readonly class CodingAgentResult
{
    /**
     * @param  list<string>  $messages
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public bool $successful;

    public array $messages;

    public ?string $error;

    public array $payload;

    public ?CodingAgentInvocation $invocation;

    public array $context;

    public function __construct(
        bool $successful,
        array $messages = [],
        ?string $error = null,
        array $payload = [],
        ?CodingAgentInvocation $invocation = null,
        array $context = [],
    ) {
        $this->successful = $successful;
        $this->messages = $messages;
        $this->error = $error;
        $this->payload = $payload;
        $this->invocation = $invocation;
        $this->context = $invocation?->toContext($context) ?? $context;
    }
}
