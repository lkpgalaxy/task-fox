<?php

namespace App\Services\PullRequests;

use App\Contracts\PullRequestProvider;
use App\Enums\PullRequestReviewState;
use App\Jobs\DispatchNextTaskRunJob;
use App\Models\Task;
use App\Models\TaskRun;
use RuntimeException;

class PullRequestStatusRefresher
{
    public function __construct(
        private readonly PullRequestProvider $pullRequestProvider,
    ) {}

    public function refresh(Task $task): PullRequestReviewState
    {
        $task->loadMissing('latestPullRequestRun');
        $run = $task->latestPullRequestRun;

        if (! $run || ! $run->pull_request_url) {
            throw new RuntimeException('No pull request URL available for this task.');
        }

        $state = $this->pullRequestProvider->getReviewState($run->pull_request_url);

        if ($state === PullRequestReviewState::MERGED) {
            $task->update(['status' => Task::STATUS_DONE]);

            $run->update(['status' => TaskRun::STATUS_DONE, 'finished_at' => now()]);

            DispatchNextTaskRunJob::dispatch();
        }

        if ($state === PullRequestReviewState::CLOSED) {
            $task->update([
                'status' => Task::STATUS_REJECTED,
                'rejected_at' => now(),
                'approved_at' => null,
                'approved_by_user_id' => null,
            ]);

            $run->update([
                'status' => TaskRun::STATUS_REJECTED,
                'last_error' => 'Pull request closed.',
                'finished_at' => now(),
            ]);

            DispatchNextTaskRunJob::dispatch();
        }

        return $state;
    }
}
