<?php

namespace App\DataTransferObjects;

readonly class PullRequestResult
{
    public function __construct(
        public string $url,
        public int $number,
    ) {}
}
