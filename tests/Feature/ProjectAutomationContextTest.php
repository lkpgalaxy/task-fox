<?php

use App\Jobs\DispatchNextAiRunJob;
use App\Jobs\RunApprovedTaskWithCodingAgentJob;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\CodingAgents\CodexCodingAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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
