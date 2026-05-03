<?php

namespace App\Contracts;

use App\DataTransferObjects\ExternalTaskResult;
use App\Models\ExternalTaskLink;
use App\Models\Task;

interface ExternalTaskProvider
{
    public function createTask(Task $task): ExternalTaskResult;

    public function addComment(ExternalTaskLink $link, string $comment): void;

    public function attachPullRequest(ExternalTaskLink $link, string $pullRequestUrl): void;
}
