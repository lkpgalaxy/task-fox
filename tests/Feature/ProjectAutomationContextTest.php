<?php

use App\Contracts\CodingAgent;
use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\CodingAgentResult;
use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Jobs\DispatchNextTaskRunJob;
use App\Jobs\RunApprovedTaskWithCodingAgentJob;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunLog;
use App\Models\User;
use App\Services\CodingAgents\CodexCodingAgent;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('manual tasks can be created without a project', function () {
    $this->post(route('tasks.store'), [
        'title' => 'Triage loose task',
        'description' => 'This task should be assignable to a project later.',
        'priority' => Task::PRIORITY_MEDIUM,
        'deadline' => null,
        'assignee_user_id' => null,
        'source_input_id' => null,
        'project_id' => null,
    ])->assertRedirect(route('tasks.index'));

    $task = Task::query()->sole();

    expect($task->project_id)->toBeNull()
        ->and($task->status)->toBe(Task::STATUS_DRAFT);
});

test('approve requires a project before changing task status', function () {
    Queue::fake();

    User::factory()->create();
    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Projectless task',
        'description' => 'Should be blocked until a project is assigned.',
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $this->from(route('tasks.index'))->post(route('tasks.approve', $task))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHasErrors([
            'project' => 'Assign a project before approving this task.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_PENDING_APPROVAL);

    $task->update(['project_id' => $project->id]);

    $this->post(route('tasks.approve', $task))->assertRedirect(route('tasks.index'));

    expect($task->refresh()->status)->toBe(Task::STATUS_APPROVED);
});

test('reject marks task rejected and dispatches the next task without a project', function () {
    Queue::fake();

    User::factory()->create();
    $task = Task::create([
        'title' => 'Projectless rejection',
        'description' => 'Should be rejectable without repository context.',
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
        'branch_name' => 'ai-task-'.$task->id.'-projectless-rejection',
        'workspace_path' => '/tmp/task-fox',
        'base_branch' => 'main',
    ]);

    $this->from(route('tasks.index'))->post(route('tasks.reject', $task))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHasNoErrors();

    expect($task->refresh())
        ->project_id->toBeNull()
        ->status->toBe(Task::STATUS_REJECTED)
        ->rejected_at->not->toBeNull();

    expect($run->refresh())
        ->status->toBe(TaskRun::STATUS_REJECTED)
        ->last_error->toBe('Task rejected.')
        ->finished_at->not->toBeNull();

    Queue::assertPushed(DispatchNextTaskRunJob::class);
});

test('failed tasks can be retried and queued for execution', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
        'base_branch' => 'develop',
    ]);
    $task = Task::create([
        'title' => 'Retry failed run',
        'description' => 'A failed task should be eligible for another run.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
        'pull_request_url' => 'https://github.com/example/task-fox/pull/10',
        'pull_request_number' => 10,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-retry-failed-run',
        'workspace_path' => '/tmp/task-fox',
        'base_branch' => 'develop',
    ]);
    $run->initializeWorkflowState($task);

    $this->post(route('tasks.retry', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Task queued for retry.');

    $task->refresh();

    expect($task)
        ->status->toBe(Task::STATUS_APPROVED)
        ->approved_by_user_id->toBe(auth()->id())
        ->approved_at->not->toBeNull()
        ->pull_request_url->toBeNull()
        ->pull_request_number->toBeNull();

    expect($run->refresh())
        ->status->toBe(TaskRun::STATUS_QUEUED)
        ->last_error->toBeNull()
        ->finished_at->toBeNull();

    Queue::assertPushed(
        DispatchNextTaskRunJob::class,
        fn (DispatchNextTaskRunJob $job): bool => $job->taskId === $task->id,
    );
});

test('retry rejects failed tasks without a matching resumable task run', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Changed failed run',
        'description' => 'Retry requires a matching request hash.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-changed-failed-run',
        'workspace_path' => '/tmp/task-fox',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);

    $task->update(['description' => 'The task request changed after failure.']);

    $this->post(route('tasks.retry', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'retry' => 'This failed task has changed since its last resumable run. Submit it for approval to start a new run.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED);

    Queue::assertNotPushed(DispatchNextTaskRunJob::class);
});

test('failed tasks can be rerun with a fresh queued workflow run', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox-rerun',
        'base_branch' => 'develop',
    ]);
    $task = Task::create([
        'title' => 'Rerun failed workflow',
        'description' => 'A failed task should be eligible for a fresh workflow run.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $failedRun = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-failed-workflow',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
        'last_error' => 'Previous failure.',
        'finished_at' => now(),
    ]);
    $failedRun->initializeWorkflowState($task);
    TaskRunLog::create([
        'task_run_id' => $failedRun->id,
        'level' => 'error',
        'message' => 'Previous failed workflow log',
        'context' => [],
    ]);

    $this->post(route('tasks.rerun-workflow', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Task workflow queued for rerun.');

    $freshRun = TaskRun::query()->whereKeyNot($failedRun->id)->sole();

    expect($task->refresh())
        ->status->toBe(Task::STATUS_APPROVED)
        ->approved_by_user_id->toBe(auth()->id())
        ->approved_at->not->toBeNull()
        ->and($failedRun->refresh())
        ->status->toBe(TaskRun::STATUS_FAILED)
        ->last_error->toBe('Previous failure.')
        ->and($failedRun->logs()->where('message', 'Previous failed workflow log')->exists())->toBeTrue()
        ->and($freshRun)
        ->status->toBe(TaskRun::STATUS_QUEUED)
        ->branch_name->toBe('pending')
        ->workspace_path->toBe('/tmp/task-fox-rerun')
        ->base_branch->toBe('develop')
        ->attempt_count->toBe(0)
        ->review_attempt_count->toBe(0)
        ->and($freshRun->requestHash())->toBe(TaskRun::requestHashForTask($task->refresh()))
        ->and($freshRun->nextRunnableCheckpoint())->toBe(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);

    Queue::assertPushed(
        DispatchNextTaskRunJob::class,
        fn (DispatchNextTaskRunJob $job): bool => $job->taskId === $task->id,
    );
});

test('rejected tasks can be rerun with a fresh queued workflow run', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox-rerun-rejected',
        'base_branch' => 'develop',
    ]);
    $task = Task::create([
        'title' => 'Rerun rejected workflow',
        'description' => 'A rejected task should be eligible for a fresh workflow run.',
        'status' => Task::STATUS_REJECTED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
        'rejected_at' => now(),
    ]);

    $this->post(route('tasks.rerun-workflow', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Task workflow queued for rerun.');

    $freshRun = TaskRun::query()->sole();

    expect($task->refresh())
        ->status->toBe(Task::STATUS_APPROVED)
        ->approved_by_user_id->toBe(auth()->id())
        ->approved_at->not->toBeNull()
        ->rejected_at->toBeNull()
        ->and($freshRun)
        ->status->toBe(TaskRun::STATUS_QUEUED)
        ->branch_name->toBe('pending')
        ->workspace_path->toBe('/tmp/task-fox-rerun-rejected')
        ->base_branch->toBe('develop')
        ->attempt_count->toBe(0)
        ->review_attempt_count->toBe(0)
        ->and($freshRun->requestHash())->toBe(TaskRun::requestHashForTask($task->refresh()))
        ->and($freshRun->nextRunnableCheckpoint())->toBe(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);

    Queue::assertPushed(
        DispatchNextTaskRunJob::class,
        fn (DispatchNextTaskRunJob $job): bool => $job->taskId === $task->id,
    );
});

test('only failed or rejected tasks can be rerun', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Already approved',
        'description' => 'Rerun should be limited to failed tasks.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    $this->post(route('tasks.rerun-workflow', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'status' => 'Only failed or rejected tasks can be rerun.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_APPROVED)
        ->and(TaskRun::query()->count())->toBe(0);

    Queue::assertNotPushed(DispatchNextTaskRunJob::class);
});

