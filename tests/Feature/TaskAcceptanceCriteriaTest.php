<?php

use App\Models\InputSource;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunLog;
use App\Models\User;
use App\Services\CodingAgents\CodexCodingAgent;
use App\Services\PullRequests\GithubPullRequestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('manual task creation stores acceptance criteria on the task', function () {
    $assignee = User::factory()->create(['github_username' => 'linh']);
    $source = InputSource::create([
        'title' => 'Planning notes',
        'body' => 'Build task acceptance criteria storage.',
        'analysis_status' => 'completed',
    ]);

    $response = $this->post(route('tasks.store'), [
        'title' => 'Store acceptance criteria',
        'description' => 'Persist criteria directly with the task.',
        'priority' => Task::PRIORITY_HIGH,
        'deadline' => '2026-05-10',
        'assignee_user_id' => $assignee->id,
        'source_input_id' => $source->id,
        'acceptance_criteria' => [
            ['body' => 'Criteria are saved in tasks.acceptance_criteria.', 'checked' => false],
        ],
    ]);

    $response->assertRedirect(route('tasks.index'));

    $task = Task::query()->sole();

    expect(Schema::hasTable('acceptance_criterias'))->toBeFalse()
        ->and($task->status)->toBe(Task::STATUS_DRAFT)
        ->and($task->acceptance_criteria)->toBe([
            ['body' => 'Criteria are saved in tasks.acceptance_criteria.', 'checked' => false],
        ]);
});

test('manual task creation does not require a source input', function () {
    $response = $this->post(route('tasks.store'), [
        'title' => 'Create task without source',
        'description' => 'Manual tasks do not need to come from an input source.',
        'priority' => Task::PRIORITY_MEDIUM,
        'deadline' => null,
        'assignee_user_id' => null,
        'acceptance_criteria' => [
            ['body' => 'The task is created without source metadata.', 'checked' => false],
        ],
    ]);

    $response->assertRedirect(route('tasks.index'));

    expect(Task::query()->sole())
        ->source_input_id->toBeNull()
        ->title->toBe('Create task without source');
});

test('draft task can be submitted for approval', function () {
    $approver = User::factory()->create();
    $task = Task::create([
        'title' => 'Ready for review',
        'description' => 'Submit this draft for approval.',
        'acceptance_criteria' => [
            ['body' => 'The draft moves to pending approval.', 'checked' => false],
        ],
        'status' => Task::STATUS_DRAFT,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => $approver->id,
        'approved_at' => now(),
        'rejected_at' => now(),
    ]);

    $response = $this->post(route('tasks.submit-for-approval', $task));

    $response
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHas('status', 'Task submitted for approval.');

    expect($task->refresh())
        ->status->toBe(Task::STATUS_PENDING_APPROVAL)
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->rejected_at->toBeNull();
});

