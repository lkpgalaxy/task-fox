<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['task_run_id', 'input_source_id', 'level', 'message', 'context'])]
class TaskRunLog extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<int, TaskRun>
     */
    public function taskRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class);
    }

    /**
     * @return BelongsTo<InputSource, $this>
     */
    public function inputSource(): BelongsTo
    {
        return $this->belongsTo(InputSource::class);
    }
}