test('rerun requires a project before creating a fresh workflow run', function () {
    Queue::fake();

    $task = Task::create([
        'title' => 'Projectless rerun',
        'description' => 'Rerun needs repository context.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $this->post(route('tasks.rerun-workflow', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'project' => 'Assign a project before rerunning this task.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED)
        ->and(TaskRun::query()->count())->toBe(0);

    Queue::assertNotPushed(DispatchNextTaskRunJob::class);
});

test('rerun requires an actor before creating a fresh workflow run', function () {
    Queue::fake();

    $this->withoutMiddleware(Authenticate::class);
    auth()->logout();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Actorless rerun',
        'description' => 'Rerun needs an approval actor.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    $this->post(route('tasks.rerun-workflow', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'actor' => 'No actor available to record rerun approval.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED)
        ->and(TaskRun::query()->count())->toBe(0);

    Queue::assertNotPushed(DispatchNextTaskRunJob::class);
});

test('dispatch reuses the latest failed resumable task run and preserves logs', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox-current',
        'base_branch' => 'develop',
    ]);
    SystemSetting::factory()->create([
        'analyze_source_model' => 'gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
        'plan_model' => 'gpt-5.5',
        'plan_reasoning_effort' => 'high',
        'implement_model' => 'gpt-5.5',
        'implement_reasoning_effort' => 'medium',
        'review_model' => 'gpt-5.5',
        'review_reasoning_effort' => 'high',
        'commit_message_model' => 'gpt-5.4-mini',
        'commit_message_reasoning_effort' => 'medium',
    ]);
    $task = Task::create([
        'title' => 'Reuse failed run',
        'description' => 'Retry should not create a replacement run.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $historicalRun = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-old',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
    ]);
    $historicalRun->initializeWorkflowState($task);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-reuse-failed-run',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);
    SystemSetting::query()->sole()->update([
        'analyze_source_model' => 'gpt-4.1-mini',
        'analyze_source_reasoning_effort' => 'low',
        'plan_model' => 'gpt-4.1-mini',
        'plan_reasoning_effort' => 'low',
        'implement_model' => 'gpt-4.1-mini',
        'implement_reasoning_effort' => 'low',
        'review_model' => 'gpt-4.1-mini',
        'review_reasoning_effort' => 'low',
        'commit_message_model' => 'gpt-4.1-mini',
        'commit_message_reasoning_effort' => 'low',
    ]);
    TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'error',
        'message' => 'Previous failure',
        'context' => [],
    ]);

    app()->call([new DispatchNextTaskRunJob($task->id), 'handle']);

    expect(TaskRun::query()->count())->toBe(2)
        ->and($run->refresh()->status)->toBe(TaskRun::STATUS_QUEUED)
        ->and($run->workspace_path)->toBe('/tmp/task-fox-current')
        ->and($run->base_branch)->toBe('develop')
        ->and($run->analyze_source_model)->toBe('gpt-5.4')
        ->and($run->analyze_source_reasoning_effort)->toBe('medium')
        ->and($run->plan_model)->toBe('gpt-5.5')
        ->and($run->plan_reasoning_effort)->toBe('high')
        ->and($run->implement_model)->toBe('gpt-5.5')
        ->and($run->implement_reasoning_effort)->toBe('medium')
        ->and($run->review_model)->toBe('gpt-5.5')
        ->and($run->review_reasoning_effort)->toBe('high')
        ->and($run->commit_message_model)->toBe('gpt-5.4-mini')
        ->and($run->commit_message_reasoning_effort)->toBe('medium')
        ->and($run->logs()->where('message', 'Previous failure')->exists())->toBeTrue();

    Queue::assertPushed(
        RunApprovedTaskWithCodingAgentJob::class,
        fn (RunApprovedTaskWithCodingAgentJob $job): bool => $job->taskRunId === $run->id,
    );
});

test('dispatch starts a queued resumable retry run for the selected task', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox-current',
        'base_branch' => 'develop',
    ]);
    $task = Task::create([
        'title' => 'Start queued retry',
        'description' => 'Retry queued run should not block itself.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'ai-task-'.$task->id.'-start-queued-retry',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);

    app()->call([new DispatchNextTaskRunJob($task->id), 'handle']);

    expect(TaskRun::query()->count())->toBe(1)
        ->and($task->refresh()->status)->toBe(Task::STATUS_RUNNING)
        ->and($run->refresh()->status)->toBe(TaskRun::STATUS_QUEUED)
        ->and($run->workspace_path)->toBe('/tmp/task-fox-current')
        ->and($run->base_branch)->toBe('develop');

    Queue::assertPushed(
        RunApprovedTaskWithCodingAgentJob::class,
        fn (RunApprovedTaskWithCodingAgentJob $job): bool => $job->taskRunId === $run->id,
    );
});

test('editing request fields after failure requires approval for a fresh run and keeps old logs', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Edit failed task',
        'description' => 'Failed task content.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => auth()->id(),
        'approved_at' => now(),
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-edit-failed-task',
        'workspace_path' => '/tmp/task-fox',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);
    TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'error',
        'message' => 'Historical failure',
        'context' => [],
    ]);

    $this->patch(route('tasks.update', $task), [
        'title' => 'Edited failed task',
        'description' => 'Failed task content changed.',
        'priority' => Task::PRIORITY_HIGH,
        'deadline' => null,
        'assignee_user_id' => null,
        'reviewer_user_id' => null,
        'project_id' => $project->id,
        'source_input_id' => null,
    ])->assertRedirect(route('tasks.index', ['task' => $task->id]));

    expect($task->refresh())
        ->status->toBe(Task::STATUS_PENDING_APPROVAL)
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->and(TaskRun::query()->count())->toBe(1)
        ->and($run->logs()->where('message', 'Historical failure')->exists())->toBeTrue();
});

test('only failed tasks can be retried', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Already pending',
        'description' => 'Retry should be limited to failed tasks.',
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    $this->post(route('tasks.retry', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'status' => 'Only failed tasks can be retried.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_PENDING_APPROVAL);

    Queue::assertNotPushed(DispatchNextTaskRunJob::class);
});

test('task run creation snapshots project workspace path and base branch', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
        'base_branch' => 'develop',
    ]);
    SystemSetting::factory()->create([
        'analyze_source_model' => 'gpt-5.4',
        'analyze_source_reasoning_effort' => 'medium',
        'plan_model' => 'gpt-5.5',
        'plan_reasoning_effort' => 'high',
        'implement_model' => 'gpt-5.5',
        'implement_reasoning_effort' => 'medium',
        'review_model' => 'gpt-5.5',
        'review_reasoning_effort' => 'high',
        'commit_message_model' => 'gpt-5.4-mini',
        'commit_message_reasoning_effort' => 'medium',
    ]);
    $task = Task::create([
        'title' => 'Run against project repository',
        'description' => 'task run should use project repository settings.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    app()->call([new DispatchNextTaskRunJob($task->id), 'handle']);

    $run = TaskRun::query()->sole();

    expect($run)
        ->workspace_path->toBe('/tmp/task-fox')
        ->base_branch->toBe('develop')
        ->analyze_source_model->toBe('gpt-5.4')
        ->analyze_source_reasoning_effort->toBe('medium')
        ->plan_model->toBe('gpt-5.5')
        ->plan_reasoning_effort->toBe('high')
        ->implement_model->toBe('gpt-5.5')
        ->implement_reasoning_effort->toBe('medium')
        ->review_model->toBe('gpt-5.5')
        ->review_reasoning_effort->toBe('high')
        ->commit_message_model->toBe('gpt-5.4-mini')
        ->commit_message_reasoning_effort->toBe('medium');

    Queue::assertPushed(RunApprovedTaskWithCodingAgentJob::class);
});

test('task run snapshot defaults blank project base branch to main', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Mainline Project',
        'workspace_path' => '/tmp/mainline',
        'url' => 'https://github.com/example/mainline',
    ]);
    $task = Task::create([
        'title' => 'Run against main',
        'description' => 'Blank base branch should default to main.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    app()->call([new DispatchNextTaskRunJob($task->id), 'handle']);

    expect(TaskRun::query()->sole()->base_branch)->toBe('main');
});