test('non draft task cannot be submitted for approval', function () {
    $task = Task::create([
        'title' => 'Already waiting',
        'description' => 'This task is already pending approval.',
        'acceptance_criteria' => [
            ['body' => 'The task status remains unchanged.', 'checked' => false],
        ],
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $response = $this->post(route('tasks.submit-for-approval', $task));

    $response
        ->assertRedirect(route('tasks.index', ['task' => $task->id]))
        ->assertSessionHasErrors('status');

    expect($task->refresh()->status)->toBe(Task::STATUS_PENDING_APPROVAL);
});

test('updating acceptance criteria stores the new criteria on the task', function () {
    $task = Task::create([
        'title' => 'Original title',
        'description' => 'Original description.',
        'acceptance_criteria' => [
            ['body' => 'Original criterion.', 'checked' => false],
        ],
        'status' => Task::STATUS_DRAFT,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $response = $this->patch(route('tasks.update', $task), [
        'title' => 'Original title',
        'description' => 'Original description.',
        'priority' => Task::PRIORITY_MEDIUM,
        'deadline' => null,
        'assignee_user_id' => null,
        'source_input_id' => null,
        'acceptance_criteria' => [
            ['body' => 'Updated criterion.', 'checked' => true],
        ],
    ]);

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));

    expect($task->refresh()->acceptance_criteria)->toBe([
        ['body' => 'Updated criterion.', 'checked' => true],
    ]);
});

test('task creation defaults reviewer from selected project default reviewer', function () {
    $reviewer = User::factory()->create(['github_username' => 'reviewer-login']);
    $project = Project::create([
        'name' => 'Reviewed Project',
        'workspace_path' => '/tmp/reviewed-project',
        'default_reviewer_user_id' => $reviewer->id,
    ]);

    $this->post(route('tasks.store'), [
        'title' => 'Default reviewer task',
        'description' => 'Task should inherit the project reviewer.',
        'priority' => Task::PRIORITY_MEDIUM,
        'deadline' => null,
        'assignee_user_id' => null,
        'reviewer_user_id' => null,
        'source_input_id' => null,
        'project_id' => $project->id,
        'acceptance_criteria' => [
            ['body' => 'Reviewer is set from the project.', 'checked' => false],
        ],
    ])->assertRedirect(route('tasks.index'));

    expect(Task::query()->sole()->reviewer_user_id)->toBe($reviewer->id);
});

test('task reviewer can be updated without resetting approval', function () {
    $approver = User::factory()->create();
    $reviewer = User::factory()->create(['github_username' => 'reviewer-login']);
    $task = Task::create([
        'title' => 'Approved task',
        'description' => 'Reviewer changes should not require reapproval.',
        'acceptance_criteria' => [
            ['body' => 'Reviewer can be assigned independently.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => $approver->id,
        'approved_at' => now(),
    ]);

    $this->patch(route('tasks.update', $task), [
        'title' => 'Approved task',
        'description' => 'Reviewer changes should not require reapproval.',
        'priority' => Task::PRIORITY_MEDIUM,
        'deadline' => null,
        'assignee_user_id' => null,
        'reviewer_user_id' => $reviewer->id,
        'source_input_id' => null,
        'project_id' => null,
        'acceptance_criteria' => [
            ['body' => 'Reviewer can be assigned independently.', 'checked' => false],
        ],
    ])->assertRedirect(route('tasks.index', ['task' => $task->id]));

    expect($task->refresh())
        ->status->toBe(Task::STATUS_APPROVED)
        ->reviewer_user_id->toBe($reviewer->id)
        ->approved_by_user_id->toBe($approver->id)
        ->approved_at->not->toBeNull();
});

test('editing acceptance criteria on an approved task resets approval', function () {
    $approver = User::factory()->create();
    $task = Task::create([
        'title' => 'Approved task',
        'description' => 'Already approved.',
        'acceptance_criteria' => [
            ['body' => 'Existing criterion.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'approved_by_user_id' => $approver->id,
        'approved_at' => now(),
    ]);

    $response = $this->patch(route('tasks.update', $task), [
        'title' => 'Approved task',
        'description' => 'Already approved.',
        'priority' => Task::PRIORITY_MEDIUM,
        'deadline' => null,
        'assignee_user_id' => null,
        'source_input_id' => null,
        'acceptance_criteria' => [
            ['body' => 'Changed criterion.', 'checked' => false],
        ],
    ]);

    $response->assertRedirect(route('tasks.index', ['task' => $task->id]));

    $task->refresh();

    expect($task)
        ->status->toBe(Task::STATUS_PENDING_APPROVAL)
        ->approved_by_user_id->toBeNull()
        ->approved_at->toBeNull()
        ->and($task->acceptance_criteria)->toBe([
            ['body' => 'Changed criterion.', 'checked' => false],
        ]);
});

test('pull request body renders acceptance criteria from the task json column', function () {
    $task = Task::create([
        'title' => 'Prepare PR',
        'description' => 'Open a pull request with criteria.',
        'acceptance_criteria' => [
            ['body' => 'PR body includes the stored criterion.', 'checked' => true],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $reflection = new ReflectionClass(GithubPullRequestProvider::class);
    $method = $reflection->getMethod('buildPrBody');
    $body = $method->invoke(new GithubPullRequestProvider, $task);

    expect($body)->toContain('- [x] PR body includes the stored criterion.');
});

test('github pull request creation parses gh create url output', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-gh-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-gh-bin-'.uniqid();
    $argsPath = $binPath.'/args.txt';
    $envPath = $binPath.'/env.txt';
    $author = User::factory()->create([
        'email' => 'commit-author@example.com',
        'github_username' => 'commit-author',
        'github_token' => 'ghp_author_token',
    ]);

    mkdir($workspacePath);
    mkdir($binPath);
    runTaskAcceptanceProcess(['git', 'init', '-b', 'main'], $workspacePath);
    file_put_contents($workspacePath.'/README.md', "Initial content\n");
    runTaskAcceptanceProcess(['git', 'add', 'README.md'], $workspacePath);
    runTaskAcceptanceProcess([
        'git',
        '-c',
        'user.email=bootstrap@example.com',
        '-c',
        'user.name=Bootstrap Author',
        'commit',
        '-m',
        'Initial commit',
    ], $workspacePath);
    runTaskAcceptanceProcess(['git', 'checkout', '-b', 'task/create-pr'], $workspacePath);
    file_put_contents($workspacePath.'/feature.txt', "Generated change\n");
    runTaskAcceptanceProcess(['git', 'add', 'feature.txt'], $workspacePath);
    runTaskAcceptanceProcess([
        'git',
        '-c',
        'user.email=bootstrap@example.com',
        '-c',
        'user.name=Bootstrap Author',
        'commit',
        '-m',
        'Prepared change',
    ], $workspacePath);

    file_put_contents(
        $binPath.'/gh',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s\n' \"\${GH_TOKEN-unset}\" > ".escapeshellarg($envPath)."\nprintf '%s\n' 'https://github.com/example/repo/pull/456'\n"
    );
    chmod($binPath.'/gh', 0755);

    $task = Task::create([
        'title' => 'Create PR',
        'description' => 'Open the pull request.',
        'acceptance_criteria' => [
            ['body' => 'PR can be created.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_CREATING_PR,
        'branch_name' => 'task/create-pr',
        'workspace_path' => $workspacePath,
    ]);

    $originalPath = getenv('PATH');
    $originalGlobalConfig = getenv('GIT_CONFIG_GLOBAL');
    putenv('PATH='.$binPath.PATH_SEPARATOR.$originalPath);
    putenv('GIT_CONFIG_GLOBAL=/dev/null');

    try {
        $result = (new GithubPullRequestProvider)->createPullRequest($task, $run, $author);
    } finally {
        putenv('PATH='.$originalPath);
        putenv($originalGlobalConfig === false ? 'GIT_CONFIG_GLOBAL' : 'GIT_CONFIG_GLOBAL='.$originalGlobalConfig);
    }

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);
    $ghToken = trim((string) file_get_contents($envPath));
    $commit = trim(runTaskAcceptanceProcess(['git', 'log', '-1', '--pretty=%s'], $workspacePath));
    $commitAuthor = trim(runTaskAcceptanceProcess(['git', 'log', '-1', '--pretty=%an <%ae>'], $workspacePath));

    expect($result)
        ->url->toBe('https://github.com/example/repo/pull/456')
        ->number->toBe(456)
        ->and($ghToken)->toBe('ghp_author_token')
        ->and($commit)->toBe('Prepared change')
        ->and($commitAuthor)->toBe('Bootstrap Author <bootstrap@example.com>')
        ->and($args)->toContain('pr')
        ->and($args)->toContain('create')
        ->and($args)->not->toContain('--json');
});

test('github pull request creation falls back to inherited gh auth without a token source', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-gh-fallback-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-gh-fallback-bin-'.uniqid();
    $envPath = $binPath.'/env.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    runTaskAcceptanceProcess(['git', 'init', '-b', 'main'], $workspacePath);
    file_put_contents($workspacePath.'/README.md', "Initial content\n");
    runTaskAcceptanceProcess(['git', 'add', 'README.md'], $workspacePath);
    runTaskAcceptanceProcess([
        'git',
        '-c',
        'user.email=bootstrap@example.com',
        '-c',
        'user.name=Bootstrap Author',
        'commit',
        '-m',
        'Initial commit',
    ], $workspacePath);
    runTaskAcceptanceProcess(['git', 'checkout', '-b', 'task/create-pr'], $workspacePath);

    file_put_contents(
        $binPath.'/gh',
        "#!/bin/sh\nprintf '%s\n' \"\${GH_TOKEN-unset}\" > ".escapeshellarg($envPath)."\nprintf '%s\n' 'https://github.com/example/repo/pull/789'\n"
    );
    chmod($binPath.'/gh', 0755);

    $task = Task::create([
        'title' => 'Create PR without token',
        'description' => 'Open the pull request.',
        'acceptance_criteria' => [
            ['body' => 'PR can be created with process auth.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_CREATING_PR,
        'branch_name' => 'task/create-pr',
        'workspace_path' => $workspacePath,
    ]);

    $originalPath = getenv('PATH');
    $originalToken = getenv('GH_TOKEN');
    putenv('PATH='.$binPath.PATH_SEPARATOR.$originalPath);
    putenv('GH_TOKEN');

    try {
        $result = (new GithubPullRequestProvider)->createPullRequest($task, $run);
    } finally {
        putenv('PATH='.$originalPath);
        putenv($originalToken === false ? 'GH_TOKEN' : 'GH_TOKEN='.$originalToken);
    }

    expect($result)
        ->url->toBe('https://github.com/example/repo/pull/789')
        ->number->toBe(789)
        ->and(trim((string) file_get_contents($envPath)))->toBe('unset');
});

test('github review request uses rest api instead of pr edit', function () {
    $binPath = sys_get_temp_dir().'/task-fox-gh-review-bin-'.uniqid();
    $argsPath = $binPath.'/args.txt';
    $envPath = $binPath.'/env.txt';
    $actor = User::factory()->create([
        'github_username' => 'author-login',
        'github_token' => 'ghp_author_token',
    ]);
    $reviewer = User::factory()->create(['github_username' => 'reviewer-login']);

    mkdir($binPath);
    file_put_contents(
        $binPath.'/gh',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s\n' \"\${GH_TOKEN-unset}\" > ".escapeshellarg($envPath)."\n"
    );
    chmod($binPath.'/gh', 0755);

    $originalPath = getenv('PATH');
    putenv('PATH='.$binPath.PATH_SEPARATOR.$originalPath);

    try {
        (new GithubPullRequestProvider)->requestReview(
            'https://github.com/example/repo/pull/456',
            $reviewer,
            $actor,
        );
    } finally {
        putenv('PATH='.$originalPath);
    }

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($args)
        ->toBe([
            'api',
            '--method',
            'POST',
            'repos/example/repo/pulls/456/requested_reviewers',
            '-f',
            'reviewers[]=reviewer-login',
        ])
        ->and(trim((string) file_get_contents($envPath)))->toBe('ghp_author_token');
});

test('codex agent prompt renders acceptance criteria from the task json column', function () {
    $task = Task::create([
        'title' => 'Implement feature',
        'description' => 'Use the stored criteria.',
        'acceptance_criteria' => [
            ['body' => 'Prompt includes the stored criterion.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/test',
        'plan' => 'Use the stored acceptance criterion.',
    ]);
    $binPath = sys_get_temp_dir().'/task-fox-codex-'.uniqid();
    $argsPath = $binPath.'/args.txt';

    mkdir($binPath);
    file_put_contents($binPath.'/codex', "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\n");
    chmod($binPath.'/codex', 0755);

    $agent = new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]);

    $result = $agent->run($task, $run);

    expect($result->successful)->toBeTrue()
        ->and(file_get_contents($argsPath))->toContain('Prompt includes the stored criterion.');
});

test('codex agent prompt enforces acceptance criteria driven implementation workflow', function () {
    $project = Project::create([
        'name' => 'Workflow App',
        'workspace_path' => '/tmp/workflow-app',
        'url' => 'https://workflow.test',
    ]);
    $task = Task::create([
        'title' => 'Implement workflow',
        'description' => 'Use acceptance criteria as the implementation contract.',
        'acceptance_criteria' => [
            ['body' => 'List criteria before implementation.', 'checked' => false],
            ['body' => 'Report verification proof for each criterion.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    $reflection = new ReflectionClass(CodexCodingAgent::class);
    $method = $reflection->getMethod('buildTaskPrompt');
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/workflow',
        'plan' => 'Inspect the workflow and update the implementation.',
    ]);
    $prompt = $method->invoke(new CodexCodingAgent, $task, $run);

    expect($prompt)
        ->toContain('Acceptance-criteria-driven workflow:')
        ->toContain('Stored implementation plan:')
        ->toContain('Inspect the workflow and update the implementation.')
        ->toContain('Project URL for frontend screenshots:')
        ->toContain('https://workflow.test')
        ->toContain('Follow the stored implementation plan above as the implementation contract for this run.')
        ->toContain('Pause and fail only if the stored plan is impossible to execute or contradicts the current task description or acceptance criteria.')
        ->toContain('1. [ ] List criteria before implementation.')
        ->toContain('2. [ ] Report verification proof for each criterion.')
        ->toContain('extract and list every acceptance criterion')
        ->toContain('Treat [x] criteria as already verified and [ ] criteria as the remaining contract to satisfy')
        ->toContain('Inspect the relevant Laravel/Inertia code, existing tests, DESIGN.md for UI work, and version-specific docs')
        ->toContain('pause and ask for clarification before implementation')
        ->toContain('Pest feature/unit tests so each acceptance criterion has direct coverage')
        ->toContain('run TypeScript/lint checks for React/Inertia changes')
        ->toContain('with Playwright, and capture a screenshot of the implemented result')
        ->toContain('open the project URL above or the relevant page under it with Playwright')
        ->toContain(storage_path("app/task-runs/{$run->id}/screenshots/implementation.png"))
        ->toContain('Create the screenshot directory if it does not exist')
        ->toContain('include the screenshot path in your final response when a screenshot was captured')
        ->toContain('Do not start the application or dev server for screenshots; use the configured project URL')
        ->toContain('vendor/bin/pint --dirty --format agent')
        ->toContain('php artisan test --compact')
        ->toContain('Fix failing tests instead of ignoring them')
        ->toContain('explicitly mark every verified criterion as [x]')
        ->toContain('Final response must include the acceptance-criteria checklist, tests run, and whether they passed');
});

test('codex review command uses native review mode and accepts passing text output', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-review-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-review-codex-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';
    $outputPath = $workspacePath.'/last-message.json';

    mkdir($workspacePath);
    mkdir($binPath);
    createCodexReviewStub(
        $binPath,
        $argsPath,
        'No findings.',
    );

    $task = Task::create([
        'title' => 'Review command',
        'description' => 'Review should use codex exec review.',
        'acceptance_criteria' => [
            ['body' => 'Review mode is used.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'review_model' => 'gpt-5.5',
        'review_reasoning_effort' => 'high',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/review-command',
        'workspace_path' => $workspacePath,
        'base_branch' => 'staging',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->reviewChanges($task, $run, 3);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($result->payload['review_text'])->toBe('No findings.')
        ->and($args)->toContain('exec')
        ->and($args)->toContain('--model')
        ->and($args[array_search('--model', $args, true) + 1])->toBe('gpt-5.5')
        ->and($args)->toContain('-c')
        ->and($args[array_search('-c', $args, true) + 1])->toBe('model_reasoning_effort="high"')
        ->and($args)->toContain('-C')
        ->and($args[array_search('-C', $args, true) + 1])->toBe($workspacePath)
        ->and($args)->toContain('review')
        ->and($args)->toContain('--base')
        ->and($args[array_search('--base', $args, true) + 1])->toBe('staging')
        ->and($args)->not->toContain('--uncommitted')
        ->and($args)->toContain('--title')
        ->and($args[array_search('--title', $args, true) + 1])->toBe('Task '.$task->id.': Review command')
        ->and($args)->toContain('--output-last-message')
        ->and($args[array_search('--output-last-message', $args, true) + 1])->toStartWith(sys_get_temp_dir());
});

test('codex review command omits the model flag when no review model is configured', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-review-no-model-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-review-no-model-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    createCodexReviewStub(
        $binPath,
        $argsPath,
        'No actionable findings.',
    );

    $task = Task::create([
        'title' => 'Review command without model',
        'description' => 'Review should use Codex defaults when no model is configured.',
        'acceptance_criteria' => [
            ['body' => 'Review mode is used.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'review_model' => null,
        'review_reasoning_effort' => null,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/review-no-model',
        'workspace_path' => $workspacePath,
        'base_branch' => 'develop',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->reviewChanges($task, $run, 1);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($args)->not->toContain('--model')
        ->and($args)->not->toContain('-c');
});

test('codex review command surfaces findings as a failed review result', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-review-findings-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-review-findings-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    createCodexReviewStub(
        $binPath,
        $argsPath,
        "The tracked diff is missing test coverage.\n\nReview comment:\n\n- [P2] Missing test -- /tmp/example.php:1-3\n  Add a test for the new behavior.",
    );

    $task = Task::create([
        'title' => 'Review findings',
        'description' => 'Review should surface findings.',
        'acceptance_criteria' => [
            ['body' => 'Findings are returned.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/review-findings',
        'workspace_path' => $workspacePath,
        'base_branch' => 'develop',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->reviewChanges($task, $run, 1);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toContain('[P2] Missing test')
        ->and($result->payload['review_text'])->toContain('Add a test for the new behavior.');
});

test('codex review command fails closed on empty text output', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-review-invalid-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-review-invalid-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    createCodexReviewStub($binPath, $argsPath, '');

    $task = Task::create([
        'title' => 'Review empty output',
        'description' => 'Empty output should fail closed.',
        'acceptance_criteria' => [
            ['body' => 'Empty review output is rejected.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/review-empty',
        'workspace_path' => $workspacePath,
        'base_branch' => 'develop',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->reviewChanges($task, $run, 1);

    expect($result->successful)->toBeFalse()
        ->and($result->error)->toBe('Coding agent review returned no output.')
        ->and($result->payload['review_text'])->toBe('');
});

test('codex review fix prompt includes the review feedback and fix instructions', function () {
    $task = Task::create([
        'title' => 'Fix review findings',
        'description' => 'Use review feedback to make the smallest correct change.',
        'acceptance_criteria' => [
            ['body' => 'The fix prompt includes the stored plan.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_REVIEWING_CHANGES,
        'branch_name' => 'task/fix-review-findings',
        'base_branch' => 'develop',
        'plan' => 'Keep the change minimal.',
    ]);

    $reflection = new ReflectionClass(CodexCodingAgent::class);
    $method = $reflection->getMethod('buildFixReviewPrompt');
    $prompt = $method->invoke(
        new CodexCodingAgent,
        $task,
        $run,
        "Error: Needs fixes.\n\nReview text:\n- [P1] Include missing views.",
        2,
    );

    expect($prompt)
        ->toContain('Fix the review findings for task '.$task->id.': Fix review findings')
        ->toContain('Use review feedback to make the smallest correct change.')
        ->toContain('Acceptance criteria:')
        ->toContain('The fix prompt includes the stored plan.')
        ->toContain('Stored implementation plan:')
        ->toContain('Keep the change minimal.')
        ->toContain('Base branch: develop')
        ->toContain('Review attempt: 2')
        ->toContain('Review feedback:')
        ->toContain('Needs fixes.')
        ->toContain('Include missing views.')
        ->toContain('Make the smallest correct fix that resolves the review feedback.')
        ->toContain('Do not commit, push, or create a pull request.')
        ->toContain('leave a final checklist of the addressed findings in your response');
});

test('codex commit message command uses the configured model', function () {
    $workspacePath = sys_get_temp_dir().'/task-fox-commit-workspace-'.uniqid();
    $binPath = sys_get_temp_dir().'/task-fox-commit-codex-bin-'.uniqid();
    $argsPath = $workspacePath.'/args.txt';

    mkdir($workspacePath);
    mkdir($binPath);
    file_put_contents(
        $binPath.'/codex',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf 'feat: generated subject\n'\n"
    );
    chmod($binPath.'/codex', 0755);

    $task = Task::create([
        'title' => 'Generate commit subject',
        'description' => 'Commit message generation should honor the configured model.',
        'acceptance_criteria' => [
            ['body' => 'Commit message generation uses Codex.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    SystemSetting::factory()->create([
        'commit_message_model' => 'gpt-5.4-mini',
        'commit_message_reasoning_effort' => 'medium',
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_GENERATING_COMMIT_MESSAGE,
        'branch_name' => 'task/commit-message',
        'workspace_path' => $workspacePath,
        'base_branch' => 'develop',
    ]);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->generateCommitMessage($task, $run);

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);

    expect($result->successful)->toBeTrue()
        ->and($result->payload['message'])->toBe('feat: generated subject')
        ->and($args)->toContain('--model')
        ->and($args[array_search('--model', $args, true) + 1])->toBe('gpt-5.4-mini')
        ->and($args)->toContain('-c')
        ->and($args[array_search('-c', $args, true) + 1])->toBe('model_reasoning_effort="medium"');
});

test('codex agent prompt pauses when acceptance criteria are absent', function () {
    $task = Task::create([
        'title' => 'Implement unclear task',
        'description' => 'This task needs explicit acceptance criteria first.',
        'acceptance_criteria' => [],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $reflection = new ReflectionClass(CodexCodingAgent::class);
    $method = $reflection->getMethod('buildTaskPrompt');
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/unclear',
    ]);
    $prompt = $method->invoke(new CodexCodingAgent, $task, $run);

    expect($prompt)
        ->toContain('- No acceptance criteria were provided.')
        ->toContain('If any criterion is missing, unclear, or not testable, pause and ask for clarification before implementation.');
});

test('codex agent refuses to implement tasks without acceptance criteria', function () {
    $task = Task::create([
        'title' => 'Implement unclear task',
        'description' => 'This task needs explicit acceptance criteria first.',
        'acceptance_criteria' => [],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/unclear',
    ]);
    $binPath = sys_get_temp_dir().'/task-fox-codex-unused-'.uniqid();
    $argsPath = $binPath.'/args.txt';

    mkdir($binPath);
    file_put_contents($binPath.'/codex', "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\n");
    chmod($binPath.'/codex', 0755);

    $result = (new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]))->run($task, $run);

    expect($result)
        ->successful->toBeFalse()
        ->error->toBe('Acceptance criteria are required before implementation.')
        ->and(file_exists($argsPath))->toBeFalse();
});

test('task run request hash ignores acceptance criteria checked state', function () {
    $task = Task::create([
        'title' => 'Retry verified work',
        'description' => 'Checked state can change during verification.',
        'acceptance_criteria' => [
            ['body' => 'The same criterion body remains.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_FAILED,
        'branch_name' => 'task/retry-verified-work',
    ]);
    $run->initializeWorkflowState($task);

    $task->forceFill([
        'acceptance_criteria' => [
            ['body' => 'The same criterion body remains.', 'checked' => true],
        ],
    ])->save();

    expect($run->refresh()->hasMatchingRequestHash($task->refresh()))->toBeTrue();
});

test('task index loads latest task run without ambiguous columns', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Review implementation',
        'description' => 'Confirm the latest run can be displayed.',
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/review-implementation',
    ]);

    $this->get(route('tasks.index'))->assertOk();
});

test('task index reads pull request data from the latest pull request bearing task run', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Keep older PR visible',
        'description' => 'A newer run without a PR should not hide the latest PR run.',
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_WAITING_FOR_MERGE,
        'branch_name' => 'task/with-pr',
        'pull_request_url' => 'https://github.com/example/repo/pull/77',
        'pull_request_number' => 77,
    ]);

    TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/newer-without-pr',
    ]);

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Index')
            ->where('tasks.0.latest_task_run.branch_name', 'task/newer-without-pr')
            ->where('tasks.0.latest_task_run.pull_request_url', null)
            ->where('tasks.0.latest_pull_request_run.pull_request_url', 'https://github.com/example/repo/pull/77')
            ->where('tasks.0.latest_pull_request_run.pull_request_number', 77)
            ->missing('tasks.0.pull_request_url')
            ->missing('tasks.0.pull_request_number')
        );
});

test('automation schema keeps execution state off tasks and project ids off task runs', function () {
    expect(Schema::hasColumn('tasks', 'pull_request_url'))->toBeFalse()
        ->and(Schema::hasColumn('tasks', 'pull_request_number'))->toBeFalse()
        ->and(Schema::hasColumn('task_runs', 'project_id'))->toBeFalse()
        ->and(Schema::hasColumn('task_runs', 'test_cases'))->toBeFalse()
        ->and(Schema::hasColumn('task_run_logs', 'task_run_id'))->toBeTrue()
        ->and(Schema::hasColumn('task_run_logs', 'ai_run_id'))->toBeFalse();
});

test('task index only lists input sources created today', function () {
    $this->withoutVite();

    $todaySource = InputSource::create([
        'title' => 'Today source',
        'file_disk' => 'local',
        'file_path' => 'input-sources/today.txt',
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'analysis_status' => 'completed',
    ]);

    $olderSource = InputSource::create([
        'title' => 'Older source',
        'file_disk' => 'local',
        'file_path' => 'input-sources/older.txt',
        'mime_type' => 'text/plain',
        'file_size' => 100,
        'analysis_status' => 'completed',
    ]);
    $olderSource->forceFill([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ])->save();

    $this->get(route('tasks.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Index')
            ->has('sourceInputs', 1)
            ->where('sourceInputs.0.id', $todaySource->id)
            ->where('sourceInputs.0.title', 'Today source')
        );
});

test('task index can select a pending approval task without external messages', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Needs approval',
        'description' => 'Open the detail panel.',
        'acceptance_criteria' => [
            ['body' => 'The task details can be opened.', 'checked' => false],
        ],
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $this->get(route('tasks.index', ['task' => $task->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Index')
            ->where('selectedTask.id', $task->id)
            ->where('selectedTask.status', Task::STATUS_PENDING_APPROVAL)
            ->where('selectedTask.external_messages', [])
        );
});

test('task index partial reload refreshes only the selected task details', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Polling detail task',
        'description' => 'Refresh this task without reloading the board.',
        'acceptance_criteria' => [
            ['body' => 'Selected task data refreshes independently.', 'checked' => false],
        ],
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/polling-detail',
        'workflow_state' => [
            'request_hash' => 'initial-hash',
            'checkpoints' => [],
        ],
    ]);

    $response = $this->get(route('tasks.index', ['task' => $task->id]));

    TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'info',
        'message' => 'Polling refreshed this log',
        'context' => ['checkpoint' => 'planned'],
    ]);

    $run->update([
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'workflow_state' => [
            'request_hash' => 'updated-hash',
            'checkpoints' => [
                ['name' => 'planned', 'status' => 'completed'],
            ],
        ],
    ]);

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Index')
            ->has('tasks', 1)
            ->has('users')
            ->where('selectedTask.id', $task->id)
            ->reloadOnly('selectedTask', fn (Assert $reload) => $reload
                ->missing('tasks')
                ->missing('users')
                ->missing('sourceInputs')
                ->missing('projects')
                ->where('selectedTask.id', $task->id)
                ->where('selectedTask.task_runs.0.status', TaskRun::STATUS_IMPLEMENTING)
                ->where('selectedTask.task_runs.0.workflow_state.request_hash', 'updated-hash')
                ->where('selectedTask.task_runs.0.logs.0.message', 'Polling refreshed this log')
            )
        );
});

test('selected task includes source input preview metadata', function () {
    $this->withoutVite();

    $source = InputSource::create([
        'title' => 'Planning notes',
        'filename' => 'planning-notes.txt',
        'file_disk' => 'local',
        'file_path' => 'input-sources/planning-notes.txt',
        'mime_type' => 'text/plain',
        'file_size' => 128,
        'analysis_status' => 'completed',
    ]);
    $reviewer = User::factory()->create([
        'name' => 'Project Reviewer',
        'github_username' => 'project-reviewer',
    ]);
    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
        'database_name' => 'task_fox',
        'database_username' => 'task_fox_user',
        'database_password' => 'secret',
        'credential_username' => 'repo_user',
        'credential_password' => 'repo_secret',
        'base_branch' => 'develop',
        'default_reviewer_user_id' => $reviewer->id,
    ]);
    $task = Task::create([
        'title' => 'Build source links',
        'description' => 'Expose preview metadata to the task detail modal.',
        'acceptance_criteria' => [
            ['body' => 'The source preview link is available.', 'checked' => false],
        ],
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
        'source_input_id' => $source->id,
        'project_id' => $project->id,
    ]);

    $this->get(route('tasks.index', ['task' => $task->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Index')
            ->where('selectedTask.id', $task->id)
            ->where('selectedTask.source_input.id', $source->id)
            ->where('selectedTask.source_input.title', 'Planning notes')
            ->where('selectedTask.source_input.filename', 'planning-notes.txt')
            ->where('selectedTask.source_input.mime_type', 'text/plain')
            ->where('selectedTask.source_input.file_size', 128)
            ->where('selectedTask.source_input.has_file', true)
            ->where('selectedTask.project.id', $project->id)
            ->where('selectedTask.project.name', 'Task Fox')
            ->where('selectedTask.project.database_name', 'task_fox')
            ->where('selectedTask.project.database_username', 'task_fox_user')
            ->where('selectedTask.project.has_database_password', true)
            ->where('selectedTask.project.has_credential_username', true)
            ->where('selectedTask.project.has_credential_password', true)
            ->where('selectedTask.project.base_branch', 'develop')
            ->where('selectedTask.project.default_reviewer.id', $reviewer->id)
        );
});

test('pending approval task can be approved from the task board', function () {
    Queue::fake();
    $actor = auth()->user();
    $project = Project::create([
        'name' => 'Task Fox',
        'workspace_path' => '/tmp/task-fox',
        'url' => 'https://github.com/example/task-fox',
    ]);

    $task = Task::create([
        'title' => 'Approve me',
        'description' => 'This task should approve without a server error.',
        'acceptance_criteria' => [
            ['body' => 'The approval route redirects successfully.', 'checked' => false],
        ],
        'status' => Task::STATUS_PENDING_APPROVAL,
        'priority' => Task::PRIORITY_MEDIUM,
        'project_id' => $project->id,
    ]);

    $response = $this->post(route('tasks.approve', $task));

    $response->assertRedirect(route('tasks.index'));

    expect($task->refresh())
        ->status->toBe(Task::STATUS_APPROVED)
        ->approved_by_user_id->toBe($actor->id)
        ->approved_at->not->toBeNull();
});

function runTaskAcceptanceProcess(array $command, string $cwd): string
{
    $process = new Process($command, $cwd);
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException($process->getErrorOutput());
    }

    return (string) $process->getOutput();
}

function createCodexReviewStub(string $binPath, string $argsPath, string $outputContent): void
{
    $script = <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > '__ARGS_PATH__'
output_file=''
while [ $# -gt 0 ]; do
    if [ "$1" = "--output-last-message" ]; then
        shift
        output_file="$1"
        break
    fi
    shift
done
cat <<'__OUTPUT_MARKER__' > "$output_file"
__OUTPUT_CONTENT__
__OUTPUT_MARKER__
SH;

    $script = str_replace(
        ['__ARGS_PATH__', '__OUTPUT_CONTENT__'],
        [$argsPath, $outputContent],
        $script,
    );

    file_put_contents($binPath.'/codex', $script);
    chmod($binPath.'/codex', 0755);
}
