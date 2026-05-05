<?php

namespace App\Contracts;

use App\DataTransferObjects\CodingAgentResult;
use App\Models\Task;
use App\Models\TaskRun;

interface CodingAgent extends Agent
{
    public function plan(Task $task, TaskRun $run): CodingAgentResult;

    public function run(Task $task, TaskRun $run): CodingAgentResult;

    public function captureScreenshot(Task $task, TaskRun $run): CodingAgentResult;

    public function reviewChanges(Task $task, TaskRun $run, int $attempt): CodingAgentResult;

    public function fixReviewFindings(Task $task, TaskRun $run, string $reviewFeedback, int $attempt): CodingAgentResult;

    public function generateCommitMessage(Task $task, TaskRun $run): CodingAgentResult;
}