test('failed approved task keeps approval audit fields', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $approver = User::factory()->create();
    $project = Project::create([
        'name' => 'Preserve Approval Project',
        'workspace_path' => $repositoryPath,
    ]);
    $task = Task::create([
        'title' => 'Preserve approval on failure',
        'description' => 'A failed run should not erase approval history.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => $approver->id,
        'approved_at' => now(),
        'project_id' => $project->id,
    ]);
    $run = createAutomationRun($task, $repositoryPath, 'task/preserve-approval');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Implement the failure path.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: false, error: 'Implementation failed.'));
            $mock->shouldReceive('reviewChanges')->never();
            $mock->shouldReceive('generateCommitMessage')->never();
        })
    );

    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh())
        ->status->toBe(Task::STATUS_FAILED)
        ->approved_by_user_id->toBe($approver->id)
        ->approved_at->not->toBeNull()
        ->and($run->refresh())
        ->status->toBe(TaskRun::STATUS_FAILED)
        ->last_error->toBe('Implementation failed.');
});

test('codex coding agent uses the run workspace as codex workspace and process cwd', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-codex-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $cwdPath = $workspacePath.'/cwd.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/codex',
        "#!/bin/sh\npwd > ".escapeshellarg($cwdPath)."\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\n"
    );
    chmod($binPath.'/codex', 0755);

    $task = Task::create([
        'title' => 'Implement in workspace',
        'description' => 'The coding agent must run inside the project workspace.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'implement_model' => 'gpt-5.5',
        'implement_reasoning_effort' => 'medium',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/workspace',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);
    SystemSetting::query()->sole()->update([
        'implement_model' => 'gpt-4.1-mini',
        'implement_reasoning_effort' => 'low',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($result->context['command'])->toContain('exec')
        ->and($result->context['command'])->toContain('[prompt omitted]')
        ->and(trim((string) file_get_contents($cwdPath)))->toBe($workspacePath)
        ->and($args)->toContain('exec')
        ->and($args)->toContain('--model')
        ->and($args[array_search('--model', $args, true) + 1])->toBe('gpt-5.5')
        ->and($args)->toContain('-c')
        ->and($args[array_search('-c', $args, true) + 1])->toBe('model_reasoning_effort="medium"')
        ->and($args)->toContain('--dangerously-bypass-approvals-and-sandbox')
        ->and($args)->toContain('-C')
        ->and($args[array_search('-C', $args, true) + 1])->toBe($workspacePath);
});

test('codex coding agent omits the model flag when the implementation model is blank', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-codex-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $cwdPath = $workspacePath.'/cwd.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/codex',
        "#!/bin/sh\npwd > ".escapeshellarg($cwdPath)."\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\n"
    );
    chmod($binPath.'/codex', 0755);

    $task = Task::create([
        'title' => 'Implement without model',
        'description' => 'The coding agent should fall back to Codex defaults.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'implement_model' => null,
        'implement_reasoning_effort' => null,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/workspace',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and(trim((string) file_get_contents($cwdPath)))->toBe($workspacePath)
        ->and($args)->not->toContain('--model')
        ->and($args)->not->toContain('-c');
});

test('codex planning uses read only ephemeral sandbox and extracts proposed plan', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-plan-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-plan-codex-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/codex',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '<proposed_plan>\\n## Plan\\n\\n- Inspect files.\\n</proposed_plan>\\n'\n"
    );
    chmod($binPath.'/codex', 0755);

    $task = Task::create([
        'title' => 'Plan in workspace',
        'description' => 'The coding agent must plan without mutating files.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'plan_model' => 'gpt-5.5',
        'plan_reasoning_effort' => 'high',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/planning',
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->plan($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($result->payload['plan'])->toBe("## Plan\n\n- Inspect files.")
        ->and($args)->toContain('exec')
        ->and($args)->toContain('--model')
        ->and($args[array_search('--model', $args, true) + 1])->toBe('gpt-5.5')
        ->and($args)->toContain('-c')
        ->and($args[array_search('-c', $args, true) + 1])->toBe('model_reasoning_effort="high"')
        ->and($args)->toContain('--sandbox')
        ->and($args[array_search('--sandbox', $args, true) + 1])->toBe('read-only')
        ->and($args)->toContain('--ephemeral')
        ->and($args)->toContain('-C')
        ->and($args[array_search('-C', $args, true) + 1])->toBe($workspacePath)
        ->and(file_get_contents($argsPath))->toContain('Do not ask the user any questions or request clarification.')
        ->and(file_get_contents($argsPath))->toContain('choose the safest reasonable assumption and record it in the Assumptions section')
        ->and(file_get_contents($argsPath))->toContain('Return only the final <proposed_plan> block')
        ->and(file_get_contents($argsPath))->not->toContain('Ask no questions unless');
});

test('codex review command uses native base branch review mode', function () {
    $task = Task::create([
        'title' => 'Review with native mode',
        'description' => 'Review should use Codex native review mode.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'review_model' => 'gpt-5.5',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/review-upstream',
        'base_branch' => 'develop',
    ]);

    $reflection = new ReflectionClass(CodexCodingAgent::class);
    $method = $reflection->getMethod('buildReviewCommand');
    $command = $method->invoke(new CodexCodingAgent, $task, $run, '/tmp/codex-review-output');

    expect($command)
        ->toContain('review')
        ->toContain('--base')
        ->and($command[array_search('--base', $command, true) + 1])->toBe('develop')
        ->and($command)->toContain('--title')
        ->and($command[array_search('--title', $command, true) + 1])->toBe('Task '.$task->id.': Review with native mode')
        ->and($command)->toContain('--output-last-message')
        ->and($command)->not->toContain('--uncommitted')
        ->and($command)->not->toContain('Return exactly one JSON object with this shape:');
});

test('pull request review is requested from the project default reviewer before the task assignee', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $assignee = User::factory()->create(['github_username' => 'assignee-login']);
    $defaultReviewer = User::factory()->create(['github_username' => 'reviewer-login']);
    $project = Project::create([
        'name' => 'Review Project',
        'workspace_path' => $repositoryPath,
        'default_reviewer_user_id' => $defaultReviewer->id,
    ]);
    $task = Task::create([
        'title' => 'Open reviewed PR',
        'description' => 'Default reviewer should be requested.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee->id,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'task/default-reviewer',
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocks($defaultReviewer, $assignee);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh())
        ->status->toBe(Task::STATUS_PR_CREATED)
        ->and($run->refresh()->pull_request_url)->toBe('https://github.com/example/repo/pull/123');

});

test('pull request review is skipped when the reviewer resolves to the pull request author', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $assignee = User::factory()->create(['github_username' => 'assignee-login']);
    $project = Project::create([
        'name' => 'Review Project',
        'workspace_path' => $repositoryPath,
    ]);
    $task = Task::create([
        'title' => 'Open reviewed PR',
        'description' => 'Assignee cannot review their own pull request.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee->id,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'task/assignee-reviewer',
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocksWithoutReview($assignee);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED)
        ->and($run->refresh()->isCheckpointComplete(TaskRun::CHECKPOINT_REVIEW_REQUESTED))->toBeTrue();

    $log = $run->logs()
        ->where('message', 'Pull request review request skipped')
        ->sole();

    expect($log->context)
        ->reason->toBe('reviewer_is_pull_request_author')
        ->reviewer_user_id->toBe($assignee->id)
        ->matching_user_id->toBe($assignee->id);
});

test('pull request review is skipped when the reviewer resolves to the approving user', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    runSuccessfulProcess(['git', 'checkout', '-b', 'task/resume-reviewed-pr'], $repositoryPath);
    $approver = User::factory()->create(['github_username' => 'approver-login']);
    $project = Project::create([
        'name' => 'Review Project',
        'workspace_path' => $repositoryPath,
        'default_reviewer_user_id' => $approver->id,
    ]);
    $task = Task::create([
        'title' => 'Open reviewed PR',
        'description' => 'Approver cannot review their own pull request.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => $approver->id,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'task/approver-reviewer',
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocksWithoutReview();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED)
        ->and($run->refresh()->isCheckpointComplete(TaskRun::CHECKPOINT_REVIEW_REQUESTED))->toBeTrue();

    $log = $run->logs()
        ->where('message', 'Pull request review request skipped')
        ->sole();

    expect($log->context)
        ->reason->toBe('reviewer_is_task_approver')
        ->reviewer_user_id->toBe($approver->id)
        ->matching_user_id->toBe($approver->id);
});

test('pull request review uses task reviewer before project default reviewer', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $taskReviewer = User::factory()->create(['github_username' => 'task-reviewer']);
    $defaultReviewer = User::factory()->create(['github_username' => 'project-reviewer']);
    $project = Project::create([
        'name' => 'Review Project',
        'workspace_path' => $repositoryPath,
        'default_reviewer_user_id' => $defaultReviewer->id,
    ]);
    $task = Task::create([
        'title' => 'Open reviewed PR',
        'description' => 'Task reviewer should be requested.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'reviewer_user_id' => $taskReviewer->id,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'task/task-reviewer',
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocks($taskReviewer);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED);
});

test('RunApprovedTaskWithCodingAgentJob counts implementation and review attempts separately', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Separate attempts');
    $run = createAutomationRun($task, $repositoryPath, 'task/separate-attempts');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Implement and review separately.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->with(Mockery::type(Task::class), Mockery::type(TaskRun::class), 1)
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: separate attempts']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->attempt_count->toBe(1)
        ->review_attempt_count->toBe(1)
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE);
});

test('RunApprovedTaskWithCodingAgentJob stores failed test output for agent retry diagnosis', function () {
    Queue::fake();
    config([
        'automation.agent.retry_limit' => 1,
        'automation.tests.command' => 'php -r \'fwrite(STDERR, "SQLSTATE[HY000]: General error: 1 no such table: sessions\n"); exit(2);\'',
    ]);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Store failed test output');
    $run = createAutomationRun($task, $repositoryPath, 'task/store-failed-test-output');
    $run->update(['retry_limit' => 1]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Implement and verify.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')->never();
            $mock->shouldReceive('generateCommitMessage')->never();
        })
    );
    test()->instance(ExternalTaskProvider::class, Mockery::mock(ExternalTaskProvider::class));
    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    $testLogContext = $run->logs()->where('message', 'Test command executed')->first()?->context ?? [];

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED)
        ->and($run->refresh()->last_error)
        ->toContain('Tests failed after retry limit reached.')
        ->toContain('Verification command failed:')
        ->toContain('Exit code: 2')
        ->toContain('no such table: sessions')
        ->and($testLogContext['stderr'] ?? '')
        ->toContain('no such table: sessions');
});

