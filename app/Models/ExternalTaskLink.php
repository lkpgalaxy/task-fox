<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['task_id', 'external_task_provider', 'external_task_id', 'external_url'])]
class ExternalTaskLink extends Model
{
    /**
     * @return BelongsTo<int, Task>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return HasMany<int, ExternalTaskMessage>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ExternalTaskMessage::class);
    }
}
