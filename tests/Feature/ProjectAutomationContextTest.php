<?php

use App\Contracts\CodingAgent;
use App\Contracts\ExternalTaskProvider;
use App\Contracts\PullRequestProvider;
use App\DataTransferObjects\CodingAgentResult;
use App\DataTransferObjects\PullRequestResult;
use App\Enums\PullRequestReviewState;
use App\Jobs\DispatchNextAiRunJob;
use App\Jobs\RunApprovedTaskWithCodingAgentJob;
use App\Models\AiRun;
use App\Models\AiRunLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CodingAgents\CodexCodingAgent;
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
        'acceptance_criteria' => [
            ['body' => 'Task is created without a project.', 'checked' => false],
        ],
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
        'acceptance_criteria' => [
            ['body' => 'Requires project before workflow action.', 'checked' => false],
        ],
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

test('reject can change task status without a project', function () {
    Queue::fake();

    User::factory()->create();
    $task = Task::create([
        'title' => 'Projectless rejection',
        'description' => 'Should be rejectable without repository context.',
        'acceptance_criteria' => [
            ['body' => 'Can be rejected during triage.', 'checked' => false],
        ],
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $this->from(route('tasks.index'))->post(route('tasks.reject', $task))
        ->assertRedirect(route('tasks.index'))
        ->assertSessionHasNoErrors();

    expect($task->refresh())
        ->project_id->toBeNull()
        ->status->toBe(Task::STATUS_REJECTED)
        ->rejected_at->not->toBeNull();
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
        'acceptance_criteria' => [
            ['body' => 'Retry schedules another AI run.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
        'pull_request_url' => 'https://github.com/example/task-fox/pull/10',
        'pull_request_number' => 10,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-retry-failed-run',
        'repository_path' => '/tmp/task-fox',
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
        ->status->toBe(AiRun::STATUS_QUEUED)
        ->last_error->toBeNull()
        ->finished_at->toBeNull();

    Queue::assertPushed(
        DispatchNextAiRunJob::class,
        fn (DispatchNextAiRunJob $job): bool => $job->taskId === $task->id,
    );
});

test('retry rejects failed tasks without a matching resumable ai run', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
    ]);
    $task = Task::create([
        'title' => 'Changed failed run',
        'description' => 'Retry requires a matching request hash.',
        'acceptance_criteria' => [
            ['body' => 'Retry is blocked.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-changed-failed-run',
        'repository_path' => '/tmp/task-fox',
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

    Queue::assertNotPushed(DispatchNextAiRunJob::class);
});

test('dispatch reuses the latest failed resumable ai run and preserves logs', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox-current',
        'base_branch' => 'develop',
    ]);
    $task = Task::create([
        'title' => 'Reuse failed run',
        'description' => 'Retry should not create a replacement run.',
        'acceptance_criteria' => [
            ['body' => 'Old logs remain attached.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $historicalRun = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-old',
        'repository_path' => '/tmp/task-fox-old',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
    ]);
    $historicalRun->initializeWorkflowState($task);
    $run = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-reuse-failed-run',
        'repository_path' => '/tmp/task-fox-old',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);
    AiRunLog::create([
        'ai_run_id' => $run->id,
        'level' => 'error',
        'message' => 'Previous failure',
        'context' => [],
    ]);

    app()->call([new DispatchNextAiRunJob($task->id), 'handle']);

    expect(AiRun::query()->count())->toBe(2)
        ->and($run->refresh()->status)->toBe(AiRun::STATUS_QUEUED)
        ->and($run->repository_path)->toBe('/tmp/task-fox-current')
        ->and($run->workspace_path)->toBe('/tmp/task-fox-current')
        ->and($run->base_branch)->toBe('develop')
        ->and($run->logs()->where('message', 'Previous failure')->exists())->toBeTrue();

    Queue::assertPushed(
        RunApprovedTaskWithCodingAgentJob::class,
        fn (RunApprovedTaskWithCodingAgentJob $job): bool => $job->aiRunId === $run->id,
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
        'acceptance_criteria' => [
            ['body' => 'Queued retry is dispatched.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_QUEUED,
        'branch_name' => 'ai-task-'.$task->id.'-start-queued-retry',
        'repository_path' => '/tmp/task-fox-old',
        'workspace_path' => '/tmp/task-fox-old',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);

    app()->call([new DispatchNextAiRunJob($task->id), 'handle']);

    expect(AiRun::query()->count())->toBe(1)
        ->and($task->refresh()->status)->toBe(Task::STATUS_RUNNING)
        ->and($run->refresh()->status)->toBe(AiRun::STATUS_QUEUED)
        ->and($run->repository_path)->toBe('/tmp/task-fox-current')
        ->and($run->base_branch)->toBe('develop');

    Queue::assertPushed(
        RunApprovedTaskWithCodingAgentJob::class,
        fn (RunApprovedTaskWithCodingAgentJob $job): bool => $job->aiRunId === $run->id,
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
        'acceptance_criteria' => [
            ['body' => 'Original criterion.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => auth()->id(),
        'approved_at' => now(),
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-'.$task->id.'-edit-failed-task',
        'repository_path' => '/tmp/task-fox',
        'workspace_path' => '/tmp/task-fox',
        'base_branch' => 'main',
    ]);
    $run->initializeWorkflowState($task);
    AiRunLog::create([
        'ai_run_id' => $run->id,
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
        'acceptance_criteria' => [
            ['body' => 'Updated criterion.', 'checked' => false],
        ],
    ])->assertRedirect(route('tasks.index', ['task' => $task->id]));

    expect($task->refresh())
        ->status->toBe(Task::STATUS_PENDING_APPROVAL)
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->and(AiRun::query()->count())->toBe(1)
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
        'acceptance_criteria' => [
            ['body' => 'Non-failed task is not queued.', 'checked' => false],
        ],
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

    Queue::assertNotPushed(DispatchNextAiRunJob::class);
});

test('ai run creation snapshots project workspace path and base branch', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
        'base_branch' => 'develop',
    ]);
    $task = Task::create([
        'title' => 'Run against project repository',
        'description' => 'AI run should use project repository settings.',
        'acceptance_criteria' => [
            ['body' => 'Run snapshots repository context.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    app()->call([new DispatchNextAiRunJob($task->id), 'handle']);

    $run = AiRun::query()->sole();

    expect($run)
        ->project_id->toBe($project->id)
        ->repository_path->toBe('/tmp/task-fox')
        ->workspace_path->toBe('/tmp/task-fox')
        ->base_branch->toBe('develop');

    Queue::assertPushed(RunApprovedTaskWithCodingAgentJob::class);
});

test('ai run snapshot defaults blank project base branch to main', function () {
    Queue::fake();

    $project = Project::create([
        'name' => 'Mainline Project',
        'workspace_path' => '/tmp/mainline',
        'url' => 'https://github.com/example/mainline',
    ]);
    $task = Task::create([
        'title' => 'Run against main',
        'description' => 'Blank base branch should default to main.',
        'acceptance_criteria' => [
            ['body' => 'Base branch defaults to main.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    app()->call([new DispatchNextAiRunJob($task->id), 'handle']);

    expect(AiRun::query()->sole()->base_branch)->toBe('main');
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
        'acceptance_criteria' => [
            ['body' => 'Approval metadata remains visible.', 'checked' => false],
        ],
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
        ->status->toBe(AiRun::STATUS_FAILED)
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
        'acceptance_criteria' => [
            ['body' => 'Workspace is set for Codex.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/workspace',
        'repository_path' => $workspacePath,
        'workspace_path' => $workspacePath,
        'base_branch' => 'main',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and(trim((string) file_get_contents($cwdPath)))->toBe($workspacePath)
        ->and($args)->toContain('exec')
        ->and($args)->toContain('--dangerously-bypass-approvals-and-sandbox')
        ->and($args)->toContain('-C')
        ->and($args[array_search('-C', $args, true) + 1])->toBe($workspacePath);
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
        'acceptance_criteria' => [
            ['body' => 'Default reviewer receives the review request.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee->id,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_QUEUED,
        'branch_name' => 'task/default-reviewer',
        'repository_path' => $repositoryPath,
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocks($defaultReviewer, $assignee);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh())
        ->status->toBe(Task::STATUS_PR_CREATED)
        ->pull_request_url->toBe('https://github.com/example/repo/pull/123');

    Queue::assertPushed(DispatchNextAiRunJob::class);
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
        'acceptance_criteria' => [
            ['body' => 'Self-review requests are skipped.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee->id,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_QUEUED,
        'branch_name' => 'task/assignee-reviewer',
        'repository_path' => $repositoryPath,
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocksWithoutReview($assignee);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED)
        ->and($run->refresh()->isCheckpointComplete(AiRun::CHECKPOINT_REVIEW_REQUESTED))->toBeTrue();
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
        'acceptance_criteria' => [
            ['body' => 'Approver review requests are skipped.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => $approver->id,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_QUEUED,
        'branch_name' => 'task/approver-reviewer',
        'repository_path' => $repositoryPath,
        'workspace_path' => $repositoryPath,
        'base_branch' => 'main',
    ]);

    bindSuccessfulRunMocksWithoutReview();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED)
        ->and($run->refresh()->isCheckpointComplete(AiRun::CHECKPOINT_REVIEW_REQUESTED))->toBeTrue();
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
        'acceptance_criteria' => [
            ['body' => 'Task reviewer receives the review request.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'reviewer_user_id' => $taskReviewer->id,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_QUEUED,
        'branch_name' => 'task/task-reviewer',
        'repository_path' => $repositoryPath,
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
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->with(Mockery::type(Task::class), Mockery::type(AiRun::class), 1)
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
        ->status->toBe(AiRun::STATUS_WAITING_FOR_MERGE);
});

test('RunApprovedTaskWithCodingAgentJob reviews after tests pass and before pull request creation', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $events = [];
    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Review before PR');
    $run = createAutomationRun($task, $repositoryPath, 'task/review-before-pr');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use (&$events, $repositoryPath): void {
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

    expect($events)->toBe(['implementation', 'review', 'commit-message', 'pull-request']);
});

test('RunApprovedTaskWithCodingAgentJob stops review retries after success', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Review retry success');
    $run = createAutomationRun($task, $repositoryPath, 'task/review-retry-success');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->twice()
                ->andReturn(
                    new CodingAgentResult(successful: false, error: 'Needs fixes.'),
                    new CodingAgentResult(successful: true),
                );
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: review retry']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->review_attempt_count->toBe(2)
        ->status->toBe(AiRun::STATUS_WAITING_FOR_MERGE);
});

test('RunApprovedTaskWithCodingAgentJob fails after three failed reviews', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Review failures continue');
    $run = createAutomationRun($task, $repositoryPath, 'task/review-failures-continue');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('reviewChanges')
                ->times(3)
                ->andReturn(new CodingAgentResult(successful: false, error: 'Review failed.'));
            $mock->shouldReceive('generateCommitMessage')
                ->never();
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
        ->review_attempt_count->toBe(3)
        ->status->toBe(AiRun::STATUS_FAILED)
        ->last_error->toBe('Coding agent review failed after retry limit reached.')
        ->and($run->logs()->where('message', 'Coding agent review failed after retry limit')->exists())
        ->toBeTrue();
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
    runSuccessfulProcess(['git', 'remote', 'remove', 'origin'], $repositoryPath);

    $task = createApprovedAutomationTask($repositoryPath, 'Push failure before PR');
    $run = createAutomationRun($task, $repositoryPath, 'task/push-failure-before-pr');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use ($repositoryPath): CodingAgentResult {
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
        ->and($run->status)->toBe(AiRun::STATUS_FAILED)
        ->and($run->last_error)->toStartWith('Unable to push committed changes:')
        ->and($run->checkpoint(AiRun::CHECKPOINT_CHANGES_COMMITTED)['status'])->toBe(AiRun::CHECKPOINT_STATUS_FAILED)
        ->and($run->checkpoint(AiRun::CHECKPOINT_PULL_REQUEST_CREATED)['status'])->toBe(AiRun::CHECKPOINT_STATUS_PENDING);
});

test('RunApprovedTaskWithCodingAgentJob resumes from first incomplete checkpoint', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Resume from review');
    $run = createAutomationRun($task, $repositoryPath, 'task/resume-from-review');
    runSuccessfulProcess(['git', 'checkout', '-B', 'task/resume-from-review'], $repositoryPath);
    $run->initializeWorkflowState($task);
    $run->markCheckpointCompleted(AiRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->markCheckpointCompleted(AiRun::CHECKPOINT_IMPLEMENTATION_VERIFIED);
    $run->update(['status' => AiRun::STATUS_FAILED]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
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
        ->status->toBe(AiRun::STATUS_WAITING_FOR_MERGE)
        ->attempt_count->toBe(0)
        ->review_attempt_count->toBe(1)
        ->and($run->isCheckpointComplete(AiRun::CHECKPOINT_CHANGES_REVIEWED))->toBeTrue();
});

test('AI run retries the failed checkpoint before the next pending checkpoint', function () {
    $task = Task::create([
        'title' => 'Retry failed checkpoint',
        'description' => 'Retry should resume the failed checkpoint.',
        'acceptance_criteria' => [
            ['body' => 'The failed checkpoint is retried first.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'task/retry-failed-checkpoint',
        'repository_path' => base_path(),
    ]);

    $run->initializeWorkflowState($task);
    $run->markCheckpointCompleted(AiRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->markCheckpointFailed(AiRun::CHECKPOINT_CHANGES_REVIEWED, 'Review failed.');

    expect($run->nextIncompleteCheckpoint())->toBe(AiRun::CHECKPOINT_IMPLEMENTATION_VERIFIED)
        ->and($run->nextRunnableCheckpoint())->toBe(AiRun::CHECKPOINT_CHANGES_REVIEWED);
});

test('verified implementation marks unchecked acceptance criteria as checked', function () {
    Queue::fake();
    config(['automation.tests.command' => 'true']);

    $repositoryPath = createCleanGitRepository();
    $task = createApprovedAutomationTask($repositoryPath, 'Mark criteria verified');
    $task->forceFill([
        'acceptance_criteria' => [
            ['body' => 'Implementation is present.', 'checked' => false],
            ['body' => 'Existing behavior is already verified.', 'checked' => true],
        ],
    ])->save();
    $run = createAutomationRun($task, $repositoryPath, 'task/mark-criteria-verified');

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath): void {
            $mock->shouldReceive('run')
                ->once()
                ->andReturnUsing(function () use ($repositoryPath): CodingAgentResult {
                    file_put_contents($repositoryPath.'/verified.txt', "Verified\n");

                    return new CodingAgentResult(successful: true);
                });
            $mock->shouldReceive('reviewChanges')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true));
            $mock->shouldReceive('generateCommitMessage')
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: mark criteria verified']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->acceptance_criteria)->toBe([
        ['body' => 'Implementation is present.', 'checked' => true],
        ['body' => 'Existing behavior is already verified.', 'checked' => true],
    ]);

    $log = AiRunLog::query()
        ->where('ai_run_id', $run->id)
        ->where('message', 'Acceptance criteria verified')
        ->sole();

    expect($log->context)->toMatchArray([
        'verified_count' => 2,
        'newly_verified_count' => 1,
    ]);
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
    $run->markCheckpointCompleted(AiRun::CHECKPOINT_REPOSITORY_PREPARED);
    $run->update(['status' => AiRun::STATUS_FAILED]);

    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock) use ($repositoryPath): void {
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

test('failed task can create a pull request from the latest ai run branch', function () {
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
        'acceptance_criteria' => [
            ['body' => 'Manual PR is created from the run branch.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee->id,
        'project_id' => $project->id,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'project_id' => $project->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-1-open-manual-pr',
        'repository_path' => '/tmp/manual-pr',
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
                    Mockery::on(fn (AiRun $givenRun): bool => $givenRun->is($run)),
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
        ->pull_request_url->toBe('https://github.com/example/repo/pull/456')
        ->pull_request_number->toBe(456)
        ->and($run->refresh())
        ->status->toBe(AiRun::STATUS_WAITING_FOR_MERGE)
        ->pull_request_url->toBe('https://github.com/example/repo/pull/456')
        ->pull_request_number->toBe(456)
        ->last_error->toBeNull();
});

test('manual pull request creation requires profile identity before changing run status', function () {
    $actor = User::factory()->create(['github_username' => null]);
    $task = Task::create([
        'title' => 'Missing identity',
        'description' => 'Manual PR creation requires a Git author.',
        'acceptance_criteria' => [
            ['body' => 'Manual PR is blocked without profile identity.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-1-missing-identity',
        'repository_path' => '/tmp/manual-pr',
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
        ->toBe(AiRun::STATUS_FAILED);
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
        'acceptance_criteria' => [
            ['body' => 'Manual PR is blocked without a token.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => 'ai-task-1-missing-token',
        'repository_path' => '/tmp/manual-pr',
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
        ->toBe(AiRun::STATUS_FAILED);
});

test('manual pull request creation requires a latest ai run branch', function () {
    $task = Task::create([
        'title' => 'Missing branch',
        'description' => 'A branch is required to create a pull request.',
        'acceptance_criteria' => [
            ['body' => 'Manual PR is blocked without a branch.', 'checked' => false],
        ],
        'status' => Task::STATUS_FAILED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_FAILED,
        'branch_name' => '',
        'repository_path' => '',
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
            'pull_request' => 'The latest AI run does not have a branch to open.',
        ]);

    expect($task->refresh()->status)->toBe(Task::STATUS_FAILED);
});

function bindSuccessfulRunMocks(User $expectedReviewer, ?User $expectedActor = null): void
{
    test()->instance(
        CodingAgent::class,
        Mockery::mock(CodingAgent::class, function (MockInterface $mock): void {
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
                    Mockery::type(AiRun::class),
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
                    Mockery::type(AiRun::class),
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

function createApprovedAutomationTask(string $repositoryPath, string $title, ?User $assignee = null): Task
{
    $project = Project::create([
        'name' => $title,
        'workspace_path' => $repositoryPath,
    ]);

    return Task::create([
        'title' => $title,
        'description' => 'Run the approved automation flow.',
        'acceptance_criteria' => [
            ['body' => 'Automation completes.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'assignee_user_id' => $assignee?->id,
        'project_id' => $project->id,
    ]);
}

function createAutomationRun(Task $task, string $repositoryPath, string $branchName): AiRun
{
    return AiRun::create([
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'status' => AiRun::STATUS_QUEUED,
        'branch_name' => $branchName,
        'repository_path' => $repositoryPath,
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