test('RunApprovedTaskWithCodingAgentJob uses finite implementation retry limit from task run snapshot', function () {
    Queue::fake();

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Finite implementation retry limit');
    $run = createAutomationRun($task, $repositoryPath, 'task/finite-implementation-retry-limit');
    $run->update(['retry_limit' => 2]);
    $testsCountPath = $repositoryPath.'/tests-count.txt';
    config(['automation.tests.command' => reviewCountingTestCommand($testsCountPath, 1)]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Retry implementation once.']));
            $mock->shouldReceive('run')
                ->twice()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: finite implementation retry']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->attempt_count->toBe(2)
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE)
        ->and(trim((string) file_get_contents($testsCountPath)))->toBe('2');
});

test('RunApprovedTaskWithCodingAgentJob retries implementation without limit when snapshot retry limit is unlimited', function () {
    Queue::fake();

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Unlimited implementation retries');
    $run = createAutomationRun($task, $repositoryPath, 'task/unlimited-implementation-retries');
    $run->update(['retry_limit' => -1]);
    $testsCountPath = $repositoryPath.'/tests-count.txt';
    config(['automation.tests.command' => reviewCountingTestCommand($testsCountPath, 1).' && false']);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($testsCountPath): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Retry until tests pass.']));
            $mock->shouldReceive('run')
                ->times(3)
                ->andReturnUsing(function () use ($testsCountPath): CodingAgentResult {
                    if (is_file($testsCountPath) && trim((string) file_get_contents($testsCountPath)) === '2') {
                        config(['automation.tests.command' => 'true']);
                    }

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: unlimited implementation retry']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->attempt_count->toBe(3)
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE);
});

test('RunApprovedTaskWithCodingAgentJob reviews changes before commit', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $events = [];
    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Review before PR');
    $run = createAutomationRun($task, $repositoryPath, 'task/review-before-pr');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use (&$events, $repositoryPath): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'planning';

                    return new CodingAgentResult(successful: true, payload: ['plan' => 'Implement before review.']);
                });
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use (&$events, $repositoryPath): CodingAgentResult {
                    $events[] = 'implementation';
                    file_put_contents($repositoryPath.'/feature.txt', "Implemented\n");

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'review';

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'commit-message';

                    return new CodingAgentResult(successful: true, payload: ['message' => 'feat: add reviewed change']);
                });
        })
    );
    bindSuccessfulAuxiliaryMocks($events, $repositoryPath, 'feat: add reviewed change');

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    $logMessages = TaskRunLog::query()
        ->where('task_run_id', $run->id)
        ->orderBy('id')
        ->pluck('message')
        ->all();

    expect($events)->toBe(['planning', 'implementation', 'review', 'commit-message', 'pull-request'])
        ->and($run->refresh()->plan)->toBe('Implement before review.')
        ->and(collect($run->workflowCheckpoints())->pluck('name')->all())->toBe([
            TaskRun::CHECKPOINT_REPOSITORY_PREPARED,
            TaskRun::CHECKPOINT_PLANNED,
            TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED,
            TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED,
            TaskRun::CHECKPOINT_CHANGES_REVIEWED,
            TaskRun::CHECKPOINT_CHANGES_COMMITTED,
            TaskRun::CHECKPOINT_PULL_REQUEST_CREATED,
            TaskRun::CHECKPOINT_REVIEW_REQUESTED,
            TaskRun::CHECKPOINT_EXTERNAL_TASK_UPDATED,
        ])
        ->and(array_search('Coding agent review passed', $logMessages, true))->toBeLessThan(
            array_search('Coding agent commit message generation started', $logMessages, true),
        );
});

test('RunApprovedTaskWithCodingAgentJob captures screenshot before review when project URL is configured', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $events = [];
    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Screenshot before review', null, 'https://app.test');
    $run = createAutomationRun($task, $repositoryPath, 'task/screenshot-before-review');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use (&$events, $run): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'planning';

                    return new CodingAgentResult(successful: true, payload: ['plan' => 'Implement before screenshot.']);
                });
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'implementation';

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('smokeTestUrl')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'url-smoke';

                    return new CodingAgentResult(successful: true, messages: ['url smoke passed']);
                });
            $mock->shouldReceive('captureScreenshot')
                ->once()
                ->andReturnUsing(function () use (&$events, $run): CodingAgentResult {
                    $events[] = 'screenshot';
                    file_put_contents(storage_path("app/task-runs/{$run->id}/screenshots/implementation.png"), 'png');

                    return new CodingAgentResult(successful: true, messages: ['screenshot captured']);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'review';

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturnUsing(function () use (&$events): CodingAgentResult {
                    $events[] = 'commit-message';

                    return new CodingAgentResult(successful: true, payload: ['message' => 'test: screenshot step']);
                });
        })
    );
    bindSuccessfulAuxiliaryMocks($events);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($events)->toBe(['planning', 'implementation', 'url-smoke', 'screenshot', 'review', 'commit-message', 'pull-request'])
        ->and($run->refresh()->checkpoint(TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_COMPLETED)
        ->and($run->logs()->where('message', 'URL smoke test passed')->exists())->toBeTrue()
        ->and($run->logs()->where('message', 'Screenshot captured')->exists())->toBeTrue();
});

