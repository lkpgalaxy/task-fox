<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['ai_run_id', 'input_source_id', 'level', 'message', 'context'])]
class AiRunLog extends Model
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
     * @return BelongsTo<int, AiRun>
     */
    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiRun::class);
    }

    /**
     * @return BelongsTo<InputSource, $this>
     */
    public function inputSource(): BelongsTo
    {
        return $this->belongsTo(InputSource::class);
    }
}
