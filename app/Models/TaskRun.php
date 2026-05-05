<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

#[Fillable([
    'task_id',
    'status',
    'plan',
    'branch_name',
    'workspace_path',
    'base_branch',
    'pull_request_url',
    'pull_request_number',
    'attempt_count',
    'review_attempt_count',
    'workflow_state',
    'last_error',
    'started_at',
    'finished_at',
])]
class TaskRun extends Model
{
    public const CHECKPOINT_REPOSITORY_PREPARED = 'repository_prepared';

    public const CHECKPOINT_PLANNED = 'planned';

    public const CHECKPOINT_IMPLEMENTATION_VERIFIED = 'implementation_verified';

    public const CHECKPOINT_CHANGES_REVIEWED = 'changes_reviewed';

    public const CHECKPOINT_CHANGES_COMMITTED = 'changes_committed';

    public const CHECKPOINT_PULL_REQUEST_CREATED = 'pull_request_created';

    public const CHECKPOINT_REVIEW_REQUESTED = 'review_requested';

    public const CHECKPOINT_EXTERNAL_TASK_UPDATED = 'external_task_updated';

    public const CHECKPOINT_STATUS_PENDING = 'pending';

    public const CHECKPOINT_STATUS_RUNNING = 'running';

    public const CHECKPOINT_STATUS_COMPLETED = 'completed';

    public const CHECKPOINT_STATUS_SKIPPED = 'skipped';

    public const CHECKPOINT_STATUS_FAILED = 'failed';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_PLANNING = 'planning';

    public const STATUS_IMPLEMENTING = 'implementing';

    public const STATUS_TESTING = 'testing';

    public const STATUS_REVIEWING_CHANGES = 'reviewing_changes';

    public const STATUS_GENERATING_COMMIT_MESSAGE = 'generating_commit_message';

    public const STATUS_COMMITTING_CHANGES = 'committing_changes';

    public const STATUS_CREATING_PR = 'creating_pr';

