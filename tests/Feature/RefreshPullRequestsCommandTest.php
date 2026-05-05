<?php

use App\Contracts\PullRequestProvider;
use App\Enums\PullRequestReviewState;
use App\Jobs\DispatchNextTaskRunJob;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('scheduled pull request refresh marks merged pull requests as done', function () {
    Queue::fake();

    $task = Task::create([
        'title' => 'Refresh scheduled PR',
        'description' => 'Scheduler refreshes the latest PR-bearing run.',
        'status' => Task::STATUS_PR_CREATED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $pullRequestRun = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
        'branch_name' => 'task/with-pr',
        'pull_request_url' => 'https://github.com/example/repo/pull/55',
        'pull_request_number' => 55,
    ]);
    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'task/newer-without-pr',
    ]);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getReviewState')
                ->once()
                ->with('https://github.com/example/repo/pull/55')
                ->andReturn(PullRequestReviewState::MERGED);
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
        })
    );

    $this->artisan('tasks:refresh-pull-requests')
        ->assertSuccessful();

    expect($task->refresh()->status)->toBe(Task::STATUS_DONE)
        ->and($pullRequestRun->refresh()->status)->toBe(TaskRun::STATUS_DONE)
        ->and($pullRequestRun->finished_at)->not->toBeNull();

    Queue::assertPushed(DispatchNextTaskRunJob::class);
});

test('scheduled pull request refresh ignores tasks that are not waiting on pull requests', function () {
    $task = Task::create([
        'title' => 'Already done PR',
        'description' => 'Done tasks should not keep refreshing.',
        'status' => Task::STATUS_DONE,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_DONE,
        'branch_name' => 'task/done-pr',
        'pull_request_url' => 'https://github.com/example/repo/pull/56',
        'pull_request_number' => 56,
    ]);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getReviewState')->never();
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
        })
    );

    $this->artisan('tasks:refresh-pull-requests')
        ->assertSuccessful();

    expect($task->refresh()->status)->toBe(Task::STATUS_DONE);
});
