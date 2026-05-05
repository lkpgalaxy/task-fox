<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

#[Fillable([
    'title',
    'description',
    'acceptance_criteria',
    'status',
    'priority',
    'deadline',
    'assignee_user_id',
    'reviewer_user_id',
    'source_input_id',
    'project_id',
    'approved_by_user_id',
    'approved_at',
    'rejected_at',
])]
class Task extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_RUNNING = 'running';

    public const STATUS_PR_CREATED = 'pr_created';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REJECTED = 'rejected';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const APPROVAL_FIELDS = [
        'title',
        'description',
        'priority',
        'deadline',
        'assignee_user_id',
        'source_input_id',
        'project_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'acceptance_criteria' => 'array',
            'deadline' => 'date',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<int, TaskRun>
     */
    public function taskRuns(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    /**
     * @return HasOne<int, TaskRun>
     */
    public function latestTaskRun(): HasOne
    {
        return $this->hasOne(TaskRun::class)->latestOfMany('id');
    }

    /**
     * @return HasOne<int, TaskRun>
     */
    public function latestPullRequestRun(): HasOne
    {
        return $this->hasOne(TaskRun::class)->ofMany(
            ['id' => 'max'],
            fn ($query) => $query->whereNotNull('pull_request_url'),
        );
    }

    /**
     * @return BelongsTo<User, Task>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /**
     * @return BelongsTo<User, Task>
     */
    public function approvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /**
     * @return BelongsTo<User, Task>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }

    /**
     * @return BelongsTo<InputSource, Task>
     */
    public function sourceInput(): BelongsTo
    {
        return $this->belongsTo(InputSource::class, 'source_input_id');
    }

    /**
     * @return BelongsTo<Project, Task>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return HasOne<int, ExternalTaskLink>
     */
    public function externalTaskLink(): HasOne
    {
        return $this->hasOne(ExternalTaskLink::class);
    }

    /**
     * @return Builder<Task>
     */
    public function scopeApproved($query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    /**
     * @return Builder<Task>
     */
    public function scopePendingApproval($query): Builder
    {
        return $query->where('status', self::STATUS_PENDING_APPROVAL);
    }

    /**
     * @param  array<string>  $keys
     */
    public function wasApprovedKeyModified(array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $this->getOriginal())) {
                continue;
            }

            if ($this->getAttribute($key) !== $this->getOriginal($key)) {
                return true;
            }
        }

        return false;
    }
}
