<?php

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskRunLog;
use App\Models\TaskRunPhaseSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

test('task details return run logs in latest-first order', function () {
    $this->withoutVite();

    $task = Task::create([
        'title' => 'Ordered run logs task',
        'description' => 'Task details should show the newest run logs first.',
        'status' => Task::STATUS_RUNNING,
        'priority' => Task::PRIORITY_MEDIUM,
    ]);
    $run = TaskRun::create([
        'task_id' => $task->id,
        'status' => TaskRun::STATUS_PLANNING,
        'branch_name' => 'task/ordered-run-logs',
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

    $newerLog = TaskRunLog::create([
        'task_run_id' => $run->id,
        'level' => 'info',
        'message' => 'Newer log entry',
        'context' => ['phase' => 'implementing'],
    ]);
    $newerLog->forceFill([
        'created_at' => now()->addSecond(),
        'updated_at' => now()->addSecond(),
    ])->save();

    TaskRunPhaseSession::create([
        'task_run_id' => $run->id,
        'phase' => TaskRunPhaseSession::PHASE_PLAN,
        'status' => TaskRunPhaseSession::STATUS_COMPLETED,
        'session_id' => 'thread-plan',
        'attempt_count' => 1,
    ]);
    TaskRunPhaseSession::create([
        'task_run_id' => $run->id,
        'phase' => TaskRunPhaseSession::PHASE_REVIEW,
        'status' => TaskRunPhaseSession::STATUS_COMPLETED,
        'attempt_count' => 1,
    ]);

    $this->get(route('tasks.index', ['task' => $task->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('tasks/Index')
            ->where('selectedTask.id', $task->id)
            ->has('selectedTask.task_runs', 1)
            ->has('selectedTask.task_runs.0.phase_sessions', 2)
            ->where('selectedTask.task_runs.0.phase_sessions.0.session_id', 'thread-plan')
            ->where('selectedTask.task_runs.0.phase_sessions.1.session_id', null)
            ->missing('selectedTask.task_runs.0.phase_sessions.0.resume_command')
            ->has('selectedTask.task_runs.0.logs', 2)
            ->where('selectedTask.task_runs.0.logs.0.id', $newerLog->id)
            ->where('selectedTask.task_runs.0.logs.0.message', 'Newer log entry')
            ->where('selectedTask.task_runs.0.logs.1.id', $olderLog->id)
            ->where('selectedTask.task_runs.0.logs.1.message', 'Older log entry')
        );
});
