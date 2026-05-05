<?php

namespace App\Contracts;

use App\DataTransferObjects\CodingAgentResult;
use App\Models\AiRun;
use App\Models\Task;

interface CodingAgent extends Agent
{
    public function run(Task $task, AiRun $run): CodingAgentResult;

    public function reviewChanges(Task $task, AiRun $run, int $attempt): CodingAgentResult;

    public function generateCommitMessage(Task $task, AiRun $run): CodingAgentResult;
}
