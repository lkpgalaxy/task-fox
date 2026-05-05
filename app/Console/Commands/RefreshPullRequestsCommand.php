<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Services\PullRequests\PullRequestStatusRefresher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('tasks:refresh-pull-requests')]
#[Description('Refresh open task pull request states.')]
class RefreshPullRequestsCommand extends Command
{
    public function handle(PullRequestStatusRefresher $pullRequestStatusRefresher): int
    {
        $refreshedCount = 0;
        $failedCount = 0;

        Task::query()
            ->where('status', Task::STATUS_PR_CREATED)
            ->whereHas('taskRuns', fn ($query) => $query->whereNotNull('pull_request_url'))
            ->with('latestPullRequestRun')
            ->lazyById()
            ->each(function (Task $task) use ($pullRequestStatusRefresher, &$refreshedCount, &$failedCount): void {
                try {
                    $state = $pullRequestStatusRefresher->refresh($task);
                    $refreshedCount++;

                    $this->components->info("Task {$task->id} pull request state is {$state->value}.");
                } catch (Throwable $exception) {
                    $failedCount++;

                    $this->components->warn("Task {$task->id} pull request refresh failed: {$exception->getMessage()}");
                }
            });

        $this->components->info("Refreshed {$refreshedCount} pull request(s).");

        return $failedCount === 0 ? self::SUCCESS : self::FAILURE;
    }
}
