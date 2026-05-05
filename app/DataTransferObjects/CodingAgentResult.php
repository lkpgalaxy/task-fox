<?php

namespace App\DataTransferObjects;

readonly class CodingAgentResult
{
    /**
     * @param  list<string>  $messages
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public bool $successful,
        public array $messages = [],
        public ?string $error = null,
        public array $payload = [],
        public array $context = [],
    ) {}
}