test('RunApprovedTaskWithCodingAgentJob fails implementation verification when URL smoke test fails', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'URL smoke failure', null, 'https://app.test');
    $run = createAutomationRun($task, $repositoryPath, 'task/url-smoke-failure');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Implement before URL smoke.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('smokeTestUrl')
                ->once()
                ->andReturn(new CodingAgentResult(successful: false, error: 'Page shows a database exception.'));
            $mock->shouldReceive('captureScreenshot')->never();
            $mock->shouldReceive('reviewChanges')->never();
            $mock->shouldReceive('fixReviewFindings')->never();
            $mock->shouldReceive('generateCommitMessage')->never();
        })
    );
    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED)
        ->and($run->refresh()->status)->toBe(TaskRun::STATUS_FAILED)
        ->and($run->last_error)->toBe('Page shows a database exception.')
        ->and($run->checkpoint(TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_FAILED)
        ->and($run->checkpoint(TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_PENDING);
});

test('RunApprovedTaskWithCodingAgentJob stops review retries after success', function () {
    Queue::fake();

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Review retry success');
    $run = createAutomationRun($task, $repositoryPath, 'task/review-retry-success');
    $testsCountPath = $repositoryPath.'/tests-count.txt';
    config(['automation.tests.command' => reviewCountingTestCommand($testsCountPath)]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Review with retry.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->twice()
                ->with(Mockery::type(Task::class), Mockery::type(TaskRun::class), Mockery::any())
                ->andReturn(
                    new CodingAgentResult(
                        successful: false,
                        messages: ['review output'],
                        error: 'Needs fixes.',
                        payload: ['findings' => [['title' => '[P2] Missing test']]],
                    ),
                    new CodingAgentResult(successful: true),
                );
            $mock->shouldReceive('fixReviewFindings')
                ->once()
                ->with(
                    Mockery::type(Task::class),
                    Mockery::type(TaskRun::class),
                    Mockery::on(fn (string $feedback): bool => str_contains($feedback, 'Needs fixes.') && str_contains($feedback, 'review output')),
                    1,
                )
                ->andReturn(new CodingAgentResult(successful: true, messages: ['applied fix']));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: review retry']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->review_attempt_count->toBe(2)
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE)
        ->and(trim((string) file_get_contents($testsCountPath)))->toBe('2');
});

test('RunApprovedTaskWithCodingAgentJob logs model metadata for agent phases', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Agent model logs');
    $run = createAutomationRun($task, $repositoryPath, 'task/agent-model-logs');
    $run->update([
        'plan_model' => 'gpt-plan',
        'plan_reasoning_effort' => 'low',
        'implement_model' => 'gpt-implement',
        'implement_reasoning_effort' => 'medium',
        'review_model' => 'gpt-review',
        'review_reasoning_effort' => 'high',
        'commit_message_model' => 'gpt-commit',
        'commit_message_reasoning_effort' => null,
    ]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(
                    successful: true,
                    messages: ['Coding agent planning command completed.'],
                    payload: ['plan' => 'Log every agent phase.'],
                    context: ['command' => ['codex', 'exec', '[prompt omitted]']],
                ));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(
                    successful: true,
                    context: ['command' => ['codex', 'exec', '[prompt omitted]']],
                ));
            $mock->shouldReceive('reviewChanges')
                ->twice()
                ->andReturn(
                    new CodingAgentResult(
                        successful: false,
                        messages: ['review output'],
                        error: 'Needs fixes.',
                        payload: ['findings' => [['title' => '[P2] Missing test']]],
                    ),
                    new CodingAgentResult(successful: true),
                );
            $mock->shouldReceive('fixReviewFindings')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, messages: ['applied fix']));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: log agent metadata']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    $logs = TaskRunLog::query()
        ->where('task_run_id', $run->id)
        ->whereIn('message', [
            'Coding agent planning started',
            'Coding agent invocation started',
            'Coding agent review started',
            'Coding agent review fix started',
            'Coding agent commit message generation started',
        ])
        ->orderBy('id')
        ->get();

    $logsByMessage = $logs->groupBy('message');

    $expectAgentContext = function (array $context, string $phase, ?string $model, ?string $reasoningEffort): void {
        expect($context)->toMatchArray([
            'coding_agent' => 'codex',
            'agent_phase' => $phase,
            'agent_model' => $model,
            'agent_reasoning_effort' => $reasoningEffort,
        ]);
    };

    expect($logs)->toHaveCount(6);

    $planningStartedContext = $logsByMessage->get('Coding agent planning started')->sole()->context;
    $invocationStartedContext = $logsByMessage->get('Coding agent invocation started')->sole()->context;

    $expectAgentContext($planningStartedContext, 'plan', 'gpt-plan', 'low');
    $expectAgentContext($invocationStartedContext, 'implement', 'gpt-implement', 'medium');
    expect($planningStartedContext['command'])->toBe(['codex', 'exec', '[prompt omitted]'])
        ->and($invocationStartedContext['command'])->toBe(['codex', 'exec', '[prompt omitted]']);
    $logsByMessage->get('Coding agent review started')->each(
        fn (TaskRunLog $log) => $expectAgentContext($log->context, 'review', 'gpt-review', 'high'),
    );
    $expectAgentContext($logsByMessage->get('Coding agent review fix started')->sole()->context, 'review_fix', 'gpt-implement', 'medium');
    $expectAgentContext($logsByMessage->get('Coding agent commit message generation started')->sole()->context, 'commit_message', 'gpt-commit', null);

    expect(TaskRunLog::query()
        ->where('task_run_id', $run->id)
        ->where('message', 'Coding agent output')
        ->where('context->message', 'Coding agent planning command completed.')
        ->sole()
        ->context)->toMatchArray([
            'command' => ['codex', 'exec', '[prompt omitted]'],
        ]);
});

test('RunApprovedTaskWithCodingAgentJob skips review after retry limit and continues', function () {
    Queue::fake();
    config(['automation.agent.retry_limit' => 3]);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Review failures continue');
    $run = createAutomationRun($task, $repositoryPath, 'task/review-failures-continue');
    $run->update(['retry_limit' => 1]);
    $testsCountPath = $repositoryPath.'/tests-count.txt';
    config(['automation.tests.command' => reviewCountingTestCommand($testsCountPath)]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Review failures continue.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(
                    successful: false,
                    messages: ['review output'],
                    error: 'Review failed.',
                    payload: ['findings' => [['title' => '[P2] Missing test']]],
                ));
            $mock->shouldReceive('fixReviewFindings')->never();
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: skip review retry limit']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->review_attempt_count->toBe(1)
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE)
        ->last_error->toBeNull()
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_REVIEWED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_SKIPPED)
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_COMMITTED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_COMPLETED)
        ->and($run->pull_request_url)->toBe('https://github.com/example/repo/pull/123')
        ->and($run->logs()->where('message', 'Coding agent review failed after retry limit')->exists())
        ->toBeTrue()
        ->and(trim((string) file_get_contents($testsCountPath)))->toBe('1');
});

test('RunApprovedTaskWithCodingAgentJob fails when the review fixer fails before commit', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Fixer failure');
    $run = createAutomationRun($task, $repositoryPath, 'task/fixer-failure');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Fix review findings.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(
                    successful: false,
                    messages: ['review output'],
                    error: 'Needs fixes.',
                    payload: ['findings' => [['title' => '[P2] Missing test']]],
                ));
            $mock->shouldReceive('fixReviewFindings')
                ->once()
                ->andReturn(new CodingAgentResult(successful: false, error: 'Fix failed.'));
            $mock->shouldReceive('generateCommitMessage')->never();
        })
    );
    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );
    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->status->toBe(TaskRun::STATUS_FAILED)
        ->last_error->toBe('Fix failed.')
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_REVIEWED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_FAILED)
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_COMMITTED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_PENDING);
});

