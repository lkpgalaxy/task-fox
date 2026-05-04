<?php

use App\Models\AiRun;
use App\Models\InputSource;
use App\Models\Project;
use App\Models\Task;
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
        ->and($task->acceptance_criteria)->toBe([
            ['body' => 'Criteria are saved in tasks.acceptance_criteria.', 'checked' => false],
        ]);
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

    mkdir($workspacePath);
    mkdir($binPath);
    runTaskAcceptanceProcess(['git', 'init', '-b', 'main'], $workspacePath);
    runTaskAcceptanceProcess(['git', 'config', 'user.email', 'tests@example.com'], $workspacePath);
    runTaskAcceptanceProcess(['git', 'config', 'user.name', 'Task Fox Tests'], $workspacePath);
    file_put_contents($workspacePath.'/README.md', "Initial content\n");
    runTaskAcceptanceProcess(['git', 'add', 'README.md'], $workspacePath);
    runTaskAcceptanceProcess(['git', 'commit', '-m', 'Initial commit'], $workspacePath);
    runTaskAcceptanceProcess(['git', 'checkout', '-b', 'task/create-pr'], $workspacePath);
    file_put_contents($workspacePath.'/feature.txt', "Generated change\n");

    file_put_contents(
        $binPath.'/gh',
        "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\nprintf '%s\n' 'https://github.com/example/repo/pull/456'\n"
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
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_CREATING_PR,
        'branch_name' => 'task/create-pr',
        'repository_path' => $workspacePath,
        'workspace_path' => $workspacePath,
    ]);

    $originalPath = getenv('PATH');
    putenv('PATH='.$binPath.PATH_SEPARATOR.$originalPath);

    try {
        $result = (new GithubPullRequestProvider)->createPullRequest($task, $run);
    } finally {
        putenv('PATH='.$originalPath);
    }

    $args = file($argsPath, FILE_IGNORE_NEW_LINES);
    $commit = trim(runTaskAcceptanceProcess(['git', 'log', '-1', '--pretty=%s'], $workspacePath));

    expect($result)
        ->url->toBe('https://github.com/example/repo/pull/456')
        ->number->toBe(456)
        ->and($commit)->toBe('feat: complete task '.$task->id.' create-pr')
        ->and($args)->toContain('pr')
        ->and($args)->toContain('create')
        ->and($args)->not->toContain('--json');
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
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/test',
        'repository_path' => base_path(),
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
    $task = Task::create([
        'title' => 'Implement workflow',
        'description' => 'Use acceptance criteria as the implementation contract.',
        'acceptance_criteria' => [
            ['body' => 'List criteria before implementation.', 'checked' => false],
            ['body' => 'Report verification proof for each criterion.', 'checked' => false],
        ],
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $reflection = new ReflectionClass(CodexCodingAgent::class);
    $method = $reflection->getMethod('buildTaskPrompt');
    $prompt = $method->invoke(new CodexCodingAgent, $task);

    expect($prompt)
        ->toContain('Acceptance-criteria-driven workflow:')
        ->toContain('1. List criteria before implementation.')
        ->toContain('2. Report verification proof for each criterion.')
        ->toContain('extract and list every acceptance criterion')
        ->toContain('verification checklist with one expected proof per item')
        ->toContain('Inspect the relevant Laravel/Inertia code, existing tests, DESIGN.md for UI work, and version-specific docs')
        ->toContain('pause and ask for clarification before implementation')
        ->toContain('Pest feature/unit tests so each acceptance criterion has direct coverage')
        ->toContain('run TypeScript/lint checks for React/Inertia changes')
        ->toContain('vendor/bin/pint --dirty --format agent')
        ->toContain('php artisan test --compact')
        ->toContain('Fix failing tests instead of ignoring them')
        ->toContain('explicitly mark every acceptance criterion as satisfied')
        ->toContain('Final response must include the acceptance-criteria checklist, tests run, and whether they passed');
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
    $prompt = $method->invoke(new CodexCodingAgent, $task);

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
    $run = AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/unclear',
        'repository_path' => base_path(),
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

test('task index loads latest ai run without ambiguous columns', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Review implementation',
        'description' => 'Confirm the latest run can be displayed.',
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    AiRun::create([
        'task_id' => $task->id,
        'status' => AiRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/review-implementation',
        'repository_path' => base_path(),
    ]);

    $this->get(route('tasks.index'))->assertOk();
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
