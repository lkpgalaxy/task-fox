<?php

namespace App\Services\ExternalTaskProviders;

use App\Contracts\ExternalTaskProvider;
use App\DataTransferObjects\ExternalTaskResult;
use App\Models\ExternalTaskLink;
use App\Models\Task;

class NullExternalTaskProvider implements ExternalTaskProvider
{
    public function createTask(Task $task): ExternalTaskResult
    {
        return new ExternalTaskResult(handled: false);
    }

    public function addComment(ExternalTaskLink $link, string $comment): void
    {
        // Optional integration intentionally disabled.
    }

    public function attachPullRequest(ExternalTaskLink $link, string $pullRequestUrl): void
    {
        // Optional integration intentionally disabled.
    }
}
