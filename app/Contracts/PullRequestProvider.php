<?php

namespace App\Contracts;

use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Models\AiRun;
use App\Models\Task;
use App\Models\User;

interface PullRequestProvider
{
    public function createPullRequest(Task $task, AiRun $run, ?User $author = null): PullRequestResult;

    public function requestReview(string $pullRequestUrl, User $user, ?User $actor = null): void;

    public function getReviewState(string $pullRequestUrl): PullRequestReviewState;
}