test('RunApprovedTaskWithCodingAgentJob fails when tests fail after a review fix', function () {
    Queue::fake();

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Tests fail after fix');
    $run = createAutomationRun($task, $repositoryPath, 'task/tests-fail-after-fix');
    $testsCountPath = $repositoryPath.'/tests-count.txt';
    config(['automation.tests.command' => reviewCountingTestCommand($testsCountPath, 2)]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Retry tests after a fix.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(
                    successful: false,
                    messages: ['review output'],
                    error: 'Needs fixes.',
                    payload: ['findings' => [['title' => '[P2] Missing test']]],
                ));
            $mock->shouldReceive('fixReviewFindings')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, messages: ['fixed']));
            $mock->shouldReceive('generateCommitMessage')->never();
        })
    );
    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );
    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->status->toBe(TaskRun::STATUS_FAILED)
        ->last_error->toBe('Tests failed after review fix.')
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_REVIEWED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_FAILED)
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_COMMITTED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_PENDING)
        ->and(trim((string) file_get_contents($testsCountPath)))->toBe('2');
});

test('RunApprovedTaskWithCodingAgentJob uses generated commit message for git commit', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $assignee = User::factory()->create([
        'email' => 'agent@example.com',
        'github_username' => 'agent-login',
    ]);
    $task = createApprovedAutomationTask($repositoryPath, 'Generated commit message', $assignee);
    $run = createAutomationRun($task, $repositoryPath, 'task/generated-commit-message');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Generate a commit message after implementation.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use ($repositoryPath): CodingAgentResult {
                    file_put_contents($repositoryPath.'/generated.txt', "Generated\n");

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'feat: use generated subject']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    $commit = trim(runSuccessfulProcessWithOutput(['git', 'log', '-1', '--pretty=%s'], $repositoryPath));
    $author = trim(runSuccessfulProcessWithOutput(['git', 'log', '-1', '--pretty=%an <%ae>'], $repositoryPath));
    $localCommit = trim(runSuccessfulProcessWithOutput(['git', 'rev-parse', 'task/generated-commit-message'], $repositoryPath));
    $remoteBranch = trim(runSuccessfulProcessWithOutput(['git', 'ls-remote', 'origin', 'refs/heads/task/generated-commit-message'], $repositoryPath));

    expect($commit)->toBe('feat: use generated subject')
        ->and($author)->toBe('agent-login <agent@example.com>')
        ->and($remoteBranch)->toStartWith($localCommit);
});

test('RunApprovedTaskWithCodingAgentJob fails before pull request creation when branch push fails', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();

    $task = createApprovedAutomationTask($repositoryPath, 'Push failure before PR');
    $run = createAutomationRun($task, $repositoryPath, 'task/push-failure-before-pr');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Create changes before push.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use ($repositoryPath): CodingAgentResult {
                    runSuccessfulProcess(['git', 'remote', 'remove', 'origin'], $repositoryPath);
                    file_put_contents($repositoryPath.'/push-failure.txt', "Cannot push\n");

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: push failure']));
        })
    );
    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );
    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    $run->refresh();

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED)
        ->and($run->status)->toBe(TaskRun::STATUS_FAILED)
        ->and($run->last_error)->toStartWith('Unable to push committed changes:')
        ->and($run->checkpoint(TaskRun::CHECKPOINT_CHANGES_COMMITTED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_FAILED)
        ->and($run->checkpoint(TaskRun::CHECKPOINT_PULL_REQUEST_CREATED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_PENDING);
});

test('RunApprovedTaskWithCodingAgentJob resumes from first incomplete checkpoint', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Resume from review');
    $run = createAutomationRun($task, $repositoryPath, 'task/resume-from-review');
    runSuccessfulProcess(['git', 'checkout', '-B', 'task/resume-from-review'], $repositoryPath);
    $run->initializeWorkflowState($task);
    $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->markCheckpointCompleted(TaskRun::CHECKPOINT_PLANNED);
    $run->markCheckpointCompleted(TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED);
    $run->update(['status' => TaskRun::STATUS_FAILED]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')->never();
            $mock->shouldReceive('run')->never();
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: resume from review']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE)
        ->attempt_count->toBe(0)
        ->review_attempt_count->toBe(1)
        ->and($run->isCheckpointComplete(TaskRun::CHECKPOINT_CHANGES_REVIEWED))->toBeTrue();
});

test('task run retries the failed checkpoint before the next pending checkpoint', function () {
    $task = Task::create([
        'title' => 'Retry failed checkpoint',
        'description' => 'Retry should resume the failed checkpoint.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'task/retry-failed-checkpoint',
    ]);

    $run->initializeWorkflowState($task);
    $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->markCheckpointFailed(TaskRun::CHECKPOINT_CHANGES_REVIEWED, 'Review failed.');

    expect($run->nextIncompleteCheckpoint())->toBe(TaskRun::CHECKPOINT_PLANNED)
        ->and($run->nextRunnableCheckpoint())->toBe(TaskRun::CHECKPOINT_CHANGES_REVIEWED);
});

test('task run workflow initializes planning before implementation verification', function () {
    $task = Task::create([
        'title' => 'Plan before implementation',
        'description' => 'Planning should be an automatic checkpoint.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'task/plan-before-implementation',
    ]);

    $run->initializeWorkflowState($task);

    expect(collect($run->refresh()->workflowCheckpoints())->pluck('name')->all())->toBe([
        TaskRun::CHECKPOINT_REPOSITORY_PREPARED,
        TaskRun::CHECKPOINT_PLANNED,
        TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED,
        TaskRun::CHECKPOINT_SCREENSHOT_VERIFIED,
        TaskRun::CHECKPOINT_CHANGES_REVIEWED,
        TaskRun::CHECKPOINT_CHANGES_COMMITTED,
        TaskRun::CHECKPOINT_PULL_REQUEST_CREATED,
        TaskRun::CHECKPOINT_REVIEW_REQUESTED,
        TaskRun::CHECKPOINT_EXTERNAL_TASK_UPDATED,
    ]);
});

test('task run retries a failed planning checkpoint before implementation', function () {
    $task = Task::create([
        'title' => 'Retry failed planning',
        'description' => 'Retry should resume planning before implementation.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'task/retry-failed-planning',
    ]);

    $run->initializeWorkflowState($task);
    $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->markCheckpointFailed(TaskRun::CHECKPOINT_PLANNED, 'Planning failed.');

    expect($run->nextRunnableCheckpoint())->toBe(TaskRun::CHECKPOINT_PLANNED);
});

test('failed planning stores checkpoint failure and does not run implementation', function () {
    Queue::fake();

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Planning failure');
    $run = createAutomationRun($task, $repositoryPath, 'task/planning-failure');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: false, error: 'Planning failed.'));
            $mock->shouldReceive('run')->never();
            $mock->shouldReceive('reviewChanges')->never();
            $mock->shouldReceive('generateCommitMessage')->never();
        })
    );
    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );
    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->status->toBe(TaskRun::STATUS_FAILED)
        ->last_error->toBe('Planning failed.')
        ->plan->toBeNull()
        ->and($run->checkpoint(TaskRun::CHECKPOINT_PLANNED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_FAILED)
        ->and($run->checkpoint(TaskRun::CHECKPOINT_IMPLEMENTATION_VERIFIED)['status'])->toBe(TaskRun::CHECKPOINT_STATUS_PENDING);
});

