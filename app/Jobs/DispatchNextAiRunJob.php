<?php

namespace App\Jobs;

use App\Models\AiRun;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class DispatchNextAiRunJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public function __construct(public ?int $taskId = null) {}

    public function handle(): void
    {
        $lock = Cache::lock('automation:dispatch-next-ai-run', 10);

        if (! $lock->get()) {
            return;
        }

        try {
            $activeRunQuery = AiRun::query()->whereIn('status', AiRun::ACTIVE_STATUSES);

            if ($this->taskId !== null) {
                $activeRunQuery->where(function ($query): void {
                    $query
                        ->where('task_id', '!=', $this->taskId)
                        ->orWhere('status', '!=', AiRun::STATUS_QUEUED);
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

            $task->loadMissing('project:id,workspace_path,base_branch');

            $task->update(['status' => Task::STATUS_RUNNING]);

            $workspacePath = (string) $task->project?->workspace_path;
            $baseBranch = trim((string) $task->project?->base_branch) !== ''
                ? (string) $task->project?->base_branch
                : 'main';

            $resumableRun = $task->aiRuns()
                ->whereIn('status', [AiRun::STATUS_FAILED, AiRun::STATUS_QUEUED])
                ->latest('id')
                ->get()
                ->first(function (AiRun $run) use ($task): bool {
                    return $run->requestHash() !== null && $run->hasMatchingRequestHash($task);
                });

            if ($resumableRun instanceof AiRun) {
                $resumableRun->update([
                    'status' => AiRun::STATUS_QUEUED,
                    'last_error' => null,
                    'finished_at' => null,
                    'project_id' => $task->project_id,
                    'repository_path' => $workspacePath,
                    'workspace_path' => $workspacePath,
                    'base_branch' => $baseBranch,
                ]);

                RunApprovedTaskWithCodingAgentJob::dispatch($resumableRun->id);

                return;
            }

            $run = $task->aiRuns()->create([
                'status' => AiRun::STATUS_QUEUED,
                'attempt_count' => 0,
                'review_attempt_count' => 0,
                'branch_name' => 'pending',
                'project_id' => $task->project_id,
                'repository_path' => $workspacePath,
                'workspace_path' => $workspacePath,
                'base_branch' => $baseBranch,
            ]);
            $run->initializeWorkflowState($task);

            RunApprovedTaskWithCodingAgentJob::dispatch($run->id);
        } finally {
            $lock->release();
        }
    }
}
