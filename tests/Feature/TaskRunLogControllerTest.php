<?php

use App\Models\InputSource;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('logs page includes task run logs on initial load', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Logged task',
        'description' => 'This task has logs.',
        'acceptance_criteria' => [
            ['body' => 'Logs render on the logs page.', 'checked' => false],
        ],
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/logged-task',
    ]);
    $source = InputSource::create([
        'title' => 'Source log',
        'analysis_status' => 'completed',
    ]);

    TaskRunLog::create([
        'task_run_id' => $run->id,
        'input_source_id' => $source->id,
        'level' => 'info',
        'message' => 'Initial log entry',
        'context' => ['phase' => 'planning'],
    ]);

    $this->get(route('logs.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Logs')
            ->has('logs', 1)
            ->where('logs.0.message', 'Initial log entry')
            ->where('logs.0.task_id', $task->id)
            ->where('logs.0.task_title', 'Logged task')
            ->where('logs.0.run_id', $run->id)
            ->where('logs.0.run_status', TaskRun::STATUS_PLANNING)
            ->where('logs.0.input_source_id', $source->id)
            ->where('logs.0.input_source_title', 'Source log')
        );
});

test('logs partial reload refreshes only log records', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Polling logs task',
        'description' => 'The logs page polls for fresh records.',
        'acceptance_criteria' => [
            ['body' => 'Fresh logs appear without unrelated props.', 'checked' => false],
        ],
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/polling-logs',
    ]);

    $olderLog = TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'info',
        'message' => 'Older log entry',
        'context' => ['phase' => 'planning'],
    ]);
    $olderLog->forceFill([
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ])->save();

    $response = $this->get(route('logs.index'));

    $freshLog = TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'info',
        'message' => 'Fresh polled log entry',
        'context' => ['phase' => 'implementing'],
    ]);
    $freshLog->forceFill([
        'created_at' => now()->addSecond(),
        'updated_at' => now()->addSecond(),
    ])->save();

    $response
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Logs')
            ->has('logs', 1)
            ->where('logs.0.message', 'Older log entry')
            ->reloadOnly('logs', fn (Assert $reload) => $reload
                ->has('logs', 2)
                ->where('logs.0.message', 'Fresh polled log entry')
                ->where('logs.0.context.phase', 'implementing')
                ->missing('tasks')
                ->missing('selectedTask')
                ->missing('users')
            )
        );
});
