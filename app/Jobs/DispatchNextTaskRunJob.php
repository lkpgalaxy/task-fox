<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskRun;
use App\Services\SystemSettingsResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class DispatchNextTaskRunJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(public ?int $taskId = null) {}

    public function handle(SystemSettingsResolver $settingsResolver): void
    {
        $lock = Cache::lock('automation:dispatch-next-task-run', 10);

        if (! $lock->get()) {
            return;
        }

        try {
            $activeRunQuery = TaskRun::query()->whereIn('status', TaskRun::ACTIVE_STATUSES);

            if ($this->taskId !== null) {
                $activeRunQuery->where(function ($query): void {
                    $query
                        ->where('task_id', '!=', $this->taskId)
                        ->orWhere('status', '!=', TaskRun::STATUS_QUEUED);
                });
            }

            if ($activeRunQuery->exists()) {
                return;
            }

            $taskQuery = Task::query()
                ->where('status', Task::STATUS_APPROVED)
                ->orderBy('created_at');

            if ($this->taskId !== null) {
                $taskQuery->where('id', $this->taskId);
            }

            $task = $taskQuery->first();

            if ($task === null) {
                return;
            }

            if ($task->project_id === null) {
                return;
            }

            $task->loadMissing('project:id,workspace_path,base_branch', 'approvedByUser:id,automation_coding_agent_driver');

            $task->update(['status' => Task::STATUS_RUNNING]);

            $workspacePath = (string) $task->project?->workspace_path;
            $baseBranch = trim((string) $task->project?->base_branch) !== ''
                ? (string) $task->project?->base_branch
                : 'main';

            $resumableRun = $task->taskRuns()
                ->whereIn('status', [TaskRun::STATUS_FAILED, TaskRun::STATUS_QUEUED])
                ->latest('id')
                ->get()
                ->first(function (TaskRun $run) use ($task): bool {
                    return $run->requestHash() !== null && $run->hasMatchingRequestHash($task);
                });

            if ($resumableRun instanceof TaskRun) {
                $resumableRun->update([
                    'status' => TaskRun::STATUS_QUEUED,
                    'last_error' => null,
                    'finished_at' => null,
                    'workspace_path' => $workspacePath,
                    'base_branch' => $baseBranch,
                ]);

                RunApprovedTaskWithCodingAgentJob::dispatch($resumableRun->id);

                return;
            }

            $run = $task->taskRuns()->create([
                'status' => TaskRun::STATUS_QUEUED,
                'attempt_count' => 0,
                'review_attempt_count' => 0,
                'branch_name' => 'pending',
                'workspace_path' => $workspacePath,
                'base_branch' => $baseBranch,
                'coding_agent_driver' => $settingsResolver->effectiveCodingAgentDriver($task->approvedByUser),
            ]);
            $run->initializeWorkflowState($task);

            RunApprovedTaskWithCodingAgentJob::dispatch($run->id);
        } finally {
            $lock->release();
        }
    }
}
