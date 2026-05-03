<?php

namespace App\Contracts;

use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Models\AiRun;
use App\Models\Task;
use App\Models\User;

interface PullRequestProvider
{
    public function createPullRequest(Task $task, AiRun $run): PullRequestResult;

    public function requestReview(string $pullRequestUrl, User $user): void;

    public function getReviewState(string $pullRequestUrl): PullRequestReviewState;
}
