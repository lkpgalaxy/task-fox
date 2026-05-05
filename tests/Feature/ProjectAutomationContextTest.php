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

    Queue::assertPushed(
        DispatchNextAiRunJob::class,
        fn (DispatchNextAiRunJob $job): bool => $job->taskId === $task->id,
    );
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

test('pull request review falls back to the task assignee when no default reviewer is configured', function () {
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
        'description' => 'Assignee should be requested.',
        'acceptance_criteria' => [
            ['body' => 'Assignee receives the review request.', 'checked' => false],
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

    bindSuccessfulRunMocks($assignee, $assignee);

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($task->refresh()->status)->toBe(Task::STATUS_PR_CREATED);
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

test('RunApprovedTaskWithCodingAgentJob continues to commit message generation after three failed reviews', function () {
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
                ->once()
                ->andReturn(new CodingAgentResult(successful: true, payload: ['message' => 'test: continue after review failures']));
        })
    );
    bindSuccessfulAuxiliaryMocks();

    app()->call([new RunApprovedTaskWithCodingAgentJob($run->id), 'handle']);

    expect($run->refresh())
        ->review_attempt_count->toBe(3)
        ->status->toBe(AiRun::STATUS_WAITING_FOR_MERGE)
        ->and($run->logs()->where('message', 'Coding agent review failed after retry limit; continuing to commit message generation')->exists())
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

    expect($commit)->toBe('feat: use generated subject')
        ->and($author)->toBe('agent-login <agent@example.com>');
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

    mkdir($repositoryPath);
    runSuccessfulProcess(['git', 'init', '-b', 'main'], $repositoryPath);
    runSuccessfulProcess(['git', 'config', 'user.email', 'tests@example.com'], $repositoryPath);
    runSuccessfulProcess(['git', 'config', 'user.name', 'Task Fox Tests'], $repositoryPath);
    file_put_contents($repositoryPath.'/README.md', "Review test\n");
    runSuccessfulProcess(['git', 'add', 'README.md'], $repositoryPath);
    runSuccessfulProcess(['git', 'commit', '-m', 'Initial commit'], $repositoryPath);

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