test('repository checkpoint retry checks out existing ai branch without resetting work', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Preserve branch work');
    $run = createAutomationRun($task, $repositoryPath, 'task/preserve-branch-work');

    runSuccessfulProcess(['git', 'checkout', '-B', 'task/preserve-branch-work'], $repositoryPath);
    file_put_contents($repositoryPath.'/preserved.txt', "Keep me\n");
    runSuccessfulProcess(['git', 'add', 'preserved.txt'], $repositoryPath);
    runSuccessfulProcess(['git', 'commit', '-m', 'Preserved work'], $repositoryPath);
    runSuccessfulProcess(['git', 'checkout', 'main'], $repositoryPath);

    $run->initializeWorkflowState($task);
    $run->markCheckpointCompleted(TaskRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->update(['status' => TaskRun::STATUS_FAILED]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Preserve existing branch work.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use ($repositoryPath): CodingAgentResult {
                    expect(file_exists($repositoryPath.'/preserved.txt'))->toBeTrue();

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: preserve branch work']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect(trim(runSuccessfulProcessWithOutput(['git', 'branch', '--show-current'], $repositoryPath)))
        ->toBe('task/preserve-branch-work');
});

test('fresh repository preparation discards dirty work and recreates task branch from updated base', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $originPath = trim(runSuccessfulProcessWithOutput(['git', 'remote', 'get-url', 'origin'], $repositoryPath));
    $upstreamPath = sys_get_temp_dir().'/task-fox-upstream-'.uniqid();

    runSuccessfulProcess(['git', 'clone', $originPath, $upstreamPath], sys_get_temp_dir());
    runSuccessfulProcess(['git', 'config', 'user.email', 'upstream@example.com'], $upstreamPath);
    runSuccessfulProcess(['git', 'config', 'user.name', 'Task Fox Upstream'], $upstreamPath);
    file_put_contents($upstreamPath.'/base.txt', "Latest base\n");
    runSuccessfulProcess(['git', 'add', 'base.txt'], $upstreamPath);
    runSuccessfulProcess(['git', 'commit', '-m', 'Update base branch'], $upstreamPath);
    runSuccessfulProcess(['git', 'push', 'origin', 'main'], $upstreamPath);

    file_put_contents($repositoryPath.'/README.md', "Dirty workspace\n");
    file_put_contents($repositoryPath.'/untracked.txt', "Remove me\n");

    $task = createApprovedAutomationTask($repositoryPath, 'Fresh preparation');
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => 'pending',
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath, $task): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturnUsing(function () use ($repositoryPath, $task): CodingAgentResult {
                    expect(file_get_contents($repositoryPath.'/README.md'))->toBe("Review test\n")
                        ->and(file_exists($repositoryPath.'/untracked.txt'))->toBeFalse()
                        ->and(file_get_contents($repositoryPath.'/base.txt'))->toBe("Latest base\n")
                        ->and(trim(runSuccessfulProcessWithOutput(['git', 'branch', '--show-current'], $repositoryPath)))
                        ->toBe('ai-task-'.$task->id.'-fresh-preparation');

                    return new CodingAgentResult(successful: true, payload: ['plan' => 'Verify fresh preparation.']);
                });
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: fresh preparation']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    $baseCommit = trim(runSuccessfulProcessWithOutput(['git', 'rev-parse', 'main'], $repositoryPath));
    $branchParent = trim(runSuccessfulProcessWithOutput(['git', 'rev-parse', 'ai-task-'.$task->id.'-fresh-preparation~0'], $repositoryPath));

    expect($run->refresh()->isCheckpointComplete(TaskRun::CHECKPOINT_REPOSITORY_PREPARED))->toBeTrue()
        ->and($branchParent)->toBe($baseCommit);
});

test('failed task can create a pull request from the latest task run branch', function () {
    $actor = User::factory()->create([
        'email' => 'author@example.com',
        'github_username' => 'author-login',
        'github_token' => 'ghp_author_token',
    ]);
    $assignee = User::factory()->create(['github_username' => 'assignee-login']);
    $defaultReviewer = User::factory()->create(['github_username' => 'reviewer-login']);
    $project = Project::create([
        'name' => 'Manual PR Project',
        'workspace_path' => '/tmp/manual-pr',
        'default_reviewer_user_id' => $defaultReviewer->id,
    ]);
    $task = Task::create([
        'title' => 'Open manual PR',
        'description' => 'A failed run can still have useful changes to open.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee->id,
        'project_id' => $project->id,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-1-open-manual-pr',
        'workspace_path' => '/tmp/manual-pr',
        'base_branch' => 'main',
        'last_error' => 'Tests failed after retry limit reached.',
    ]);
    $this->actingAs($actor);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock) use ($run, $actor, $defaultReviewer): void {
            $mock->shouldReceive('createPullRequest')
                ->once()
                ->with(
                    Mockery::type(Task::class),
                    Mockery::on(fn (TaskRun $givenRun): bool => $givenRun->is($run)),
                    Mockery::on(fn (User $givenUser): bool => $givenUser->is($actor)),
                )
                ->andReturn(new PullRequestResult(
                    url: 'https://github.com/example/repo/pull/456',
                    number: 456,
                ));
            $mock->shouldReceive('requestReview')
                ->once()
                ->with(
                    'https://github.com/example/repo/pull/456',
                    Mockery::on(fn (User $user): bool => $user->is($defaultReviewer)),
                    Mockery::on(fn (User $givenUser): bool => $givenUser->is($actor)),
                );
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    $this->post(route('tasks.create-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Pull request created.');

    expect($task->refresh())
        ->status->toBe(Task::STATUS_PR_CREATED)
        ->and($run->refresh())
        ->status->toBe(TaskRun::STATUS_WAITING_FOR_MERGE)
        ->pull_request_url->toBe('https://github.com/example/repo/pull/456')
        ->pull_request_number->toBe(456)
        ->last_error->toBeNull();
});

test('manual pull request creation requires profile identity before changing run status', function () {
    $actor = User::factory()->create(['github_username' => null]);
    $task = Task::create([
        'title' => 'Missing identity',
        'description' => 'Manual PR creation requires a Git author.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-1-missing-identity',
        'workspace_path' => '/tmp/manual-pr',
    ]);
    $this->actingAs($actor);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    $this->post(route('tasks.create-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'pull_request_identity' => 'Complete your profile email, GitHub username, and GitHub token before creating a pull request.',
        ]);

    expect($task->refresh()->status)
        ->toBe(Task::STATUS_FAILED)
        ->and($run->refresh()->status)
        ->toBe(TaskRun::STATUS_FAILED);
});

test('manual pull request creation requires a saved github token before changing run status', function () {
    $actor = User::factory()->create([
        'email' => 'author@example.com',
        'github_username' => 'author-login',
        'github_token' => null,
    ]);
    $task = Task::create([
        'title' => 'Missing token',
        'description' => 'Manual PR creation requires a GitHub token.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'ai-task-1-missing-token',
        'workspace_path' => '/tmp/manual-pr',
    ]);
    $this->actingAs($actor);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    $this->post(route('tasks.create-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'pull_request_identity' => 'Complete your profile email, GitHub username, and GitHub token before creating a pull request.',
        ]);

    expect($task->refresh()->status)
        ->toBe(Task::STATUS_FAILED)
        ->and($run->refresh()->status)
        ->toBe(TaskRun::STATUS_FAILED);
});

test('manual pull request creation requires a latest task run branch', function () {
    $task = Task::create([
        'title' => 'Missing branch',
        'description' => 'A branch is required to create a pull request.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => '',
    ]);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );

    $this->post(route('tasks.create-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors([
            'pull_request' => 'The latest task run does not have a branch to open.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED);
});

test('manual pull request creation rejects tasks with an existing pull request run', function () {
    $actor = User::factory()->create([
        'email' => 'author@example.com',
        'github_username' => 'author-login',
        'github_token' => 'ghp_author_token',
    ]);
    $task = Task::create([
        'title' => 'Do not duplicate PRs',
        'description' => 'Manual creation should not open a second pull request.',
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
        'branch_name' => 'task/existing-pr',
        'pull_request_url' => 'https://github.com/example/repo/pull/44',
        'pull_request_number' => 44,
    ]);

    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'task/newer-run-without-pr',
    ]);

    $this->actingAs($actor);

    $this->post(route('tasks.create-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors(['pull_request' => 'This task already has a pull request.']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED);
});

test('refresh pull request uses the latest pull request bearing task run', function () {
    $task = Task::create([
        'title' => 'Refresh existing PR',
        'description' => 'Refresh should ignore newer runs without pull requests.',
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

    $this->post(route('tasks.refresh-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Pull request state is merged.');

    expect($task->refresh()->status)->toBe(Task::STATUS_DONE)
        ->and($pullRequestRun->refresh()->status)->toBe(TaskRun::STATUS_DONE);
});

test('refresh pull request marks closed pull requests as rejected', function () {
    Queue::fake();

    $task = Task::create([
        'title' => 'Refresh closed PR manually',
        'description' => 'Manual refresh should reject closed pull requests.',
        'status' => Task::STATUS_PR_CREATED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_at' => now(),
    ]);
    $pullRequestRun = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
        'branch_name' => 'task/manual-closed-pr',
        'pull_request_url' => 'https://github.com/example/repo/pull/58',
        'pull_request_number' => 58,
    ]);

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getReviewState')
                ->once()
                ->with('https://github.com/example/repo/pull/58')
                ->andReturn(PullRequestReviewState::CLOSED);
            $mock->shouldReceive('createPullRequest')->never();
            $mock->shouldReceive('requestReview')->never();
        })
    );

    $this->post(route('tasks.refresh-pr', $task))
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Pull request state is closed.');

    expect($task->refresh()->status)->toBe(Task::STATUS_REJECTED)
        ->and($task->rejected_at)->not->toBeNull()
        ->and($task->approved_at)->toBeNull()
        ->and($pullRequestRun->refresh()->status)->toBe(TaskRun::STATUS_REJECTED)
        ->and($pullRequestRun->last_error)->toBe('Pull request closed.')
        ->and($pullRequestRun->finished_at)->not->toBeNull();

    Queue::assertPushed(DispatchNextTaskRunJob::class);
});

