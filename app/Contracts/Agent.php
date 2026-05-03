<?php

namespace App\Contracts;

use App\DataTransferObjects\CodingAgentResult;
use App\Models\InputSource;

interface Agent
{
    /**
     * @param  array<int, array<string, mixed>>  $projectSummaries
     */
    public function analyzeInputSource(InputSource $inputSource, array $projectSummaries): CodingAgentResult;
}
