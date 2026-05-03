<?php

namespace App\DataTransferObjects;

readonly class CodingAgentResult
{
    /**
     * @param  list<string>  $messages
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public bool $successful,
        public array $messages = [],
        public ?string $error = null,
        public array $payload = [],
    ) {}
}