test('task run logs remain attached to their task run', function () {
    $task = Task::create([
        'title' => 'Log by run',
        'description' => 'Logs should resolve through task runs.',
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/log-by-run',
    ]);
    $log = TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'info',
        'message' => 'Attached to task run',
    ]);

    expect($log->taskRun->is($run))->toBeTrue()
        ->and($run->logs()->sole()->is($log))->toBeTrue();
});

function bindSuccessfulRunMocks(User $expectedReviewer, ?User $expectedActor = null): void
{
    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Create the pull request.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: create pull request']));
        })
    );

    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock) use ($expectedReviewer, $expectedActor): void {
            $mock->shouldReceive('createPullRequest')
                ->once()
                ->with(
                    Mockery::type(Task::class),
                    Mockery::type(TaskRun::class),
                    $expectedActor === null ? null : Mockery::on(fn (User $user): bool => $user->is($expectedActor)),
                )
                ->andReturn(new PullRequestResult(
                    url: 'https://github.com/example/repo/pull/123',
                    number: 123,
                ));
            $mock->shouldReceive('requestReview')
                ->once()
                ->with(
                    'https://github.com/example/repo/pull/123',
                    Mockery::on(fn (User $user): bool => $user->is($expectedReviewer)),
                    $expectedActor === null ? null : Mockery::on(fn (User $user): bool => $user->is($expectedActor)),
                );
            $mock->shouldReceive('getReviewState')
                ->never()
                ->andReturn(PullRequestReviewState::UNKNOWN);
        })
    );
}

function bindSuccessfulRunMocksWithoutReview(?User $expectedActor = null): void
{
    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('plan')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['plan' => 'Create the pull request without review.']));
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: create pull request']));
        })
    );

    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock) use ($expectedActor): void {
            $mock->shouldReceive('createPullRequest')
                ->once()
                ->with(
                    Mockery::type(Task::class),
                    Mockery::type(TaskRun::class),
                    $expectedActor === null ? null : Mockery::on(fn (User $user): bool => $user->is($expectedActor)),
                )
                ->andReturn(new PullRequestResult(
                    url: 'https://github.com/example/repo/pull/123',
                    number: 123,
                ));
            $mock->shouldReceive('requestReview')->never();
            $mock->shouldReceive('getReviewState')->never();
        })
    );
}

function bindSuccessfulAuxiliaryMocks(?array &$events = null, ?string $repositoryPath = null, ?string $expectedCommit = null): void
{
    test()->instance(
        ExternalTaskProvider::class,
        Mockery::mock(ExternalTaskProvider::class)
    );

    test()->instance(
        PullRequestProvider::class,
        Mockery::mock(PullRequestProvider::class, function (MockInterface $mock) use (&$events, $repositoryPath, $expectedCommit): void {
            $mock->shouldReceive('createPullRequest')
                ->once()
                ->andReturnUsing(function () use (&$events, $repositoryPath, $expectedCommit): PullRequestResult {
                    $events[] = 'pull-request';

                    if ($repositoryPath !== null && $expectedCommit !== null) {
                        expect(trim(runSuccessfulProcessWithOutput(['git', 'log', '-1', '--pretty=%s'], $repositoryPath)))->toBe($expectedCommit);
                    }

                    return new PullRequestResult(
                        url: 'https://github.com/example/repo/pull/123',
                        number: 123,
                    );
                });
            $mock->shouldReceive('requestReview')->zeroOrMoreTimes();
            $mock->shouldReceive('getReviewState')->never();
        })
    );
}

function createApprovedAutomationTask(string $repositoryPath, string $title, ?User $assignee = null, ?string $projectUrl = null): Task
{
    $project = Project::create([
        'name' => $title,
        'workspace_path' => $repositoryPath,
        'url' => $projectUrl,
    ]);

    return Task::create([
        'title' => $title,
        'description' => 'Run the approved automation flow.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee?->id,
        'project_id' => $project->id,
    ]);
}

function createAutomationRun(Task $task, string $repositoryPath, string $branchName): TaskRun
{
    return TaskRun::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'status' => TaskRun::STATUS_QUEUED,
        'branch_name' => $branchName,
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);
}

function createCleanGitRepository(): string
{
    $repositoryPath = sys_get_temp_dir().'/task-fox-review-repo-'.uniqid();
    $originPath = sys_get_temp_dir().'/task-fox-review-origin-'.uniqid();

    mkdir($repositoryPath);
    mkdir($originPath);
    runSuccessfulProcess(['git', 'init', '--bare'], $originPath);
    runSuccessfulProcess(['git', 'init', '-b', 'main'], $repositoryPath);
    runSuccessfulProcess(['git', 'config', 'user.email', 'tests@example.com'], $repositoryPath);
    runSuccessfulProcess(['git', 'config', 'user.name', 'Task Fox Tests'], $repositoryPath);
    file_put_contents($repositoryPath.'/README.md', "Review test\n");
    runSuccessfulProcess(['git', 'add', 'README.md'], $repositoryPath);
    runSuccessfulProcess(['git', 'commit', '-m', 'Initial commit'], $repositoryPath);
    runSuccessfulProcess(['git', 'remote', 'add', 'origin', $originPath], $repositoryPath);
    runSuccessfulProcess(['git', 'push', '-u', 'origin', 'main'], $repositoryPath);

    return $repositoryPath;
}

function runSuccessfulProcess(array $command, string $cwd): void
{
    $process = new Process($command, $cwd);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getErrorOutput());
    }
}

function runSuccessfulProcessWithOutput(array $command, string $cwd): string
{
    $process = new Process($command, $cwd);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getErrorOutput());
    }

    return (string) $process->getOutput();
}

function reviewCountingTestCommand(string $path, ?int $failOnInvocation = null): string
{
    $script = 'count=0; if [ -f '.escapeshellarg($path).' ]; then count=$(cat '.escapeshellarg($path).'); fi; count=$((count + 1)); printf %s "$count" > '.escapeshellarg($path).';';

    if ($failOnInvocation !== null) {
        $script .= ' if [ "$count" -eq '.(int) $failOnInvocation.' ]; then exit 1; fi;';
    }

    return 'sh -c '.escapeshellarg($script);
}
