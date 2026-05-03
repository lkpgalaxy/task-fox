<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['external_task_link_id', 'type', 'payload', 'status', 'error', 'sent_at'])]
class ExternalTaskMessage extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<int, ExternalTaskLink>
     */
    public function externalTaskLink(): BelongsTo
    {
        return $this->belongsTo(ExternalTaskLink::class, 'external_task_link_id');
    }
}
