<?php

namespace App\DataTransferObjects;

readonly class ExternalTaskResult
{
    public function __construct(
        public bool $handled,
        public ?string $provider = null,
        public ?string $externalTaskId = null,
        public ?string $externalUrl = null,
    ) {}
}
