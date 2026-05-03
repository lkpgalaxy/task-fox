<?php

namespace App\Contracts;

use App\DataTransferObjects\CodingAgentResult;
use App\Models\AiRun;
use App\Models\Task;

interface CodingAgent extends Agent
{
    public function run(Task $task, AiRun $run): CodingAgentResult;
}
