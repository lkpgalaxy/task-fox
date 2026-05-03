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

uses(RefreshDatabase::class);

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
        ->and(file_get_contents($argsPath))->toContain('- Prompt includes the stored criterion.');
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
    User::factory()->create();
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
        ->approved_by_user_id->not->toBeNull()
        ->approved_at->not->toBeNull();
});
