<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable([
    'task_id',
    'project_id',
    'status',
    'plan',
    'test_cases',
    'branch_name',
    'repository_path',
    'workspace_path',
    'base_branch',
    'pull_request_url',
    'pull_request_number',
    'attempt_count',
    'review_attempt_count',
    'last_error',
    'started_at',
    'finished_at',
])]
class AiRun extends Model
{
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'test_cases' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Project, AiRun>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<int, AiRunLog>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(AiRunLog::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function flattenedTestCases(): array
    {
        $cases = $this->test_cases;

        if (! is_array($cases)) {
            return [];
        }

        return (new Collection($cases))
            ->filter(static fn (array $case): bool => isset($case['name']))
            ->values()
            ->all();
    }
}