    public const STATUS_WAITING_FOR_MERGE = 'waiting_for_merge';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_PREPARING,
        self::STATUS_PLANNING,
        self::STATUS_IMPLEMENTING,
        self::STATUS_TESTING,
        self::STATUS_REVIEWING_CHANGES,
        self::STATUS_GENERATING_COMMIT_MESSAGE,
        self::STATUS_COMMITTING_CHANGES,
        self::STATUS_CREATING_PR,
        self::STATUS_WAITING_FOR_MERGE,
    ];

    public const WORKFLOW_CHECKPOINTS = [
        self::CHECKPOINT_REPOSITORY_PREPARED,
        self::CHECKPOINT_PLANNED,
        self::CHECKPOINT_IMPLEMENTATION_VERIFIED,
        self::CHECKPOINT_CHANGES_REVIEWED,
        self::CHECKPOINT_CHANGES_COMMITTED,
        self::CHECKPOINT_PULL_REQUEST_CREATED,
        self::CHECKPOINT_REVIEW_REQUESTED,
        self::CHECKPOINT_EXTERNAL_TASK_UPDATED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workflow_state' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return HasMany<int, TaskRunLog>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(TaskRunLog::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function initializeWorkflowState(Task $task): void
    {
        if (is_array($this->workflow_state)) {
            return;
        }

        $this->forceFill([
            'workflow_state' => [
                'request_hash' => self::requestHashForTask($task),
                'checkpoints' => collect(self::WORKFLOW_CHECKPOINTS)
                    ->map(fn (string $checkpoint): array => [
                        'name' => $checkpoint,
                        'status' => self::CHECKPOINT_STATUS_PENDING,
                        'attempts' => 0,
                        'completed_at' => null,
                        'failed_at' => null,
                        'error' => null,
                    ])
                    ->values()
                    ->all(),
            ],
        ])->save();
    }

    public function hasMatchingRequestHash(Task $task): bool
    {
        $requestHash = $this->requestHash();

        return $requestHash === self::requestHashForTask($task)
            || $requestHash === self::legacyRequestHashForTask($task);
    }

    public function requestHash(): ?string
    {
        $hash = Arr::get($this->workflow_state, 'request_hash');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    public static function requestHashForTask(Task $task): string
    {
        $criteria = Collection::make($task->acceptance_criteria ?? [])
            ->map(fn (array $criterion): array => [
                'body' => (string) Arr::get($criterion, 'body', ''),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'title' => (string) $task->title,
            'description' => (string) $task->description,
            'acceptance_criteria' => $criteria,
            'priority' => (string) $task->priority,
            'deadline' => $task->deadline?->toDateString(),
            'assignee_user_id' => $task->assignee_user_id,
            'source_input_id' => $task->source_input_id,
            'project_id' => $task->project_id,
        ], JSON_THROW_ON_ERROR));
    }

    private static function legacyRequestHashForTask(Task $task): string
    {
        $criteria = Collection::make($task->acceptance_criteria ?? [])
            ->map(fn (array $criterion): array => [
                'body' => (string) Arr::get($criterion, 'body', ''),
                'checked' => (bool) Arr::get($criterion, 'checked', false),
            ])
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'title' => (string) $task->title,
            'description' => (string) $task->description,
            'acceptance_criteria' => $criteria,
            'priority' => (string) $task->priority,
            'deadline' => $task->deadline?->toDateString(),
            'assignee_user_id' => $task->assignee_user_id,
            'source_input_id' => $task->source_input_id,
            'project_id' => $task->project_id,
        ], JSON_THROW_ON_ERROR));
    }

    public function nextIncompleteCheckpoint(): ?string
    {
        foreach ($this->workflowCheckpoints() as $checkpoint) {
            $status = (string) Arr::get($checkpoint, 'status', self::CHECKPOINT_STATUS_PENDING);

            if (! in_array($status, [self::CHECKPOINT_STATUS_COMPLETED, self::CHECKPOINT_STATUS_SKIPPED], true)) {
                return (string) $checkpoint['name'];
            }
        }

        return null;
    }

    public function nextRunnableCheckpoint(): ?string
    {
        foreach ($this->workflowCheckpoints() as $checkpoint) {
            $status = (string) Arr::get($checkpoint, 'status', self::CHECKPOINT_STATUS_PENDING);

            if ($status === self::CHECKPOINT_STATUS_FAILED) {
                return (string) $checkpoint['name'];
            }
        }

        return $this->nextIncompleteCheckpoint();
    }

    public function runningCheckpoint(): ?string
    {
        foreach ($this->workflowCheckpoints() as $checkpoint) {
            $status = (string) Arr::get($checkpoint, 'status', self::CHECKPOINT_STATUS_PENDING);

            if ($status === self::CHECKPOINT_STATUS_RUNNING) {
                return (string) $checkpoint['name'];
            }
        }

        return null;
    }

    public function isCheckpointComplete(string $checkpointName): bool
    {
        $status = (string) Arr::get($this->checkpoint($checkpointName), 'status', self::CHECKPOINT_STATUS_PENDING);

        return in_array($status, [self::CHECKPOINT_STATUS_COMPLETED, self::CHECKPOINT_STATUS_SKIPPED], true);
    }

    public function checkpointAttempts(string $checkpointName): int
    {
        return (int) Arr::get($this->checkpoint($checkpointName), 'attempts', 0);
    }

    public function markCheckpointRunning(string $checkpointName): void
    {
        $this->updateCheckpoint($checkpointName, function (array $checkpoint): array {
            $checkpoint['status'] = self::CHECKPOINT_STATUS_RUNNING;
            $checkpoint['attempts'] = ((int) ($checkpoint['attempts'] ?? 0)) + 1;
            $checkpoint['failed_at'] = null;
            $checkpoint['error'] = null;

            return $checkpoint;
        });
    }

    public function markCheckpointCompleted(string $checkpointName): void
    {
        $this->updateCheckpoint($checkpointName, fn (array $checkpoint): array => array_merge($checkpoint, [
            'status' => self::CHECKPOINT_STATUS_COMPLETED,
            'completed_at' => now()->toIso8601String(),
            'failed_at' => null,
            'error' => null,
        ]));
    }

    public function markCheckpointSkipped(string $checkpointName): void
    {
        $this->updateCheckpoint($checkpointName, fn (array $checkpoint): array => array_merge($checkpoint, [
            'status' => self::CHECKPOINT_STATUS_SKIPPED,
            'completed_at' => now()->toIso8601String(),
            'failed_at' => null,
            'error' => null,
        ]));
    }

    public function markCheckpointFailed(string $checkpointName, string $error): void
    {
        $this->updateCheckpoint($checkpointName, fn (array $checkpoint): array => array_merge($checkpoint, [
            'status' => self::CHECKPOINT_STATUS_FAILED,
            'failed_at' => now()->toIso8601String(),
            'error' => $error,
        ]));
    }

    /**
     * @return list<array{name: string, status: string, attempts: int, completed_at: ?string, failed_at: ?string, error: ?string}>
     */
    public function workflowCheckpoints(): array
    {
        $checkpoints = Arr::get($this->workflow_state, 'checkpoints');

        if (! is_array($checkpoints)) {
            return [];
        }

        return array_values($checkpoints);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkpoint(string $checkpointName): array
    {
        foreach ($this->workflowCheckpoints() as $checkpoint) {
            if (($checkpoint['name'] ?? null) === $checkpointName) {
                return $checkpoint;
            }
        }

        return [];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $callback
     */
    private function updateCheckpoint(string $checkpointName, callable $callback): void
    {
        $state = $this->workflow_state ?? [];
        $checkpoints = Arr::get($state, 'checkpoints', []);

        foreach ($checkpoints as $index => $checkpoint) {
            if (($checkpoint['name'] ?? null) === $checkpointName) {
                $checkpoints[$index] = $callback($checkpoint);

                break;
            }
        }

        $state['checkpoints'] = array_values($checkpoints);

        $this->forceFill(['workflow_state' => $state])->save();
        $this->refresh();
    }
}
