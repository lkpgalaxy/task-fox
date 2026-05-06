<?php

namespace App\Models;

use Database\Factories\TaskRunPhaseSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'task_run_id',
    'phase',
    'status',
    'session_id',
    'model',
    'reasoning_effort',
    'attempt_count',
    'input_tokens',
    'cached_input_tokens',
    'output_tokens',
    'total_tokens',
    'total_cost_usd',
    'command',
    'last_error',
    'started_at',
    'finished_at',
])]
class TaskRunPhaseSession extends Model
{
    /** @use HasFactory<TaskRunPhaseSessionFactory> */
    use HasFactory;

    public const PHASE_PLAN = 'plan';

    public const PHASE_IMPLEMENT = 'implement';

    public const PHASE_TEST = 'test';

    public const PHASE_REVIEW = 'review';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const PHASES = [
        self::PHASE_PLAN,
        self::PHASE_IMPLEMENT,
        self::PHASE_TEST,
        self::PHASE_REVIEW,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'input_tokens' => 'integer',
            'cached_input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'total_tokens' => 'integer',
            'command' => 'array',
            'total_cost_usd' => 'decimal:8',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<int, TaskRun>
     */
    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }
}
