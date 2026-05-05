<?php

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\User;
use App\Services\CodingAgents\CodexCodingAgent;
use App\Services\PullRequests\GithubPullRequestProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('tasks table no longer has an acceptance criteria column', function () {
    expect(Schema::hasColumn('tasks', 'acceptance_criteria'))->toBeFalse();
});

test('manual task creation does not validate or store acceptance criteria', function () {
    $response = $this->post(route('tasks.store'), [
        'title' => 'Create task without criteria',
        'description' => 'Manual tasks only require the implementation description.',
        'priority' => Task::PRIORITY_HIGH,
        'deadline' => '2026-05-10',
        'assignee_user_id' => null,
        'reviewer_user_id' => null,
        'source_input_id' => null,
        'project_id' => null,
        'acceptance_criteria' => [
            ['body' => 'This payload is ignored.', 'checked' => false],
        ],
    ]);

    $response->assertRedirect(route('tasks.index'));

    expect(Task::query()->sole())
        ->title->toBe('Create task without criteria')
        ->status->toBe(Task::STATUS_DRAFT);
});

test('task updates no longer include criteria in approval change detection', function () {
    $approver = User::factory()->create();
    $reviewer = User::factory()->create(['github_username' => 'reviewer-login']);
    $task = Task::create([
        'title' => 'Approved task',
        'description' => 'Reviewer changes should not require reapproval.',
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
            ['body' => 'This ignored payload does not reset approval.', 'checked' => false],
        ],
    ])->assertRedirect(route('tasks.index', ['task' => $task->id]));

    expect($task->refresh())
        ->status->toBe(Task::STATUS_APPROVED)
        ->reviewer_user_id->toBe($reviewer->id)
        ->approved_by_user_id->toBe($approver->id)
        ->approved_at->not->toBeNull();
});

test('pull request body does not render an acceptance criteria checklist', function () {
    $task = Task::create([
        'title' => 'Prepare PR',
        'description' => 'Open a pull request without a criteria section.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);

    $reflection = new ReflectionClass(GithubPullRequestProvider::class);
    $method = $reflection->getMethod('buildPrBody');
    $body = $method->invoke(new GithubPullRequestProvider, $task);

    expect($body)
        ->toContain('Description:')
        ->toContain('Open a pull request without a criteria section.')
        ->not->toContain('Acceptance criteria:')
        ->not->toContain('- [');
});

test('codex agent implementation prompt does not require acceptance criteria', function () {
    $task = Task::create([
        'title' => 'Implement feature',
        'description' => 'Use the task description, plan, tests, screenshot, and review as the contract.',
        'status' => Task::STATUS_APPROVED,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_IMPLEMENTING,
        'branch_name' => 'task/test',
        'plan' => 'Implement from the stored plan.',
    ]);
    $binPath = sys_get_temp_dir().'/task-fox-codex-no-criteria-'.uniqid();
    $argsPath = $binPath.'/args.txt';

    mkdir($binPath);
    file_put_contents($binPath.'/codex', "#!/bin/sh\nprintf '%s\n' \"$@\" > ".escapeshellarg($argsPath)."\n");
    chmod($binPath.'/codex', 0755);

    $agent = new CodexCodingAgent([
        'PATH' => $binPath.PATH_SEPARATOR.getenv('PATH'),
    ]);

    $result = $agent->run($task, $run);
    $prompt = file_get_contents($argsPath);

    expect($result->successful)->toBeTrue()
        ->and($prompt)->toContain('Stored implementation plan:')
        ->and($prompt)->toContain('tests, screenshot verification, and code review as the implementation contract')
        ->and($prompt)->not->toContain('Acceptance criteria:')
        ->and($prompt)->not->toContain('Acceptance-criteria-driven workflow')
        ->and($prompt)->not->toContain('Acceptance criteria are required');
});
