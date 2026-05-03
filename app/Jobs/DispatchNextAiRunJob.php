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
            if (AiRun::query()->whereIn('status', AiRun::ACTIVE_STATUSES)->exists()) {
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

            $task->update(['status' => Task::STATUS_RUNNING]);

            $run = $task->aiRuns()->create([
                'status' => AiRun::STATUS_QUEUED,
                'attempt_count' => 0,
                'branch_name' => 'pending',
                'repository_path' => (string) config('automation.repository.path'),
            ]);

            RunApprovedTaskWithCodingAgentJob::dispatch($run->id);
        } finally {
            $lock->release();
        }
    }
}
