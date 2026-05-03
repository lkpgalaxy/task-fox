<?php

namespace App\Contracts;

use App\DataTransferObjects\CodingAgentResult;
use App\Models\AiRun;
use App\Models\InputSource;
use App\Models\Task;

interface CodingAgent
{
    public function run(Task $task, AiRun $run): CodingAgentResult;

    public function analyzeInputSource(InputSource $inputSource): CodingAgentResult;
}
